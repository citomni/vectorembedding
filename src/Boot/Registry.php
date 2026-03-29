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
 * Declare this provider package's boot contributions.
 *
 * Behavior:
 * - Registers HTTP and CLI service bindings for the vector embedding service.
 * - Registers HTTP and CLI cfg overlays for vectorembedding.
 *
 * Notes:
 * - This package does not register HTTP routes.
 * - This package does not register CLI commands in V1.
 * - HTTP and CLI reuse the same service map and cfg overlay.
 */
final class Registry {

	/**
	 * HTTP service map.
	 *
	 * @var array<string, string|array<string, mixed>>
	 */
	public const MAP_HTTP = [
		'vectorEmbedder' => \CitOmni\VectorEmbedding\Service\VectorEmbedder::class,
	];

	/**
	 * HTTP cfg overlay.
	 *
	 * @var array<string, mixed>
	 */
	public const CFG_HTTP = [
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
	public const ROUTES_HTTP = [
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
	 * CLI service map.
	 *
	 * @var array<string, string|array<string, mixed>>
	 */
	public const MAP_CLI = self::MAP_HTTP;

	/**
	 * CLI cfg overlay.
	 *
	 * @var array<string, mixed>
	 */
	public const CFG_CLI = self::CFG_HTTP;

	/**
	 * CLI commands.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	public const COMMANDS_CLI = [
		'vectorembedding:embed' => [
			'command' => \CitOmni\VectorEmbedding\Command\EmbedCommand::class,
			'description' => 'Generate embedding vectors for input text',
		],
	];
}
