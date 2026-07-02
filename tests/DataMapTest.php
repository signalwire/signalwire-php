<?php

declare(strict_types=1);

namespace SignalWire\Tests;

use PHPUnit\Framework\TestCase;
use SignalWire\DataMap\DataMap;
use SignalWire\SWAIG\FunctionResult;
use SignalWire\Tests\Support\Shape;

class DataMapTest extends TestCase
{
    // ── Construction ─────────────────────────────────────────────────────

    public function testConstructionWithFunctionName(): void
    {
        $dm = new DataMap('get_weather');
        $result = $dm->toSwaigFunction();

        $this->assertSame('get_weather', $result['function']);
        $this->assertArrayNotHasKey('purpose', $result);
        $this->assertArrayNotHasKey('argument', $result);
        $this->assertArrayNotHasKey('data_map', $result);
    }

    // ── Purpose / Description ────────────────────────────────────────────

    public function testPurposeSetsPurpose(): void
    {
        $dm = new DataMap('lookup');
        $dm->purpose('Look up a record');
        $result = $dm->toSwaigFunction();

        $this->assertSame('Look up a record', $result['purpose']);
    }

    public function testDescriptionAliasesPurpose(): void
    {
        $dm = new DataMap('lookup');
        $dm->description('Alias test');
        $result = $dm->toSwaigFunction();

        $this->assertSame('Alias test', $result['purpose']);
    }

    // ── Parameter ────────────────────────────────────────────────────────

    public function testParameterAddsPropertyAndRequired(): void
    {
        $dm = new DataMap('fn');
        $dm->parameter('city', 'string', 'The city name', true);
        $result = $dm->toSwaigFunction();

        $this->assertArrayHasKey('argument', $result);
        $this->assertSame('object', Shape::at($result, 'argument', 'type'));
        $this->assertSame([
            'city' => ['type' => 'string', 'description' => 'The city name'],
        ], Shape::at($result, 'argument', 'properties'));
        $this->assertSame(['city'], Shape::at($result, 'argument', 'required'));
    }

    public function testOptionalParameterNotInRequired(): void
    {
        $dm = new DataMap('fn');
        $dm->parameter('note', 'string', 'Optional note', false);
        $result = $dm->toSwaigFunction();

        $this->assertArrayNotHasKey('required', Shape::sub($result, 'argument'));
    }

    public function testParameterWithEnum(): void
    {
        $dm = new DataMap('fn');
        $dm->parameter('unit', 'string', 'Unit', true, ['celsius', 'fahrenheit']);
        $result = $dm->toSwaigFunction();

        $prop = Shape::sub($result, 'argument', 'properties', 'unit');
        $this->assertSame(['celsius', 'fahrenheit'], $prop['enum']);
        $this->assertSame('string', $prop['type']);
        $this->assertSame('Unit', $prop['description']);
    }

    public function testMultipleParametersAccumulate(): void
    {
        $dm = new DataMap('fn');
        $dm->parameter('a', 'string', 'First', true);
        $dm->parameter('b', 'integer', 'Second', true);
        $dm->parameter('c', 'boolean', 'Third', false);
        $result = $dm->toSwaigFunction();

        $this->assertCount(3, Shape::sub($result, 'argument', 'properties'));
        $this->assertSame(['a', 'b'], Shape::at($result, 'argument', 'required'));
    }

    public function testDuplicateRequiredParameterNotDuplicated(): void
    {
        $dm = new DataMap('fn');
        $dm->parameter('x', 'string', 'Desc', true);
        $dm->parameter('x', 'string', 'Updated desc', true);
        $result = $dm->toSwaigFunction();

        $this->assertCount(1, Shape::sub($result, 'argument', 'required'));
        $this->assertSame('Updated desc', Shape::at($result, 'argument', 'properties', 'x', 'description'));
    }

    // ── Expression ───────────────────────────────────────────────────────

