<?php

declare(strict_types=1);

namespace SignalWire\REST\Namespaces;

use SignalWire\REST\HttpClient;

/**
 * Compat conference management with participants, recordings, and streams.
 */
class CompatConferences
{
    private HttpClient $http;
    private string $basePath;

    public function __construct(HttpClient $http, string $basePath)
    {
        $this->http = $http;
        $this->basePath = $basePath;
    }

    public function getBasePath(): string
    {
        return $this->basePath;
    }

    /** @param array<string,mixed> $params @return array<string,mixed> */
    public function list(array $params = []): array
    {
        return $this->http->get($this->basePath, $params);
    }

    /** @return array<string,mixed> */
    public function get(string $sid): array
    {
        return $this->http->get($this->basePath . '/' . $sid);
    }

    /** @param array<string,mixed> $body @return array<string,mixed> */
    public function update(string $sid, array $body): array
    {
        return $this->http->post($this->basePath . '/' . $sid, $body);
    }

    // ------------------------------------------------------------------
    // Participants
    // ------------------------------------------------------------------

    /** @param array<string,mixed> $params @return array<string,mixed> */
    public function listParticipants(string $conferenceSid, array $params = []): array
    {
        return $this->http->get(
            $this->basePath . '/' . $conferenceSid . '/Participants',
            $params
        );
    }

    /** @return array<string,mixed> */
    public function getParticipant(string $conferenceSid, string $callSid): array
    {
        return $this->http->get(
            $this->basePath . '/' . $conferenceSid . '/Participants/' . $callSid
        );
    }

    /** @param array<string,mixed> $body @return array<string,mixed> */
    public function updateParticipant(string $conferenceSid, string $callSid, array $body): array
    {
        return $this->http->post(
            $this->basePath . '/' . $conferenceSid . '/Participants/' . $callSid,
            $body
        );
    }

    /** @return array<string,mixed> */
    public function removeParticipant(string $conferenceSid, string $callSid): array
    {
        return $this->http->delete(
            $this->basePath . '/' . $conferenceSid . '/Participants/' . $callSid
        );
    }

    // ------------------------------------------------------------------
    // Conference recordings
    // ------------------------------------------------------------------

    /** @param array<string,mixed> $params @return array<string,mixed> */
    public function listRecordings(string $conferenceSid, array $params = []): array
    {
        return $this->http->get(
            $this->basePath . '/' . $conferenceSid . '/Recordings',
            $params
        );
    }

    /** @return array<string,mixed> */
    public function getRecording(string $conferenceSid, string $recordingSid): array
    {
        return $this->http->get(
            $this->basePath . '/' . $conferenceSid . '/Recordings/' . $recordingSid
        );
    }

    /** @param array<string,mixed> $body @return array<string,mixed> */
    public function updateRecording(string $conferenceSid, string $recordingSid, array $body): array
    {
        return $this->http->post(
            $this->basePath . '/' . $conferenceSid . '/Recordings/' . $recordingSid,
            $body
        );
    }

    /** @return array<string,mixed> */
    public function deleteRecording(string $conferenceSid, string $recordingSid): array
    {
        return $this->http->delete(
            $this->basePath . '/' . $conferenceSid . '/Recordings/' . $recordingSid
        );
    }

    // ------------------------------------------------------------------
    // Conference streams
    // ------------------------------------------------------------------

    /** @param array<string,mixed> $body @return array<string,mixed> */
    public function startStream(string $conferenceSid, array $body): array
    {
        return $this->http->post(
            $this->basePath . '/' . $conferenceSid . '/Streams',
            $body
        );
    }

    /** @param array<string,mixed> $body @return array<string,mixed> */
    public function stopStream(string $conferenceSid, string $streamSid, array $body): array
    {
        return $this->http->post(
            $this->basePath . '/' . $conferenceSid . '/Streams/' . $streamSid,
            $body
        );
    }
}
