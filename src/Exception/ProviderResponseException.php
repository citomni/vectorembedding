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
 * Provider response failure for the vectorembedding package.
 *
 * Behavior:
 * - Signals that a provider response could not be decoded or validated.
 * - Covers malformed JSON, missing required response fields, and invalid response shapes.
 */
final class ProviderResponseException extends VectorEmbeddingException {
}
