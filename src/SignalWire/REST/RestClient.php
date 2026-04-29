<?php

declare(strict_types=1);

namespace SignalWire\REST;

use SignalWire\REST\Namespaces\Fabric;
use SignalWire\REST\Namespaces\Calling;

/**
 * Top-level SignalWire REST client.
 *
 * Provides lazy access to every API namespace (fabric, calling,
 * phone_numbers, datasphere, video, compat, etc.).  Credentials can be
 * supplied explicitly or pulled from environment variables.
 */
class RestClient
{
    private string $projectId;
    private string $token;
    private string $space;
    private string $baseUrl;
    private HttpClient $http;

    // -----------------------------------------------------------------
    // Lazily-initialised namespace instances
    // -----------------------------------------------------------------
    private ?Fabric $fabric = null;
    private ?Calling $calling = null;
    private ?CrudResource $phoneNumbers = null;
    private ?CrudResource $datasphere = null;
    private ?CrudResource $video = null;
    private ?CrudResource $compat = null;
    private ?CrudResource $addresses = null;
    private ?CrudResource $queues = null;
    private ?CrudResource $recordings = null;
    private ?CrudResource $numberGroups = null;
    private ?CrudResource $verifiedCallers = null;
    private ?CrudResource $sipProfile = null;
    private ?CrudResource $lookup = null;
    private ?CrudResource $shortCodes = null;
    private ?CrudResource $importedNumbers = null;
    private ?CrudResource $mfa = null;
    private ?CrudResource $registry = null;
    private ?CrudResource $logs = null;
    private ?CrudResource $project = null;
    private ?CrudResource $pubsub = null;
    private ?CrudResource $chat = null;

    /**
     * @param string $projectId Project ID (falls back to SIGNALWIRE_PROJECT_ID env var).
     * @param string $token     API token  (falls back to SIGNALWIRE_API_TOKEN env var).
     * @param string $space     Space host or full base URL.
     *                          - "mycompany.signalwire.com" → https://mycompany.signalwire.com
     *                          - "https://example.com:8080" → used verbatim
     *                          - "http://127.0.0.1:8080"   → used verbatim (test fixtures)
     *                          Falls back to SIGNALWIRE_SPACE env var.
     */
    public function __construct(string $projectId = '', string $token = '', string $space = '')
    {
        $this->projectId = $projectId !== '' ? $projectId : (string) getenv('SIGNALWIRE_PROJECT_ID');
        $this->token     = $token     !== '' ? $token     : (string) getenv('SIGNALWIRE_API_TOKEN');
        $this->space     = $space     !== '' ? $space     : (string) getenv('SIGNALWIRE_SPACE');

        if ($this->projectId === '') {
            throw new \InvalidArgumentException(
                'projectId is required (pass explicitly or set SIGNALWIRE_PROJECT_ID)'
            );
        }
        if ($this->token === '') {
            throw new \InvalidArgumentException(
                'token is required (pass explicitly or set SIGNALWIRE_API_TOKEN)'
            );
        }
        if ($this->space === '') {
            throw new \InvalidArgumentException(
                'space is required (pass explicitly or set SIGNALWIRE_SPACE)'
            );
        }

        // Accept a bare host ("space.signalwire.com") or a fully-qualified
        // URL. The latter is used by tests and audit harnesses to point
        // the client at a loopback fixture without forcing https://.
        $this->baseUrl = preg_match('#^https?://#i', $this->space)
            ? rtrim($this->space, '/')
            : 'https://' . $this->space;
        $this->http = new HttpClient($this->projectId, $this->token, $this->baseUrl);
    }

    // -----------------------------------------------------------------
    // Getters
    // -----------------------------------------------------------------

    public function getProjectId(): string
    {
        return $this->projectId;
    }

    public function getToken(): string
    {
        return $this->token;
    }

    public function getSpace(): string
    {
        return $this->space;
    }

    public function getBaseUrl(): string
    {
        return $this->baseUrl;
    }

    public function getHttp(): HttpClient
    {
        return $this->http;
    }

    // -----------------------------------------------------------------
    // Namespace accessors (lazy initialisation)
    // -----------------------------------------------------------------

    /** Fabric API (sub-resources: subscribers, sip_endpoints, call_flows, ...). */
    public function fabric(): Fabric
    {
        if ($this->fabric === null) {
            $this->fabric = new Fabric($this->http);
        }
        return $this->fabric;
    }

    /** Calling API (37 call-control commands). */
    public function calling(): Calling
    {
        if ($this->calling === null) {
            $this->calling = new Calling($this->http, $this->projectId);
        }
        return $this->calling;
    }

