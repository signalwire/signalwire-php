<?php

declare(strict_types=1);

namespace SignalWire\Tests;

use PHPUnit\Framework\TestCase;
use SignalWire\Agent\AgentBase;
use SignalWire\Livewire\Agent;
use SignalWire\Livewire\AgentHandoff;
use SignalWire\Livewire\AgentServer;
use SignalWire\Livewire\AgentSession;
use SignalWire\Livewire\ChatContext;
use SignalWire\Livewire\InferenceLLM;
use SignalWire\Livewire\InferenceSTT;
use SignalWire\Livewire\JobContext;
use SignalWire\Livewire\JobProcess;
use SignalWire\Livewire\LiveWire;
use SignalWire\Livewire\Plugins\CartesiaTTS;
use SignalWire\Livewire\Plugins\DeepgramSTT;
use SignalWire\Livewire\Plugins\OpenAILLM;
use SignalWire\Livewire\Plugins\SileroVAD;
use SignalWire\Livewire\Room;
use SignalWire\Livewire\RunContext;
use SignalWire\Livewire\StopResponse;
use SignalWire\Livewire\ToolError;
use SignalWire\SWAIG\FunctionResult;

/**
 * LiveWire subsystem — real-behavior tests (mirror the TS/Python shim behavior).
 */
class LivewireTest extends TestCase
{
    // ── Agent ────────────────────────────────────────────────────────────

    public function testAgentStoresInstructionsAndTools(): void
    {
        $tool = LiveWire::functionTool(fn (array $a, RunContext $c) => 'ok', name: 'greet', description: 'Greet');
        $agent = new Agent(instructions: 'You are helpful.', tools: [$tool]);

        $this->assertSame('You are helpful.', $agent->instructions);
        $this->assertCount(1, $agent->tools());
        $this->assertSame('greet', $agent->tools()[0]['name']);
        $this->assertNull($agent->getSession());
    }

    public function testAgentUpdateInstructionsAndTools(): void
    {
        $agent = new Agent(instructions: 'old');
        $agent->updateInstructions('new');
        $this->assertSame('new', $agent->instructions);

        $t = LiveWire::functionTool(fn ($a, $c) => 'x', name: 't1');
        $agent->updateTools([$t]);
        $this->assertSame('t1', $agent->tools()[0]['name']);
    }

    public function testAgentPipelineNodesAreNoops(): void
    {
        $agent = new Agent(instructions: 'p');
        // Nodes must be callable and not throw (no-op advisories); state is
        // untouched by them.
        $agent->sttNode();
        $agent->llmNode();
        $agent->ttsNode();
        $agent->onEnter();
        $agent->onExit();
        $agent->onUserTurnCompleted();
        $this->assertSame('p', $agent->instructions);
    }

    public function testAgentSessionRoundTripBindsAgent(): void
    {
        $agent = new Agent(instructions: 'hi');
        $session = new AgentSession();
        $session->start($agent);

        $this->assertSame($session, $agent->getSession());
        $sw = $session->getSwAgent();
        $this->assertInstanceOf(AgentBase::class, $sw);
    }

    // ── AgentSession.start → real AgentBase config mapping ───────────────

    public function testStartMapsLlmModelStrippingProviderPrefix(): void
    {
        $agent = new Agent(instructions: 'p', llm: 'openai/gpt-4o');
        $session = new AgentSession();
        $session->start($agent);

        $swml = $this->swmlJson($session);
        $this->assertStringContainsString('"model":"gpt-4o"', $swml);
    }

    public function testStartMapsEndpointingDelaysSecondsToMs(): void
    {
        // min 0.5s -> 500ms end_of_speech_timeout, max 3.0s -> 3000ms attention_timeout
        $agent = new Agent(instructions: 'p');
        $session = new AgentSession(minEndpointingDelay: 0.5, maxEndpointingDelay: 3.0);
        $session->start($agent);

        $swml = $this->swmlJson($session);
        $this->assertStringContainsString('"end_of_speech_timeout":500', $swml);
        $this->assertStringContainsString('"attention_timeout":3000', $swml);
    }

