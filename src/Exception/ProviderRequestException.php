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

namespace CitOmni\VectorEmbedding\Exception;

/**
 * Provider request failure for the vectorembedding package.
 *
 * Behavior:
 * - Signals failures while building or sending a provider request.
 * - Covers transport failures, invalid header construction, timeouts, and HTTP-level request failures.
 */
final class ProviderRequestException extends VectorEmbeddingException {
}
