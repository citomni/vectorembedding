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

namespace CitOmni\VectorEmbedding\Adapter;

use CitOmni\VectorEmbedding\Exception\InvalidRequestException;
use CitOmni\VectorEmbedding\Exception\ProviderResponseException;
use CitOmni\VectorEmbedding\Interface\EmbeddingAdapterInterface;

/**
 * OpenAI embeddings adapter.
 *
 * Translates the package's normalized internal embedding request into the
 * OpenAI embeddings API request shape and parses the provider response back
 * into the package's stable internal response format.
 *
 * Behavior:
 * - Builds the OpenAI embeddings endpoint URL from profile config.
 * - Accepts text items only in V1.
 * - Maps internal text items to OpenAI's "input" field.
 * - Always requests float output encoding.
 * - Passes through supported package-level options.
 * - Parses OpenAI's "data" list into the package "vectors" list.
 * - Maps usage.prompt_tokens to usage.input_tokens.
 *
 * Notes:
 * - This adapter is intentionally narrow and stateless in behavior.
 * - Provider credentials must live in headers, never in payloads.
 *
 * @see https://platform.openai.com/docs/api-reference/embeddings
 */
final class OpenAiEmbeddingAdapter implements EmbeddingAdapterInterface {

	/** @var string Profile identifier for diagnostics. */
	private string $profileId;

	/** @var array Resolved profile configuration. */
	private array $profileConfig;


	/**
	 * Create a new adapter instance.
	 *
	 * @param string $profileId Profile identifier.
	 * @param array $profileConfig Resolved profile configuration.
	 */
	public function __construct(string $profileId, array $profileConfig) {
		$this->profileId = $profileId;
		$this->profileConfig = $profileConfig;
	}


	/**
	 * Build the provider-specific endpoint URL.
	 *
	 * @return string Provider endpoint URL.
	 */
	public function buildUrl(): string {
		return \rtrim((string)$this->profileConfig['base_url'], '/') . '/embeddings';
	}


	/**
	 * Build the provider-specific request payload.
	 *
	 * Behavior:
	 * - Accepts only text items in V1.
	 * - Converts internal items[] to OpenAI input.
	 * - Sends model from profile config.
	 * - Always sets encoding_format to float.
	 * - Includes dimensions when explicitly provided.
	 * - Rejects unsupported package options and provider options.
	 *
	 * @param array $request Normalized internal embedding request.
	 * @return array Provider request payload as an associative array.
	 * @throws InvalidRequestException When the request is incompatible with the OpenAI adapter.
	 */
	public function buildRequest(array $request): array {
		$model = (string)($this->profileConfig['model'] ?? '');
		if ($model === '') {
			throw new InvalidRequestException(\sprintf('OpenAI adapter for profile "%s" requires a non-empty model in profile config.', $this->profileId));
		}

		$providerOptions = $request['provider_options'] ?? [];
		if ($providerOptions !== []) {
			$unknownKeys = \implode(', ', \array_keys($providerOptions));
			throw new InvalidRequestException(\sprintf('OpenAI adapter does not support provider_options in V1. Unsupported key(s): %s', $unknownKeys));
		}

		$input = [];

		foreach ($request['items'] as $i => $item) {
			$type = (string)($item['type'] ?? '');

			if ($type !== 'text') {
				throw new InvalidRequestException(\sprintf('OpenAI adapter supports only text items in V1. Item at index %d has unsupported type "%s".', $i, $type));
			}

			$text = (string)($item['text'] ?? '');
			if ($text === '') {
				throw new InvalidRequestException(\sprintf('OpenAI text item at index %d must contain a non-empty string "text".', $i));
			}

			$input[] = $text;
		}

		if ($input === []) {
			throw new InvalidRequestException('OpenAI adapter requires at least one text item.');
		}

		$payload = [
			'input' => \count($input) === 1 ? $input[0] : $input,
			'model' => $model,
			'encoding_format' => 'float',
		];

		$options = $request['options'] ?? [];

		if (($options['task_type'] ?? null) !== null) {
			throw new InvalidRequestException('OpenAI embeddings API does not support the package option "task_type".');
		}

		if (($options['dimensions'] ?? null) !== null) {
			$dimensions = $options['dimensions'];

			if (!\is_int($dimensions)) {
				throw new InvalidRequestException('OpenAI option "dimensions" must be an integer when provided.');
			}

			if ($dimensions <= 0) {
				throw new InvalidRequestException('OpenAI option "dimensions" must be greater than zero.');
			}

			$payload['dimensions'] = $dimensions;
		}

		return $payload;
	}


