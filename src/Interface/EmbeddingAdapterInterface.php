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

namespace CitOmni\VectorEmbedding\Interface;

/**
 * Contract for provider-specific embedding adapters.
 *
 * Adapters are responsible for:
 * - building the provider endpoint URL
 * - building the provider-specific request payload
 * - building the provider-specific headers
 * - parsing the provider response into the package response fragment
 *
 * Adapters are not responsible for:
 * - transport execution
 * - common logging
 * - profile resolution
 * - top-level request normalization
 * - package-level input validation
 * - cache policy
 *
 * Notes:
 * - Adapters receive profile config through their constructor.
 * - Method parameters are reserved for per-call data only.
 */
interface EmbeddingAdapterInterface {

	/**
	 * Build the provider-specific endpoint URL.
	 *
	 * @return string Provider endpoint URL.
	 */
	public function buildUrl(): string;

	/**
	 * Build the provider-specific request payload.
	 *
	 * @param array $request Normalized internal embedding request.
	 * @return array Provider request payload as an associative array.
	 */
	public function buildRequest(array $request): array;

	/**
	 * Build the provider-specific headers.
	 *
	 * @param array $request Normalized internal embedding request.
	 * @return array<int, string> HTTP header lines.
	 */
	public function buildHeaders(array $request): array;

	/**
	 * Parse the transport result into the package response fragment.
	 *
	 * The returned array is expected to contain provider-normalized data
	 * that the shared service can finalize into the full package response.
	 *
	 * @param array $transportResult Raw response from the curl service.
	 * @param array $request Normalized internal embedding request.
	 * @return array Normalized package response fragment.
	 */
	public function parseResponse(array $transportResult, array $request): array;
}
