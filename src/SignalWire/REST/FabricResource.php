<?php

declare(strict_types=1);

namespace SignalWire\REST;

/**
 * Standard fabric resource: CRUD + addresses (PATCH update).
 *
 * Mirrors Python's signalwire.rest._base.FabricResource — an intermediate base
 * over CrudWithAddresses. Concrete generated fabric resources extend it (or
 * FabricResourcePUT). Lives in the rest base (not the fabric namespace) so the
 * generated fabric_resources_generated subclasses can inherit it without a
 * cycle. Adds no methods of its own — the surface is CrudWithAddresses's.
 */
class FabricResource extends CrudWithAddresses
{
}

/**
 * Fabric resource that uses PUT for updates.
 *
 * Mirrors Python's signalwire.rest._base.FabricResourcePUT — like
 * FabricResource but pins the update verb to PUT.
 */
class FabricResourcePUT extends CrudWithAddresses
{
    protected string $updateMethod = 'PUT';

    public function __construct(HttpClient $client, string $basePath)
    {
        parent::__construct($client, $basePath, 'PUT');
    }
}
