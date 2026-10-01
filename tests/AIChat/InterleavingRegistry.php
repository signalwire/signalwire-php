<?php

declare(strict_types=1);

/*
 * Copyright (c) 2026 SignalWire
 *
 * Licensed under the MIT License.
 * See LICENSE file in the project root for full license information.
 */

namespace SignalWire\Tests\AIChat;

use SignalWire\AIChat\NonceEntry;

/**
 * A registry that, the first time a nonce is looked up, runs another
 * registration and redemption before the lookup returns.
 *
 * @extends \ArrayObject<string, NonceEntry>
 */
final class InterleavingRegistry extends \ArrayObject
{
    /** Run once, inside the first lookup. */
    public ?\Closure $interleave = null;

    private bool $raced = false;

    public function offsetExists(mixed $key): bool
    {
        $found = parent::offsetExists($key);
        if (!$this->raced && $this->interleave !== null) {
            $this->raced = true;
            ($this->interleave)();
        }
        return $found;
    }
}
