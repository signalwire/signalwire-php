<?php

declare(strict_types=1);

namespace SignalWire\Tests\Relay;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Test;
use SignalWire\Relay\Call;
use SignalWire\Relay\Client as RelayClient;
use SignalWire\Relay\Event;

/**
 * Real-mock-backed tests for inbound calls (server-initiated).
 *
 * Ported from signalwire-python/tests/unit/relay/test_inbound_call_mock.py.
 * The mock's ``POST /__mock__/inbound_call`` endpoint pushes a
 * ``calling.call.receive`` frame to the SDK — exactly what the production
 * RELAY server emits when a phone call arrives in a context the SDK
 * subscribed to. Each test asserts both the SDK behaviour AND the journal.
 */
class InboundCallMockTest extends TestCase
{
    private RelayHarness $mock;
    private RelayClient $client;

    protected function setUp(): void
    {
        $this->mock = MockTest::harness();
        $this->mock->reset();
        $this->client = MockTest::client();
    }

    protected function tearDown(): void
    {
        try {
            $this->client->disconnect();
        } catch (\Throwable) {
        }
    }

    /**
     * Build a signalwire.event(calling.call.state) frame the harness can
     * push at the SDK.
     *
     * @return array<string,mixed>
     */
    private static function stateFrame(string $callId, string $callState, string $tag = ''): array
    {
        return [
            'jsonrpc' => '2.0',
            'id'      => bin2hex(random_bytes(8)),
            'method'  => 'signalwire.event',
            'params'  => [
                'event_type' => 'calling.call.state',
                'params'     => [
                    'call_id'    => $callId,
                    'node_id'    => 'mock-relay-node-1',
                    'tag'        => $tag,
                    'call_state' => $callState,
                    'direction'  => 'inbound',
                    'device'     => [
                        'type'   => 'phone',
                        'params' => [
                            'from_number' => '+15551110000',
                            'to_number'   => '+15552220000',
                        ],
                    ],
                ],
            ],
        ];
    }

    // ------------------------------------------------------------------
    // Basic inbound-call handler dispatch
    // ------------------------------------------------------------------

    #[Test]
    public function onCallHandlerFiresWithCallObject(): void
    {
        $seen = new \ArrayObject();
        $this->client->onCall(function (Call $call) use ($seen): void {
            $seen[] = $call;
        });

        $this->mock->inboundCall([
            'call_id'     => 'c-handler',
            'from_number' => '+15551110000',
            'to_number'   => '+15552220000',
            'auto_states' => ['created'],
        ]);
        $arrived = MockTest::pumpUntil(
            $this->client,
            fn() => count($seen) >= 1,
            5.0,
        );
        $this->assertTrue($arrived);
        $this->assertCount(1, $seen);
        $first = $seen[0];
        $this->assertInstanceOf(Call::class, $first);
        $this->assertSame('c-handler', $first->callId);

        // Journal: the server pushed a calling.call.receive event.
        $sends = $this->mock->journal()->send('calling.call.receive');
        $this->assertNotEmpty($sends);
    }

    #[Test]
    public function inboundCallObjectHasCorrectCallIdAndDirection(): void
    {
        $captured = new \ArrayObject();
        $this->client->onCall(function (Call $call) use ($captured): void {
            $captured['call_id'] = $call->callId;
            $captured['direction'] = $call->direction;
        });

        $this->mock->inboundCall(['call_id' => 'c-dir', 'auto_states' => ['created']]);
        $arrived = MockTest::pumpUntil(
            $this->client,
            fn() => isset($captured['call_id']),
            5.0,
        );
        $this->assertTrue($arrived);
        $this->assertSame('c-dir', $captured['call_id']);
        $this->assertSame('inbound', $captured['direction']);

        $this->assertNotEmpty($this->mock->journal()->send('calling.call.receive'));
    }

    #[Test]
    public function inboundCallCarriesFromToInDevice(): void
    {
        $captured = new \ArrayObject();
        $this->client->onCall(function (Call $call) use ($captured): void {
            $captured['device'] = $call->device;
        });

        $this->mock->inboundCall([
            'call_id'     => 'c-from-to',
            'from_number' => '+15551112233',
            'to_number'   => '+15554445566',
            'auto_states' => ['created'],
        ]);
        $arrived = MockTest::pumpUntil(
            $this->client,
            fn() => isset($captured['device']),
            5.0,
        );
        $this->assertTrue($arrived);
        $params = $captured['device']['params'] ?? [];
        $this->assertSame('+15551112233', $params['from_number'] ?? null);
        $this->assertSame('+15554445566', $params['to_number'] ?? null);

        $this->assertNotEmpty($this->mock->journal()->send('calling.call.receive'));
    }

