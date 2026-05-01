<?php

declare(strict_types=1);

namespace SignalWire\REST;

use SignalWire\REST\Namespaces\Fabric;
use SignalWire\REST\Namespaces\Calling;
use SignalWire\REST\Namespaces\Compat;
use SignalWire\REST\Namespaces\Video;
use SignalWire\REST\Namespaces\Logs;
use SignalWire\REST\Namespaces\Registry;
use SignalWire\REST\Namespaces\Project;
use SignalWire\REST\Namespaces\Datasphere;
use SignalWire\REST\Namespaces\Queues;
use SignalWire\REST\Namespaces\NumberGroups;
use SignalWire\REST\Namespaces\ShortCodes;
use SignalWire\REST\Namespaces\ImportedNumbers;
use SignalWire\REST\Namespaces\Mfa;
use SignalWire\REST\Namespaces\SipProfile;
use SignalWire\REST\Namespaces\Addresses;
use SignalWire\REST\Namespaces\Recordings;

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
    private ?Datasphere $datasphere = null;
    private ?Video $video = null;
    private ?Compat $compat = null;
    private ?Addresses $addresses = null;
    private ?Queues $queues = null;
    private ?Recordings $recordings = null;
    private ?NumberGroups $numberGroups = null;
    private ?CrudResource $verifiedCallers = null;
    private ?SipProfile $sipProfile = null;
    private ?CrudResource $lookup = null;
    private ?ShortCodes $shortCodes = null;
    private ?ImportedNumbers $importedNumbers = null;
    private ?Mfa $mfa = null;
    private ?Registry $registry = null;
    private ?Logs $logs = null;
    private ?Project $project = null;
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

    /** Datasphere documents (CRUD + chunks + search). */
    public function datasphere(): Datasphere
    {
        if ($this->datasphere === null) {
            $this->datasphere = new Datasphere($this->http);
        }
        return $this->datasphere;
    }

    /**
     * Video API namespace (rooms, room_sessions, room_recordings,
     * conferences, conference_tokens, streams).
     */
    public function video(): Video
    {
        if ($this->video === null) {
            $this->video = new Video($this->http);
        }
        return $this->video;
    }

    /**
     * Compatibility (Twilio-compatible LaML) API.
     *
     * Returns a ``Compat`` namespace object exposing the LAML sub-resources
     * (calls, messages, faxes, conferences, phoneNumbers, recordings, ...).
     */
    public function compat(): Compat
    {
        if ($this->compat === null) {
            $this->compat = new Compat($this->http, $this->projectId);
        }
        return $this->compat;
    }

    /** Addresses (list / create / get / delete — no update). */
    public function addresses(): Addresses
    {
        if ($this->addresses === null) {
            $this->addresses = new Addresses($this->http);
        }
        return $this->addresses;
    }

    /** Queues (CRUD + member operations). */
    public function queues(): Queues
    {
        if ($this->queues === null) {
            $this->queues = new Queues($this->http);
        }
        return $this->queues;
    }

    /** Recordings (list / get / delete only). */
    public function recordings(): Recordings
    {
        if ($this->recordings === null) {
            $this->recordings = new Recordings($this->http);
        }
        return $this->recordings;
    }

    /** Number groups (CRUD + membership operations). */
    public function numberGroups(): NumberGroups
    {
        if ($this->numberGroups === null) {
            $this->numberGroups = new NumberGroups($this->http);
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

    /** SIP profile (singleton, get + update). */
    public function sipProfile(): SipProfile
    {
        if ($this->sipProfile === null) {
            $this->sipProfile = new SipProfile($this->http);
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

    /** Short codes (list / get / update only). */
    public function shortCodes(): ShortCodes
    {
        if ($this->shortCodes === null) {
            $this->shortCodes = new ShortCodes($this->http);
        }
        return $this->shortCodes;
    }

    /** Imported phone numbers (create only). */
    public function importedNumbers(): ImportedNumbers
    {
        if ($this->importedNumbers === null) {
            $this->importedNumbers = new ImportedNumbers($this->http);
        }
        return $this->importedNumbers;
    }

    /** Multi-factor authentication (sms / call / verify). */
    public function mfa(): Mfa
    {
        if ($this->mfa === null) {
            $this->mfa = new Mfa($this->http);
        }
        return $this->mfa;
    }

    /** Registry (10DLC brands, campaigns, orders, numbers). */
    public function registry(): Registry
    {
        if ($this->registry === null) {
            $this->registry = new Registry($this->http);
        }
        return $this->registry;
    }

    /** Logs (messages, voice, fax, conferences). */
    public function logs(): Logs
    {
        if ($this->logs === null) {
            $this->logs = new Logs($this->http);
        }
        return $this->logs;
    }

    /** Project management (project tokens). */
    public function project(): Project
    {
        if ($this->project === null) {
            $this->project = new Project($this->http);
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
