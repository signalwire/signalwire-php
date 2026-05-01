<?php

declare(strict_types=1);

namespace SignalWire\REST\Namespaces;

use SignalWire\REST\CrudResource;

/**
 * cXML applications — read-only by API design. ``create`` is implemented as
 * an explicit failure (mirroring Python's ``NotImplementedError``) because
 * the API has no create endpoint for cXML applications.
 */
class FabricCxmlApplications extends CrudResource
{
    /**
     * cXML applications cannot be created via this API — only PUT/GET/DELETE
     * exist on ``/api/fabric/resources/cxml_applications/{id}``.
     *
     * @param array<string,mixed> $data
     * @return never
     * @throws \BadMethodCallException always.
     */
    public function create(array $data): array
    {
        throw new \BadMethodCallException('cXML applications cannot be created via this API');
    }
}