    #[Test]
    public function inboundCallInitialStateIsCreated(): void
    {
        $captured = new \ArrayObject();
        $this->client->onCall(function (Call $call) use ($captured): void {
            $captured['state'] = $call->state;
        });

        $this->mock->inboundCall(['call_id' => 'c-state', 'auto_states' => ['created']]);
        $arrived = MockTest::pumpUntil(
            $this->client,
            fn() => isset($captured['state']),
            5.0,
        );
        $this->assertTrue($arrived);
        $this->assertSame('created', $captured['state']);
        $this->assertNotEmpty($this->mock->journal()->send('calling.call.receive'));
    }

    // ------------------------------------------------------------------
    // Handler answers — calling.answer journaled
    // ------------------------------------------------------------------

    #[Test]
    public function answerInHandlerJournalsCallingAnswer(): void
    {
        $answered = new \ArrayObject();
        $this->client->onCall(function (Call $call) use ($answered): void {
            $call->answer();
            $answered[] = true;
        });

        $this->mock->inboundCall(['call_id' => 'c-ans', 'auto_states' => ['created']]);
        $done = MockTest::pumpUntil(
            $this->client,
            fn() => count($answered) >= 1,
            5.0,
        );
        $this->assertTrue($done);

        $answers = $this->mock->journal()->recv('calling.answer');
        $this->assertNotEmpty($answers, 'no calling.answer in journal');
        $this->assertSame('c-ans', $answers[count($answers) - 1]->frame['params']['call_id'] ?? null);
    }

    #[Test]
    public function answerThenStateEventAdvancesCallState(): void
    {
        $captured = new \ArrayObject();
        $this->client->onCall(function (Call $call) use ($captured): void {
            $captured['call'] = $call;
            $call->answer();
        });

        $this->mock->inboundCall(['call_id' => 'c-ans-state', 'auto_states' => ['created']]);
        $arrived = MockTest::pumpUntil(
            $this->client,
            fn() => isset($captured['call']),
            5.0,
        );
        $this->assertTrue($arrived);

        // Push a state(answered) update.
        $this->mock->push(self::stateFrame('c-ans-state', 'answered'));
        /** @var Call $call */
        $call = $captured['call'];
        $advanced = MockTest::pumpUntil(
            $this->client,
            fn() => $call->state === 'answered',
            5.0,
        );
        $this->assertTrue($advanced);
        $this->assertSame('answered', $call->state);

        $this->assertNotEmpty($this->mock->journal()->recv('calling.answer'));
    }

    // ------------------------------------------------------------------
    // Handler hangs up / passes
    // ------------------------------------------------------------------

    #[Test]
    public function hangupInHandlerJournalsCallingEnd(): void
    {
        $hung = new \ArrayObject();
        $this->client->onCall(function (Call $call) use ($hung): void {
            $call->hangup('busy');
            $hung[] = true;
        });

        $this->mock->inboundCall(['call_id' => 'c-hangup', 'auto_states' => ['created']]);
        $done = MockTest::pumpUntil(
            $this->client,
            fn() => count($hung) >= 1,
            5.0,
        );
        $this->assertTrue($done);

        $ends = $this->mock->journal()->recv('calling.end');
        $this->assertNotEmpty($ends, 'no calling.end in journal');
        $p = $ends[count($ends) - 1]->frame['params'] ?? [];
        $this->assertSame('c-hangup', $p['call_id'] ?? null);
        $this->assertSame('busy', $p['reason'] ?? null);
    }

    #[Test]
    public function passInHandlerJournalsCallingPass(): void
    {
        $passed = new \ArrayObject();
        $this->client->onCall(function (Call $call) use ($passed): void {
            $call->pass();
            $passed[] = true;
        });

        $this->mock->inboundCall(['call_id' => 'c-pass', 'auto_states' => ['created']]);
        $done = MockTest::pumpUntil(
            $this->client,
            fn() => count($passed) >= 1,
            5.0,
        );
        $this->assertTrue($done);

        $passes = $this->mock->journal()->recv('calling.pass');
        $this->assertNotEmpty($passes);
        $this->assertSame(
            'c-pass',
            $passes[count($passes) - 1]->frame['params']['call_id'] ?? null,
        );
    }

    // ------------------------------------------------------------------
    // Multiple inbound calls — independent state
    // ------------------------------------------------------------------

