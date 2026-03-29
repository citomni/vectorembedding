<?php
declare(strict_types=1);
/*
 * This file is part of the CitOmni framework.
 * Low overhead, high performance, ready for anything.
 *
 * For more information, visit https://github.com/citomni
 *
 * Copyright (c) 2012-present Lars Grove Mortensen
 * SPDX-License-Identifier: MIT
 *
 * For full copyright, trademark, and license information,
 * please see the LICENSE file distributed with this source code.
 */

namespace CitOmni\VectorEmbedding\Service;

use CitOmni\Kernel\Service\BaseService;
use CitOmni\VectorEmbedding\Exception\AdapterNotFoundException;
use CitOmni\VectorEmbedding\Exception\InvalidRequestException;
use CitOmni\VectorEmbedding\Exception\ProviderRequestException;
use CitOmni\VectorEmbedding\Exception\ProviderResponseException;
use CitOmni\VectorEmbedding\Exception\VectorEmbeddingConfigException;
use CitOmni\VectorEmbedding\Interface\EmbeddingAdapterInterface;

/**
 * Embedding vector generation service.
 *
 * Provides one deterministic public method for generating semantic embedding
 * vectors from input content, delegating provider-specific request/response
 * handling to dedicated adapters while owning shared transport, logging,
 * and response finalization.
 *
 * Behavior:
 * - Validates raw input shape and known top-level option/debug keys.
 * - Normalizes the input request into one canonical internal structure.
 * - Resolves profile configuration from package config.
 * - Instantiates the correct adapter (cached per profile within the request).
 * - Delegates URL, request payload, and header building to the adapter.
 * - Executes transport through the shared curl service.
 * - Delegates response parsing to the adapter.
 * - Finalizes and returns a stable package response.
 * - Logs success/failure when the log service is available.
 *
 * Notes:
 * - Provider credentials must never be placed in provider payloads.
 * - Secrets belong in headers, not in the logged request body.
 *
 * Typical usage:
 *   $result = $this->app->vectorEmbedder->embed([
 *       'profile' => 'gemini-embedding-2',
 *       'items'   => [['type' => 'text', 'text' => 'Lejelovens regler om depositum']],
 *   ]);
 *
 * @see EmbeddingAdapterInterface
 */
final class VectorEmbedder extends BaseService {

	/** @var string Log file name used for all vectorembedding log entries. */
	private const LOG_FILE = 'vectorembedding';

	/** @var string[] Recognized keys in the top-level options array. */
	private const KNOWN_OPTIONS = ['dimensions', 'task_type'];

	/** @var string[] Recognized keys in the top-level debug array. */
	private const KNOWN_DEBUG_KEYS = ['include_raw_response', 'include_built_request'];

	/** @var array<string, EmbeddingAdapterInterface> Adapter instances cached per profile ID. */
	private array $adapters = [];






	// ----------------------------------------------------------------
	// Public API
	// ----------------------------------------------------------------

