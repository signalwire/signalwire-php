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
 * A registry that records each assignment, as a store backed by shared storage
 * would see it.
 *
 * @extends \ArrayObject<string, NonceEntry>
 */
final class RecordingRegistry extends \ArrayObject
{
    /** @var list<array{string, bool}> */
    public array $assigned = [];

    public function offsetSet(mixed $key, mixed $value): void
    {
        $this->assigned[] = [(string) $key, $value->redeemed];
        parent::offsetSet($key, $value);
    }
}
