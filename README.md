# CitOmni VectorEmbedding

`citomni/vectorembedding` is a reusable CitOmni provider package for semantic embedding integrations. It offers one compact, stable internal contract for embedding-style requests and responses, so application code can work against a consistent structure rather than provider-specific JSON payloads. The package is intentionally narrow in scope: It is designed to be deterministic, explicit, and operationally useful, not a speculative "AI toolbox" bucket.

At its core, VectorEmbedding separates application intent from provider transport. The application submits a normalized request, VectorEmbedding resolves a profile, delegates provider translation to an adapter, performs the outbound HTTP call through the existing CitOmni cURL service, parses the provider response back into a common format, and returns one stable internal embedding response. This design keeps the public surface area small while preserving room for provider-specific capabilities through explicit escape hatches.

## Highlights

- **Unified embedding contract for CitOmni applications** with one compact internal request/response format across providers.
- **Profile-driven provider selection** so applications target stable profile ids rather than provider-specific payload logic.
- **Adapter-based translation layer** that keeps provider-specific JSON, headers, URLs, and response parsing out of application code.
- **Explicit support for package-level embedding options** such as dimensions and task type, translated by adapters into provider-native semantics.
- **Operationally explicit logging** with useful execution context and no parallel transport stack.
- **Reuse of the existing CitOmni cURL service** instead of introducing a second HTTP abstraction.
- **CLI integration out of the box** through `vectorembedding:embed` for diagnostics, development, and scripting.

## Why VectorEmbedding

Embedding APIs differ in endpoint structure, request shape, option semantics, authentication headers, output dimensionality handling, and response envelopes. Those differences are operationally real, but they should not leak into every controller, command, or application service. VectorEmbedding exists to absorb that variability behind a single internal request/response model and a profile-based configuration layer. Profiles select concrete adapters and models; adapters perform provider-specific translation; the application stays focused on intent.

In practical terms, this yields four advantages:

- A single internal request format across providers.
- A single normalized response format for downstream application code.
- Explicit profile-driven configuration rather than implicit provider branching.
- Provider-specific flexibility without collapsing into an unstructured catch-all abstraction.

## Design principles

VectorEmbedding follows a deliberately conservative architecture:

- **Profiles, not providers, are selected by the application.** A profile defines the adapter, model, endpoint base, API credentials, and timeout policy.
- **Adapters own translation.** They build provider URLs, payloads, headers, and response normalization.
- **The existing CitOmni cURL service remains the transport.** VectorEmbedding does not introduce its own generic transport layer.
- **Validation is structural and explicit.** The package validates request shape, known package-level option keys, and required fields. Provider-specific compatibility stays in adapters.
- **The response contract is stable.** Applications read one normalized `vectors[]` structure regardless of provider.
- **Logging is explicit.** Useful operational context is logged without inventing a second observability layer.

## Requirements

- PHP **8.2+**
- `ext-json`
- `citomni/kernel`
- `citomni/infrastructure`
- A CitOmni application with the existing cURL service available

OPcache is strongly recommended in production.

## Installation

```bash
composer require citomni/vectorembedding
composer dump-autoload -o
````

Register the package provider in `config/providers.php`:

```php
<?php
declare(strict_types=1);