	/**
	 * Generate embedding vectors for the given input.
	 *
	 * Behavior:
	 * - Validates raw input shape and known option/debug keys.
	 * - Normalizes the input into the canonical internal request shape.
	 * - Validates structural correctness (items, options, debug).
	 * - Resolves the target profile from config.
	 * - Builds the provider-specific URL, request, and headers via the adapter.
	 * - Executes transport through the curl service.
	 * - Parses the provider response via the adapter.
	 * - Returns a stable package response array.
	 *
	 * @param  array  $input  Embedding request (profile, items, options, provider_options, debug).
	 * @return array  Normalized package response with vectors, usage, and meta.
	 * @throws InvalidRequestException         On structurally invalid input.
	 * @throws VectorEmbeddingConfigException  On missing or malformed profile config.
	 * @throws AdapterNotFoundException        When the adapter class is missing or invalid.
	 * @throws ProviderRequestException        On transport or HTTP-level failure.
	 * @throws ProviderResponseException       When the provider response cannot be parsed.
	 */
	public function embed(array $input): array {
		$startTime = \hrtime(true);

		// -- 1. Validate raw input and normalize --------------------------
		$this->validateRawInput($input);

		$request = $this->normalizeRequest($input);
		$this->validateRequest($request);

		// -- 2. Resolve profile ------------------------------------------
		$profileId = $request['profile'];
		$profileConfig = $this->resolveProfile($profileId);

		// -- 3. Build adapter --------------------------------------------
		$adapter = $this->getAdapter($profileId, $profileConfig);

		// -- 4. Build provider URL, payload, and headers -----------------
		try {
			$endpointUrl = $adapter->buildUrl();
			$providerPayload = $adapter->buildRequest($request);
			$providerHeaders = $adapter->buildHeaders($request);
		} catch (InvalidRequestException $e) {
			$durationMs = $this->elapsedMs($startTime);
			$this->logFailure($profileId, $profileConfig, $request, $durationMs, $e->getMessage(), $request['debug']);
			throw $e;
		}

		$this->validateHeaders($providerHeaders);

		// -- 5. Encode and build curl request ----------------------------
		try {
			$jsonBody = \json_encode($providerPayload, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE);
		} catch (\JsonException $e) {
			$durationMs = $this->elapsedMs($startTime);
			$this->logFailure($profileId, $profileConfig, $request, $durationMs, $e->getMessage(), $request['debug']);
			throw new ProviderRequestException(
				\sprintf('Failed to JSON-encode provider payload for profile "%s": %s', $profileId, $e->getMessage()),
				0,
				$e
			);
		}

		$curlRequest = [
			'url' => $endpointUrl,
			'method' => 'POST',
			'headers' => $providerHeaders,
			'body' => $jsonBody,
			'timeout' => (int)($profileConfig['timeout'] ?? 30),
			'connect_timeout' => (int)($profileConfig['connect_timeout'] ?? 10),
		];

		// -- 6. Execute transport ----------------------------------------
		$debug = $request['debug'];

		try {
			$transportResult = $this->app->curl->execute($curlRequest);
		} catch (\Throwable $e) {
			$durationMs = $this->elapsedMs($startTime);
			$this->logFailure($profileId, $profileConfig, $request, $durationMs, $e->getMessage(), $debug);
			throw new ProviderRequestException(
				\sprintf('Transport failure for profile "%s": %s', $profileId, $e->getMessage()),
				(int)$e->getCode(),
				$e
			);
		}

		// -- 7. Check HTTP-level success ---------------------------------
		if (!$transportResult['is_http_success']) {
			$durationMs = $this->elapsedMs($startTime);
			$statusCode = $transportResult['status_code'] ?? 0;
			$responseBody = $transportResult['body'] ?? '';

			$this->logFailure(
				$profileId,
				$profileConfig,
				$request,
				$durationMs,
				\sprintf('HTTP %d: %s', $statusCode, $this->truncateForLog($responseBody, 500)),
				$debug
			);

			throw new ProviderRequestException(
				\sprintf('Provider returned HTTP %d for profile "%s".', $statusCode, $profileId),
				$statusCode
			);
		}

		// -- 8. Parse provider response ----------------------------------
		try {
			$parsed = $adapter->parseResponse($transportResult, $request);
		} catch (ProviderResponseException $e) {
			$durationMs = $this->elapsedMs($startTime);
			$this->logFailure($profileId, $profileConfig, $request, $durationMs, $e->getMessage(), $debug);
			throw $e;
		} catch (\Throwable $e) {
			$durationMs = $this->elapsedMs($startTime);
			$this->logFailure($profileId, $profileConfig, $request, $durationMs, $e->getMessage(), $debug);
			throw new ProviderResponseException(
				\sprintf('Failed to parse response for profile "%s": %s', $profileId, $e->getMessage()),
				(int)$e->getCode(),
				$e
			);
		}

		// -- 9. Finalize response ----------------------------------------
		$durationMs = $this->elapsedMs($startTime);

		$response = [
			'profile' => $profileId,
			'provider' => (string)($profileConfig['provider'] ?? ''),
			'model' => (string)($profileConfig['model'] ?? ''),
			'vectors' => $parsed['vectors'] ?? [],
			'usage' => [
				'input_tokens' => $parsed['usage']['input_tokens'] ?? null,
				'total_tokens' => $parsed['usage']['total_tokens'] ?? null,
			],
			'raw' => $debug['include_raw_response'] ? ($transportResult['body'] ?? null) : null,
			'meta' => [
				'cached' => false,
				'cache_key' => null,
				'duration_ms' => $durationMs,
			],
		];

		if ($debug['include_built_request']) {
			$response['meta']['built_request'] = $providerPayload;
		}

		// -- 10. Log success ---------------------------------------------
		$this->logSuccess($profileId, $profileConfig, $request, $response, $debug);

		return $response;
	}







