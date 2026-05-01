<?php

declare(strict_types=1);

namespace SignalWire\REST;

// Class is defined in CrudResource.php along with BaseResource and
// CrudResource. This shim ensures PSR-4 autoloading triggers the file
// load when a caller references CrudWithAddresses before CrudResource.
require_once __DIR__ . '/CrudResource.php';