return [
	\CitOmni\VectorEmbedding\Boot\Registry::class,
];
```

Once registered, the package exposes the `vectorEmbedder` service and the `vectorembedding:embed` CLI command.

## Public service

The primary entry point is the VectorEmbedding service:

```php
$this->app->vectorEmbedder->embed(array $request): array
```

The service is responsible for:

1. Validating raw input shape.
2. Normalizing the internal request.
3. Resolving the selected profile.
4. Validating structural request shape.
5. Instantiating the configured adapter.
6. Building the provider URL, payload, and headers.
7. Sending the HTTP request via `$this->app->curl->execute(...)`.
8. Parsing and normalizing the provider response.
9. Returning the common response format.

## Internal request format

VectorEmbedding uses a compact internal request model centered on `profile`, `items`, `options`, `provider_options`, and `debug`.

```php
[
	'profile' => 'openai-text-embedding-3-small',
	'items' => [
		[
			'type' => 'text',
			'text' => 'Lejelovens regler om depositum',
		],
	],
	'options' => [
		'dimensions' => null,
		'task_type' => null,
	],
	'provider_options' => [],
	'debug' => [
		'include_raw_response' => false,
		'include_built_request' => false,
	],
]
```

### Items

The request is intentionally item-based rather than text-shortcut-based. This keeps the internal contract structurally consistent and leaves room for broader modality support later.

Text is the canonical baseline in V1:

```php
[
	'type' => 'text',
	'text' => 'Lejelovens regler om depositum',
]
```

V1 is honest about what it supports: The package is multimodal-ready in contract shape, but current adapters and CLI usage are text-first.

### Options and provider-specific escape hatch

VectorEmbedding uses an explicit package-level whitelist for `options`. The currently recognized package-level keys are:

* `dimensions`
* `task_type`

Unknown option keys fail fast. This is deliberate and avoids silently swallowing typos such as `dimensons`.

Provider-specific deviations belong in `provider_options`, which acts as the sanctioned escape hatch for capabilities that do not fit the cross-provider abstraction cleanly. In V1, adapters may choose to reject unsupported `provider_options` entirely.

### Debug flags

The request may also include a small debug subtree:

* `include_raw_response`
* `include_built_request`

These control whether raw provider response data and the built provider payload are attached to the normalized response.

## Normalized response format

Adapters normalize provider responses into a common structure:

```php
[
	'profile' => 'openai-text-embedding-3-small',
	'provider' => 'openai',
	'model' => 'text-embedding-3-small',
	'vectors' => [
		[
			'index' => 0,
			'vector' => [0.123, -0.456, 0.789],
			'meta' => [
				'input_type' => 'text',
			],
		],
	],
	'usage' => [
		'input_tokens' => 10,
		'total_tokens' => 10,
	],
	'raw' => null,
	'meta' => [
		'cached' => false,
		'cache_key' => null,
		'duration_ms' => 123,
	],
]
```

This response model gives application code one stable place to read:

* the resolved profile,
* the provider name,
* the model name,
* the returned vectors,
* usage information where the provider supplies it,
* optional raw provider data,
* and operational metadata such as duration.

### Response guarantees

* `profile` is always present.
* `provider` is always present.
* `model` is always present.
* `vectors` is always an array.
* `usage` is always an array.
* `raw` is always present, defaulting to `null`.
* `meta` is always an array.

## Configuration

VectorEmbedding is configured under the `vectorembedding` node. The important concept is the **profile**: the application selects a profile, and the profile resolves to a concrete adapter and endpoint configuration.

```php
<?php
declare(strict_types=1);

