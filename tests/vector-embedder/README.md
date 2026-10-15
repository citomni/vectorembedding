# VectorEmbedder suite

Standalone checks for `\CitOmni\VectorEmbedding\Service\VectorEmbedder::embed()` on the shipped cfg baseline. No Composer autoloader, no network, no files written.

```
php tests/vector-embedder/run.php
```

Expected: `5 passed, 0 failed`

`PASS` lines go to stdout, `FAIL` lines to stderr. The last line holds the totals, and the exit code is 1 when a check fails.

## How it works

The service runs unchanged on the doubles in `doubles.php`: `\CitOmni\Kernel\Cfg` and `\CitOmni\Kernel\Service\BaseService` are aliased to doubles, and the app double carries a curl double and a log double. Every case starts from `Registry::CFG_COMMON` with an `api_key` in each profile. The curl double records each request and answers it with HTTP 200 and a fixed OpenAI embeddings response, so the real `OpenAiEmbeddingAdapter` builds the request and parses the response.

## Checks

R marks a regression check: it fails against the service as it was before the fix it guards. The other checks pin existing behavior.

| | Check | Guards |
|---|---|---|
| | The shipped baseline embeds with default_profile and attaches no debug data | `default_profile`, profile URL and timeouts, debug off by default |
| R | Debug flags in cfg apply when the request sets none | `vectorembedding.debug`, which a cast of the Cfg node to array used to ignore |
| | Debug flags in the request win over cfg | Request-level `debug` |
| | An explicit profile wins over default_profile | Request-level `profile` |
| | An unknown profile fails with VectorEmbeddingConfigException before any request | Profile lookup |

`php tests/run.php` runs every suite in the package.
