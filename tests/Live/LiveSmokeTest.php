<?php

declare(strict_types=1);

namespace SignalWire\Tests\Live;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use SignalWire\Relay\Client as RelayClient;
use SignalWire\REST\RestClient;
use SignalWire\SWML\Service;

/**
 * Real-server smoke — plan a-bar 6.5.
 *
 * The ONLY check that catches mock↔production drift the AUTHORITATIVE_SPEC_SOURCING
 * program hasn't closed yet: it hits the REAL SignalWire platform, not the mock.
 * Opt-in and creds-gated, so it is a clean no-op without secrets:
 *   * SWSDK_LIVE_TESTS=1 must be set (the shared cross-port opt-in convention), AND
 *   * SIGNALWIRE_PROJECT_ID / SIGNALWIRE_API_TOKEN / SIGNALWIRE_SPACE must be present.
 * Absent either, every test skips cleanly (never fails) — safe on any fork / a
 * secret-less run. Grouped `live` so the normal suite excludes it by default.
 *
 * Coverage (deliberately minimal — a liveness probe, not a parity suite):
 *   1. auth + one REST list   (RestClient → phoneNumbers → list)
 *   2. one SWML render        (Service → renderSwml, no network)
 *   3. one RELAY connect      (Relay\Client → connect + authenticate)
 */
#[Group('live')]
final class LiveSmokeTest extends TestCase
{
    private string $project = '';
    private string $token = '';
    private string $space = '';

    protected function setUp(): void
    {
        if (getenv('SWSDK_LIVE_TESTS') !== '1') {
            self::markTestSkipped('live smoke is opt-in: set SWSDK_LIVE_TESTS=1 to enable');
        }
        $this->project = (string) getenv('SIGNALWIRE_PROJECT_ID');
        $this->token = (string) getenv('SIGNALWIRE_API_TOKEN');
        $this->space = (string) getenv('SIGNALWIRE_SPACE');
        if ($this->project === '' || $this->token === '' || $this->space === '') {
            self::markTestSkipped(
                'live smoke needs SIGNALWIRE_PROJECT_ID / SIGNALWIRE_API_TOKEN / SIGNALWIRE_SPACE'
            );
        }
    }

    #[Test]
    public function restListAgainstTheRealPlatform(): void
    {
        $client = new RestClient($this->project, $this->token, $this->space);
        // A successful auth + list against the real platform returns without
        // throwing; iterating the first page proves the response is traversable.
        $result = $client->phoneNumbers()->list();
        foreach ($result as $_row) {
            break; // one row is enough to prove the list is traversable
        }
        $this->addToAssertionCount(1);
    }

    #[Test]
    public function swmlRenders(): void
    {
        // No network — proves the document renders end-to-end.
        $svc = new Service(name: 'smoke', route: '/smoke');
        $swml = $svc->renderSwml();
        $this->assertArrayHasKey('sections', $swml);
    }

    #[Test]
    public function relayConnectAndAuthenticate(): void
    {
        $client = new RelayClient([
            'project'  => $this->project,
            'token'    => $this->token,
            'host'     => $this->space,
            'contexts' => ['default'],
        ]);
        // connect() + authenticate() throw on failure; reaching disconnect proves
        // the RELAY handshake against the real platform succeeded.
        $client->connect();
        $client->authenticate();
        $client->disconnect();
        $this->addToAssertionCount(1);
    }
}