    public function testExpressionAddsToExpressionsList(): void
    {
        $dm = new DataMap('fn');
        $dm->expression('${args.color}', '/^red$/', ['response' => 'Red detected']);
        $result = $dm->toSwaigFunction();

        $this->assertArrayHasKey('data_map', $result);
        $this->assertCount(1, Shape::sub($result, 'data_map', 'expressions'));
        $this->assertSame('${args.color}', Shape::at($result, 'data_map', 'expressions', 0, 'string'));
        $this->assertSame('/^red$/', Shape::at($result, 'data_map', 'expressions', 0, 'pattern'));
        $this->assertSame(['response' => 'Red detected'], Shape::at($result, 'data_map', 'expressions', 0, 'output'));
        $this->assertArrayNotHasKey('nomatch_output', Shape::sub($result, 'data_map', 'expressions', 0));
    }

    public function testExpressionWithNomatchOutput(): void
    {
        $dm = new DataMap('fn');
        $dm->expression('${args.x}', '/yes/', 'matched', 'not matched');
        $result = $dm->toSwaigFunction();

        $this->assertSame('not matched', Shape::at($result, 'data_map', 'expressions', 0, 'nomatch_output'));
    }

    public function testMultipleExpressionsAccumulate(): void
    {
        $dm = new DataMap('fn');
        $dm->expression('${a}', '/1/', 'one');
        $dm->expression('${b}', '/2/', 'two');
        $result = $dm->toSwaigFunction();

        $this->assertCount(2, Shape::sub($result, 'data_map', 'expressions'));
    }

    // ── Webhook ──────────────────────────────────────────────────────────

    public function testWebhookCreatesEntry(): void
    {
        $dm = new DataMap('fn');
        $dm->webhook('GET', 'https://api.example.com/data');
        $result = $dm->toSwaigFunction();

        $this->assertArrayHasKey('data_map', $result);
        $this->assertCount(1, Shape::sub($result, 'data_map', 'webhooks'));
        $this->assertSame('GET', Shape::at($result, 'data_map', 'webhooks', 0, 'method'));
        $this->assertSame('https://api.example.com/data', Shape::at($result, 'data_map', 'webhooks', 0, 'url'));
    }

    public function testWebhookWithAllOptions(): void
    {
        $dm = new DataMap('fn');
        $dm->webhook(
            'POST',
            'https://api.example.com/submit',
            ['Authorization' => 'Bearer tok'],
            'query',
            true,
            ['city']
        );
        $result = $dm->toSwaigFunction();
        $wh = Shape::sub($result, 'data_map', 'webhooks', 0);

        $this->assertSame('POST', $wh['method']);
        $this->assertSame(['Authorization' => 'Bearer tok'], $wh['headers']);
        $this->assertSame('query', $wh['form_param']);
        $this->assertTrue($wh['input_args_as_params']);
        $this->assertSame(['city'], $wh['require_args']);
    }

    public function testWebhookOmitsEmptyOptionalFields(): void
    {
        $dm = new DataMap('fn');
        $dm->webhook('GET', 'https://example.com');
        $result = $dm->toSwaigFunction();
        $wh = Shape::sub($result, 'data_map', 'webhooks', 0);

        $this->assertArrayNotHasKey('headers', $wh);
        $this->assertArrayNotHasKey('form_param', $wh);
        $this->assertArrayNotHasKey('input_args_as_params', $wh);
        $this->assertArrayNotHasKey('require_args', $wh);
    }

    // ── webhookExpressions ───────────────────────────────────────────────

    public function testWebhookExpressionsModifiesLastWebhook(): void
    {
        $dm = new DataMap('fn');
        $dm->webhook('GET', 'https://a.com');
        $dm->webhookExpressions([
            ['string' => '${response}', 'pattern' => '/ok/', 'output' => 'success'],
        ]);
        $result = $dm->toSwaigFunction();
        $wh = Shape::sub($result, 'data_map', 'webhooks', 0);

        $this->assertCount(1, Shape::sub($wh, 'expressions'));
        $this->assertSame('${response}', Shape::at($wh, 'expressions', 0, 'string'));
    }

    public function testWebhookExpressionsIgnoredWithNoWebhooks(): void
    {
        $dm = new DataMap('fn');
        $dm->webhookExpressions([['string' => 'x', 'pattern' => 'y', 'output' => 'z']]);
        $result = $dm->toSwaigFunction();

        $this->assertArrayNotHasKey('data_map', $result);
    }

