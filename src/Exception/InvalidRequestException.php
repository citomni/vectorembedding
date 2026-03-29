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
 * Invalid caller input for the vectorembedding package.
 *
 * Behavior:
 * - Signals structurally invalid request input.
 * - Covers invalid item shapes, unknown package-level options, and similar caller errors.
 */
final class InvalidRequestException extends VectorEmbeddingException {
}