    public function testStartDisablesBargeWhenInterruptionsNotAllowed(): void
    {
        $agent = new Agent(instructions: 'p');
        $session = new AgentSession(allowInterruptions: false);
        $session->start($agent);

        $swml = $this->swmlJson($session);
        $this->assertStringContainsString('"barge_confidence":1', $swml);
    }

    public function testSayQueuedBeforeStartBecomesGreetingSection(): void
    {
        $agent = new Agent(instructions: 'Base prompt.');
        $session = new AgentSession();
        $session->say('Hello there, welcome!');
        $session->start($agent);

        $swml = $this->swmlJson($session);
        $this->assertStringContainsString('Hello there, welcome!', $swml);
    }

    public function testFunctionToolRegisteredAsSwaigAndExecutes(): void
    {
        $called = [];
        $tool = LiveWire::functionTool(
            handler: function (array $args, RunContext $ctx) use (&$called): string {
                $called['args'] = $args;
                $called['ctx']  = $ctx;
                return 'the time is now';
            },
            name: 'get_time',
            description: 'Return the current time.',
            parameters: ['tz' => ['type' => 'string', 'description' => 'timezone']],
        );

        $agent = new Agent(instructions: 'p', tools: [$tool]);
        $session = new AgentSession();
        $session->start($agent);
        $sw = $session->getSwAgent();
        $this->assertNotNull($sw);

        // The tool must be registered on the underlying AgentBase.
        $this->assertTrue($sw->hasFunction('get_time'));
        $fn = $sw->getFunction('get_time');
        $this->assertIsArray($fn);
        $this->assertSame('Return the current time.', $fn['purpose']);
        $this->assertIsArray($fn['argument']);
        $this->assertIsArray($fn['argument']['properties']);
        $this->assertArrayHasKey('tz', $fn['argument']['properties']);

        // And the handler must actually execute, wrapping the string result.
        $handler = $fn['_handler'];
        $this->assertIsCallable($handler);
        $result  = $handler(['tz' => 'UTC'], []);
        $this->assertInstanceOf(FunctionResult::class, $result);
        $this->assertSame(['tz' => 'UTC'], $called['args']);
        $this->assertInstanceOf(RunContext::class, $called['ctx']);
        $this->assertSame($session, $called['ctx']->session);
        $this->assertStringContainsString('the time is now', $result->toJson());
    }

    public function testUpdateAgentReplacesPrompt(): void
    {
        $a1 = new Agent(instructions: 'first');
        $session = new AgentSession();
        $session->start($a1);

        $a2 = new Agent(instructions: 'second prompt');
        $session->updateAgent($a2);
        $this->assertSame($session, $a2->getSession());

        $swml = $this->swmlJson($session);
        $this->assertStringContainsString('second prompt', $swml);
    }

    // ── userdata / RunContext ────────────────────────────────────────────

    public function testUserdataGetterSetterAndRunContextView(): void
    {
        $session = new AgentSession(userdata: ['user' => 'alice']);
        $this->assertSame(['user' => 'alice'], $session->getUserdata());

        $session->setUserdata(['user' => 'bob']);
        $this->assertSame(['user' => 'bob'], $session->getUserdata());

        // RunContext.userdata reflects the bound session's userdata.
        $ctx = new RunContext($session);
        $this->assertSame(['user' => 'bob'], $ctx->getUserdata());

        // With no session, userdata is an empty array.
        $bare = new RunContext();
        $this->assertSame([], $bare->getUserdata());
    }

    // ── ChatContext ──────────────────────────────────────────────────────

    public function testChatContextAppendChainsAndStoresMessages(): void
    {
        $ctx = new ChatContext();
        $ret = $ctx->append(role: 'system', text: 'be nice')->append(text: 'hi');

        $this->assertSame($ctx, $ret);
        $this->assertSame(
            [
                ['role' => 'system', 'content' => 'be nice'],
                ['role' => 'user', 'content' => 'hi'],
            ],
            $ctx->messages,
        );
    }

