<?php

declare(strict_types=1);

namespace SignalWire\Tests;

use PHPUnit\Framework\TestCase;
use SignalWire\Agent\AgentBase;
use SignalWire\SWAIG\FunctionResult;

/**
 * Executes the SWAIG tool handlers of the shipped examples.
 *
 * Why this suite exists: a shipped example called
 * `FunctionResult::addAction('set_global_data', [...])` with TWO arguments,
 * against a PHP method whose signature takes ONE array. That is a fatal
 * ArgumentCountError — but {@see \SignalWire\SWML\Service::onFunctionCall()}
 * catches \Throwable and converts any handler failure into a
 * `FunctionResult("Error executing function '<name>': …")`. So the defect never
 * crashed anything: the tool just silently answered with an error string
 * instead of doing its job, and both the test suite and a manual smoke run
 * looked fine.
 *
 * Loading an example proves it parses; only INVOKING its handlers proves they
 * run. These tests therefore call the handler and assert the result is the real
 * answer — explicitly rejecting the swallowed-error response that masked the
 * bug.
 */
class ExampleToolHandlerTest extends TestCase
{
    private static function repoRoot(): string
    {
        return dirname(__DIR__);
    }

    /**
     * Load an example that follows the `build*()`-function convention and
     * return the configured agent, without starting its HTTP server.
     */
    private static function loadAgent(string $relPath, string $builder): AgentBase
    {
        require_once self::repoRoot() . '/' . $relPath;
        self::assertTrue(
            function_exists($builder),
            "{$relPath} must define {$builder}() so it can be loaded in-process",
        );
        /** @var AgentBase $agent */
        $agent = $builder();
        return $agent;
    }

    /**
     * Invoke a registered tool and fail loudly if the SDK swallowed a fatal.
     *
     * @param array<string,mixed> $args
     */
    private static function callTool(AgentBase $agent, string $name, array $args): FunctionResult
    {
        $result = $agent->onFunctionCall($name, $args, []);
        self::assertInstanceOf(
            FunctionResult::class,
            $result,
            "tool '{$name}' is not registered on the agent",
        );

        // onFunctionCall() catches \Throwable and returns this shape. Without
        // this assertion an ArgumentCountError, TypeError or Error inside the
        // handler reads as a perfectly ordinary FunctionResult.
        $response = $result->getResponse();
        self::assertStringNotContainsString(
            "Error executing function '{$name}'",
            $response,
            "tool '{$name}' threw inside its handler; the SDK swallowed it into "
            . "the response string: {$response}",
        );

        return $result;
    }

    public function testSimpleAgentWeatherToolReturnsTheForecastNotASwallowedError(): void
    {
        $agent = self::loadAgent('examples/simple_agent.php', 'buildSimpleAgent');

        $result = self::callTool($agent, 'get_weather', ['location' => 'Chicago']);

        // The real answer, proving the handler ran to completion.
        self::assertStringContainsString('Chicago', $result->getResponse());
    }

    public function testSimpleAgentWeatherToolEmitsTheSetGlobalDataAction(): void
    {
        $agent = self::loadAgent('examples/simple_agent.php', 'buildSimpleAgent');

        $result = self::callTool($agent, 'get_weather', ['location' => 'Chicago']);
        $arr = $result->toArray();

        // The handler's whole purpose beyond the spoken response: record the
        // location in global data. The wire shape is {set_global_data: {...}} —
        // a single-key map per action, matching the reference's
        // add_action(name, data) -> {name: data}.
        self::assertArrayHasKey('action', $arr);
        self::assertIsArray($arr['action']);
        self::assertContains(
            ['set_global_data' => ['weather_location' => 'Chicago']],
            $arr['action'],
        );
    }

    public function testSimpleAgentTimeToolRuns(): void
    {
        $agent = self::loadAgent('examples/simple_agent.php', 'buildSimpleAgent');

        $result = self::callTool($agent, 'get_time', []);

        self::assertNotSame('', $result->getResponse());
    }
}
