<?php

declare(strict_types=1);

namespace SignalWire\REST\Namespaces;

use SignalWire\REST\CrudResource;
use SignalWire\REST\HttpClient;

/**
 * Phone Numbers namespace — list, search, purchase, get, update, release, bind.
 *
 * Mirrors Python ``signalwire.rest.namespaces.phone_numbers.PhoneNumbersResource``:
 * the standard CRUD surface (update uses PUT) plus ``search`` and the typed
 * binding helpers. The binding model is: set ``call_handler`` + the
 * handler-specific companion field on the phone number; the server
 * auto-materializes the matching Fabric resource.
 *
 * The ``call_handler`` wire values below mirror Python's ``PhoneCallHandler``
 * enum (which PHP exposes directly as these constants — see PORT_OMISSIONS.md).
 */
class PhoneNumbersResource extends CrudResource
{
    private const HANDLER_RELAY_SCRIPT = 'relay_script';
    private const HANDLER_LAML_WEBHOOKS = 'laml_webhooks';
    private const HANDLER_LAML_APPLICATION = 'laml_application';
    private const HANDLER_AI_AGENT = 'ai_agent';
    private const HANDLER_CALL_FLOW = 'call_flow';
    private const HANDLER_RELAY_APPLICATION = 'relay_application';
    private const HANDLER_RELAY_TOPIC = 'relay_topic';

    public function __construct(HttpClient $http)
    {
        parent::__construct($http, '/api/relay/rest/phone_numbers', 'PUT');
    }

    /**
     * Search available phone numbers (GET .../search).
     *
     * @param array<string,mixed> $params Query-string parameters.
     * @return array<string,mixed>
     */
    public function search(array $params = []): array
    {
        return $this->client->get($this->path('search'), $params);
    }

    // -- Typed binding helpers -------------------------------------------
    //
    // Each helper is a one-line wrapper over update() with the right
    // call_handler value and companion field already set. Pass through extra
    // fields via $extra for cases a helper doesn't name explicitly.

    /**
     * Route inbound calls to an SWML webhook URL.
     *
     * @param array<string,mixed> $extra
     * @return array<string,mixed>
     */
    public function setSwmlWebhook(string $resourceId, string $url, array $extra = []): array
    {
        return $this->update($resourceId, array_merge([
            'call_handler' => self::HANDLER_RELAY_SCRIPT,
            'call_relay_script_url' => $url,
        ], $extra));
    }

    /**
     * Route inbound calls to a cXML (Twilio-compat / LAML) webhook.
     *
     * @param array<string,mixed> $extra
     * @return array<string,mixed>
     */
    public function setCxmlWebhook(
        string $resourceId,
        string $url,
        ?string $fallbackUrl = null,
        ?string $statusCallbackUrl = null,
        array $extra = []
    ): array {
        $body = [
            'call_handler' => self::HANDLER_LAML_WEBHOOKS,
            'call_request_url' => $url,
        ];
        if ($fallbackUrl !== null) {
            $body['call_fallback_url'] = $fallbackUrl;
        }
        if ($statusCallbackUrl !== null) {
            $body['call_status_callback_url'] = $statusCallbackUrl;
        }
        return $this->update($resourceId, array_merge($body, $extra));
    }

    /**
     * Route inbound calls to an existing cXML application by ID.
     *
     * @param array<string,mixed> $extra
     * @return array<string,mixed>
     */
    public function setCxmlApplication(string $resourceId, string $applicationId, array $extra = []): array
    {
        return $this->update($resourceId, array_merge([
            'call_handler' => self::HANDLER_LAML_APPLICATION,
            'call_laml_application_id' => $applicationId,
        ], $extra));
    }

    /**
     * Route inbound calls to an AI Agent Fabric resource by ID.
     *
     * @param array<string,mixed> $extra
     * @return array<string,mixed>
     */
    public function setAiAgent(string $resourceId, string $agentId, array $extra = []): array
    {
        return $this->update($resourceId, array_merge([
            'call_handler' => self::HANDLER_AI_AGENT,
            'call_ai_agent_id' => $agentId,
        ], $extra));
    }

    /**
     * Route inbound calls to a Call Flow by ID.
     *
     * @param array<string,mixed> $extra
     * @return array<string,mixed>
     */
    public function setCallFlow(string $resourceId, string $flowId, ?string $version = null, array $extra = []): array
    {
        $body = [
            'call_handler' => self::HANDLER_CALL_FLOW,
            'call_flow_id' => $flowId,
        ];
        if ($version !== null) {
            $body['call_flow_version'] = $version;
        }
        return $this->update($resourceId, array_merge($body, $extra));
    }

    /**
     * Route inbound calls to a named RELAY application.
     *
     * @param array<string,mixed> $extra
     * @return array<string,mixed>
     */
    public function setRelayApplication(string $resourceId, string $name, array $extra = []): array
    {
        return $this->update($resourceId, array_merge([
            'call_handler' => self::HANDLER_RELAY_APPLICATION,
            'call_relay_application' => $name,
        ], $extra));
    }

    /**
     * Route inbound calls to a RELAY topic (client subscription).
     *
     * @param array<string,mixed> $extra
     * @return array<string,mixed>
     */
    public function setRelayTopic(
        string $resourceId,
        string $topic,
        ?string $statusCallbackUrl = null,
        array $extra = []
    ): array {
        $body = [
            'call_handler' => self::HANDLER_RELAY_TOPIC,
            'call_relay_topic' => $topic,
        ];
        if ($statusCallbackUrl !== null) {
            $body['call_relay_topic_status_callback_url'] = $statusCallbackUrl;
        }
        return $this->update($resourceId, array_merge($body, $extra));
    }
}