	// ----------------------------------------------------------------
	// Raw input validation
	// ----------------------------------------------------------------

	/**
	 * Validate raw caller input before normalization.
	 *
	 * Behavior:
	 * - Fails fast on wrong container types for options, provider_options, and debug.
	 * - Fails fast on unknown keys inside options and debug.
	 *
	 * @param  array  $input  Raw caller input.
	 * @throws InvalidRequestException  On invalid raw input shape.
	 */
	private function validateRawInput(array $input): void {
		if (\array_key_exists('options', $input) && !\is_array($input['options'])) {
			throw new InvalidRequestException('"options" must be an array when provided.');
		}

		if (\array_key_exists('provider_options', $input) && !\is_array($input['provider_options'])) {
			throw new InvalidRequestException('"provider_options" must be an array when provided.');
		}

		if (\array_key_exists('debug', $input) && !\is_array($input['debug'])) {
			throw new InvalidRequestException('"debug" must be an array when provided.');
		}

		if (isset($input['options'])) {
			$unknownOptions = \array_diff(\array_keys($input['options']), self::KNOWN_OPTIONS);
			if ($unknownOptions !== []) {
				throw new InvalidRequestException(\sprintf('Unknown options key(s): %s', \implode(', ', $unknownOptions)));
			}
		}

		if (isset($input['debug'])) {
			$unknownDebug = \array_diff(\array_keys($input['debug']), self::KNOWN_DEBUG_KEYS);
			if ($unknownDebug !== []) {
				throw new InvalidRequestException(\sprintf('Unknown debug key(s): %s', \implode(', ', $unknownDebug)));
			}
		}
	}







	// ----------------------------------------------------------------
	// Normalization and validation
	// ----------------------------------------------------------------

	/**
	 * Normalize raw input into the canonical internal request shape.
	 *
	 * @param  array  $input  Raw caller input.
	 * @return array  Normalized request.
	 */
	private function normalizeRequest(array $input): array {
		$cfg = $this->app->cfg->vectorembedding;
		$cfgDebug = (array)($cfg->debug ?? []);

		// Resolve profile: explicit > default_profile config.
		$profile = '';
		if (isset($input['profile']) && \is_string($input['profile']) && $input['profile'] !== '') {
			$profile = $input['profile'];
		} else {
			$default = (string)($cfg->default_profile ?? '');
			if ($default !== '') {
				$profile = $default;
			}
		}

		// Normalize debug: request overrides config defaults.
		$debugInput = isset($input['debug']) ? $input['debug'] : [];
		$debug = [
			'include_raw_response' => (bool)($debugInput['include_raw_response'] ?? $cfgDebug['include_raw_response'] ?? false),
			'include_built_request' => (bool)($debugInput['include_built_request'] ?? $cfgDebug['include_built_request'] ?? false),
		];

		// Normalize options.
		$options = isset($input['options']) ? $input['options'] : [];
		$normalizedOptions = [
			'dimensions' => $options['dimensions'] ?? null,
			'task_type' => $options['task_type'] ?? null,
		];

		return [
			'profile' => $profile,
			'items' => $input['items'] ?? [],
			'options' => $normalizedOptions,
			'provider_options' => $input['provider_options'] ?? [],
			'debug' => $debug,
		];
	}


