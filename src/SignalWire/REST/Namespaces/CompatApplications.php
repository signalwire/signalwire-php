<?php

declare(strict_types=1);

namespace SignalWire\REST\Namespaces;

use SignalWire\REST\CrudResource;

/**
 * Compat application management.
 */
class CompatApplications extends CrudResource
{
    /**
     * @param array<string,mixed> $body
     * @return array<string,mixed>
     */
    public function update(string $sid, array $body): array
    {
        return $this->client->post($this->basePath . '/' . $sid, $body);
    }
}
