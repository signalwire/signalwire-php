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
use SignalWire\SWML\Service;
use SignalWire\Utils\SchemaUtils;
use SignalWire\Utils\SchemaValidationError;

/**
 * The shallow closed-key check and anyOf/oneOf-shaped verb configs.
 *
 * validateAgainstInnerSchema tested ONLY `oneOf`. An `anyOf`-shaped verb node
 * carries no `type` of its own, so it fell through to the plain-object path,
 * where `properties` is empty and schemaIsClosed() answers false — silently
 * DISENGAGING the closed-key check and reporting SUCCESS for any key whatsoever.
 * The check did not report a problem; it stopped checking and reported success,
 * which is the worse of the two.
 *
 * php is the port where that was LIVE end to end. The other ports keep a deep
 * Draft-2020-12 engine behind this shallow pass, so a disengage there is
 * survivable; php's initFullValidator() is deliberately empty, so this partial
 * check is the ONLY gate. Through the public Service::addVerb, a forbidden key
 * was accepted and RENDERED INTO THE EMITTED DOCUMENT:
 *
 *     "sleep": { "duration": 5, "zzz_forbidden": 1 }
 *
 * Three verbs in the shipped schema.json are `anyOf`-shaped — send_sms (2 object
 * branches), sleep (object-with-duration / integer / SWMLVar) and unset (string
 * / array-of-string). connect and play are `oneOf` and were already handled.
 *
 * The semantic: a config satisfying a union satisfies SOME branch, so the known
 * keys are the UNION of the object branches' keys, and a key belonging to no
 * branch belongs to no valid document. Non-object branches contribute nothing
 * (they constrain the config to not be an object at all — a different question).
 * unset has no object branch, so it correctly stays disengaged.
 */
class SchemaUtilsAnyOfTest extends TestCase
{
    /**
     * The verb configs the shipped schema expresses as an anyOf/oneOf, with the
     * key set the union must resolve to and a legitimate config that must keep
     * passing.
     *
     * @return array<string, array{0: string, 1: string, 2: array<string, mixed>, 3: int}>
     */
    public static function unionShapedVerbs(): array
    {
        return [
            // verb, a key that must be in the resolved set, legit config, key count
            'sleep (anyOf: object|integer|SWMLVar)' => ['sleep', 'duration', ['duration' => 5000], 1],
            'send_sms (anyOf of 2 $refs)' => ['send_sms', 'body', [
                'to_number' => '+15551110000',
                'from_number' => '+15552220000',
                'body' => 'hi',
            ], 6],
            'play (oneOf of 2 $refs)' => ['play', 'url', ['url' => 'https://example.test/a.mp3'], 8],
            'connect (oneOf of 4 $refs)' => ['connect', 'to', ['to' => 'sip:alice@example.test'], 22],
        ];
    }

    /**
     * Whether the shallow closed-key check is ENGAGED for a verb, observed
     * through the PUBLIC api: a key that appears in no schema branch is rejected
     * by name.
     *
     * The resolver that computes the key set is private (the python reference has
     * no counterpart, so a public accessor would be invented surface), so the
     * contract is probed the way a caller experiences it.
     */
    private static function checkIsEngaged(SchemaUtils $su, string $verb): bool
    {
        [, $errors] = $su->validateVerb($verb, ['zzz_no_such_key_anywhere' => 1]);
        return str_contains(implode(' ', $errors), 'zzz_no_such_key_anywhere');
    }

    /**
     * Whether a single key is ACCEPTED as known for a verb — i.e. it is in the
     * resolved union of the branch key sets. Probed through the public api by
     * offering the verb a config of just that key with a WELL-TYPED value, and
     * checking that no "Unknown property" error names it.
     *
     * The value has to be well-typed, because the union path reports the branch
     * with the FEWEST errors: a known-but-mistyped key on branch B loses to
     * branch A, which does not declare the key at all and so reports it as
     * unknown. That message misattribution is a pre-existing property of the
     * best-branch reporting (it predates the anyOf fix and is shared with the
     * go/ts/dotnet ports); it does not affect the accept/reject verdict, which is
     * what this suite pins.
     *
     * @param mixed $value a value valid for $key in the branch that declares it
     */
    private static function keyIsKnown(SchemaUtils $su, string $verb, string $key, $value = 'probe'): bool
    {
        [, $errors] = $su->validateVerb($verb, [$key => $value]);
        foreach ($errors as $e) {
            if (str_contains($e, "Unknown property '$key'")) {
                return false;
            }
        }
        return true;
    }