    // ── AgentHandoff / exceptions ────────────────────────────────────────

    public function testAgentHandoffCarriesAgentAndReturns(): void
    {
        $target = new Agent(instructions: 'target');
        $h = new AgentHandoff($target, 'done');
        $this->assertSame($target, $h->agent);
        $this->assertSame('done', $h->returns);
    }

    public function testStopResponseAndToolErrorAreExceptions(): void
    {
        $this->assertInstanceOf(\Throwable::class, new StopResponse('stop'));
        $this->assertInstanceOf(\Throwable::class, new ToolError('boom'));
        $this->expectException(ToolError::class);
        throw new ToolError('boom');
    }

    // ── AgentServer / run_app ────────────────────────────────────────────

    public function testAgentServerRtcSessionBareRegistration(): void
    {
        $server = new AgentServer();
        $fn = static function (JobContext $ctx): void {
        };
        $ret = $server->rtcSession($fn);
        $this->assertNull($ret);
        $this->assertSame($fn, $server->getEntrypoint());
    }

    public function testAgentServerRtcSessionParameterizedDecorator(): void
    {
        $server = new AgentServer();
        $register = $server->rtcSession(agentName: 'my-agent');
        $this->assertIsCallable($register);

        $fn = static function (JobContext $ctx): void {
        };
        $register($fn);
        $this->assertSame($fn, $server->getEntrypoint());
    }

    public function testRunAppInvokesSetupAndEntrypointAndStartsAgent(): void
    {
        $order = [];
        $server = new AgentServer();
        $server->setupFnc = function (JobProcess $proc) use (&$order): void {
            $order[] = 'setup';
            $proc->userdata['warm'] = true;
        };

        $started = new class ('LiveWireAgent') extends AgentBase {
            public bool $ran = false;
            public function run(): void
            {
                $this->ran = true;
            }
        };

        $server->rtcSession(function (JobContext $ctx) use (&$order, $started): void {
            $order[] = 'entry';
            // Entry binds a started agent onto the ctx for run_app to start.
            $ctx->agent = $started;
        });

        // Suppress the banner/tip stderr noise during the test.
        LiveWire::runApp($server);

        $this->assertSame(['setup', 'entry'], $order);
        $this->assertTrue($started->ran);
    }

    // ── Inference stubs / plugins ────────────────────────────────────────

    public function testInferenceStubsCaptureModel(): void
    {
        $this->assertSame('nova-2', (new InferenceSTT('nova-2'))->model);
        $this->assertSame('gpt-4o', (new InferenceLLM('gpt-4o'))->model);
    }

    public function testPluginsConstructAndOpenAiCapturesModel(): void
    {
        // All plugin stubs must construct without error.
        new DeepgramSTT(['api_key' => 'x']);
        new CartesiaTTS();
        $llm = new OpenAILLM(['model' => 'gpt-4o-mini']);
        $this->assertSame('gpt-4o-mini', $llm->model);

        // SileroVAD.load() factory returns a fresh instance.
        $vad = SileroVAD::load();
        $this->assertInstanceOf(SileroVAD::class, $vad);
    }

    public function testRoomHasConstantName(): void
    {
        $this->assertSame('livewire-room', (new Room())->name);
    }

    public function testJobContextProvidesRoomAndProc(): void
    {
        $ctx = new JobContext();
        $this->assertInstanceOf(Room::class, $ctx->room);
        $this->assertInstanceOf(JobProcess::class, $ctx->proc);
        // connect / waitForParticipant are no-ops.
        $ctx->connect();
        $ctx->waitForParticipant();
        $this->assertSame([], $ctx->proc->userdata);
    }

    /** Render the session's underlying AgentBase SWML as a compact JSON string. */
    private function swmlJson(AgentSession $session): string
    {
        $sw = $session->getSwAgent();
        $this->assertNotNull($sw);
        return (string) json_encode($sw->renderSwml());
    }
}