    // ── body ─────────────────────────────────────────────────────────────

    public function testBodyModifiesLastWebhook(): void
    {
        $dm = new DataMap('fn');
        $dm->webhook('POST', 'https://a.com');
        $dm->body(['key' => '${args.val}']);
        $result = $dm->toSwaigFunction();

        $this->assertSame(['key' => '${args.val}'], Shape::at($result, 'data_map', 'webhooks', 0, 'body'));
    }

    public function testBodyIgnoredWithNoWebhooks(): void
    {
        $dm = new DataMap('fn');
        $dm->body(['k' => 'v']);
        $result = $dm->toSwaigFunction();

        $this->assertArrayNotHasKey('data_map', $result);
    }

    // ── params ───────────────────────────────────────────────────────────

    public function testParamsModifiesLastWebhook(): void
    {
        $dm = new DataMap('fn');
        $dm->webhook('GET', 'https://a.com');
        $dm->params(['q' => '${args.query}']);
        $result = $dm->toSwaigFunction();

        $this->assertSame(['q' => '${args.query}'], Shape::at($result, 'data_map', 'webhooks', 0, 'params'));
    }

    public function testParamsIgnoredWithNoWebhooks(): void
    {
        $dm = new DataMap('fn');
        $dm->params(['q' => 'v']);
        $result = $dm->toSwaigFunction();

        $this->assertArrayNotHasKey('data_map', $result);
    }

    // ── foreach ──────────────────────────────────────────────────────────

    public function testForeachModifiesLastWebhook(): void
    {
        $dm = new DataMap('fn');
        $dm->webhook('GET', 'https://a.com');
        $dm->foreach(['input_key' => 'items', 'output_key' => 'names', 'append' => true]);
        $result = $dm->toSwaigFunction();

        $this->assertSame(
            ['input_key' => 'items', 'output_key' => 'names', 'append' => true],
            Shape::at($result, 'data_map', 'webhooks', 0, 'foreach')
        );
    }

    public function testForeachIgnoredWithNoWebhooks(): void
    {
        $dm = new DataMap('fn');
        $dm->foreach(['input_key' => 'x', 'output_key' => 'y']);
        $result = $dm->toSwaigFunction();

        $this->assertArrayNotHasKey('data_map', $result);
    }

    // ── output on webhook ────────────────────────────────────────────────

    public function testOutputOnWebhookWithArray(): void
    {
        $dm = new DataMap('fn');
        $dm->webhook('GET', 'https://a.com');
        $dm->output(['response' => 'Done: ${response}']);
        $result = $dm->toSwaigFunction();

        $this->assertSame(
            ['response' => 'Done: ${response}'],
            Shape::at($result, 'data_map', 'webhooks', 0, 'output')
        );
    }

    public function testOutputOnWebhookWithString(): void
    {
        $dm = new DataMap('fn');
        $dm->webhook('GET', 'https://a.com');
        $dm->output('plain string');
        $result = $dm->toSwaigFunction();

        $this->assertSame('plain string', Shape::at($result, 'data_map', 'webhooks', 0, 'output'));
    }

    public function testOutputOnWebhookWithFunctionResult(): void
    {
        // post_process flows through DataMap output only alongside an action
        // (Python envelope parity: post_process requires an action).
        $fr = new FunctionResult('OK');
        $fr->setPostProcess(true);
        $fr->addAction(['stop' => true]);

        $dm = new DataMap('fn');
        $dm->webhook('GET', 'https://a.com');
        $dm->output($fr);
        $result = $dm->toSwaigFunction();

        $expected = ['response' => 'OK', 'action' => [['stop' => true]], 'post_process' => true];
        $this->assertSame($expected, Shape::at($result, 'data_map', 'webhooks', 0, 'output'));
    }

    public function testOutputIgnoredWithNoWebhooks(): void
    {
        $dm = new DataMap('fn');
        $dm->output('ignored');
        $result = $dm->toSwaigFunction();

        $this->assertArrayNotHasKey('data_map', $result);
    }

