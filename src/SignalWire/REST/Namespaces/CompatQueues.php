<?php

declare(strict_types=1);

namespace SignalWire\REST\Namespaces;

use SignalWire\REST\CrudResource;

/**
 * Compat queue management with member sub-resources.
 */
class CompatQueues extends CrudResource
{
    /**
     * @param array<string,mixed> $body
     * @return array<string,mixed>
     */
    public function update(string $sid, array $body): array
    {
        return $this->client->post($this->basePath . '/' . $sid, $body);
    }

    /** @param array<string,mixed> $params @return array<string,mixed> */
    public function listMembers(string $queueSid, array $params = []): array
    {
        return $this->client->get(
            $this->basePath . '/' . $queueSid . '/Members',
            $params
        );
    }

    /** @return array<string,mixed> */
    public function getMember(string $queueSid, string $callSid): array
    {
        return $this->client->get(
            $this->basePath . '/' . $queueSid . '/Members/' . $callSid
        );
    }

    /** @param array<string,mixed> $body @return array<string,mixed> */
    public function dequeueMember(string $queueSid, string $callSid, array $body = []): array
    {
        return $this->client->post(
            $this->basePath . '/' . $queueSid . '/Members/' . $callSid,
            $body
        );
    }
}
