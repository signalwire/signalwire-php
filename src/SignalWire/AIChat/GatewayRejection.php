<?php

declare(strict_types=1);

/*
 * Copyright (c) 2026 SignalWire
 *
 * Licensed under the MIT License.
 * See LICENSE file in the project root for full license information.
 */

namespace SignalWire\AIChat;

/**
 * A request the gateway refused, with the status the browser should see.
 *
 * Deliberately coarse: the browser is told *that* it was refused and, at most,
 * which of a handful of buckets it fell into. Anything finer would let a
 * caller map out the caps and the allowlist by probing.
 */
class GatewayRejection extends \RuntimeException
{
    /**
     * Refuse a browser request with the HTTP status the route should return.
     *
     * @param int    $status HTTP status to send back (401 bad key, 403 origin or
     *                       handle, 400 disallowed method, 413 a request, message
     *                       or metadata over its size limit, 429 a cap was hit).
     * @param string $reason Short, fixed explanation. It reaches the browser, so
     *                       it names only the bucket: for a handle, "malformed
     *                       handle" (it doesn't parse), "invalid handle" (its
     *                       signature doesn't verify) or "expired handle" (it
     *                       verified but is past its expiry), and never the
     *                       caps' values or the allowlist.
     */
    public function __construct(
        public readonly int $status,
        public readonly string $reason,
    ) {
        parent::__construct(sprintf('%d: %s', $status, $reason));
    }
}
