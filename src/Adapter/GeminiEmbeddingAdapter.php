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
 * Gemini embeddings adapter.
 *
 * Translates the package's normalized internal embedding request into the
 * Gemini embeddings API request shape and parses the provider response back
 * into the package's stable internal response format.
 *
 * Behavior:
 * - Builds the Gemini embedContent endpoint URL from profile config.
 * - Accepts text items only in V1.
 * - Maps internal text items to Gemini content.parts[].text.
 * - Passes through supported package-level options.
 * - Parses Gemini embeddings[].values into the package vectors[] list.
 *
 * Notes:
 * - This adapter currently targets text embedding through embedContent.
 * - Provider credentials must live in headers, never in payloads.
 * - Gemini supports multimodal embedding models, but V1 keeps adapter behavior
 *   aligned with the current service/request contract focused on text items.
 */
final class GeminiEmbeddingAdapter implements EmbeddingAdapterInterface {

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
	 * @throws InvalidRequestException When the configured model is missing.
	 */
	public function buildUrl(): string {
		$model = (string)($this->profileConfig['model'] ?? '');
		if ($model === '') {
			throw new InvalidRequestException(\sprintf('Gemini adapter for profile "%s" requires a non-empty model in profile config.', $this->profileId));
		}

		return \rtrim((string)$this->profileConfig['base_url'], '/') . '/models/' . $model . ':embedContent';
	}


	/**
	 * Build the provider-specific request payload.
	 *
	 * Behavior:
	 * - Accepts only text items in V1.
	 * - Maps internal items[] to Gemini content.parts[].
	 * - Sends model as "models/{model}".
	 * - Includes taskType when explicitly provided.
	 * - Includes output_dimensionality when explicitly provided.
	 * - Rejects unsupported provider_options in V1.
	 *
	 * @param array $request Normalized internal embedding request.
	 * @return array Provider request payload as an associative array.
	 * @throws InvalidRequestException When the request is incompatible with the Gemini adapter.
	 */
	public function buildRequest(array $request): array {
		$model = (string)($this->profileConfig['model'] ?? '');
		if ($model === '') {
			throw new InvalidRequestException(\sprintf('Gemini adapter for profile "%s" requires a non-empty model in profile config.', $this->profileId));
		}

		$providerOptions = $request['provider_options'] ?? [];
		if ($providerOptions !== []) {
			$unknownKeys = \implode(', ', \array_keys($providerOptions));
			throw new InvalidRequestException(\sprintf('Gemini adapter does not support provider_options in V1. Unsupported key(s): %s', $unknownKeys));
		}

		$parts = [];

		foreach ($request['items'] as $i => $item) {
			$type = (string)($item['type'] ?? '');

			if ($type !== 'text') {
				throw new InvalidRequestException(\sprintf('Gemini adapter supports only text items in V1. Item at index %d has unsupported type "%s".', $i, $type));
			}

			$text = (string)($item['text'] ?? '');
			if ($text === '') {
				throw new InvalidRequestException(\sprintf('Gemini text item at index %d must contain a non-empty string "text".', $i));
			}

			$parts[] = [
				'text' => $text,
			];
		}

		if ($parts === []) {
			throw new InvalidRequestException('Gemini adapter requires at least one text item.');
		}

		$payload = [
			'model' => 'models/' . $model,
			'content' => [
				'parts' => $parts,
			],
		];

		$options = $request['options'] ?? [];

		if (($options['task_type'] ?? null) !== null) {
			$taskType = $options['task_type'];

			if (!\is_string($taskType) || $taskType === '') {
				throw new InvalidRequestException('Gemini option "task_type" must be a non-empty string when provided.');
			}

			$payload['taskType'] = $taskType;
		}

		if (($options['dimensions'] ?? null) !== null) {
			$dimensions = $options['dimensions'];

			if (!\is_int($dimensions)) {
				throw new InvalidRequestException('Gemini option "dimensions" must be an integer when provided.');
			}

			if ($dimensions <= 0) {
				throw new InvalidRequestException('Gemini option "dimensions" must be greater than zero.');
			}

			$payload['output_dimensionality'] = $dimensions;
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
			'x-goog-api-key: ' . $this->profileConfig['api_key'],
		];
	}


