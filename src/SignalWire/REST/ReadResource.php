<?php

declare(strict_types=1);

namespace SignalWire\REST;

// ReadResource is defined in CrudResource.php alongside BaseResource,
// CrudResource and CrudWithAddresses (they form one tightly-coupled base
// hierarchy that the audit's brace-tracking enumerator reads as a single
// file). This shim ensures PSR-4 autoloading triggers the file load when a
// caller references ReadResource before CrudResource.
require_once __DIR__ . '/CrudResource.php';