	/**
	 * Validate the normalized request structure.
	 *
	 * Behavior:
	 * - Fails fast on missing profile, empty items, invalid item shapes,
	 *   and invalid normalized container types.
	 * - Validates generic known item types only.
	 * - Leaves provider-specific modality support to the adapter.
	 *
	 * @param  array  $request  Normalized request.
	 * @throws InvalidRequestException  On structurally invalid input.
	 */
	private function validateRequest(array $request): void {
		// Profile must resolve to a non-empty string.
		if ($request['profile'] === '') {
			throw new InvalidRequestException('No profile specified and no default_profile configured.');
		}

		// Items must be a non-empty array.
		if (!\is_array($request['items']) || $request['items'] === []) {
			throw new InvalidRequestException('Input "items" must be a non-empty array.');
		}

		// Validate each item.
		foreach ($request['items'] as $i => $item) {
			if (!\is_array($item)) {
				throw new InvalidRequestException(\sprintf('Item at index %d must be an array.', $i));
			}

			if (!isset($item['type']) || !\is_string($item['type']) || $item['type'] === '') {
				throw new InvalidRequestException(\sprintf('Item at index %d must contain a non-empty string "type".', $i));
			}

			// Validate generic known item types structurally.
			if ($item['type'] === 'text') {
				if (!isset($item['text']) || !\is_string($item['text']) || $item['text'] === '') {
					throw new InvalidRequestException(\sprintf('Text item at index %d must contain a non-empty string "text".', $i));
				}
			}
		}
	}








	// ----------------------------------------------------------------
	// Profile resolution
	// ----------------------------------------------------------------

	/**
	 * Resolve profile configuration from package config.
	 *
	 * Behavior:
	 * - Reads the wrapped vectorembedding.profiles cfg node via toArray().
	 * - Verifies that the requested profile exists.
	 * - Verifies that the resolved profile is an array.
	 * - Verifies required profile keys used by the shared transport flow.
	 *
	 * Notes:
	 * - The vectorembedding cfg node is expected to exist in the package baseline.
	 * - profiles is an associative cfg node and is therefore exposed as Cfg, not a raw array.
	 *
	 * @param string $profileId The profile identifier.
	 * @return array Resolved profile configuration.
	 * @throws VectorEmbeddingConfigException When the profile is missing or malformed.
	 */
	private function resolveProfile(string $profileId): array {

		$profiles = $this->app->cfg->vectorembedding->profiles->toArray();  // profiles is a wrapped associative cfg node

		if (!isset($profiles[$profileId])) {
			throw new VectorEmbeddingConfigException(\sprintf('Profile "%s" not found in vectorembedding.profiles config.', $profileId));
		}

		$profile = $profiles[$profileId];

		if (!\is_array($profile)) {
			throw new VectorEmbeddingConfigException(\sprintf('Profile "%s" must be an array.', $profileId));
		}

		if (!isset($profile['adapter']) || !\is_string($profile['adapter']) || $profile['adapter'] === '') {
			throw new VectorEmbeddingConfigException(\sprintf('Profile "%s" is missing a valid "adapter" class.', $profileId));
		}

		if (!isset($profile['base_url']) || !\is_string($profile['base_url']) || $profile['base_url'] === '') {
			throw new VectorEmbeddingConfigException(\sprintf('Profile "%s" is missing a valid "base_url".', $profileId));
		}

		if (!isset($profile['api_key']) || !\is_string($profile['api_key']) || $profile['api_key'] === '') {
			throw new VectorEmbeddingConfigException(\sprintf('Profile "%s" is missing a valid "api_key".', $profileId));
		}

		return $profile;
	}








	// ----------------------------------------------------------------
	// Adapter management
	// ----------------------------------------------------------------

	/**
	 * Get or create an adapter instance for the given profile.
	 *
	 * Behavior:
	 * - Returns a cached instance if one exists for the profile ID.
	 * - Validates that the adapter class exists and implements the interface.
	 * - Instantiates with ($profileId, $profileConfig).
	 *
	 * @param  string  $profileId      Profile identifier.
	 * @param  array   $profileConfig  Resolved profile configuration.
	 * @return EmbeddingAdapterInterface
	 * @throws AdapterNotFoundException  When the class is missing or invalid.
	 */
	private function getAdapter(string $profileId, array $profileConfig): EmbeddingAdapterInterface {
		if (isset($this->adapters[$profileId])) {
			return $this->adapters[$profileId];
		}

		$adapterClass = $profileConfig['adapter'];

		if (!\class_exists($adapterClass)) {
			throw new AdapterNotFoundException(\sprintf('Adapter class "%s" not found for profile "%s".', $adapterClass, $profileId));
		}

		$adapter = new $adapterClass($profileId, $profileConfig);

		if (!$adapter instanceof EmbeddingAdapterInterface) {
			throw new AdapterNotFoundException(\sprintf('Adapter class "%s" does not implement EmbeddingAdapterInterface.', $adapterClass));
		}

		$this->adapters[$profileId] = $adapter;

		return $adapter;
	}