	/**
	 * Parse the transport result into the package response format.
	 *
	 * Behavior:
	 * - Decodes the JSON response body.
	 * - Accepts Gemini embeddings returned either as a list or as a single object.
	 * - Expects each embedding entry to contain a values array.
	 * - Maps embeddings[].values into the package vectors[] list.
	 *
	 * @param array $transportResult Raw response from the curl service.
	 * @param array $request Normalized internal embedding request.
	 * @return array Normalized package response fragment for VectorEmbedder finalization.
	 * @throws ProviderResponseException When the provider response is malformed or cannot be decoded.
	 */
	public function parseResponse(array $transportResult, array $request): array {
		$body = $transportResult['body'] ?? '';

		if (!\is_string($body) || $body === '') {
			throw new ProviderResponseException(\sprintf('Gemini response body is empty for profile "%s".', $this->profileId));
		}

		try {
			$decoded = \json_decode($body, true, 512, \JSON_THROW_ON_ERROR);
		} catch (\JsonException $e) {
			throw new ProviderResponseException(
				\sprintf('Failed to decode Gemini response JSON for profile "%s": %s', $this->profileId, $e->getMessage()),
				0,
				$e
			);
		}

		if (!\is_array($decoded)) {
			throw new ProviderResponseException(\sprintf('Gemini response root must decode to an array for profile "%s".', $this->profileId));
		}

		$embeddingsNode = $decoded['embeddings'] ?? null;

		if ($embeddingsNode === null) {
			throw new ProviderResponseException(\sprintf('Gemini response is missing "embeddings" for profile "%s".', $this->profileId));
		}

		$embeddingRows = $this->normalizeEmbeddingRows($embeddingsNode);

		if ($embeddingRows === []) {
			throw new ProviderResponseException(\sprintf('Gemini response contains no embeddings for profile "%s".', $this->profileId));
		}

		$vectors = [];

		foreach ($embeddingRows as $i => $row) {
			if (!\is_array($row)) {
				throw new ProviderResponseException(\sprintf('Gemini embedding row at index %d must be an array.', $i));
			}

			if (!isset($row['values']) || !\is_array($row['values'])) {
				throw new ProviderResponseException(\sprintf('Gemini embedding row at index %d is missing a valid "values" array.', $i));
			}

			$vector = [];
			foreach ($row['values'] as $j => $value) {
				if (!\is_int($value) && !\is_float($value)) {
					throw new ProviderResponseException(\sprintf('Gemini embedding value at embeddings[%d].values[%d] must be numeric.', $i, $j));
				}

				$vector[] = (float)$value;
			}

			$vectors[] = [
				'index' => $i,
				'vector' => $vector,
				'meta' => [
					'input_type' => 'text',
				],
			];
		}

		return [
			'vectors' => $vectors,
			'usage' => [
				'input_tokens' => null,
				'total_tokens' => null,
			],
		];
	}


	/**
	 * Normalize Gemini embeddings payload into a list of embedding rows.
	 *
	 * Behavior:
	 * - Accepts either:
	 *   - embeddings: [{ values: [...] }, ...]
	 *   - embeddings: { values: [...] }
	 *
	 * @param mixed $embeddingsNode Raw embeddings node from the decoded response.
	 * @return array<int, array<string, mixed>> Normalized list of embedding rows.
	 * @throws ProviderResponseException When the embeddings node shape is invalid.
	 */
	private function normalizeEmbeddingRows(mixed $embeddingsNode): array {
		if (!\is_array($embeddingsNode)) {
			throw new ProviderResponseException(\sprintf('Gemini "embeddings" must be an array or object-like array for profile "%s".', $this->profileId));
		}

		if (isset($embeddingsNode['values']) && \is_array($embeddingsNode['values'])) {
			return [$embeddingsNode];
		}

		if (\array_is_list($embeddingsNode)) {
			return $embeddingsNode;
		}

		throw new ProviderResponseException(\sprintf('Gemini "embeddings" has an unsupported shape for profile "%s".', $this->profileId));
	}

}
