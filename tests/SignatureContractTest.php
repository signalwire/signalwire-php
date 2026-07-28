<?php

declare(strict_types=1);

namespace SignalWire\Tests;

use PHPUnit\Framework\TestCase;
use SignalWire\Agent\AgentBase;
use SignalWire\Relay\Call;
use SignalWire\Relay\Client as RelayClient;
use SignalWire\REST\HttpClient;
use SignalWire\Server\AgentServer;
use SignalWire\Skills\SkillManager;
use SignalWire\SWAIG\FunctionResult;
use SignalWire\SWML\Service;

/**
 * Parameter-CONTRACT pins: `required`-ness, declared DEFAULT VALUES, and
 * parameter ORDER are behaviour a caller observes, not idiom, so they must not
 * drift from the Python reference.
 *
 * These are reflection assertions ON PURPOSE. A behavioural test that passes an
 * argument explicitly does NOT cover that argument's default — it exercises the
 * supplied value and would keep passing if the default were changed or removed.
 * The wire/behaviour consequences of each fix are covered in the per-subject
 * suites (DataMapTest, FunctionResultTest, InboundCallMockTest,
 * AgentServerTest, QueueAndDigitMockTest, AgentBaseTest); this class is the
 * backstop that fails the moment a default is re-invented or a required param
 * is re-defaulted.
 *
 * Every expectation below cites the reference line it mirrors.
 */
class SignatureContractTest extends TestCase
{
    /**
     * @param class-string $class
     * @return array<string, \ReflectionParameter>
     */
    private function params(string $class, string $method): array
    {
        $out = [];
        foreach ((new \ReflectionMethod($class, $method))->getParameters() as $p) {
            $out[$p->getName()] = $p;
        }
        return $out;
    }

    /** @param class-string $class */
    private function assertRequired(string $class, string $method, string $param): void
    {
        $p = $this->params($class, $method)[$param] ?? null;
        $this->assertNotNull($p, "{$class}::{$method} has no \${$param}");
        $this->assertFalse(
            $p->isOptional(),
            "{$class}::{$method}(\${$param}) must be REQUIRED — the reference "
            . 'declares it with no default, so a port default is an invented value.'
        );
    }

    /** @param class-string $class */
    private function assertDefault(string $class, string $method, string $param, mixed $expected): void
    {
        $p = $this->params($class, $method)[$param] ?? null;
        $this->assertNotNull($p, "{$class}::{$method} has no \${$param}");
        $this->assertTrue(
            $p->isDefaultValueAvailable(),
            "{$class}::{$method}(\${$param}) must have a default"
        );
        $this->assertSame(
            $expected,
            $p->getDefaultValue(),
            "{$class}::{$method}(\${$param}) default must match the reference"
        );
    }

    /**
     * @param class-string $class
     * @param list<string> $expected
     */
    private function assertOrder(string $class, string $method, array $expected): void
    {
        $this->assertSame(
            $expected,
            array_keys($this->params($class, $method)),
            "{$class}::{$method} parameter ORDER must match the reference"
        );
    }

    // ------------------------------------------------------------------
    // default-invented — the reference REQUIRES the param; the port must not
    // supply a value when the caller omits it.
    // ------------------------------------------------------------------

    /** ai_config_mixin.py:462 — add_internal_filler(function_name, language_code, fillers) */
    public function testAddInternalFillerRequiresLanguageCodeAndFillers(): void
    {
        $this->assertRequired(AgentBase::class, 'addInternalFiller', 'languageCode');
        $this->assertRequired(AgentBase::class, 'addInternalFiller', 'fillers');
    }

    /** swml_service.py:993 — handle_request(method, url, headers, body=None) */
    public function testHandleRequestRequiresHeaders(): void
    {
        $this->assertRequired(Service::class, 'handleRequest', 'headers');
        $this->assertDefault(Service::class, 'handleRequest', 'body', null);
    }

    /** relay/call.py:1603 — queue_leave(queue_name, *, ...) */
    public function testQueueLeaveRequiresQueueName(): void
    {
        $this->assertRequired(Call::class, 'queueLeave', 'queue_name');
    }

    /** relay/client.py:486 — execute(method, params) */
    public function testRelayClientExecuteRequiresParams(): void
    {
        $this->assertRequired(RelayClient::class, 'execute', 'params');
    }

