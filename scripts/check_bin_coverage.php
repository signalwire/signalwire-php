<?php

/**
 * check_bin_coverage.php — the BIN-COVERAGE gate.
 *
 * bin/ holds extensionless `#!/usr/bin/env php` CLI scripts. Neither of the two
 * quality tools can pick those up by a glob:
 *
 *   - phpstan walks a directory for `*.php` only and has no extensionless-glob
 *     option (`fileExtensions` requires an actual extension), so `paths: [bin]`
 *     silently analyses NOTHING but bin/ai-chat-*.php.
 *   - php-cs-fixer's Finder matches by `->name()`, so an extensionless file is
 *     covered only if it is named one-by-one.
 *
 * Both configs therefore carry a hand-maintained list, and a hand-maintained
 * list rots the moment someone adds a CLI. That is exactly how bin/ ended up
 * with 2113 LOC of shipped code — including the documented `swaig-test` —
 * analysed by nothing at all until 2026-07-30.
 *
 * This gate makes the rot impossible: every extensionless file in bin/ must be
 * listed in BOTH phpstan.neon's `paths` and .php-cs-fixer.php's finder. Adding a
 * CLI without wiring it reds the gate with the exact lines to add.
 *
 * Exit 0 = every bin/ script is covered by both tools; 1 = something is missing.
 */

declare(strict_types=1);

$repoRoot = dirname(__DIR__);
$binDir = $repoRoot . '/bin';

$phpstanPath = $repoRoot . '/phpstan.neon';
$fixerPath = $repoRoot . '/.php-cs-fixer.php';

$phpstan = file_get_contents($phpstanPath);
$fixer = file_get_contents($fixerPath);
if ($phpstan === false || $fixer === false) {
    fwrite(STDERR, "BIN-COVERAGE: cannot read phpstan.neon / .php-cs-fixer.php\n");
    exit(1);
}

/** @var list<string> $missing */
$missing = [];
/** @var list<string> $covered */
$covered = [];

$entries = scandir($binDir);
if ($entries === false) {
    fwrite(STDERR, "BIN-COVERAGE: cannot read {$binDir}\n");
    exit(1);
}
sort($entries);

foreach ($entries as $entry) {
    if ($entry === '.' || $entry === '..') {
        continue;
    }
    $full = $binDir . '/' . $entry;
    if (!is_file($full)) {
        continue;
    }
    // *.php files ARE matched by both tools' globs — nothing to hand-wire.
    if (str_ends_with($entry, '.php')) {
        continue;
    }

    $inPhpstan = str_contains($phpstan, '- bin/' . $entry);
    $inFixer = str_contains($fixer, "->name('" . $entry . "')");

    if ($inPhpstan && $inFixer) {
        $covered[] = $entry;
        continue;
    }

    $lack = [];
    if (!$inPhpstan) {
        $lack[] = "phpstan.neon (add:  - bin/{$entry})";
    }
    if (!$inFixer) {
        $lack[] = ".php-cs-fixer.php (add:  ->name('{$entry}'))";
    }
    $missing[] = $entry . ' — not covered by ' . implode(' and ', $lack);
}

if ($missing !== []) {
    fwrite(STDERR, "BIN-COVERAGE FAIL: extensionless bin/ script(s) escape lint/format:\n");
    foreach ($missing as $line) {
        fwrite(STDERR, "  {$line}\n");
    }
    fwrite(STDERR, "\nEvery bin/ CLI must be listed in BOTH configs by explicit name;\n");
    fwrite(STDERR, "neither tool can glob an extensionless file.\n");
    exit(1);
}

printf("BIN-COVERAGE: %d extensionless bin/ script(s) covered by phpstan + php-cs-fixer\n", count($covered));
exit(0);
