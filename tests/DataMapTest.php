<?php

declare(strict_types=1);

namespace SignalWire\Tests;

use PHPUnit\Framework\TestCase;
use SignalWire\DataMap\DataMap;
use SignalWire\SWAIG\FunctionResult;

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
        $this->assertSame('object', $result['argument']['type']);
        $this->assertSame([
            'city' => ['type' => 'string', 'description' => 'The city name'],
        ], $result['argument']['properties']);
        $this->assertSame(['city'], $result['argument']['required']);
    }

    public function testOptionalParameterNotInRequired(): void
    {
        $dm = new DataMap('fn');
        $dm->parameter('note', 'string', 'Optional note', false);
        $result = $dm->toSwaigFunction();

        $this->assertArrayNotHasKey('required', $result['argument']);
    }

    public function testParameterWithEnum(): void
    {
        $dm = new DataMap('fn');
        $dm->parameter('unit', 'string', 'Unit', true, ['celsius', 'fahrenheit']);
        $result = $dm->toSwaigFunction();

        $prop = $result['argument']['properties']['unit'];
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

        $this->assertCount(3, $result['argument']['properties']);
        $this->assertSame(['a', 'b'], $result['argument']['required']);
    }

    public function testDuplicateRequiredParameterNotDuplicated(): void
    {
        $dm = new DataMap('fn');
        $dm->parameter('x', 'string', 'Desc', true);
        $dm->parameter('x', 'string', 'Updated desc', true);
        $result = $dm->toSwaigFunction();

        $this->assertCount(1, $result['argument']['required']);
        $this->assertSame('Updated desc', $result['argument']['properties']['x']['description']);
    }

    // ── Expression ───────────────────────────────────────────────────────

    public function testExpressionAddsToExpressionsList(): void
    {
        $dm = new DataMap('fn');
        $dm->expression('${args.color}', '/^red$/', ['response' => 'Red detected']);
        $result = $dm->toSwaigFunction();

        $this->assertArrayHasKey('data_map', $result);
        $this->assertCount(1, $result['data_map']['expressions']);
        $this->assertSame('${args.color}', $result['data_map']['expressions'][0]['string']);
        $this->assertSame('/^red$/', $result['data_map']['expressions'][0]['pattern']);
        $this->assertSame(['response' => 'Red detected'], $result['data_map']['expressions'][0]['output']);
        $this->assertArrayNotHasKey('nomatch_output', $result['data_map']['expressions'][0]);
    }

    public function testExpressionWithNomatchOutput(): void
    {
        $dm = new DataMap('fn');
        $dm->expression('${args.x}', '/yes/', 'matched', 'not matched');
        $result = $dm->toSwaigFunction();

        $this->assertSame('not matched', $result['data_map']['expressions'][0]['nomatch_output']);
    }

    public function testMultipleExpressionsAccumulate(): void
    {
        $dm = new DataMap('fn');
        $dm->expression('${a}', '/1/', 'one');
        $dm->expression('${b}', '/2/', 'two');
        $result = $dm->toSwaigFunction();

        $this->assertCount(2, $result['data_map']['expressions']);
    }

    // ── Webhook ──────────────────────────────────────────────────────────

    public function testWebhookCreatesEntry(): void
    {
        $dm = new DataMap('fn');
        $dm->webhook('GET', 'https://api.example.com/data');
        $result = $dm->toSwaigFunction();

        $this->assertArrayHasKey('data_map', $result);
        $this->assertCount(1, $result['data_map']['webhooks']);
        $this->assertSame('GET', $result['data_map']['webhooks'][0]['method']);
        $this->assertSame('https://api.example.com/data', $result['data_map']['webhooks'][0]['url']);
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
        $wh = $result['data_map']['webhooks'][0];

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
        $wh = $result['data_map']['webhooks'][0];

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
        $wh = $result['data_map']['webhooks'][0];

        $this->assertCount(1, $wh['expressions']);
        $this->assertSame('${response}', $wh['expressions'][0]['string']);
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

        $this->assertSame(['key' => '${args.val}'], $result['data_map']['webhooks'][0]['body']);
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

        $this->assertSame(['q' => '${args.query}'], $result['data_map']['webhooks'][0]['params']);
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
            $result['data_map']['webhooks'][0]['foreach']
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
            $result['data_map']['webhooks'][0]['output']
        );
    }

    public function testOutputOnWebhookWithString(): void
    {
        $dm = new DataMap('fn');
        $dm->webhook('GET', 'https://a.com');
        $dm->output('plain string');
        $result = $dm->toSwaigFunction();

        $this->assertSame('plain string', $result['data_map']['webhooks'][0]['output']);
    }

    public function testOutputOnWebhookWithFunctionResult(): void
    {
        $fr = new FunctionResult('OK');
        $fr->setPostProcess(true);

        $dm = new DataMap('fn');
        $dm->webhook('GET', 'https://a.com');
        $dm->output($fr);
        $result = $dm->toSwaigFunction();

        $expected = ['response' => 'OK', 'post_process' => true];
        $this->assertSame($expected, $result['data_map']['webhooks'][0]['output']);
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

        $this->assertSame(['response' => 'Fallback'], $result['data_map']['output']);
    }

    public function testFallbackOutputWithFunctionResult(): void
    {
        $fr = new FunctionResult('Error occurred');

        $dm = new DataMap('fn');
        $dm->fallbackOutput($fr);
        $result = $dm->toSwaigFunction();

        $this->assertSame(['response' => 'Error occurred'], $result['data_map']['output']);
    }

    public function testFallbackOutputWithString(): void
    {
        $dm = new DataMap('fn');
        $dm->fallbackOutput('simple fallback');
        $result = $dm->toSwaigFunction();

        $this->assertSame('simple fallback', $result['data_map']['output']);
    }

    // ── errorKeys on webhook ─────────────────────────────────────────────

    public function testErrorKeysOnWebhook(): void
    {
        $dm = new DataMap('fn');
        $dm->webhook('GET', 'https://a.com');
        $dm->errorKeys(['error', 'message']);
        $result = $dm->toSwaigFunction();

        $this->assertSame(['error', 'message'], $result['data_map']['webhooks'][0]['error_keys']);
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

        $this->assertSame(['error', 'detail'], $result['data_map']['error_keys']);
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
        $this->assertSame('object', $result['argument']['type']);
        $this->assertArrayHasKey('city', $result['argument']['properties']);
        $this->assertArrayHasKey('unit', $result['argument']['properties']);
        $this->assertSame(['city'], $result['argument']['required']);
        $this->assertSame(['celsius', 'fahrenheit'], $result['argument']['properties']['unit']['enum']);

        // Data map
        $this->assertArrayHasKey('data_map', $result);
        $dataMap = $result['data_map'];

        // Expressions
        $this->assertCount(1, $dataMap['expressions']);
        $this->assertSame('${args.city}', $dataMap['expressions'][0]['string']);

        // Webhooks
        $this->assertCount(1, $dataMap['webhooks']);
        $this->assertSame('GET', $dataMap['webhooks'][0]['method']);
        $this->assertSame(['X-Key' => 'abc'], $dataMap['webhooks'][0]['headers']);
        $this->assertSame(['response' => 'Weather: ${temp}'], $dataMap['webhooks'][0]['output']);

        // Global output
        $this->assertSame(['response' => 'Unable to retrieve weather'], $dataMap['output']);

        // Global error_keys
        $this->assertSame(['error'], $dataMap['error_keys']);
    }

    // ── createSimpleApiTool ──────────────────────────────────────────────

    public function testCreateSimpleApiTool(): void
    {
        $result = DataMap::createSimpleApiTool(
            'lookup_user',
            'Look up user by ID',
            [
                ['name' => 'user_id', 'type' => 'string', 'description' => 'User ID', 'required' => true],
                ['name' => 'format', 'type' => 'string', 'description' => 'Format', 'enum' => ['json', 'xml']],
            ],
            'GET',
            'https://api.example.com/users',
            ['response' => 'User: ${name}'],
            ['Authorization' => 'Bearer secret']
        );

        $this->assertSame('lookup_user', $result['function']);
        $this->assertSame('Look up user by ID', $result['purpose']);
        $this->assertSame(['user_id'], $result['argument']['required']);
        $this->assertArrayHasKey('format', $result['argument']['properties']);
        $this->assertSame(['json', 'xml'], $result['argument']['properties']['format']['enum']);
        $this->assertSame('GET', $result['data_map']['webhooks'][0]['method']);
        $this->assertSame('https://api.example.com/users', $result['data_map']['webhooks'][0]['url']);
        $this->assertSame(['Authorization' => 'Bearer secret'], $result['data_map']['webhooks'][0]['headers']);
        $this->assertSame(['response' => 'User: ${name}'], $result['data_map']['webhooks'][0]['output']);
    }

    public function testCreateSimpleApiToolWithoutHeaders(): void
    {
        $result = DataMap::createSimpleApiTool(
            'simple',
            'Simple tool',
            [],
            'GET',
            'https://example.com',
            'done'
        );

        $this->assertSame('simple', $result['function']);
        $this->assertArrayNotHasKey('argument', $result);
        $this->assertArrayNotHasKey('headers', $result['data_map']['webhooks'][0]);
    }

    // ── createExpressionTool ─────────────────────────────────────────────

    public function testCreateExpressionTool(): void
    {
        $result = DataMap::createExpressionTool(
            'route_call',
            'Route the call based on department',
            [
                ['name' => 'dept', 'type' => 'string', 'description' => 'Department', 'required' => true],
            ],
            [
                ['string' => '${args.dept}', 'pattern' => '/sales/', 'output' => ['response' => 'Routing to sales']],
                ['string' => '${args.dept}', 'pattern' => '/support/', 'output' => ['response' => 'Routing to support'], 'nomatch_output' => ['response' => 'Unknown']],
            ]
        );

        $this->assertSame('route_call', $result['function']);
        $this->assertSame('Route the call based on department', $result['purpose']);
        $this->assertSame(['dept'], $result['argument']['required']);
        $this->assertCount(2, $result['data_map']['expressions']);
        $this->assertSame('/sales/', $result['data_map']['expressions'][0]['pattern']);
        $this->assertArrayNotHasKey('nomatch_output', $result['data_map']['expressions'][0]);
        $this->assertSame(['response' => 'Unknown'], $result['data_map']['expressions'][1]['nomatch_output']);
    }

    public function testCreateExpressionToolNoParams(): void
    {
        $result = DataMap::createExpressionTool(
            'test',
            'Test',
            [],
            [['string' => 'x', 'pattern' => '/y/', 'output' => 'z']]
        );

        $this->assertArrayNotHasKey('argument', $result);
        $this->assertCount(1, $result['data_map']['expressions']);
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
        $this->assertSame(['q'], $result['argument']['required']);
        $this->assertSame(['query' => '${args.q}'], $result['data_map']['webhooks'][0]['body']);
        $this->assertSame(['response' => '${result}'], $result['data_map']['webhooks'][0]['output']);
        $this->assertSame(['err'], $result['data_map']['error_keys']);
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
        $webhooks = $result['data_map']['webhooks'];

        // First webhook should be untouched
        $this->assertArrayNotHasKey('output', $webhooks[0]);
        $this->assertArrayNotHasKey('body', $webhooks[0]);
        $this->assertArrayNotHasKey('params', $webhooks[0]);
        $this->assertArrayNotHasKey('error_keys', $webhooks[0]);

        // Second webhook should have all modifications
        $this->assertSame(['response' => 'from second'], $webhooks[1]['output']);
        $this->assertSame(['key' => 'val'], $webhooks[1]['body']);
        $this->assertSame(['p' => 'v'], $webhooks[1]['params']);
        $this->assertSame(['err'], $webhooks[1]['error_keys']);
    }
}
