<?php

declare(strict_types=1);

namespace SignalWire\Tests;

use PHPUnit\Framework\TestCase;
use SignalWire\Agent\AgentBase;
use SignalWire\SWAIG\FunctionResult;
use SignalWire\Logging\Logger;
use SignalWire\SWML\Schema;

/**
 * Parity with signalwire-python:
 *   tests/unit/core/test_agent_base.py::TestAgentBaseTokenMethods::test_validate_tool_token
 *   tests/unit/core/test_agent_base.py::TestAgentBaseTokenMethods::test_create_tool_token
 *
 * Python's StateMixin._create_tool_token catches all exceptions and returns ""
 * on failure. validate_tool_token rejects unknown function names up front.
 */
class ToolTokenTest extends TestCase
{
    protected function setUp(): void
    {
        Logger::reset();
        Schema::reset();
    }

    protected function tearDown(): void
    {
        Logger::reset();
        Schema::reset();
    }

    private function makeAgent(): AgentBase
    {
        $a = new AgentBase([
            'name' => 'test-agent',
            'basic_auth_user' => 'testuser',
            'basic_auth_password' => 'testpass',
        ]);
        $a->defineTool(
            'test_tool',
            't',
            [],
            fn(array $args, array $raw) => new FunctionResult('ok'),
            true,
        );
        return $a;
    }

    public function testCreateToolTokenRoundTrip(): void
    {
        $a = $this->makeAgent();
        $token = $a->createToolToken('test_tool', 'call_123');
        $this->assertNotSame('', $token, 'expected SessionManager-issued token, got empty');
        $this->assertTrue(
            $a->validateToolToken('test_tool', $token, 'call_123'),
            'validateToolToken rejected the token we just created',
        );
    }

    public function testValidateToolTokenRejectsUnknownFunction(): void
    {
        $a = new AgentBase([
            'name' => 'test-agent',
            'basic_auth_user' => 'testuser',
            'basic_auth_password' => 'testpass',
        ]);
        $this->assertFalse(
            $a->validateToolToken('not_registered', 'any_token', 'call_123'),
            'expected false for unregistered function',
        );
    }

    public function testValidateToolTokenRejectsBadToken(): void
    {
        $a = $this->makeAgent();
        $this->assertFalse(
            $a->validateToolToken('test_tool', 'garbage_token_value', 'call_123'),
            'expected false for garbage token',
        );
    }

    public function testValidateToolTokenRejectsWrongCallId(): void
    {
        $a = $this->makeAgent();
        $token = $a->createToolToken('test_tool', 'call_A');
        $this->assertNotSame('', $token);
        $this->assertFalse(
            $a->validateToolToken('test_tool', $token, 'call_B'),
            'expected false when token bound to different call_id',
        );
    }
}