    // ------------------------------------------------------------------
    // required-flip — the reference DEFAULTS the param; the port must too.
    // ------------------------------------------------------------------

    /** agent_server.py:750 — serve_static_files(directory, route="/") */
    public function testServeStaticFilesRouteDefaultsToRoot(): void
    {
        $this->assertDefault(AgentServer::class, 'serveStaticFiles', 'urlPrefix', '/');
    }

    /** function_result.py:706 — switch_context(system_prompt=None, user_prompt=None, ...) */
    public function testSwitchContextPromptsAreOptional(): void
    {
        $this->assertDefault(FunctionResult::class, 'switchContext', 'systemPrompt', null);
        $this->assertDefault(FunctionResult::class, 'switchContext', 'userPrompt', null);
    }

    /** tool_mixin.py:234 — on_function_call(name, args, raw_data=None) */
    public function testOnFunctionCallRawDataDefaultsToNull(): void
    {
        $this->assertDefault(Service::class, 'onFunctionCall', 'rawData', null);
    }

    /** swml_service.py:918 — register_routing_callback(callback_fn, path="/sip") */
    public function testRegisterRoutingCallbackPathDefaultsToSip(): void
    {
        $this->assertOrder(Service::class, 'registerRoutingCallback', ['callback', 'path']);
        $this->assertDefault(Service::class, 'registerRoutingCallback', 'path', '/sip');
    }

    /** relay/call.py:1567 — user_event(*, event=None, **kwargs) */
    public function testUserEventEventIsOptional(): void
    {
        $this->assertDefault(Call::class, 'userEvent', 'event', null);
    }

    // ------------------------------------------------------------------
    // default-mismatch — the port must supply the REFERENCE's value.
    // ------------------------------------------------------------------

    /** relay/call.py:542 — hangup(reason="hangup") */
    public function testHangupReasonDefaultsToHangup(): void
    {
        $this->assertDefault(Call::class, 'hangup', 'reason', 'hangup');
    }

    /** relay/call.py:114 and relay/message.py:93 — wait(timeout=None) = wait forever */
    public function testWaitTimeoutsDefaultToNullNotThirtySeconds(): void
    {
        $this->assertDefault(\SignalWire\Relay\Action::class, 'wait', 'timeout', null);
        $this->assertDefault(\SignalWire\Relay\Message::class, 'wait', 'timeout', null);
    }

    /** function_result.py:140 — connect(destination, final=True, from_addr=None) */
    public function testConnectFromAddrDefaultsToNull(): void
    {
        $this->assertDefault(FunctionResult::class, 'connect', 'from', null);
    }

    /** function_result.py:752 — send_sms(..., media=None, tags=None, ...) */
    public function testSendSmsCollectionsDefaultToNull(): void
    {
        $this->assertDefault(FunctionResult::class, 'sendSms', 'media', null);
        $this->assertDefault(FunctionResult::class, 'sendSms', 'tags', null);
    }

    /** skill_mixin.py:24 — add_skill(skill_name, params=None) */
    public function testAddSkillParamsDefaultToNull(): void
    {
        $this->assertDefault(AgentBase::class, 'addSkill', 'params', null);
    }

    /** rest/_base.py:306,314 — put/patch(path, body=None, request_options=None) */
    public function testHttpClientBodyDefaultsToNull(): void
    {
        $this->assertDefault(HttpClient::class, 'put', 'data', null);
        $this->assertDefault(HttpClient::class, 'patch', 'data', null);
    }

    // ------------------------------------------------------------------
    // Parameter ORDER — matched BY POSITION by the cross-port drift checker,
    // so a swapped pair is a real contract divergence even when both params
    // are individually correct.
    // ------------------------------------------------------------------

    /** skill_manager.py:26 — load_skill(skill_name, skill_class=None, params=None) */
    public function testLoadSkillParameterOrderMatchesReference(): void
    {
        $this->assertOrder(SkillManager::class, 'loadSkill', ['skillName', 'skillClass', 'params']);
        $this->assertDefault(SkillManager::class, 'loadSkill', 'skillClass', null);
        $this->assertDefault(SkillManager::class, 'loadSkill', 'params', null);
    }
}