    /** Phone numbers. */
    public function phoneNumbers(): CrudResource
    {
        if ($this->phoneNumbers === null) {
            $this->phoneNumbers = new CrudResource($this->http, '/api/relay/rest/phone_numbers');
        }
        return $this->phoneNumbers;
    }

    /** Datasphere documents. */
    public function datasphere(): CrudResource
    {
        if ($this->datasphere === null) {
            $this->datasphere = new CrudResource($this->http, '/api/datasphere/documents');
        }
        return $this->datasphere;
    }

    /** Video rooms. */
    public function video(): CrudResource
    {
        if ($this->video === null) {
            $this->video = new CrudResource($this->http, '/api/video/rooms');
        }
        return $this->video;
    }

    /** Compatibility (Twilio-compatible LaML) API. */
    public function compat(): CrudResource
    {
        if ($this->compat === null) {
            $this->compat = new CrudResource(
                $this->http,
                '/api/laml/2010-04-01/Accounts/' . $this->projectId
            );
        }
        return $this->compat;
    }

    /** Addresses. */
    public function addresses(): CrudResource
    {
        if ($this->addresses === null) {
            $this->addresses = new CrudResource($this->http, '/api/relay/rest/addresses');
        }
        return $this->addresses;
    }

    /** Queues. */
    public function queues(): CrudResource
    {
        if ($this->queues === null) {
            $this->queues = new CrudResource($this->http, '/api/fabric/resources/queues');
        }
        return $this->queues;
    }

    /** Recordings. */
    public function recordings(): CrudResource
    {
        if ($this->recordings === null) {
            $this->recordings = new CrudResource($this->http, '/api/relay/rest/recordings');
        }
        return $this->recordings;
    }

    /** Number groups. */
    public function numberGroups(): CrudResource
    {
        if ($this->numberGroups === null) {
            $this->numberGroups = new CrudResource($this->http, '/api/relay/rest/number_groups');
        }
        return $this->numberGroups;
    }

    /** Verified callers. */
    public function verifiedCallers(): CrudResource
    {
        if ($this->verifiedCallers === null) {
            $this->verifiedCallers = new CrudResource($this->http, '/api/relay/rest/verified_callers');
        }
        return $this->verifiedCallers;
    }

    /** SIP profile. */
    public function sipProfile(): CrudResource
    {
        if ($this->sipProfile === null) {
            $this->sipProfile = new CrudResource($this->http, '/api/relay/rest/sip_profiles');
        }
        return $this->sipProfile;
    }

    /** Phone number lookup. */
    public function lookup(): CrudResource
    {
        if ($this->lookup === null) {
            $this->lookup = new CrudResource($this->http, '/api/relay/rest/lookup/phone_number');
        }
        return $this->lookup;
    }

    /** Short codes. */
    public function shortCodes(): CrudResource
    {
        if ($this->shortCodes === null) {
            $this->shortCodes = new CrudResource($this->http, '/api/relay/rest/short_codes');
        }
        return $this->shortCodes;
    }

    /** Imported phone numbers. */
    public function importedNumbers(): CrudResource
    {
        if ($this->importedNumbers === null) {
            $this->importedNumbers = new CrudResource($this->http, '/api/relay/rest/imported_phone_numbers');
        }
        return $this->importedNumbers;
    }

    /** Multi-factor authentication. */
    public function mfa(): CrudResource
    {
        if ($this->mfa === null) {
            $this->mfa = new CrudResource($this->http, '/api/relay/rest/mfa');
        }
        return $this->mfa;
    }

    /** Registry (10DLC brands, campaigns, orders). */
    public function registry(): CrudResource
    {
        if ($this->registry === null) {
            $this->registry = new CrudResource($this->http, '/api/relay/rest/registry');
        }
        return $this->registry;
    }

    /** Logs (messages, voice, fax, conferences). */
    public function logs(): CrudResource
    {
        if ($this->logs === null) {
            $this->logs = new CrudResource($this->http, '/api/relay/rest/logs');
        }
        return $this->logs;
    }

    /** Project management. */
    public function project(): CrudResource
    {
        if ($this->project === null) {
            $this->project = new CrudResource($this->http, '/api/relay/rest/project');
        }
        return $this->project;
    }

    /** PubSub tokens. */
    public function pubsub(): CrudResource
    {
        if ($this->pubsub === null) {
            $this->pubsub = new CrudResource($this->http, '/api/relay/rest/pubsub');
        }
        return $this->pubsub;
    }

    /** Chat tokens. */
    public function chat(): CrudResource
    {
        if ($this->chat === null) {
            $this->chat = new CrudResource($this->http, '/api/relay/rest/chat');
        }
        return $this->chat;
    }
}
