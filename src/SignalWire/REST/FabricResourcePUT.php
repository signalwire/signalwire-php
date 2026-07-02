<?php

declare(strict_types=1);

namespace SignalWire\REST;

// FabricResourcePUT is defined in FabricResource.php alongside FabricResource
// (they are the two intermediate fabric bases and share a file). This shim
// ensures PSR-4 autoloading triggers the file load when a caller references
// FabricResourcePUT before FabricResource.
require_once __DIR__ . '/FabricResource.php';