    /**
     * The direct negative control: before the fix sleep and send_sms were
     * DISENGAGED — the closed-key check reported nothing to enforce on them and
     * accepted any key.
     *
     * @param array<string, mixed> $legit
     */
    #[DataProvider('unionShapedVerbs')]
    public function testUnionShapedVerbsResolveAKeySet(
        string $verb,
        string $wantKey,
        array $legit,
        int $wantCount,
    ): void {
        $su = new SchemaUtils();
        $this->assertTrue(
            self::checkIsEngaged($su, $verb),
            "$verb: closed-key check DISENGAGED on a union-shaped config; it must "
            . "resolve to the union of the object branches' keys"
        );
        $this->assertTrue(
            self::keyIsKnown($su, $verb, $wantKey),
            "$verb: '$wantKey' must be in the resolved union of the branch key sets"
        );
    }

    /**
     * The forbidden-key direction, through SchemaUtils: a key present in no
     * branch must be rejected, and the rejection must name it.
     *
     * @param array<string, mixed> $legit
     */
    #[DataProvider('unionShapedVerbs')]
    public function testUnionShapedVerbsRejectUnknownKeys(
        string $verb,
        string $wantKey,
        array $legit,
        int $wantCount,
    ): void {
        $su = new SchemaUtils();
        $cfg = array_merge($legit, ['zzz_forbidden' => 1]);
        [$valid, $errors] = $su->validateVerb($verb, $cfg);
        $this->assertFalse(
            $valid,
            "$verb: a key present in no branch was ACCEPTED — the closed-key check "
            . 'is disengaged on this union-shaped config'
        );
        $this->assertStringContainsString(
            'zzz_forbidden',
            implode(' ', $errors),
            "$verb: rejection must name the offending key"
        );
    }

    /**
     * The forbidden-key direction end to end through the PUBLIC API. This is the
     * one that was live: addVerb() returned true and the stray key was RENDERED
     * INTO THE EMITTED DOCUMENT.
     *
     * @param array<string, mixed> $legit
     */
    #[DataProvider('unionShapedVerbs')]
    public function testPublicAddVerbRejectsUnknownKeyOnUnionShapedVerbs(
        string $verb,
        string $wantKey,
        array $legit,
        int $wantCount,
    ): void {
        $svc = new Service('anyof-test');
        $cfg = array_merge($legit, ['zzz_forbidden' => 1]);
        try {
            $svc->addVerb($verb, $cfg);
            $rendered = $svc->renderDocument();
            $this->fail(
                "$verb: addVerb() ACCEPTED a key present in no schema branch and "
                . "emitted it into the document: $rendered"
            );
        } catch (SchemaValidationError $e) {
            $this->assertStringContainsString('zzz_forbidden', $e->getMessage());
        }
        // And nothing was emitted.
        $this->assertStringNotContainsString('zzz_forbidden', $svc->renderDocument());
    }

    /**
     * The other direction — the fix must not start rejecting valid documents.
     *
     * @param array<string, mixed> $legit
     */
    #[DataProvider('unionShapedVerbs')]
    public function testUnionShapedVerbsAcceptLegitimateConfigs(
        string $verb,
        string $wantKey,
        array $legit,
        int $wantCount,
    ): void {
        $su = new SchemaUtils();
        [$valid, $errors] = $su->validateVerb($verb, $legit);
        $this->assertTrue($valid, "$verb: legitimate config rejected: " . implode('; ', $errors));

        $svc = new Service('anyof-test');
        $this->assertTrue($svc->addVerb($verb, $legit), "$verb: addVerb rejected a legitimate config");
    }

    /**
     * The union-vs-intersection direction, tested explicitly rather than only in
     * aggregate: connect's four ConnectDevice branches differ only in their
     * discriminating key (to / serial / parallel / serial_parallel) and all four
     * must be accepted. A branch set computed as an INTERSECTION would reject
     * three of them.
     */
    public function testConnectAllFourBranchesAccepted(): void
    {
        $su = new SchemaUtils();
        $device = ['to' => 'sip:alice@example.test'];
        // Keyed by the branch's DISCRIMINATING key -> a valid value for it.
        $configs = [
            'to' => 'sip:alice@example.test',
            'serial' => [$device],
            'parallel' => [$device],
            'serial_parallel' => [[$device]],
        ];
        foreach ($configs as $key => $value) {
            $cfg = [$key => $value];
            [$valid, $errors] = $su->validateVerb('connect', $cfg);
            $this->assertTrue(
                $valid,
                "connect/$key rejected — a branch key-set computed as an "
                . 'INTERSECTION instead of a UNION does exactly this: '
                . implode('; ', $errors)
            );
            // Each discriminating key lives in exactly ONE branch, so an
            // intersection would know none of them.
            $this->assertTrue(
                self::keyIsKnown($su, 'connect', $key, $value),
                "connect: union is missing the '$key' branch key"
            );
        }
    }

