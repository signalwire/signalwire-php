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
 * A registry that hands back copies, as one backed by a cache would.
 *
 * @extends \ArrayObject<string, NonceEntry>
 */
final class CopyingRegistry extends \ArrayObject
{
    public function offsetGet(mixed $key): mixed
    {
        $value = parent::offsetGet($key);
        return $value instanceof NonceEntry ? clone $value : $value;
    }

    public function offsetSet(mixed $key, mixed $value): void
    {
        parent::offsetSet($key, clone $value);
    }
}