    #[Test]
    public function multipleInboundCallsInSequenceEachUniqueObject(): void
    {
        $seen = new \ArrayObject();
        $this->client->onCall(function (Call $call) use ($seen): void {
            $seen[] = $call;
        });

        $this->mock->inboundCall(['call_id' => 'c-seq-1', 'auto_states' => ['created']]);
        MockTest::pumpUntil($this->client, fn() => count($seen) >= 1, 5.0);
        $this->mock->inboundCall(['call_id' => 'c-seq-2', 'auto_states' => ['created']]);
        $both = MockTest::pumpUntil($this->client, fn() => count($seen) >= 2, 5.0);

        $this->assertTrue($both);
        $this->assertCount(2, $seen);
        $this->assertSame('c-seq-1', $seen[0]->callId);
        $this->assertSame('c-seq-2', $seen[1]->callId);
        $this->assertNotSame($seen[0], $seen[1]);

        // Two receives showed up in the server outbound journal.
        $this->assertGreaterThanOrEqual(
            2,
            count($this->mock->journal()->send('calling.call.receive')),
        );
    }

    #[Test]
    public function multipleInboundCallsNoStateBleed(): void
    {
        $callsById = new \ArrayObject();
        $this->client->onCall(function (Call $call) use ($callsById): void {
            $callsById[$call->callId] = $call;
            $call->answer();
        });

        $this->mock->inboundCall(['call_id' => 'cb-1', 'auto_states' => ['created']]);
        MockTest::pumpUntil($this->client, fn() => isset($callsById['cb-1']), 5.0);
        $this->mock->inboundCall(['call_id' => 'cb-2', 'auto_states' => ['created']]);
        $both = MockTest::pumpUntil(
            $this->client,
            fn() => isset($callsById['cb-1'], $callsById['cb-2']),
            5.0,
        );
        $this->assertTrue($both);

        // Push answered to ONLY cb-1.
        $this->mock->push(self::stateFrame('cb-1', 'answered'));
        /** @var Call $cb1 */
        $cb1 = $callsById['cb-1'];
        /** @var Call $cb2 */
        $cb2 = $callsById['cb-2'];
        $advanced = MockTest::pumpUntil(
            $this->client,
            fn() => $cb1->state === 'answered',
            5.0,
        );
        $this->assertTrue($advanced);
        $this->assertSame('answered', $cb1->state);
        $this->assertNotSame('answered', $cb2->state);

        $this->assertGreaterThanOrEqual(
            2,
            count($this->mock->journal()->recv('calling.answer')),
        );
    }

    // ------------------------------------------------------------------
    // Scripted state sequences
    // ------------------------------------------------------------------

    #[Test]
    public function scriptedStateSequenceAdvancesCall(): void
    {
        $captured = new \ArrayObject();
        $this->client->onCall(function (Call $call) use ($captured): void {
            $captured['call'] = $call;
            $call->answer();
        });

        $this->mock->inboundCall(['call_id' => 'c-scripted', 'auto_states' => ['created']]);
        MockTest::pumpUntil($this->client, fn() => isset($captured['call']), 5.0);

        $this->mock->push(self::stateFrame('c-scripted', 'answered'));
        $this->mock->push(self::stateFrame('c-scripted', 'ended'));

        /** @var Call $call */
        $call = $captured['call'];
        $reached = MockTest::pumpUntil(
            $this->client,
            fn() => $call->state === 'ended',
            5.0,
        );
        $this->assertTrue($reached);
        $this->assertSame('ended', $call->state);
        // Ended calls are dropped from the registry.
        $this->assertArrayNotHasKey('c-scripted', $this->client->getCalls());

        $this->assertNotEmpty($this->mock->journal()->recv('calling.answer'));
    }

    // ------------------------------------------------------------------
    // Handler patterns: completion + raise
    // ------------------------------------------------------------------

    #[Test]
    public function syncHandlerCompletesNormally(): void
    {
        $captured = new \ArrayObject();
        $this->client->onCall(function (Call $call) use ($captured): void {
            $captured['call_id'] = $call->callId;
        });

        $this->mock->inboundCall(['call_id' => 'c-sync', 'auto_states' => ['created']]);
        $arrived = MockTest::pumpUntil(
            $this->client,
            fn() => isset($captured['call_id']),
            5.0,
        );
        $this->assertTrue($arrived);
        $this->assertSame('c-sync', $captured['call_id']);

        $this->assertNotEmpty($this->mock->journal()->send('calling.call.receive'));
    }

    #[Test]
    public function handlerExceptionDoesNotCrashClient(): void
    {
        $fired = new \ArrayObject();
        $this->client->onCall(function (Call $call) use ($fired): void {
            $fired[] = true;
            throw new \RuntimeException('intentional from handler');
        });

        $this->mock->inboundCall(['call_id' => 'c-raise', 'auto_states' => ['created']]);
        $arrived = MockTest::pumpUntil(
            $this->client,
            fn() => count($fired) >= 1,
            5.0,
        );
        $this->assertTrue($arrived);
        // Drain anything else briefly.
        MockTest::pumpFor($this->client, 100);
        // Client is still alive.
        $this->assertTrue($this->client->connected);

        $this->assertNotEmpty($this->mock->journal()->send('calling.call.receive'));
    }

