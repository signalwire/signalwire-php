<?php

declare(strict_types=1);

namespace SignalWire\REST\Namespaces;

use SignalWire\REST\HttpClient;

/**
 * Logs namespace — message, voice, fax, and conference logs (read-only).
 *
 * Mirrors Python ``signalwire.rest.namespaces.logs.LogsNamespace``: each
 * sub-API lives at a different mount point because that's how the spec
 * docs slice them.
 */
class Logs
{
    private HttpClient $http;

    private MessageLogs $messages;
    private VoiceLogs $voice;
    private FaxLogs $fax;
    private ConferenceLogs $conferences;

    public function __construct(HttpClient $http)
    {
        $this->http = $http;
        $this->messages = new MessageLogs($http, '/api/messaging/logs');
        $this->voice = new VoiceLogs($http, '/api/voice/logs');
        $this->fax = new FaxLogs($http, '/api/fax/logs');
        $this->conferences = new ConferenceLogs($http, '/api/logs/conferences');
    }

    public function messages(): MessageLogs
    {
        return $this->messages;
    }

    public function voice(): VoiceLogs
    {
        return $this->voice;
    }

    public function fax(): FaxLogs
    {
        return $this->fax;
    }

    public function conferences(): ConferenceLogs
    {
        return $this->conferences;
    }

    public function getHttp(): HttpClient
    {
        return $this->http;
    }
}