	/**
	 * Build the provider-specific headers.
	 *
	 * Notes:
	 * - Required credentials are expected to be validated by the shared service
	 *   during profile resolution before the adapter is used.
	 *
	 * @param array $request Normalized internal embedding request.
	 * @return array<int, string> HTTP header lines.
	 */
	public function buildHeaders(array $request): array {
		return [
			'Content-Type: application/json',
			'Authorization: Bearer ' . $this->profileConfig['api_key'],
		];
	}


	/**
	 * Parse the transport result into the package response format.
	 *
	 * Behavior:
	 * - Decodes the JSON response body.
	 * - Expects a top-level "data" list.
	 * - Expects each data item to contain an integer index and an embedding array.
	 * - Maps OpenAI usage.prompt_tokens to package usage.input_tokens.
	 *
	 * @param array $transportResult Raw response from the curl service.
	 * @param array $request Normalized internal embedding request.
	 * @return array Normalized package response fragment for VectorEmbedder finalization.
	 * @throws ProviderResponseException When the provider response is malformed or cannot be decoded.
	 */
	public function parseResponse(array $transportResult, array $request): array {
		$body = $transportResult['body'] ?? '';

		if (!\is_string($body) || $body === '') {
			throw new ProviderResponseException(\sprintf('OpenAI response body is empty for profile "%s".', $this->profileId));
		}

		try {
			$decoded = \json_decode($body, true, 512, \JSON_THROW_ON_ERROR);
		} catch (\JsonException $e) {
			throw new ProviderResponseException(
				\sprintf('Failed to decode OpenAI response JSON for profile "%s": %s', $this->profileId, $e->getMessage()),
				0,
				$e
			);
		}

		if (!\is_array($decoded)) {
			throw new ProviderResponseException(\sprintf('OpenAI response root must decode to an array for profile "%s".', $this->profileId));
		}

		if (!isset($decoded['data']) || !\is_array($decoded['data'])) {
			throw new ProviderResponseException(\sprintf('OpenAI response is missing a valid "data" array for profile "%s".', $this->profileId));
		}

		$vectors = [];

		foreach ($decoded['data'] as $i => $row) {
			if (!\is_array($row)) {
				throw new ProviderResponseException(\sprintf('OpenAI response data row at index %d must be an array.', $i));
			}

			if (!\array_key_exists('index', $row) || !\is_int($row['index'])) {
				throw new ProviderResponseException(\sprintf('OpenAI response data row at index %d is missing a valid integer "index".', $i));
			}

			if (!isset($row['embedding']) || !\is_array($row['embedding'])) {
				throw new ProviderResponseException(\sprintf('OpenAI response data row at index %d is missing a valid "embedding" array.', $i));
			}

			$vector = [];
			foreach ($row['embedding'] as $j => $value) {
				if (!\is_int($value) && !\is_float($value)) {
					throw new ProviderResponseException(\sprintf('OpenAI embedding value at data[%d].embedding[%d] must be numeric.', $i, $j));
				}

				$vector[] = (float)$value;
			}

			$vectors[] = [
				'index' => $row['index'],
				'vector' => $vector,
				'meta' => [
					'input_type' => 'text',
				],
			];
		}

		$usage = [];
		if (isset($decoded['usage']) && \is_array($decoded['usage'])) {
			$promptTokens = $decoded['usage']['prompt_tokens'] ?? null;
			$totalTokens = $decoded['usage']['total_tokens'] ?? null;

			$usage = [
				'input_tokens' => \is_int($promptTokens) ? $promptTokens : null,
				'total_tokens' => \is_int($totalTokens) ? $totalTokens : null,
			];
		} else {
			$usage = [
				'input_tokens' => null,
				'total_tokens' => null,
			];
		}

		return [
			'vectors' => $vectors,
			'usage' => $usage,
		];
	}


}