	// ----------------------------------------------------------------
	// Transport helpers
	// ----------------------------------------------------------------

	/**
	 * Validate that adapter-built headers are well-formed.
	 *
	 * Behavior:
	 * - Requires each header to be a string.
	 * - Requires exactly one split point into name/value parts.
	 * - Requires the header name to be non-empty after trimming.
	 *
	 * @param  array  $headers  Header lines from the adapter.
	 * @throws ProviderRequestException  On invalid header shape.
	 */
	private function validateHeaders(array $headers): void {
		foreach ($headers as $i => $header) {
			if (!\is_string($header)) {
				throw new ProviderRequestException(\sprintf('Adapter header at index %d must be a string.', $i));
			}

			$parts = \explode(':', $header, 2);

			if (\count($parts) !== 2 || \trim($parts[0]) === '') {
				throw new ProviderRequestException(\sprintf('Adapter header at index %d is not a valid "Name: Value" string.', $i));
			}
		}
	}








	// ----------------------------------------------------------------
	// Timing
	// ----------------------------------------------------------------

	/**
	 * Calculate elapsed milliseconds since the given hrtime start.
	 *
	 * @param  int|float  $startTime  Value from hrtime(true).
	 * @return int  Elapsed milliseconds.
	 */
	private function elapsedMs(int|float $startTime): int {
		return (int)\round((\hrtime(true) - $startTime) / 1_000_000);
	}








	// ----------------------------------------------------------------
	// Logging
	// ----------------------------------------------------------------

	/**
	 * Log a successful embedding request.
	 *
	 * Notes:
	 * - Built requests may be logged only when explicitly enabled.
	 * - Provider credentials must never be included in provider payloads.
	 *
	 * @param  string  $profileId      Profile identifier.
	 * @param  array   $profileConfig  Resolved profile configuration.
	 * @param  array   $request        Normalized internal request.
	 * @param  array   $response       Finalized package response.
	 * @param  array   $debug          Resolved debug flags.
	 */
	private function logSuccess(string $profileId, array $profileConfig, array $request, array $response, array $debug): void {
		if (!$this->app->hasService('log')) {
			return;
		}

		$context = [
			'profile' => $profileId,
			'provider' => $profileConfig['provider'] ?? '',
			'model' => $profileConfig['model'] ?? '',
			'item_count' => \count($request['items']),
			'vector_count' => \count($response['vectors']),
			'duration_ms' => $response['meta']['duration_ms'],
			'usage' => $response['usage'],
		];

		if ($debug['include_built_request'] && isset($response['meta']['built_request'])) {
			$context['built_request'] = $response['meta']['built_request'];
		}

		$this->app->log->write(self::LOG_FILE, 'embed.ok', 'Embedding generated', $context);
	}


	/**
	 * Log a failed embedding request.
	 *
	 * @param  string  $profileId      Profile identifier.
	 * @param  array   $profileConfig  Resolved profile configuration.
	 * @param  array   $request        Normalized internal request.
	 * @param  int     $durationMs     Elapsed time in milliseconds.
	 * @param  string  $errorMessage   Error description.
	 * @param  array   $debug          Resolved debug flags.
	 */
	private function logFailure(string $profileId, array $profileConfig, array $request, int $durationMs, string $errorMessage, array $debug): void {
		if (!$this->app->hasService('log')) {
			return;
		}

		$context = [
			'profile' => $profileId,
			'provider' => $profileConfig['provider'] ?? '',
			'model' => $profileConfig['model'] ?? '',
			'item_count' => \count($request['items']),
			'duration_ms' => $durationMs,
			'error' => $errorMessage,
		];

		$this->app->log->write(self::LOG_FILE, 'embed.fail', 'Embedding failed', $context);
	}


	/**
	 * Truncate a string for log context.
	 *
	 * @param  string  $value   The string to truncate.
	 * @param  int     $maxLen  Maximum length.
	 * @return string  Truncated string.
	 */
	private function truncateForLog(string $value, int $maxLen): string {
		if (\strlen($value) <= $maxLen) {
			return $value;
		}

		return \substr($value, 0, $maxLen) . '...[truncated]';
	}

}