    // ── fallbackOutput ───────────────────────────────────────────────────

    public function testFallbackOutputSetsGlobalOutput(): void
    {
        $dm = new DataMap('fn');
        $dm->fallbackOutput(['response' => 'Fallback']);
        $result = $dm->toSwaigFunction();

        $this->assertSame(['response' => 'Fallback'], Shape::at($result, 'data_map', 'output'));
    }

    public function testFallbackOutputWithFunctionResult(): void
    {
        $fr = new FunctionResult('Error occurred');

        $dm = new DataMap('fn');
        $dm->fallbackOutput($fr);
        $result = $dm->toSwaigFunction();

        $this->assertSame(['response' => 'Error occurred'], Shape::at($result, 'data_map', 'output'));
    }

    public function testFallbackOutputWithString(): void
    {
        $dm = new DataMap('fn');
        $dm->fallbackOutput('simple fallback');
        $result = $dm->toSwaigFunction();

        $this->assertSame('simple fallback', Shape::at($result, 'data_map', 'output'));
    }

    // ── errorKeys on webhook ─────────────────────────────────────────────

    public function testErrorKeysOnWebhook(): void
    {
        $dm = new DataMap('fn');
        $dm->webhook('GET', 'https://a.com');
        $dm->errorKeys(['error', 'message']);
        $result = $dm->toSwaigFunction();

        $this->assertSame(['error', 'message'], Shape::at($result, 'data_map', 'webhooks', 0, 'error_keys'));
    }

    public function testErrorKeysIgnoredWithNoWebhooks(): void
    {
        $dm = new DataMap('fn');
        $dm->errorKeys(['err']);
        $result = $dm->toSwaigFunction();

        $this->assertArrayNotHasKey('data_map', $result);
    }

    // ── globalErrorKeys ──────────────────────────────────────────────────

    public function testGlobalErrorKeys(): void
    {
        $dm = new DataMap('fn');
        $dm->globalErrorKeys(['error', 'detail']);
        $result = $dm->toSwaigFunction();

        $this->assertSame(['error', 'detail'], Shape::at($result, 'data_map', 'error_keys'));
    }

    // ── toSwaigFunction full serialization ───────────────────────────────

    public function testToSwaigFunctionFullStructure(): void
    {
        $dm = new DataMap('get_weather');
        $dm->purpose('Get the weather for a city')
            ->parameter('city', 'string', 'City name', true)
            ->parameter('unit', 'string', 'Unit', false, ['celsius', 'fahrenheit'])
            ->expression('${args.city}', '/^test$/', ['response' => 'Test mode'])
            ->webhook('GET', 'https://api.weather.com', ['X-Key' => 'abc'])
            ->output(['response' => 'Weather: ${temp}'])
            ->fallbackOutput(['response' => 'Unable to retrieve weather'])
            ->globalErrorKeys(['error']);

        $result = $dm->toSwaigFunction();

        // Top-level keys
        $this->assertSame('get_weather', $result['function']);
        $this->assertSame('Get the weather for a city', $result['purpose']);

        // Argument
        $this->assertSame('object', Shape::at($result, 'argument', 'type'));
        $this->assertArrayHasKey('city', Shape::sub($result, 'argument', 'properties'));
        $this->assertArrayHasKey('unit', Shape::sub($result, 'argument', 'properties'));
        $this->assertSame(['city'], Shape::at($result, 'argument', 'required'));
        $this->assertSame(['celsius', 'fahrenheit'], Shape::at($result, 'argument', 'properties', 'unit', 'enum'));

        // Data map
        $this->assertArrayHasKey('data_map', $result);
        $dataMap = Shape::sub($result, 'data_map');

        // Expressions
        $this->assertCount(1, Shape::sub($dataMap, 'expressions'));
        $this->assertSame('${args.city}', Shape::at($dataMap, 'expressions', 0, 'string'));

        // Webhooks
        $this->assertCount(1, Shape::sub($dataMap, 'webhooks'));
        $this->assertSame('GET', Shape::at($dataMap, 'webhooks', 0, 'method'));
        $this->assertSame(['X-Key' => 'abc'], Shape::at($dataMap, 'webhooks', 0, 'headers'));
        $this->assertSame(['response' => 'Weather: ${temp}'], Shape::at($dataMap, 'webhooks', 0, 'output'));

        // Global output
        $this->assertSame(['response' => 'Unable to retrieve weather'], Shape::at($dataMap, 'output'));

        // Global error_keys
        $this->assertSame(['error'], Shape::at($dataMap, 'error_keys'));
    }

