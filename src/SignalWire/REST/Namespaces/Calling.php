<?php

declare(strict_types=1);

namespace SignalWire\REST\Namespaces;

use SignalWire\REST\HttpClient;

/**
 * Calling API namespace.
 *
 * Provides 37 call-control command methods that each POST to
 * /api/calling/calls with a JSON body containing the command name,
 * an optional call ID, and parameters.
 */
class Calling
{
    private HttpClient $client;
    private string $projectId;

    private const BASE_PATH = '/api/calling/calls';

    public function __construct(HttpClient $client, string $projectId)
    {
        $this->client = $client;
        $this->projectId = $projectId;
    }

    public function getClient(): HttpClient
    {
        return $this->client;
    }

    public function getProjectId(): string
    {
        return $this->projectId;
    }

    public function getBasePath(): string
    {
        return self::BASE_PATH;
    }

    // -----------------------------------------------------------------
    // Internal execute helper
    // -----------------------------------------------------------------

    /**
     * Execute a calling command via POST.
     *
     * @param string      $command  Command identifier (e.g. "calling.play").
     * @param string|null $callId   Call ID (null for commands like dial).
     * @param array<string,mixed> $params  Command parameters.
     * @return array<string,mixed>
     */
    private function execute(string $command, ?string $callId, array $params = []): array
    {
        $body = [
            'command' => $command,
            'params'  => $params,
        ];

        if ($callId !== null) {
            $body['id'] = $callId;
        }

        return $this->client->post(self::BASE_PATH, $body);
    }

    // -----------------------------------------------------------------
    // Call lifecycle (5)
    // -----------------------------------------------------------------

    /** @param array<string,mixed> $params */
    public function dial(array $params = []): array
    {
        return $this->execute('dial', null, $params);
    }

    /** @param array<string,mixed> $params */
    public function updateCall(array $params = []): array
    {
        return $this->execute('update', null, $params);
    }

    /** @param array<string,mixed> $params */
    public function end(string $callId, array $params = []): array
    {
        return $this->execute('calling.end', $callId, $params);
    }

    /** @param array<string,mixed> $params */
    public function transfer(string $callId, array $params = []): array
    {
        return $this->execute('calling.transfer', $callId, $params);
    }

    /** @param array<string,mixed> $params */
    public function disconnect(string $callId, array $params = []): array
    {
        return $this->execute('calling.disconnect', $callId, $params);
    }

    // -----------------------------------------------------------------
    // Play (5)
    // -----------------------------------------------------------------

    /** @param array<string,mixed> $params */
    public function play(string $callId, array $params = []): array
    {
        return $this->execute('calling.play', $callId, $params);
    }

    /** @param array<string,mixed> $params */
    public function playPause(string $callId, array $params = []): array
    {
        return $this->execute('calling.play.pause', $callId, $params);
    }

    /** @param array<string,mixed> $params */
    public function playResume(string $callId, array $params = []): array
    {
        return $this->execute('calling.play.resume', $callId, $params);
    }

    /** @param array<string,mixed> $params */
    public function playStop(string $callId, array $params = []): array
    {
        return $this->execute('calling.play.stop', $callId, $params);
    }

    /** @param array<string,mixed> $params */
    public function playVolume(string $callId, array $params = []): array
    {
        return $this->execute('calling.play.volume', $callId, $params);
    }

    // -----------------------------------------------------------------
    // Record (4)
    // -----------------------------------------------------------------

    /** @param array<string,mixed> $params */
    public function record(string $callId, array $params = []): array
    {
        return $this->execute('calling.record', $callId, $params);
    }

    /** @param array<string,mixed> $params */
    public function recordPause(string $callId, array $params = []): array
    {
        return $this->execute('calling.record.pause', $callId, $params);
    }

    /** @param array<string,mixed> $params */
    public function recordResume(string $callId, array $params = []): array
    {
        return $this->execute('calling.record.resume', $callId, $params);
    }

    /** @param array<string,mixed> $params */
    public function recordStop(string $callId, array $params = []): array
    {
        return $this->execute('calling.record.stop', $callId, $params);
    }

    // -----------------------------------------------------------------
    // Collect (3)
    // -----------------------------------------------------------------

    /** @param array<string,mixed> $params */
    public function collect(string $callId, array $params = []): array
    {
        return $this->execute('calling.collect', $callId, $params);
    }

    /** @param array<string,mixed> $params */
    public function collectStop(string $callId, array $params = []): array
    {
        return $this->execute('calling.collect.stop', $callId, $params);
    }

