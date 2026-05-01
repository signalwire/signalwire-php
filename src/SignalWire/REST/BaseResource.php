<?php

declare(strict_types=1);

namespace SignalWire\REST;

// Class is defined in CrudResource.php along with CrudResource and
// CrudWithAddresses. This shim ensures PSR-4 autoloading triggers the
// file load when a caller references BaseResource before CrudResource.
require_once __DIR__ . '/CrudResource.php';
