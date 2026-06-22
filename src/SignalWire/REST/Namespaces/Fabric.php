<?php

declare(strict_types=1);

namespace SignalWire\REST\Namespaces;

use SignalWire\REST\CrudWithAddresses;
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

    // PUT-update resources (lazily initialised). Every fabric resource
    // extends CrudWithAddresses so list/create/get/update/delete + the
    // {id}/addresses sub-collection are all available, mirroring Python's
    // FabricResource / FabricResourcePUT hierarchy.
    private ?CrudWithAddresses $swmlScripts = null;
    private ?CrudWithAddresses $relayApplications = null;
    private ?FabricCallFlows $callFlows = null;
    private ?FabricConferenceRooms $conferenceRooms = null;
    private ?CrudWithAddresses $freeswitchConnectors = null;
    private ?FabricSubscribers $subscribers = null;
    private ?CrudWithAddresses $sipEndpoints = null;
    private ?CrudWithAddresses $cxmlScripts = null;
    private ?FabricCxmlApplications $cxmlApplications = null;

    // PATCH-update resources
    private ?CrudWithAddresses $swmlWebhooks = null;
    private ?CrudWithAddresses $aiAgents = null;
    private ?CrudWithAddresses $sipGateways = null;
    private ?CrudWithAddresses $cxmlWebhooks = null;

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
            $this->subscribers = new FabricSubscribers($this->client, self::BASE . '/subscribers', 'PUT');
        }
        return $this->subscribers;
    }

    public function sipEndpoints(): CrudWithAddresses
    {
        if ($this->sipEndpoints === null) {
            $this->sipEndpoints = new CrudWithAddresses($this->client, self::BASE . '/sip_endpoints', 'PUT');
        }
        return $this->sipEndpoints;
    }

    public function callFlows(): FabricCallFlows
    {
        if ($this->callFlows === null) {
            $this->callFlows = new FabricCallFlows($this->client, self::BASE . '/call_flows', 'PUT');
        }
        return $this->callFlows;
    }

    public function swmlScripts(): CrudWithAddresses
    {
        if ($this->swmlScripts === null) {
            $this->swmlScripts = new CrudWithAddresses($this->client, self::BASE . '/swml_scripts', 'PUT');
        }
        return $this->swmlScripts;
    }

    public function relayApplications(): CrudWithAddresses
    {
        if ($this->relayApplications === null) {
            $this->relayApplications = new CrudWithAddresses($this->client, self::BASE . '/relay_applications', 'PUT');
        }
        return $this->relayApplications;
    }

    public function conferenceRooms(): FabricConferenceRooms
    {
        if ($this->conferenceRooms === null) {
            $this->conferenceRooms = new FabricConferenceRooms($this->client, self::BASE . '/conference_rooms', 'PUT');
        }
        return $this->conferenceRooms;
    }

    public function freeswitchConnectors(): CrudWithAddresses
    {
        if ($this->freeswitchConnectors === null) {
            $this->freeswitchConnectors = new CrudWithAddresses($this->client, self::BASE . '/freeswitch_connectors', 'PUT');
        }
        return $this->freeswitchConnectors;
    }

    public function cxmlScripts(): CrudWithAddresses
    {
        if ($this->cxmlScripts === null) {
            $this->cxmlScripts = new CrudWithAddresses($this->client, self::BASE . '/cxml_scripts', 'PUT');
        }
        return $this->cxmlScripts;
    }

    public function cxmlApplications(): FabricCxmlApplications
    {
        if ($this->cxmlApplications === null) {
            $this->cxmlApplications = new FabricCxmlApplications($this->client, self::BASE . '/cxml_applications', 'PUT');
        }
        return $this->cxmlApplications;
    }

    public function swmlWebhooks(): CrudWithAddresses
    {
        if ($this->swmlWebhooks === null) {
            $this->swmlWebhooks = new CrudWithAddresses($this->client, self::BASE . '/swml_webhooks');
        }
        return $this->swmlWebhooks;
    }

    public function aiAgents(): CrudWithAddresses
    {
        if ($this->aiAgents === null) {
            $this->aiAgents = new CrudWithAddresses($this->client, self::BASE . '/ai_agents');
        }
        return $this->aiAgents;
    }

    public function sipGateways(): CrudWithAddresses
    {
        if ($this->sipGateways === null) {
            $this->sipGateways = new CrudWithAddresses($this->client, self::BASE . '/sip_gateways');
        }
        return $this->sipGateways;
    }

    public function cxmlWebhooks(): CrudWithAddresses
    {
        if ($this->cxmlWebhooks === null) {
            $this->cxmlWebhooks = new CrudWithAddresses($this->client, self::BASE . '/cxml_webhooks');
        }
        return $this->cxmlWebhooks;
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