    // ── createSimpleApiTool ──────────────────────────────────────────────

    public function testCreateSimpleApiTool(): void
    {
        // Oracle-shape factory: returns a configured DataMap builder.
        $dataMap = DataMap::createSimpleApiTool(
            'lookup_user',
            'https://api.example.com/users',
            'User: ${response.name}',
            [
                'user_id' => ['type' => 'string', 'description' => 'User ID', 'required' => true],
                'format'  => ['type' => 'string', 'description' => 'Format'],
            ],
            'GET',
            ['Authorization' => 'Bearer secret']
        );

        $result = $dataMap->toSwaigFunction();

        $this->assertSame('lookup_user', $result['function']);
        $this->assertSame(['user_id'], Shape::at($result, 'argument', 'required'));
        $this->assertArrayHasKey('format', Shape::sub($result, 'argument', 'properties'));
        $this->assertSame('GET', Shape::at($result, 'data_map', 'webhooks', 0, 'method'));
        $this->assertSame('https://api.example.com/users', Shape::at($result, 'data_map', 'webhooks', 0, 'url'));
        $this->assertSame(['Authorization' => 'Bearer secret'], Shape::at($result, 'data_map', 'webhooks', 0, 'headers'));
        // Output derives from the response_template FunctionResult.
        $this->assertSame('User: ${response.name}', Shape::at($result, 'data_map', 'webhooks', 0, 'output', 'response'));
    }

    public function testCreateSimpleApiToolWithoutParametersOrHeaders(): void
    {
        $dataMap = DataMap::createSimpleApiTool(
            'simple',
            'https://example.com',
            'done'
        );

        $result = $dataMap->toSwaigFunction();
        $this->assertSame('simple', $result['function']);
        $this->assertArrayNotHasKey('argument', $result);
        $this->assertArrayNotHasKey('headers', Shape::sub($result, 'data_map', 'webhooks', 0));
        $this->assertSame('GET', Shape::at($result, 'data_map', 'webhooks', 0, 'method'));
    }

    public function testCreateSimpleApiToolWithBodyAndErrorKeys(): void
    {
        $dataMap = DataMap::createSimpleApiTool(
            'create_user',
            'https://api.example.com/users',
            'Created ${response.id}',
            null,
            'POST',
            null,
            ['name' => '${args.name}'],
            ['error', 'message']
        );

        $result = $dataMap->toSwaigFunction();
        $this->assertSame('POST', Shape::at($result, 'data_map', 'webhooks', 0, 'method'));
        $this->assertSame(['name' => '${args.name}'], Shape::at($result, 'data_map', 'webhooks', 0, 'body'));
        $this->assertSame(['error', 'message'], Shape::at($result, 'data_map', 'webhooks', 0, 'error_keys'));
    }

    // ── createExpressionTool ─────────────────────────────────────────────

    public function testCreateExpressionTool(): void
    {
        $dataMap = DataMap::createExpressionTool(
            'route_call',
            [
                '${args.dept}' => ['/sales/', new FunctionResult('Routing to sales')],
                '${args.dept}_support' => ['/support/', new FunctionResult('Routing to support')],
            ],
            [
                'dept' => ['type' => 'string', 'description' => 'Department', 'required' => true],
            ]
        );

        $result = $dataMap->toSwaigFunction();

        $this->assertSame('route_call', $result['function']);
        $this->assertSame(['dept'], Shape::at($result, 'argument', 'required'));
        $this->assertCount(2, Shape::sub($result, 'data_map', 'expressions'));
        $this->assertSame('/sales/', Shape::at($result, 'data_map', 'expressions', 0, 'pattern'));
    }

