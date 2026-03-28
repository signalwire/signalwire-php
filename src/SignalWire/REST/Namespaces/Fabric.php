<?php

declare(strict_types=1);

namespace SignalWire\REST\Namespaces;

use SignalWire\REST\CrudResource;
use SignalWire\REST\HttpClient;

/**
 * Fabric API namespace.
 *
 * Groups all Fabric sub-resources (subscribers, SIP endpoints, call flows,
 * SWML scripts, conference rooms, AI agents, etc.) under a single object.
 * Each sub-resource is lazily initialised as a CrudResource pointing at the
 * correct API path under /api/fabric/resources.
 */
class Fabric
{
    private HttpClient $client;

    private const BASE = '/api/fabric/resources';

    // Lazily-initialised sub-resources
    private ?CrudResource $subscribers = null;
    private ?CrudResource $sipEndpoints = null;
    private ?CrudResource $addresses = null;
    private ?CrudResource $callFlows = null;
    private ?CrudResource $swmlScripts = null;
    private ?CrudResource $conversations = null;
    private ?CrudResource $conferenceRooms = null;
    private ?CrudResource $dialPlans = null;
    private ?CrudResource $freeclimbApps = null;
    private ?CrudResource $callQueues = null;
    private ?CrudResource $aiAgents = null;
    private ?CrudResource $sipProfiles = null;
    private ?CrudResource $phoneNumbers = null;

    public function __construct(HttpClient $client)
    {
        $this->client = $client;
    }

    public function getClient(): HttpClient
    {
        return $this->client;
    }

    // -----------------------------------------------------------------
    // Sub-resource accessors (lazy)
    // -----------------------------------------------------------------

    public function subscribers(): CrudResource
    {
        if ($this->subscribers === null) {
            $this->subscribers = new CrudResource($this->client, self::BASE . '/subscribers');
        }
        return $this->subscribers;
    }

    public function sipEndpoints(): CrudResource
    {
        if ($this->sipEndpoints === null) {
            $this->sipEndpoints = new CrudResource($this->client, self::BASE . '/sip_endpoints');
        }
        return $this->sipEndpoints;
    }

    public function addresses(): CrudResource
    {
        if ($this->addresses === null) {
            $this->addresses = new CrudResource($this->client, self::BASE . '/addresses');
        }
        return $this->addresses;
    }

    public function callFlows(): CrudResource
    {
        if ($this->callFlows === null) {
            $this->callFlows = new CrudResource($this->client, self::BASE . '/call_flows');
        }
        return $this->callFlows;
    }

    public function swmlScripts(): CrudResource
    {
        if ($this->swmlScripts === null) {
            $this->swmlScripts = new CrudResource($this->client, self::BASE . '/swml_scripts');
        }
        return $this->swmlScripts;
    }

    public function conversations(): CrudResource
    {
        if ($this->conversations === null) {
            $this->conversations = new CrudResource($this->client, self::BASE . '/conversations');
        }
        return $this->conversations;
    }

    public function conferenceRooms(): CrudResource
    {
        if ($this->conferenceRooms === null) {
            $this->conferenceRooms = new CrudResource($this->client, self::BASE . '/conference_rooms');
        }
        return $this->conferenceRooms;
    }

    public function dialPlans(): CrudResource
    {
        if ($this->dialPlans === null) {
            $this->dialPlans = new CrudResource($this->client, self::BASE . '/dial_plans');
        }
        return $this->dialPlans;
    }

    public function freeclimbApps(): CrudResource
    {
        if ($this->freeclimbApps === null) {
            $this->freeclimbApps = new CrudResource($this->client, self::BASE . '/freeclimb_apps');
        }
        return $this->freeclimbApps;
    }

    public function callQueues(): CrudResource
    {
        if ($this->callQueues === null) {
            $this->callQueues = new CrudResource($this->client, self::BASE . '/call_queues');
        }
        return $this->callQueues;
    }

    public function aiAgents(): CrudResource
    {
        if ($this->aiAgents === null) {
            $this->aiAgents = new CrudResource($this->client, self::BASE . '/ai_agents');
        }
        return $this->aiAgents;
    }

    public function sipProfiles(): CrudResource
    {
        if ($this->sipProfiles === null) {
            $this->sipProfiles = new CrudResource($this->client, self::BASE . '/sip_profiles');
        }
        return $this->sipProfiles;
    }

    public function phoneNumbers(): CrudResource
    {
        if ($this->phoneNumbers === null) {
            $this->phoneNumbers = new CrudResource($this->client, self::BASE . '/phone_numbers');
        }
        return $this->phoneNumbers;
    }
}