    /** @param array<string,mixed> $params */
    public function collectStartInputTimers(string $callId, array $params = []): array
    {
        return $this->execute('calling.collect.start_input_timers', $callId, $params);
    }

    // -----------------------------------------------------------------
    // Detect (2)
    // -----------------------------------------------------------------

    /** @param array<string,mixed> $params */
    public function detect(string $callId, array $params = []): array
    {
        return $this->execute('calling.detect', $callId, $params);
    }

    /** @param array<string,mixed> $params */
    public function detectStop(string $callId, array $params = []): array
    {
        return $this->execute('calling.detect.stop', $callId, $params);
    }

    // -----------------------------------------------------------------
    // Tap (2)
    // -----------------------------------------------------------------

    /** @param array<string,mixed> $params */
    public function tap(string $callId, array $params = []): array
    {
        return $this->execute('calling.tap', $callId, $params);
    }

    /** @param array<string,mixed> $params */
    public function tapStop(string $callId, array $params = []): array
    {
        return $this->execute('calling.tap.stop', $callId, $params);
    }

    // -----------------------------------------------------------------
    // Stream (2)
    // -----------------------------------------------------------------

    /** @param array<string,mixed> $params */
    public function stream(string $callId, array $params = []): array
    {
        return $this->execute('calling.stream', $callId, $params);
    }

    /** @param array<string,mixed> $params */
    public function streamStop(string $callId, array $params = []): array
    {
        return $this->execute('calling.stream.stop', $callId, $params);
    }

    // -----------------------------------------------------------------
    // Denoise (2)
    // -----------------------------------------------------------------

    /** @param array<string,mixed> $params */
    public function denoise(string $callId, array $params = []): array
    {
        return $this->execute('calling.denoise', $callId, $params);
    }

    /** @param array<string,mixed> $params */
    public function denoiseStop(string $callId, array $params = []): array
    {
        return $this->execute('calling.denoise.stop', $callId, $params);
    }

    // -----------------------------------------------------------------
    // Transcribe (2)
    // -----------------------------------------------------------------

    /** @param array<string,mixed> $params */
    public function transcribe(string $callId, array $params = []): array
    {
        return $this->execute('calling.transcribe', $callId, $params);
    }

    /** @param array<string,mixed> $params */
    public function transcribeStop(string $callId, array $params = []): array
    {
        return $this->execute('calling.transcribe.stop', $callId, $params);
    }

    // -----------------------------------------------------------------
    // AI (4)
    // -----------------------------------------------------------------

    /** @param array<string,mixed> $params */
    public function aiMessage(string $callId, array $params = []): array
    {
        return $this->execute('calling.ai_message', $callId, $params);
    }

    /** @param array<string,mixed> $params */
    public function aiHold(string $callId, array $params = []): array
    {
        return $this->execute('calling.ai_hold', $callId, $params);
    }

    /** @param array<string,mixed> $params */
    public function aiUnhold(string $callId, array $params = []): array
    {
        return $this->execute('calling.ai_unhold', $callId, $params);
    }

    /** @param array<string,mixed> $params */
    public function aiStop(string $callId, array $params = []): array
    {
        return $this->execute('calling.ai.stop', $callId, $params);
    }

    // -----------------------------------------------------------------
    // Live transcribe / translate (2)
    // -----------------------------------------------------------------

    /** @param array<string,mixed> $params */
    public function liveTranscribe(string $callId, array $params = []): array
    {
        return $this->execute('calling.live_transcribe', $callId, $params);
    }

    /** @param array<string,mixed> $params */
    public function liveTranslate(string $callId, array $params = []): array
    {
        return $this->execute('calling.live_translate', $callId, $params);
    }

    // -----------------------------------------------------------------
    // Fax (2)
    // -----------------------------------------------------------------

    /** @param array<string,mixed> $params */
    public function sendFaxStop(string $callId, array $params = []): array
    {
        return $this->execute('calling.send_fax.stop', $callId, $params);
    }

    /** @param array<string,mixed> $params */
    public function receiveFaxStop(string $callId, array $params = []): array
    {
        return $this->execute('calling.receive_fax.stop', $callId, $params);
    }

    // -----------------------------------------------------------------
    // SIP (1)
    // -----------------------------------------------------------------

    /** @param array<string,mixed> $params */
    public function refer(string $callId, array $params = []): array
    {
        return $this->execute('calling.refer', $callId, $params);
    }

    // -----------------------------------------------------------------
    // Custom events (1)
    // -----------------------------------------------------------------

    /** @param array<string,mixed> $params */
    public function userEvent(string $callId, array $params = []): array
    {
        return $this->execute('calling.user_event', $callId, $params);
    }
}