    public function testCreateExpressionToolNoParams(): void
    {
        $dataMap = DataMap::createExpressionTool(
            'test',
            ['x' => ['/y/', new FunctionResult('z')]]
        );

        $result = $dataMap->toSwaigFunction();
        $this->assertArrayNotHasKey('argument', $result);
        $this->assertCount(1, Shape::sub($result, 'data_map', 'expressions'));
    }

    // ── Method chaining ──────────────────────────────────────────────────

    public function testMethodChainingReturnsSelf(): void
    {
        $dm = new DataMap('chain_test');

        $r1 = $dm->purpose('test');
        $this->assertSame($dm, $r1);

        $r2 = $dm->description('test2');
        $this->assertSame($dm, $r2);

        $r3 = $dm->parameter('p', 'string', 'd');
        $this->assertSame($dm, $r3);

        $r4 = $dm->expression('s', 'p', 'o');
        $this->assertSame($dm, $r4);

        $r5 = $dm->webhook('GET', 'https://x.com');
        $this->assertSame($dm, $r5);

        $r6 = $dm->webhookExpressions([]);
        $this->assertSame($dm, $r6);

        $r7 = $dm->body([]);
        $this->assertSame($dm, $r7);

        $r8 = $dm->params([]);
        $this->assertSame($dm, $r8);

        $r9 = $dm->foreach(['input_key' => 'a', 'output_key' => 'b']);
        $this->assertSame($dm, $r9);

        $r10 = $dm->output('x');
        $this->assertSame($dm, $r10);

        $r11 = $dm->fallbackOutput('x');
        $this->assertSame($dm, $r11);

        $r12 = $dm->errorKeys([]);
        $this->assertSame($dm, $r12);

        $r13 = $dm->globalErrorKeys([]);
        $this->assertSame($dm, $r13);
    }

    public function testFluentChainProducesCorrectResult(): void
    {
        $result = (new DataMap('api'))
            ->purpose('Test API')
            ->parameter('q', 'string', 'Query', true)
            ->webhook('POST', 'https://api.test.com')
            ->body(['query' => '${args.q}'])
            ->output(['response' => '${result}'])
            ->globalErrorKeys(['err'])
            ->toSwaigFunction();

        $this->assertSame('api', $result['function']);
        $this->assertSame('Test API', $result['purpose']);
        $this->assertSame(['q'], Shape::at($result, 'argument', 'required'));
        $this->assertSame(['query' => '${args.q}'], Shape::at($result, 'data_map', 'webhooks', 0, 'body'));
        $this->assertSame(['response' => '${result}'], Shape::at($result, 'data_map', 'webhooks', 0, 'output'));
        $this->assertSame(['err'], Shape::at($result, 'data_map', 'error_keys'));
    }

    // ── Webhook modifier targets last webhook ────────────────────────────

    public function testModifiersTargetLastWebhook(): void
    {
        $dm = new DataMap('fn');
        $dm->webhook('GET', 'https://first.com');
        $dm->webhook('POST', 'https://second.com');
        $dm->output(['response' => 'from second']);
        $dm->body(['key' => 'val']);
        $dm->params(['p' => 'v']);
        $dm->errorKeys(['err']);

        $result = $dm->toSwaigFunction();
        $webhooks = Shape::sub($result, 'data_map', 'webhooks');

        // First webhook should be untouched
        $this->assertArrayNotHasKey('output', Shape::sub($webhooks, 0));
        $this->assertArrayNotHasKey('body', Shape::sub($webhooks, 0));
        $this->assertArrayNotHasKey('params', Shape::sub($webhooks, 0));
        $this->assertArrayNotHasKey('error_keys', Shape::sub($webhooks, 0));

        // Second webhook should have all modifications
        $this->assertSame(['response' => 'from second'], Shape::at($webhooks, 1, 'output'));
        $this->assertSame(['key' => 'val'], Shape::at($webhooks, 1, 'body'));
        $this->assertSame(['p' => 'v'], Shape::at($webhooks, 1, 'params'));
        $this->assertSame(['err'], Shape::at($webhooks, 1, 'error_keys'));
    }
}
