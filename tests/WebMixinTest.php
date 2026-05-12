<?php

declare(strict_types=1);

namespace SignalWire\Tests;

use PHPUnit\Framework\TestCase;
use SignalWire\SWML\Service;

/**
 * Tests for Service::onRequest and Service::onSwmlRequest -- Python
 * WebMixin parity.
 *
 * Python parity:
 *   tests/unit/core/mixins/test_web_mixin.py::
 *     test_on_request_delegates_to_on_swml_request
 *     test_on_swml_request_called
 */
class WebMixinTest extends TestCase
{
    public function testOnRequestDelegatesToOnSwmlRequest(): void
    {
        $svc = new class(name: 't') extends Service {
            public ?array $lastRequestData = null;
            public ?string $lastCallbackPath = null;
            public ?array $customReturn = null;

            public function onSwmlRequest(?array $requestData = null, ?string $callbackPath = null): ?array
            {
                $this->lastRequestData = $requestData;
                $this->lastCallbackPath = $callbackPath;
                return $this->customReturn;
            }
        };

        $svc->customReturn = ['custom' => true];
        $rd = ['data' => 'val'];
        $result = $svc->onRequest($rd, '/cb');

        $this->assertSame($rd, $svc->lastRequestData);
        $this->assertSame('/cb', $svc->lastCallbackPath);
        $this->assertNotNull($result);
        $this->assertTrue($result['custom']);
    }

    public function testOnSwmlRequestDefaultReturnsNull(): void
    {
        $svc = new Service(name: 't');
        $this->assertNull($svc->onSwmlRequest(null, null));
    }

    public function testOnRequestDefaultReturnsNull(): void
    {
        $svc = new Service(name: 't');
        $this->assertNull($svc->onRequest(null, null));
    }

    public function testOnRequestPassesNullsToHook(): void
    {
        $svc = new class(name: 't') extends Service {
            public bool $called = false;
            public ?array $sawData = null;
            public ?string $sawPath = null;

            public function onSwmlRequest(?array $requestData = null, ?string $callbackPath = null): ?array
            {
                $this->called = true;
                $this->sawData = $requestData;
                $this->sawPath = $callbackPath;
                return null;
            }
        };

        $svc->onRequest(null, null);
        $this->assertTrue($svc->called);
        $this->assertNull($svc->sawData);
        $this->assertNull($svc->sawPath);
    }

    // ──────────────────────────────────────────────────────────────────
    // manualSetProxyUrl — Python WebMixin.manual_set_proxy_url parity
    // ──────────────────────────────────────────────────────────────────

    public function testManualSetProxyUrlOverridesAutoDetection(): void
    {
        $agent = new \SignalWire\Agent\AgentBase(name: 'pxy-agent', route: '/agent', basicAuthUser: 'u', basicAuthPassword: 'p');

        $ret = $agent->manualSetProxyUrl('https://abc.ngrok.io/');
        // Fluent return — chains like every other AgentBase setter.
        $this->assertSame($agent, $ret);

        // Auto-detection logic now uses the manual override; trailing
        // slash should be stripped to match Python's rstrip('/').
        $url = $agent->getProxyUrlBase([
            'X-Forwarded-Proto' => 'https',
            'X-Forwarded-Host' => 'should-be-ignored.example',
        ]);
        $this->assertSame('https://abc.ngrok.io', $url);
    }

    public function testManualSetProxyUrlEmptyStringNoOps(): void
    {
        $agent = new \SignalWire\Agent\AgentBase(name: 'pxy-agent-2', basicAuthUser: 'u', basicAuthPassword: 'p');

        // Empty proxy URL must not stomp the auto-detected value (Python
        // returns early without mutating state).
        $agent->manualSetProxyUrl('');
        $url = $agent->getProxyUrlBase(['X-Forwarded-Proto' => 'http', 'X-Forwarded-Host' => 'example.com']);
        $this->assertSame('http://example.com', $url);
    }

    // ──────────────────────────────────────────────────────────────────
    // setDynamicConfigCallback — Python WebMixin.set_dynamic_config_callback parity
    // ──────────────────────────────────────────────────────────────────

    public function testSetDynamicConfigCallbackStoresCallback(): void
    {
        $agent = new \SignalWire\Agent\AgentBase(name: 'dc-agent', basicAuthUser: 'u', basicAuthPassword: 'p');

        $invocations = [];
        $cb = function ($q, $b, $h, $a) use (&$invocations): void {
            $invocations[] = [$q, $b, $h, $a];
        };

        $ret = $agent->setDynamicConfigCallback($cb);
        $this->assertSame($agent, $ret, 'fluent return');

        // The callback should be retrievable + invokable through the
        // accessor used by the request handler.
        $stored = $agent->getDynamicConfigCallback();
        $this->assertNotNull($stored);
        $stored(['q' => 1], ['b' => 2], ['h' => 3], $agent);

        $this->assertCount(1, $invocations);
        $this->assertSame(['q' => 1], $invocations[0][0]);
        $this->assertSame(['b' => 2], $invocations[0][1]);
        $this->assertSame(['h' => 3], $invocations[0][2]);
        $this->assertSame($agent, $invocations[0][3]);
    }

    public function testSetDynamicConfigCallbackReplaceable(): void
    {
        $agent = new \SignalWire\Agent\AgentBase(name: 'dc-agent-2', basicAuthUser: 'u', basicAuthPassword: 'p');

        $first = function () { return 'first'; };
        $second = function () { return 'second'; };

        $agent->setDynamicConfigCallback($first);
        $agent->setDynamicConfigCallback($second);
        $stored = $agent->getDynamicConfigCallback();
        $this->assertSame('second', $stored());
    }
}
