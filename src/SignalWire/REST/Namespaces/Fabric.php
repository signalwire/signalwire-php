<?php

declare(strict_types=1);

namespace SignalWire\REST\Namespaces;

use SignalWire\REST\CrudResource;
use SignalWire\REST\HttpClient;

/**
 * Fabric API namespace.
 *
 * Mirrors Python ``signalwire.rest.namespaces.fabric.FabricNamespace``:
 * groups all Fabric sub-resources (subscribers, SIP endpoints, call flows,
 * SWML scripts, conference rooms, AI agents, etc.) under a single object.
 *
 * Sub-resources fall into a few buckets:
 *   - PUT-update CRUD: ``swml_scripts``, ``relay_applications``,
 *     ``call_flows``, ``conference_rooms``, ``freeswitch_connectors``,
 *     ``subscribers``, ``sip_endpoints``, ``cxml_scripts``,
 *     ``cxml_applications``.
 *   - PATCH-update CRUD: ``swml_webhooks``, ``ai_agents``, ``sip_gateways``,
 *     ``cxml_webhooks``.
 *   - Special:  ``resources`` (generic ``/api/fabric/resources``),
 *     ``addresses`` (read-only ``/api/fabric/addresses``), ``tokens``
 *     (subscriber/guest/embed token creation).
 */
class Fabric
{
    private HttpClient $client;

    private const BASE = '/api/fabric/resources';

    // PUT-update resources (lazily initialised)
    private ?CrudResource $swmlScripts = null;
    private ?CrudResource $relayApplications = null;
    private ?FabricCallFlows $callFlows = null;
    private ?FabricConferenceRooms $conferenceRooms = null;
    private ?CrudResource $freeswitchConnectors = null;
    private ?FabricSubscribers $subscribers = null;
    private ?CrudResource $sipEndpoints = null;
    private ?CrudResource $cxmlScripts = null;
    private ?FabricCxmlApplications $cxmlApplications = null;

    // PATCH-update resources
    private ?CrudResource $swmlWebhooks = null;
    private ?CrudResource $aiAgents = null;
    private ?CrudResource $sipGateways = null;
    private ?CrudResource $cxmlWebhooks = null;

    // Legacy/back-compat aliases
    private ?CrudResource $addressesAlias = null;
    private ?CrudResource $conversations = null;
    private ?CrudResource $dialPlans = null;
    private ?CrudResource $freeclimbApps = null;
    private ?CrudResource $callQueues = null;
    private ?CrudResource $sipProfiles = null;
    private ?CrudResource $phoneNumbers = null;

    // Special resources
    private ?FabricGenericResources $resources = null;
    private ?FabricAddresses $addresses = null;
    private ?FabricTokens $tokens = null;

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

    public function subscribers(): FabricSubscribers
    {
        if ($this->subscribers === null) {
            $this->subscribers = new FabricSubscribers($this->client, self::BASE . '/subscribers');
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

    public function callFlows(): FabricCallFlows
    {
        if ($this->callFlows === null) {
            $this->callFlows = new FabricCallFlows($this->client, self::BASE . '/call_flows');
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

    public function relayApplications(): CrudResource
    {
        if ($this->relayApplications === null) {
            $this->relayApplications = new CrudResource($this->client, self::BASE . '/relay_applications');
        }
        return $this->relayApplications;
    }

    public function conferenceRooms(): FabricConferenceRooms
    {
        if ($this->conferenceRooms === null) {
            $this->conferenceRooms = new FabricConferenceRooms($this->client, self::BASE . '/conference_rooms');
        }
        return $this->conferenceRooms;
    }

    public function freeswitchConnectors(): CrudResource
    {
        if ($this->freeswitchConnectors === null) {
            $this->freeswitchConnectors = new CrudResource($this->client, self::BASE . '/freeswitch_connectors');
        }
        return $this->freeswitchConnectors;
    }

    public function cxmlScripts(): CrudResource
    {
        if ($this->cxmlScripts === null) {
            $this->cxmlScripts = new CrudResource($this->client, self::BASE . '/cxml_scripts');
        }
        return $this->cxmlScripts;
    }

    public function cxmlApplications(): FabricCxmlApplications
    {
        if ($this->cxmlApplications === null) {
            $this->cxmlApplications = new FabricCxmlApplications($this->client, self::BASE . '/cxml_applications');
        }
        return $this->cxmlApplications;
    }

    public function swmlWebhooks(): CrudResource
    {
        if ($this->swmlWebhooks === null) {
            $this->swmlWebhooks = new CrudResource($this->client, self::BASE . '/swml_webhooks');
        }
        return $this->swmlWebhooks;
    }

    public function aiAgents(): CrudResource
    {
        if ($this->aiAgents === null) {
            $this->aiAgents = new CrudResource($this->client, self::BASE . '/ai_agents');
        }
        return $this->aiAgents;
    }

    public function sipGateways(): CrudResource
    {
        if ($this->sipGateways === null) {
            $this->sipGateways = new CrudResource($this->client, self::BASE . '/sip_gateways');
        }
        return $this->sipGateways;
    }

    public function cxmlWebhooks(): CrudResource
    {
        if ($this->cxmlWebhooks === null) {
            $this->cxmlWebhooks = new CrudResource($this->client, self::BASE . '/cxml_webhooks');
        }
        return $this->cxmlWebhooks;
    }

    // ---- Legacy/back-compat aliases ---------------------------------

    public function conversations(): CrudResource
    {
        if ($this->conversations === null) {
            $this->conversations = new CrudResource($this->client, self::BASE . '/conversations');
        }
        return $this->conversations;
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

    // ---- Special resources ------------------------------------------

    /**
     * Generic operations across all fabric resource types
     * (``/api/fabric/resources``).
     */
    public function resources(): FabricGenericResources
    {
        if ($this->resources === null) {
            $this->resources = new FabricGenericResources($this->client, self::BASE);
        }
        return $this->resources;
    }

    /**
     * Read-only fabric addresses (``/api/fabric/addresses``).
     */
    public function addresses(): FabricAddresses
    {
        if ($this->addresses === null) {
            $this->addresses = new FabricAddresses($this->client, '/api/fabric/addresses');
        }
        return $this->addresses;
    }

    /**
     * Subscriber / guest / invite / embed token creation
     * (``/api/fabric/...``).
     */
    public function tokens(): FabricTokens
    {
        if ($this->tokens === null) {
            $this->tokens = new FabricTokens($this->client, '/api/fabric');
        }
        return $this->tokens;
    }
}
