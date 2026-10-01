<?php

/*
 * Copyright (c) 2025 SignalWire
 *
 * Licensed under the MIT License.
 * See LICENSE file in the project root for full license information.
 */

declare(strict_types=1);

namespace SignalWire\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SignalWire\Utils\SchemaUtils;

/**
 * Fail-closed contract for SchemaUtils (#307).
 *
 * Sibling ports ruby (c606d77) and typescript (d8cfe4c) each shipped a
 * FAIL-OPEN validator: a rescue/catch around validator construction set the
 * validator to null, and a null validator ROUTED validateVerb to a lightweight
 * required-props check that reports a forbidden key as valid. A config nobody
 * validated came back clean.
 *
 * PHP does not have that shape, and these tests exist to keep it that way. The
 * property here is structural rather than a rescue clause: every loadSchema
 * failure mode returns an EMPTY schema, which extracts ZERO verbs, so
 * validateVerb answers "Unknown verb" instead of passing.
 *
 * The load-bearing assertion in every case below is `assertFalse($valid)` — a
 * broken schema must NEVER report an unvalidated config as valid. Asserting
 * only the error TEXT would let a future refactor that flips valid to true
 * still pass, so both are asserted.
 */
class SchemaUtilsFailClosedTest extends TestCase
{
    /** @var list<string> */
    private array $tmpFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->tmpFiles as $f) {
            if (is_file($f)) {
                unlink($f);
            }
        }
        $this->tmpFiles = [];
        parent::tearDown();
    }

    private function writeTmp(string $name, string $contents): string
    {
        // Repo-local scratch, never a shared global temp.
        $dir = __DIR__ . '/../.sw-tmp';
        if (!is_dir($dir)) {
            mkdir($dir, 0o777, true);
        }
        $path = $dir . '/' . $name;
        file_put_contents($path, $contents);
        $this->tmpFiles[] = $path;
        return $path;
    }

    /**
     * Every way loadSchema() can fail must produce a validator that REFUSES,
     * never one that accepts a config carrying a forbidden key.
     *
     * @return iterable<string, array{0: string}>
     */
    public static function brokenSchemaProvider(): iterable
    {
        yield 'malformed json' => ['{ not json ,,,'];
        yield 'json scalar, not an object' => ['"just a string"'];
        yield 'json array, not an object' => ['[1, 2, 3]'];
        yield 'empty object' => ['{}'];
        yield 'object with no $defs' => ['{"title": "nope"}'];
        yield '$defs but no SWMLMethod' => ['{"$defs": {"Other": {}}}'];
        yield 'SWMLMethod but no anyOf' => ['{"$defs": {"SWMLMethod": {}}}'];
    }

    #[DataProvider('brokenSchemaProvider')]
    public function testBrokenSchemaRefusesInsteadOfAcceptingForbiddenKey(string $contents): void
    {
        $path = $this->writeTmp('failclosed_broken.json', $contents);
        $su = new SchemaUtils($path);

        $this->assertSame(
            [],
            $su->getAllVerbNames(),
            'a schema that failed to load must expose no verbs'
        );

        [$valid, $errors] = $su->validateVerb('answer', [
            'zzz_forbidden_key' => 1,
        ]);

        $this->assertFalse(
            $valid,
            'answer: a config that was never validated must not report valid=true'
        );
        $this->assertNotEmpty($errors, 'a refusal must carry an error message');
        $this->assertStringContainsString('Unknown verb', $errors[0]);
    }

    /**
     * The refusal is config-independent: even an EMPTY config is refused,
     * because the point is that validation did not happen — not that this
     * particular config was bad.
     */
    public function testBrokenSchemaRefusalIsConfigIndependent(): void
    {
        $path = $this->writeTmp('failclosed_empty_obj.json', '{}');
        $su = new SchemaUtils($path);

        foreach (['answer', 'sleep', 'connect', 'play'] as $verb) {
            [$valid, $errors] = $su->validateVerb($verb, []);
            $this->assertFalse($valid, "$verb: empty config must still be refused");
            $this->assertNotEmpty($errors);
        }
    }

    /**
     * A schema file that does not exist at all is the same fail-closed path.
     */
    public function testMissingSchemaFileRefuses(): void
    {
        $su = new SchemaUtils(__DIR__ . '/../.sw-tmp/definitely_absent_schema.json');
        $this->assertSame([], $su->getAllVerbNames());

        [$valid, $errors] = $su->validateVerb('answer', ['zzz_forbidden_key' => 1]);
        $this->assertFalse($valid);
        $this->assertStringContainsString('Unknown verb', $errors[0]);
    }

    /**
     * A truncated copy of the REAL bundled schema — the realistic corruption
     * case (partial write, bad vendoring) — must also refuse.
     */
    public function testTruncatedRealSchemaRefuses(): void
    {
        $full = file_get_contents(__DIR__ . '/../src/SignalWire/SWML/schema.json');
        $this->assertIsString($full);
        $path = $this->writeTmp('failclosed_truncated.json', substr($full, 0, intdiv(strlen($full), 2)));

        $su = new SchemaUtils($path);
        [$valid, $errors] = $su->validateVerb('answer', ['zzz_forbidden_key' => 1]);
        $this->assertFalse($valid, 'a truncated schema must not validate anything as clean');
        $this->assertNotEmpty($errors);
    }

    /**
     * validateDocument fails closed for the same reason and says so.
     */
    public function testValidateDocumentRefusesOnBrokenSchema(): void
    {
        $path = $this->writeTmp('failclosed_doc.json', '{ not json ,,,');
        $su = new SchemaUtils($path);

        [$valid, $errors] = $su->validateDocument([
            'version' => '1.0.0',
            'sections' => ['main' => []],
        ]);
        $this->assertFalse($valid);
        $this->assertStringContainsString('validator not initialized', $errors[0]);
    }

    /**
     * THE OTHER DIRECTION — the happy path must be untouched. A well-formed
     * schema still loads its verbs, still ACCEPTS a good config, and still
     * REJECTS a forbidden key and a wrong-typed value. A fix proven only on the
     * refusal side could have broken this.
     */
    public function testWellFormedSchemaStillValidatesNormally(): void
    {
        $su = new SchemaUtils();

        $names = $su->getAllVerbNames();
        $this->assertContains('answer', $names);
        $this->assertGreaterThan(30, count($names), 'bundled schema should expose the full verb set');

        // accepts a good config
        [$valid, $errors] = $su->validateVerb('answer', ['max_duration' => 5]);
        $this->assertTrue($valid, 'a valid answer config must still pass');
        $this->assertSame([], $errors);

        // rejects a forbidden key on a closed verb
        [$valid, $errors] = $su->validateVerb('answer', ['zzz_forbidden_key' => 1]);
        $this->assertFalse($valid, 'a forbidden key on a closed verb must still be rejected');
        $this->assertStringContainsString("Unknown property 'zzz_forbidden_key'", $errors[0]);

        // rejects a wrong-typed value
        [$valid, $errors] = $su->validateVerb('answer', ['max_duration' => 'notanumber']);
        $this->assertFalse($valid, 'a wrong-typed value must still be rejected');
        $this->assertStringContainsString('wrong type', $errors[0]);

        // a ${var} template string still satisfies the integer-or-var union
        [$valid, $errors] = $su->validateVerb('answer', ['max_duration' => '${dur}']);
        $this->assertTrue($valid, 'a SWMLVar template string must still be accepted');
        $this->assertSame([], $errors);
    }

    /**
     * A PARTIAL schema (parses, yields verbs, but has no full-document
     * structure) is the ONE condition under which the lightweight/resolved
     * check is the intended behaviour — and it still rejects a forbidden key.
     * This is the case the sibling ports conflated with a failed validator
     * build; keeping it separate is the whole point.
     */
    public function testPartialSchemaStillChecksClosedKeys(): void
    {
        $partial = json_encode([
            '$defs' => [
                'SWMLMethod' => ['anyOf' => [['$ref' => '#/$defs/Answer']]],
                'Answer' => [
                    'type' => 'object',
                    'properties' => [
                        'answer' => [
                            'type' => 'object',
                            'properties' => ['max_duration' => ['type' => 'integer']],
                            'unevaluatedProperties' => ['not' => []],
                        ],
                    ],
                ],
            ],
        ]);
        $this->assertIsString($partial);
        $path = $this->writeTmp('failclosed_partial.json', $partial);

        $su = new SchemaUtils($path);
        $this->assertSame(['answer'], $su->getAllVerbNames());

        [$valid, $errors] = $su->validateVerb('answer', ['max_duration' => 5]);
        $this->assertTrue($valid, 'partial schema: a good config still passes');
        $this->assertSame([], $errors);

        [$valid, $errors] = $su->validateVerb('answer', ['zzz_forbidden_key' => 1]);
        $this->assertFalse($valid, 'partial schema: a forbidden key is still rejected');
        $this->assertStringContainsString("Unknown property 'zzz_forbidden_key'", $errors[0]);
    }

    /**
     * The two EXPLICIT opt-outs are unchanged and remain a clean skip — they
     * are how a caller legitimately asks for no validation, and they must stay
     * distinguishable from "the validator could not be built".
     */
    public function testExplicitlyDisabledValidationIsStillACleanSkip(): void
    {
        $su = new SchemaUtils(null, false);
        [$valid, $errors] = $su->validateVerb('answer', ['zzz_forbidden_key' => 1]);
        $this->assertTrue($valid, 'schemaValidation=false is an explicit opt-out, not a failure');
        $this->assertSame([], $errors);

        putenv('SWML_SKIP_SCHEMA_VALIDATION=1');
        try {
            $su2 = new SchemaUtils();
            [$valid2, $errors2] = $su2->validateVerb('answer', ['zzz_forbidden_key' => 1]);
            $this->assertTrue($valid2, 'SWML_SKIP_SCHEMA_VALIDATION=1 is an explicit opt-out');
            $this->assertSame([], $errors2);
        } finally {
            putenv('SWML_SKIP_SCHEMA_VALIDATION');
        }
    }
}
