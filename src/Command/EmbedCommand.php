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

namespace CitOmni\VectorEmbedding\Command;

use CitOmni\Kernel\Command\BaseCommand;

final class EmbedCommand extends BaseCommand {

	protected function signature(): array {
		return [
			'arguments' => [
				'text' => [
					'description' => 'Text to embed',
					'required' => true,
				],
			],
			'options' => [
				'profile' => [
					'short' => 'p',
					'type' => 'string',
					'description' => 'Vector embedding profile id',
					'default' => '',
				],
				'dimensions' => [
					'short' => 'd',
					'type' => 'int',
					'description' => 'Optional embedding dimensions override',
					'default' => 0,
				],
				'task-type' => [
					'short' => 't',
					'type' => 'string',
					'description' => 'Optional package-level task_type',
					'default' => '',
				],
				'raw' => [
					'short' => 'r',
					'type' => 'bool',
					'description' => 'Include raw provider response',
					'default' => false,
				],
				'built-request' => [
					'short' => 'b',
					'type' => 'bool',
					'description' => 'Include built provider request in meta',
					'default' => false,
				],
				'json' => [
					'short' => 'j',
					'type' => 'bool',
					'description' => 'Output the full normalized response as JSON',
					'default' => false,
				],
			],
		];
	}


	protected function execute(): int {
		$text = $this->argString('text');
		$profile = $this->getString('profile');
		$dimensions = $this->getInt('dimensions');
		$taskType = $this->getString('task-type');
		$json = $this->getBool('json');
		$raw = $this->getBool('raw');
		$builtRequest = $this->getBool('built-request');

		if ($dimensions < 0) {
			$this->error("--dimensions must be >= 0, got {$dimensions}.");
			return self::FAILURE;
		}

		$request = [
			'items' => [
				[
					'type' => 'text',
					'text' => $text,
				],
			],
			'options' => [],
			'provider_options' => [],
			'debug' => [
				'include_raw_response' => $raw,
				'include_built_request' => $builtRequest,
			],
		];

		if ($profile !== '') {
			$request['profile'] = $profile;
		}

		if ($dimensions > 0) {
			$request['options']['dimensions'] = $dimensions;
		}

		if ($taskType !== '') {
			$request['options']['task_type'] = $taskType;
		}

		try {
			$response = $this->app->vectorEmbedder->embed($request);
		} catch (\Throwable $e) {
			$this->error($e->getMessage());
			return self::FAILURE;
		}

		if ($json) {
			return $this->renderJson($response, 'Failed to encode response as JSON: ');
		}

		$vector = $this->extractFirstVector($response);

		if ($vector === null) {
			$this->warning('No vector was found in the normalized response.');
			return $this->renderJson($response, 'Failed to encode fallback response as JSON: ');
		}

		try {
			$this->stdout(\json_encode(
				$vector,
				\JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR
			));
		} catch (\JsonException $e) {
			$this->error('Failed to encode vector as JSON: ' . $e->getMessage());
			return self::FAILURE;
		}

		$resolvedProfile = (string)($response['profile'] ?? ($profile !== '' ? $profile : ''));
		$provider = (string)($response['provider'] ?? '');
		$model = (string)($response['model'] ?? '');
		$cached = (bool)($response['meta']['cached'] ?? false);
		$durationMs = (int)($response['meta']['duration_ms'] ?? 0);
		$vectorCount = \is_array($response['vectors'] ?? null) ? \count($response['vectors']) : 0;
		$vectorDimensions = \count($vector);
		$inputTokens = $response['usage']['input_tokens'] ?? null;
		$totalTokens = $response['usage']['total_tokens'] ?? null;

		$info = 'profile=' . $resolvedProfile
			. ' provider=' . $provider
			. ' model=' . $model
			. ' cached=' . ($cached ? 'true' : 'false')
			. ' vectors=' . $vectorCount
			. ' dimensions=' . $vectorDimensions
			. ' duration_ms=' . $durationMs;

		if (\is_int($inputTokens)) {
			$info .= ' input_tokens=' . $inputTokens;
		}

		if (\is_int($totalTokens)) {
			$info .= ' total_tokens=' . $totalTokens;
		}

		$this->info($info);

		return self::SUCCESS;
	}


	/**
	 * Render a response array as pretty-printed JSON.
	 *
	 * @param array $response Normalized response array.
	 * @param string $errorPrefix Prefix for JSON encoding failures.
	 * @return int Exit code.
	 */
	private function renderJson(array $response, string $errorPrefix): int {
		try {
			$this->stdout(\json_encode(
				$response,
				\JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES | \JSON_PRETTY_PRINT | \JSON_THROW_ON_ERROR
			));
		} catch (\JsonException $e) {
			$this->error($errorPrefix . $e->getMessage());
			return self::FAILURE;
		}

		return self::SUCCESS;
	}


	/**
	 * Extract the first vector from the normalized response.
	 *
	 * @param array $response Normalized package response.
	 * @return array<int, float>|null The first vector, or null when unavailable.
	 */
	private function extractFirstVector(array $response): ?array {
		$vectors = $response['vectors'] ?? null;
		if (!\is_array($vectors) || $vectors === []) {
			return null;
		}

		$first = $vectors[0] ?? null;
		if (!\is_array($first)) {
			return null;
		}

		$vector = $first['vector'] ?? null;
		if (!\is_array($vector) || $vector === []) {
			return null;
		}

		$out = [];

		foreach ($vector as $i => $value) {
			if (!\is_int($value) && !\is_float($value)) {
				return null;
			}

			$out[$i] = (float)$value;
		}

		return $out;
	}


}
