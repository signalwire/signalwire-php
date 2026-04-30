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
        $svc = new class(['name' => 't']) extends Service {
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
        $svc = new Service(['name' => 't']);
        $this->assertNull($svc->onSwmlRequest(null, null));
    }

    public function testOnRequestDefaultReturnsNull(): void
    {
        $svc = new Service(['name' => 't']);
        $this->assertNull($svc->onRequest(null, null));
    }

    public function testOnRequestPassesNullsToHook(): void
    {
        $svc = new class(['name' => 't']) extends Service {
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
}
