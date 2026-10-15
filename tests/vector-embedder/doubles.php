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

/*
 * Kernel and service doubles for the VectorEmbedder suite. VectorEmbedder extends
 * \CitOmni\Kernel\Service\BaseService and reads cfg through \CitOmni\Kernel\Cfg; it
 * sends requests through the curl service and logs through the log service.
 */

/**
 * Stand-in for \CitOmni\Kernel\Cfg with the kernel's read semantics over a plain array.
 */
final class CfgDouble {

	/**
	 * Wrap one cfg node.
	 *
	 * @param  array<string,mixed>  $data  Node data.
	 */
	public function __construct(private readonly array $data) {
	}

	/**
	 * Read one key of this node.
	 *
	 * @param  string  $key
	 * @return mixed  A nested node for an empty or associative array, otherwise the value; a list stays an array.
	 * @throws \OutOfBoundsException  When the key does not exist at this node.
	 */
	public function __get(string $key): mixed {
		if (!\array_key_exists($key, $this->data)) {
			throw new \OutOfBoundsException("Unknown cfg key: '{$key}'");
		}
		$value = $this->data[$key];
		return \is_array($value) && ($value === [] || !\array_is_list($value)) ? new self($value) : $value;
	}

	/**
	 * Check whether the key exists at this node.
	 *
	 * @param  string  $key
	 * @return bool  True when the key exists, also when its value is null.
	 */
	public function __isset(string $key): bool {
		return \array_key_exists($key, $this->data);
	}

	/**
	 * Return the node's data as a plain array.
	 *
	 * @return array<string,mixed>
	 */
	public function toArray(): array {
		return $this->data;
	}
}


/**
 * Stand-in for \CitOmni\Kernel\Service\BaseService: stores app and options, then calls init().
 */
abstract class BaseServiceDouble {

	/** The app double. */
	protected object $app;

	/** Service options. */
	protected array $options;

	/**
	 * Store the app and options, then run init().
	 *
	 * @param  object  $app
	 * @param  array<string,mixed>  $options
	 */
	public function __construct(object $app, array $options = []) {
		$this->app = $app;
		$this->options = $options;
		$this->init();
	}

	/**
	 * Initialization hook.
	 *
	 * @return void
	 */
	protected function init(): void {
	}
}


/**
 * App double: cfg plus the curl and log doubles.
 */
final class AppDouble {

	/** The cfg double. */
	public readonly CfgDouble $cfg;

	/** The curl double. */
	public readonly CurlDouble $curl;

	/** The log double. */
	public readonly LogDouble $log;

	/**
	 * Wrap the cfg and create the service doubles.
	 *
	 * @param  array<string,mixed>  $cfg  Complete cfg.
	 * @param  string  $body  Response body that the curl double returns.
	 */
	public function __construct(array $cfg, string $body) {
		$this->cfg = new CfgDouble($cfg);
		$this->curl = new CurlDouble($body);
		$this->log = new LogDouble();
	}

	/**
	 * Report whether a service is registered; curl and log are.
	 *
	 * @param  string  $id
	 * @return bool
	 */
	public function hasService(string $id): bool {
		return $id === 'curl' || $id === 'log';
	}
}


/**
 * Curl double: records each request and answers with HTTP 200 and a fixed body.
 */
final class CurlDouble {

	/** @var list<array<string,mixed>> Requests in the order they were executed. */
	public array $requests = [];

	/**
	 * Keep the response body.
	 *
	 * @param  string  $body
	 */
	public function __construct(private readonly string $body) {
	}

	/**
	 * Record the request and return a successful transport result.
	 *
	 * @param  array<string,mixed>  $request
	 * @return array<string,mixed>
	 */
	public function execute(array $request): array {
		$this->requests[] = $request;
		return ['is_http_success' => true, 'status_code' => 200, 'headers' => [], 'body' => $this->body];
	}
}


/**
 * Log double: records each write.
 */
final class LogDouble {

	/** @var list<array{file: string, category: string}> Writes in order; a null file is recorded as ''. */
	public array $writes = [];

	/**
	 * Record a write.
	 *
	 * @param  ?string  $file
	 * @param  string  $category
	 * @param  string|array<mixed>|object  $message
	 * @param  array<string,mixed>  $context
	 * @return void
	 */
	public function write(?string $file, string $category, string|array|object $message, array $context = []): void {
		$this->writes[] = ['file' => (string)$file, 'category' => $category];
	}
}


\class_alias(CfgDouble::class, 'CitOmni\Kernel\Cfg');
\class_alias(BaseServiceDouble::class, 'CitOmni\Kernel\Service\BaseService');
