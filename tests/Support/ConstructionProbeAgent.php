<?php

declare(strict_types=1);

namespace SignalWire\Tests\Support;

use SignalWire\Agent\AgentBase;

/**
 * Test-only probe exposing AgentBase's construction-time state.
 *
 * The reference keeps this state private (`self.agent_id` aside, which the
 * oracle does not enumerate: `_default_webhook_url`, `_suppress_logs`,
 * `_trust_proxy_for_signature` are all underscore-private in
 * signalwire/core/agent_base.py:225-254). AgentBase mirrors that by declaring
 * the getters `protected`, so the SDK adds no public surface the reference
 * lacks. Tests that need to read the stored value widen them here rather than
 * forcing the production class to publish them.
 */
final class ConstructionProbeAgent extends AgentBase
{
    public function probeAgentId(): string
    {
        return $this->getAgentId();
    }

    public function probeDefaultWebhookUrl(): ?string
    {
        return $this->getDefaultWebhookUrl();
    }

    public function probeSuppressLogs(): bool
    {
        return $this->getSuppressLogs();
    }

    public function probeTrustProxyForSignature(): bool
    {
        return $this->getTrustProxyForSignature();
    }
}