    /**
     * Pins the shapes that genuinely have no closed key set, so the fix is not
     * read as "always enforce something" — and so php's lack of a full-validator
     * fallback is not mistaken for a reason to make an un-enumerable shape
     * REFUSE. Each of these is legitimately open in the schema:
     *
     *   - set   — an OPEN object (unevaluatedProperties with no `not`, zero
     *             declared properties): a free-form variable bag by design.
     *   - unset — a union with no object branch (string | array of string).
     *   - cond / label / return — array / string / untyped, not objects at all.
     *
     * @return list<array{0: string}>
     */
    public static function nonEnumerableVerbs(): array
    {
        return [['set'], ['unset'], ['cond'], ['label'], ['return']];
    }

    #[DataProvider('nonEnumerableVerbs')]
    public function testNonEnumerableConfigsStayDisengaged(string $verb): void
    {
        $su = new SchemaUtils();
        $this->assertFalse(
            self::checkIsEngaged($su, $verb),
            "$verb has no closed key-set in the schema; the shallow check must stay "
            . 'disengaged rather than invent one'
        );
        // And the check itself must be a no-op, not a rejection: refusing on an
        // un-enumerable shape would reject these VALID documents.
        [$valid, $errors] = $su->validateVerb($verb, ['anything' => 1]);
        $this->assertTrue($valid, "$verb: disengaged check must pass, got " . implode('; ', $errors));
    }

    /**
     * Guards the shape the resolver already handled — a single `$ref`
     * (ai -> AIObject) — since the fix rewrote that path into the shared
     * recursive resolver.
     */
    public function testRefFollowingStillWorks(): void
    {
        $su = new SchemaUtils();
        $this->assertTrue(
            self::checkIsEngaged($su, 'ai'),
            'ai: $ref to AIObject must still resolve to a closed key set'
        );
        foreach (['prompt', 'params', 'SWAIG'] as $want) {
            $this->assertTrue(
                self::keyIsKnown($su, 'ai', $want),
                "ai: resolved key set is missing '$want'"
            );
        }
    }

    /**
     * The engaged/disengaged split is a COUNT, asserted exactly: this fix takes
     * it 32 -> 34 (sleep and send_sms), leaving exactly the five legitimately
     * open or non-object shapes disengaged. A count pinned at an exact bound is
     * what catches a future schema re-vendor silently disengaging a verb again —
     * a percentage floor could not, since 33/34 rounds to 100%.
     */
    public function testEngagedVerbCount(): void
    {
        $su = new SchemaUtils();
        $engaged = [];
        $disengaged = [];
        foreach ($su->getAllVerbNames() as $verb) {
            if (self::checkIsEngaged($su, $verb)) {
                $engaged[] = $verb;
            } else {
                $disengaged[] = $verb;
            }
        }
        $this->assertSame(
            ['cond', 'label', 'return', 'set', 'unset'],
            $disengaged,
            'the disengaged set must be exactly the legitimately-open/non-object shapes'
        );
        $this->assertCount(34, $engaged, 'engaged verbs: ' . implode(' ', $engaged));
    }

    /**
     * The depth bound: a self-referential `$ref` must not spin the resolver.
     * Both recursive paths are cycles here — the verb body is a union whose one
     * branch `$ref`s back to the union itself, so an unbounded resolver would
     * recurse forever through BOTH the union and the `$ref` arms. Without the
     * bound this test hangs (or exhausts the stack) rather than failing.
     *
     * Built as a standalone schema file in a repo-local .tmp so it never touches
     * the bundled schema.json.
     */
    public function testSelfReferentialRefDoesNotSpin(): void
    {
        $dir = dirname(__DIR__) . '/.tmp';
        if (!is_dir($dir)) {
            mkdir($dir, 0o777, true);
        }
        $path = $dir . '/selfref_schema_' . getmypid() . '.json';
        $schema = [
            '$defs' => [
                'SWMLMethod' => ['anyOf' => [['$ref' => '#/$defs/LoopVerb']]],
                // The verb's inner node is a union with a branch that $refs the
                // union back: Cycle -> anyOf[Cycle].
                'LoopVerb' => ['properties' => ['loopy' => ['$ref' => '#/$defs/Cycle']]],
                'Cycle' => ['anyOf' => [['$ref' => '#/$defs/Cycle']]],
            ],
        ];
        file_put_contents($path, (string) json_encode($schema));
        try {
            $su = new SchemaUtils($path);
            $this->assertContains('loopy', $su->getAllVerbNames());
            // No enumerable key set anywhere in the cycle, so the check is a
            // no-op — and it TERMINATES, which is the point of the assertion.
            $this->assertFalse(self::checkIsEngaged($su, 'loopy'));
            [$valid, $errors] = $su->validateVerb('loopy', ['whatever' => 1]);
            $this->assertTrue($valid, implode('; ', $errors));
        } finally {
            @unlink($path);
        }
    }
}
