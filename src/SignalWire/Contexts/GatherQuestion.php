<?php

declare(strict_types=1);

namespace SignalWire\Contexts;

// Class is defined in ContextBuilder.php along with the other Contexts
// classes. This shim ensures PSR-4 autoloading triggers the file load
// when callers `use` SignalWire\Contexts\GatherQuestion before any other
// Contexts class.
require_once __DIR__ . '/ContextBuilder.php';
