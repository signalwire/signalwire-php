<?php

declare(strict_types=1);

namespace SignalWire\Relay;

// Class is defined in Action.php along with the other concrete Action
// subclasses. This shim ensures PSR-4 autoloading triggers the file
// load when a caller references FaxAction before the base Action.
require_once __DIR__ . '/Action.php';
