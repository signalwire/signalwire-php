<?php

declare(strict_types=1);

namespace SignalWire\REST\Namespaces;

use SignalWire\REST\CrudResource;

/**
 * Compat call management with recording and stream sub-resources.
 *
 * Mirrors Python ``CompatCalls``: ``list``/``create``/``get``/``update`` plus
 * the per-call sub-resources for Recordings and Streams.
 */
class CompatCalls extends CrudResource
{
    /**
     * Update a call resource (POST basePath/{sid}). The LAML API uses POST
     * for in-place updates rather than PUT/PATCH.
     *
     * @param array<string,mixed> $body
     * @return array<string,mixed>
     */
    public function update(string $sid, array $body): array
    {
        return $this->client->post($this->basePath . '/' . $sid, $body);
    }

    /**
     * Start a recording on the named call. POST basePath/{sid}/Recordings.
     *
     * @param array<string,mixed> $body
     * @return array<string,mixed>
     */
    public function startRecording(string $callSid, array $body = []): array
    {
        return $this->client->post($this->basePath . '/' . $callSid . '/Recordings', $body);
    }

    /**
     * Update an active recording. POST basePath/{sid}/Recordings/{recSid}.
     *
     * @param array<string,mixed> $body
     * @return array<string,mixed>
     */
    public function updateRecording(string $callSid, string $recordingSid, array $body): array
    {
        return $this->client->post(
            $this->basePath . '/' . $callSid . '/Recordings/' . $recordingSid,
            $body
        );
    }

    /**
     * Start a media stream on the named call. POST basePath/{sid}/Streams.
     *
     * @param array<string,mixed> $body
     * @return array<string,mixed>
     */
    public function startStream(string $callSid, array $body): array
    {
        return $this->client->post($this->basePath . '/' . $callSid . '/Streams', $body);
    }

    /**
     * Stop the named media stream. POST basePath/{sid}/Streams/{streamSid}.
     *
     * @param array<string,mixed> $body
     * @return array<string,mixed>
     */
    public function stopStream(string $callSid, string $streamSid, array $body): array
    {
        return $this->client->post(
            $this->basePath . '/' . $callSid . '/Streams/' . $streamSid,
            $body
        );
    }
}