    // ------------------------------------------------------------------
    // scenario_play — full inbound flow
    // ------------------------------------------------------------------

    #[Test]
    public function scenarioPlayFullInboundFlow(): void
    {
        $captured = new \ArrayObject();
        $this->client->onCall(function (Call $call) use ($captured): void {
            $captured['call'] = $call;
            $call->answer();
        });

        // Run the scenario in a separate process so the SDK's recv loop
        // (synchronous, on this thread) can drain the inbound frames as
        // they're pushed. Without a child the scenario blocks the test
        // thread on its expect_recv waiting for calling.answer that the
        // SDK can't ship until we read.
        $http = $this->mock->httpUrl();
        $cmd = sprintf(
            'curl -fsS -X POST -H "Content-Type: application/json" '
            . '-d %s %s/__mock__/scenario_play > /tmp/scenario_play.out 2>&1 & echo $!',
            escapeshellarg(json_encode([
                ['push' => ['frame' => [
                    'jsonrpc' => '2.0',
                    'id'      => bin2hex(random_bytes(8)),
                    'method'  => 'signalwire.event',
                    'params'  => [
                        'event_type' => 'calling.call.receive',
                        'params'     => [
                            'call_id'    => 'c-scen',
                            'node_id'    => 'mock-relay-node-1',
                            'tag'        => '',
                            'call_state' => 'created',
                            'direction'  => 'inbound',
                            'device'     => [
                                'type'   => 'phone',
                                'params' => [
                                    'from_number' => '+15551110000',
                                    'to_number'   => '+15552220000',
                                ],
                            ],
                            'context'    => 'default',
                        ],
                    ],
                ]]],
                ['expect_recv' => ['method' => 'calling.answer', 'timeout_ms' => 5000]],
                ['push' => ['frame' => self::stateFrame('c-scen', 'answered')]],
                ['sleep_ms' => 50],
                ['push' => ['frame' => self::stateFrame('c-scen', 'ended')]],
            ], JSON_THROW_ON_ERROR)),
            escapeshellarg($http),
        );
        $pid = trim((string) shell_exec($cmd));
        $this->assertNotEmpty($pid, 'failed to fork scenario_play curl');

        // Drive the SDK's recv loop synchronously while the scenario runs.
        /** @var Call|null $call */
        $reached = MockTest::pumpUntil(
            $this->client,
            function () use ($captured): bool {
                $c = $captured['call'] ?? null;
                return $c instanceof Call && $c->state === 'ended';
            },
            15.0,
        );
        $this->assertTrue($reached, 'scenario did not advance call to "ended"');

        // Confirm the scenario itself completed.
        $deadline = microtime(true) + 5.0;
        $out = '';
        while (microtime(true) < $deadline) {
            if (file_exists('/tmp/scenario_play.out')) {
                $out = (string) file_get_contents('/tmp/scenario_play.out');
                if (str_contains($out, 'completed') || str_contains($out, 'timeout')) {
                    break;
                }
            }
            usleep(100_000);
        }
        $this->assertStringContainsString(
            'completed',
            $out,
            'scenario_play status did not reach "completed"',
        );

        $this->assertNotEmpty($this->mock->journal()->recv('calling.answer'));
    }

    // ------------------------------------------------------------------
    // Wire shape — calling.call.receive
    // ------------------------------------------------------------------

    #[Test]
    public function inboundCallJournalSendRecordsCallingCallReceive(): void
    {
        $fired = new \ArrayObject();
        $this->client->onCall(function () use ($fired): void {
            $fired[] = true;
        });

        $this->mock->inboundCall(['call_id' => 'c-wire', 'auto_states' => ['created']]);
        MockTest::pumpUntil($this->client, fn() => count($fired) >= 1, 5.0);

        $sends = $this->mock->journal()->send('calling.call.receive');
        $this->assertNotEmpty($sends);
        $inner = $sends[count($sends) - 1]->frame['params']['params'] ?? [];
        $this->assertSame('c-wire', $inner['call_id'] ?? null);
        $this->assertSame('inbound', $inner['direction'] ?? null);
    }

    // ------------------------------------------------------------------
    // Inbound without a registered handler — does not crash
    // ------------------------------------------------------------------

    #[Test]
    public function inboundWithoutHandlerDoesNotCrash(): void
    {
        // No on_call registered on $this->client.
        $this->mock->inboundCall(['call_id' => 'c-nohandler', 'auto_states' => ['created']]);
        MockTest::pumpFor($this->client, 200);
        $this->assertTrue($this->client->connected);

        $this->assertNotEmpty($this->mock->journal()->send('calling.call.receive'));
    }
}
