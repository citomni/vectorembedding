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

namespace CitOmni\VectorEmbedding\Tests\VectorEmbedder;

use CitOmni\VectorEmbedding\Boot\Registry;
use CitOmni\VectorEmbedding\Exception\VectorEmbeddingConfigException;
use CitOmni\VectorEmbedding\Service\VectorEmbedder;

/*
 * Isolated suite for VectorEmbedder::embed() on the cfg baseline: profile selection,
 * the debug flags and the request that reaches the curl service.
 *
 * VectorEmbedder runs unchanged on the doubles in doubles.php. The curl double answers
 * every request with a fixed OpenAI embeddings response, so nothing leaves the process.
 *
 * Usage:
 *   php tests/vector-embedder/run.php
 */

if (\PHP_SAPI !== 'cli') {
	throw new \RuntimeException('CLI only.');
}

\set_error_handler(static function (int $errno, string $errstr, string $errfile, int $errline): bool {
	throw new \ErrorException($errstr, 0, $errno, $errfile, $errline);
});

require __DIR__ . '/doubles.php';
\spl_autoload_register(static function (string $class): void {
	$prefix = 'CitOmni\\VectorEmbedding\\';
	if (\str_starts_with($class, $prefix)) {
		$file = \dirname(__DIR__, 2) . '/src/' . \str_replace('\\', '/', \substr($class, \strlen($prefix))) . '.php';
		if (\is_file($file)) {
			require $file;
		}
	}
});

/** An OpenAI embeddings response with one two-dimensional vector. */
const BODY = '{"object":"list","data":[{"object":"embedding","index":0,"embedding":[0.25,-0.5]}],"model":"text-embedding-3-small","usage":{"prompt_tokens":2,"total_tokens":2}}';

/** One text item. */
const ITEMS = [['type' => 'text', 'text' => 'hello']];

$passed = 0;
$failed = 0;

/**
 * Run one check and report it.
 *
 * @param  string  $name
 * @param  callable  $test  Throws to fail.
 * @return void
 */
function check(string $name, callable $test): void {
	global $passed, $failed;
	try {
		$test();
		$passed++;
		\fwrite(\STDOUT, "PASS {$name}\n");
	} catch (\Throwable $error) {
		$failed++;
		\fwrite(\STDERR, "FAIL {$name} - " . $error::class . ': ' . $error->getMessage() . "\n");
	}
}

/**
 * Fail the check unless the condition holds.
 *
 * @param  bool  $condition
 * @param  string  $message
 * @return void
 * @throws \RuntimeException  When the condition is false.
 */
function ok(bool $condition, string $message): void {
	if (!$condition) {
		throw new \RuntimeException($message);
	}
}

/**
 * The shipped cfg with api keys set, with $vectorembedding replacing keys of the vectorembedding node.
 *
 * @param  array<string,mixed>  $vectorembedding
 * @return array<string,mixed>
 */
function cfg(array $vectorembedding = []): array {
	$cfg = Registry::CFG_COMMON;
	foreach ($cfg['vectorembedding']['profiles'] as $id => $profile) {
		$cfg['vectorembedding']['profiles'][$id]['api_key'] = 'test-key';
	}
	$cfg['vectorembedding'] = \array_replace($cfg['vectorembedding'], $vectorembedding);
	return $cfg;
}

/**
 * Embed ITEMS on a fresh app and return the app and the result.
 *
 * @param  array<string,mixed>  $cfg
 * @param  array<string,mixed>  $input  Further request keys.
 * @return array{0: AppDouble, 1: array<string,mixed>}
 */
function embed(array $cfg, array $input = []): array {
	$app = new AppDouble($cfg, BODY);
	return [$app, (new VectorEmbedder($app))->embed($input + ['items' => ITEMS])];
}

check('The shipped baseline embeds with default_profile and attaches no debug data', function (): void {
	[$app, $result] = embed(cfg());
	ok($result['profile'] === 'openai-text-embedding-3-small', 'profile: ' . $result['profile']);
	ok(($result['vectors'][0]['vector'] ?? null) === [0.25, -0.5], 'vectors: ' . \json_encode($result['vectors']));
	ok($result['raw'] === null, 'raw is set');
	ok(!\array_key_exists('built_request', $result['meta']), 'meta.built_request is set');
	ok(\count($app->curl->requests) === 1 && $app->curl->requests[0]['url'] === 'https://api.openai.com/v1/embeddings', 'request url: ' . ($app->curl->requests[0]['url'] ?? '-'));
	ok($app->curl->requests[0]['timeout'] === 60 && $app->curl->requests[0]['connect_timeout'] === 10, 'profile timeouts not applied');
});

check('Debug flags in cfg apply when the request sets none', function (): void {
	[, $result] = embed(cfg(['debug' => ['include_raw_response' => true, 'include_built_request' => true]]));
	ok($result['raw'] === BODY, 'raw: ' . \var_export($result['raw'], true));
	ok(\is_array($result['meta']['built_request'] ?? null), 'meta.built_request is missing');
});

check('Debug flags in the request win over cfg', function (): void {
	[, $result] = embed(cfg(['debug' => ['include_raw_response' => true, 'include_built_request' => true]]),
		['debug' => ['include_raw_response' => false, 'include_built_request' => false]]);
	ok($result['raw'] === null, 'raw is set');
	ok(!\array_key_exists('built_request', $result['meta']), 'meta.built_request is set');

	[, $result] = embed(cfg(), ['debug' => ['include_raw_response' => true]]);
	ok($result['raw'] === BODY, 'raw from the request flag is missing');
});

check('An explicit profile wins over default_profile', function (): void {
	[$app, $result] = embed(cfg(), ['profile' => 'openai-text-embedding-3-large']);
	ok($result['profile'] === 'openai-text-embedding-3-large' && $result['model'] === 'text-embedding-3-large', 'profile/model: ' . $result['profile'] . '/' . $result['model']);
});

check('An unknown profile fails with VectorEmbeddingConfigException before any request', function (): void {
	$app = new AppDouble(cfg(), BODY);
	try {
		(new VectorEmbedder($app))->embed(['profile' => 'nope', 'items' => ITEMS]);
		ok(false, 'nothing was thrown');
	} catch (VectorEmbeddingConfigException) {
	}
	ok($app->curl->requests === [], 'a request was sent');
});

\fwrite(\STDOUT, "{$passed} passed, {$failed} failed\n");
exit($failed === 0 ? 0 : 1);
