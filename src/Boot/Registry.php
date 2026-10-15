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

namespace CitOmni\VectorEmbedding\Boot;

/**
 * Registry: Boot contributions of citomni/vectorembedding.
 *
 * Declares what the package adds to a host app as constant arrays. Merging them with the host
 * app's own maps, and deciding what wins, belongs to the kernel.
 *
 * Rules:
 * - Constants only: No IO, no runtime branching and no environment reads.
 * - Services and cfg that work in both modes go in MAP_COMMON and CFG_COMMON. A mode-specific
 *   constant holds only values that depend on one runtime.
 * - Ship empty API keys. Real keys belong in the host app's cfg.
 */
final class Registry {

	/**
	 * Services for HTTP and CLI.
	 *
	 * @var array<string, string|array<string, mixed>>
	 */
	public const array MAP_COMMON = [
		'vectorEmbedder' => \CitOmni\VectorEmbedding\Service\VectorEmbedder::class,
	];

	/**
	 * Cfg baseline for HTTP and CLI.
	 *
	 * @var array<string, mixed>
	 */
	public const array CFG_COMMON = [
		'vectorembedding' => [
			'default_profile' => 'openai-text-embedding-3-small',
			// 'cache' => [
				// 'enabled' => false,
				// 'ttl' => 3600,
			// ],
			'debug' => [
				'include_raw_response' => false,
				'include_built_request' => false,
			],
			'profiles' => [
				'openai-text-embedding-3-small' => [
					'adapter' => \CitOmni\VectorEmbedding\Adapter\OpenAiEmbeddingAdapter::class,
					'provider' => 'openai',
					'model' => 'text-embedding-3-small',
					'base_url' => 'https://api.openai.com/v1',
					'api_key' => '',
					'timeout' => 60,
					'connect_timeout' => 10,
				],
				'openai-text-embedding-3-large' => [
					'adapter' => \CitOmni\VectorEmbedding\Adapter\OpenAiEmbeddingAdapter::class,
					'provider' => 'openai',
					'model' => 'text-embedding-3-large',
					'base_url' => 'https://api.openai.com/v1',
					'api_key' => '',
					'timeout' => 60,
					'connect_timeout' => 10,
				],
				'gemini-embedding-001' => [
					'adapter' => \CitOmni\VectorEmbedding\Adapter\GeminiEmbeddingAdapter::class,
					'provider' => 'google',
					'model' => 'gemini-embedding-001',
					'base_url' => 'https://generativelanguage.googleapis.com/v1beta',
					'api_key' => '',
					'timeout' => 60,
					'connect_timeout' => 10,
				],
			],
		],
	];

	/**
	 * HTTP routes.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	public const array ROUTES_HTTP = [
		// '/hello' => [
			// 'controller' => \CitOmni\ProviderSkeleton\Controller\HelloController::class,
			// 'action'     => 'index',
			// 'methods'    => ['GET'],
			// 'options'    => [
				// 'who' => 'world',
			// ],
					
		// ],
	];

	/**
	 * CLI commands.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	public const array COMMANDS_CLI = [
		'vectorembedding:embed' => [
			'command' => \CitOmni\VectorEmbedding\Command\EmbedCommand::class,
			'description' => 'Generate embedding vectors for input text',
		],
	];
}