return [
	'vectorembedding' => [
		'default_profile' => 'openai-text-embedding-3-small',

		'debug' => [
			'include_raw_response' => false,
			'include_built_request' => false,
		],

		'cache' => [
			'enabled' => false,
			'ttl' => 3600,
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
```

### Configuration semantics

* `default_profile` is used when the request does not provide one.
* Each profile points to an adapter class.
* Multiple profiles may reuse the same adapter.
* Each profile carries the concrete model, endpoint base, credentials, and timeout.
* General transport defaults belong to the existing cURL configuration, not to a duplicated VectorEmbedding transport subtree.

### Notes on credentials

The package baseline may define empty `api_key` values, but production or application-level configuration must provide real credentials before the profile can be used successfully.

## Provider adapters

Adapters are the translation boundary between VectorEmbedding's internal format and each provider's native API contract.

An adapter is responsible for:

* building the outbound provider URL,
* building the outbound provider payload,
* building provider-specific headers,
* decoding the provider response body,
* normalizing the result into VectorEmbedding's common response structure,
* and raising provider-appropriate exceptions on malformed or failed responses.

The adapter contract is intentionally small:

```php
interface EmbeddingAdapterInterface {
	public function buildUrl(): string;
	public function buildRequest(array $request): array;
	public function buildHeaders(array $request): array;
	public function parseResponse(array $transportResult, array $request): array;
}
```

This keeps provider logic sharply localized and prevents transport, logging, or profile selection concerns from bleeding into adapter implementations.

## Transport model

VectorEmbedding delegates HTTP execution to the existing CitOmni cURL service:

```php
$this->app->curl->execute(array $request): array
```

That service remains the transport authority. It validates transport request shape, performs the HTTP call, applies timeout and SSL behavior, and returns the raw transport result. VectorEmbedding does not replace or duplicate that layer. Instead, it builds the cURL request array, delegates execution, and lets the adapter decode and interpret the response body.

This separation is consequential:

* VectorEmbedding does **not** centralize provider JSON parsing.
* Adapters decode provider JSON where appropriate.
* Transport exceptions remain transport exceptions in origin, even when package-level context is added around request execution.
* The package stays operationally explicit without needing a parallel transport abstraction.

## Logging

VectorEmbedding logs through the existing CitOmni log service when that service is available.

Useful VectorEmbedding log context typically includes:

* profile,
* provider,
* model,
* item count,
* vector count,
* duration,
* usage information where available,
* built request payload when explicitly enabled,
* and failure details where relevant.

Suggested log categories are intentionally few and high-signal:

* `embed.ok`
* `embed.fail`

### Logging policy

The package does not attempt to build a second observability stack. It simply logs package-level execution context in a compact, explicit way. Because the underlying cURL service may also log, production installations should use a deliberate logging policy rather than accidentally generating duplicate transport noise.

## Caching

The current V1 implementation does **not** implement response caching.

A future-friendly `cache` config node may exist in the package baseline, but caching is not yet active in the shared service flow. At present:

* `meta.cached` is always `false`
* `meta.cache_key` is always `null`

This is intentional. The package keeps the service pipeline small and real first, rather than introducing persistence before there is a concrete need.

## CLI usage

VectorEmbedding exposes a command-line entry point:

```bash
php bin/citomni vectorembedding:embed "Lejelovens regler om depositum"
```

A profile may be selected explicitly:

```bash
php bin/citomni vectorembedding:embed "Lejelovens regler om depositum" --profile="openai-text-embedding-3-small"
```

Request options may be supplied from the CLI:

```bash
php bin/citomni vectorembedding:embed "Test" --dimensions=256
php bin/citomni vectorembedding:embed "Depositum ved leje" --task-type="RETRIEVAL_QUERY"
```

The full normalized response may be printed as JSON:

```bash
php bin/citomni vectorembedding:embed "Lejelovens regler om depositum" --json
```

In plain mode, the command prints the first vector and then a compact info line containing the resolved profile, provider, model, cache status, vector count, dimensions, duration, and token usage where available.

## Operational notes

### Profiles are the stable application contract

Application code should target profile ids rather than provider-specific endpoints or model payload formats directly. This keeps provider translation localized to adapters and configuration.

### The response contract is vector-first from day one

The package returns `vectors[]`, even when a request often yields only one vector. This avoids a future breaking change from `vector` to `vectors`.

### Transport remains delegated

VectorEmbedding does not replace the CitOmni cURL service. Transport validation, HTTP execution, timeout handling, and low-level request mechanics remain delegated to the existing infrastructure layer.

### Validation is intentionally split

* The **service** validates what can be known generically: request shape, item structure, option keys, profile resolution.
* The **adapter** validates what depends on provider knowledge: unsupported modalities, provider-specific option semantics, and response shape.

### Logging should be explicit, not noisy

VectorEmbedding logs package-level success and failure context. Since the underlying cURL service may also log, production installations should choose a deliberate logging policy rather than accidentally doubling transport detail.

## Package structure

The package is organized around a small set of clear responsibilities:

```text
src/
  Adapter/
  Boot/
  Command/
  Exception/
  Interface/
  Service/
```

A representative structure includes:

* `Boot/Registry.php` for service/config/CLI registration,
* `Service/VectorEmbedder.php` as the primary orchestration service,
* `Interface/EmbeddingAdapterInterface.php` for adapter contracts,
* `Adapter/...` for concrete provider adapters,
* `Command/EmbedCommand.php` for CLI usage,
* `Exception/...` for package-specific exceptions.

## Performance notes

* Services are resolved through explicit service maps rather than scanning.
* Provider translation is localized to small adapters with a narrow contract.
* The package reuses existing infrastructure services rather than layering a second transport stack on top.
* Production should use optimized Composer autoloading.
* OPcache should be enabled in production.

Composer example:

```json
{
	"config": {
		"optimize-autoloader": true,
		"classmap-authoritative": true,
		"apcu-autoloader": true
	}
}
```

Then run:

```bash
composer dump-autoload -o
```

## Error model

VectorEmbedding defines its own domain exception family for request, configuration, adapter, provider-request, and provider-response failures.

The main exception types are:

* `VectorEmbeddingException`
* `VectorEmbeddingConfigException`
* `AdapterNotFoundException`
* `InvalidRequestException`
* `ProviderRequestException`
* `ProviderResponseException`

This keeps the fault surface small, explicit, and package-local.

## Error handling philosophy

Fail fast.

VectorEmbedding does not hide malformed requests, profile misconfiguration, unsupported item types, malformed provider responses, or transport preparation failures behind vague fallback behavior. Invalid structure should fail as invalid structure; config problems should fail as config problems; provider response problems should fail as provider response problems.

That bias is deliberate. In integration code, silent fallback logic often looks convenient right up until it becomes the reason nobody can tell what actually happened.

## Example

```php
<?php
declare(strict_types=1);

$response = $this->app->vectorEmbedder->embed([
	'profile' => 'openai-text-embedding-3-small',
	'items' => [
		[
			'type' => 'text',
			'text' => 'Lejelovens regler om depositum',
		],
	],
	'options' => [
		'dimensions' => 256,
	],
]);

$vector = $response['vectors'][0]['vector'] ?? [];
```

## Position within CitOmni

VectorEmbedding follows the broader CitOmni philosophy: explicit contracts, deterministic behavior, small public surfaces, and low overhead. It does one thing narrowly but well: it gives CitOmni applications a disciplined and reusable way to generate semantic embeddings without forcing application code to speak every provider's dialect.

---

## Contributing

* PHP 8.2+
* PSR-4
* Tabs for indentation
* K&R brace style
* Keep ownership boundaries sharp
* Keep transport in services/adapters where it belongs
* Do not introduce magic or hidden fallback behavior without an explicit and documented reason

---

## Coding & Documentation Conventions

All CitOmni projects follow the shared conventions documented here:
[CitOmni Coding & Documentation Conventions](https://github.com/citomni/docs/blob/main/contribute/CONVENTIONS.md)

---

## License

**CitOmni VectorEmbedding** is open-source under the **MIT License**.
See [LICENSE](LICENSE).

**Trademark notice:** "CitOmni" and the CitOmni logo are trademarks of **Lars Grove Mortensen**. Usage of the name or logo must follow the policy in [NOTICE](NOTICE). Do not imply endorsement or affiliation without prior written permission.

---

## Trademarks

"CitOmni" and the CitOmni logo are trademarks of **Lars Grove Mortensen**.
You may make factual references to "CitOmni", but do not modify the marks, create confusingly similar logos, or imply sponsorship, endorsement, or affiliation without prior written permission.
Do not register or use "citomni" (or confusingly similar terms) in company names, domains, social handles, or top-level vendor/package names.
For details, see [NOTICE](NOTICE).

---

## Author

Developed by Lars Grove Mortensen © 2012-present.

---

CitOmni - low overhead, high performance, ready for anything.