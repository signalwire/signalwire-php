<?php

/**
 * php-cs-fixer config for the signalwire-php SDK (the FMT gate).
 *
 * House style: PSR-12 plus the strict-types declaration this codebase
 * already carries in every file (`declare(strict_types=1)`). This is a
 * SOURCE-STYLE-ONLY gate — it must NOT change the SWAIG wire bytes or the
 * audited public surface (port_signatures.json + port_surface.json stay
 * byte-identical, the EMISSION differ stays green). Accordingly we keep the
 * rule set to formatting/whitespace/ordering concerns and avoid any rule that
 * could rewrite identifiers, string contents, or array *values*.
 *
 * Local ($CI unset) run-ci.sh applies fixes in place; CI ($CI=true) runs
 * --dry-run --diff and fails on any unformatted source. See scripts/run-ci.sh.
 */

declare(strict_types=1);

// EVERY PHP directory in the repo. There is one house style — the one the
// shipped library meets — and examples/ is shipping code too (owner ruling
// 2026-07-29). Until 2026-07-30 examples/, relay/examples/ and rest/examples/
// were formatted by NOTHING, and bin/ was covered only for the single named
// `swaig-test` while its six sibling extensionless CLIs were skipped.
//
// bin/ holds EXTENSIONLESS `#!/usr/bin/env php` scripts, which `->name('*.php')`
// cannot match, so each is named explicitly. ADD ANY NEW bin/ SCRIPT HERE —
// scripts/check_bin_coverage.php is the gate that fails if one is missing.
$finder = PhpCsFixer\Finder::create()
    ->in(__DIR__ . '/src')
    ->in(__DIR__ . '/tests')
    ->in(__DIR__ . '/scripts')
    ->in(__DIR__ . '/bin')
    ->in(__DIR__ . '/examples')
    ->in(__DIR__ . '/relay/examples')
    ->in(__DIR__ . '/rest/examples')
    ->name('*.php')
    ->name('envelope-dump')
    ->name('pagination-dump')
    ->name('secret-scrub-dump')
    ->name('secure-default-dump')
    ->name('swaig-test')
    ->name('token-interop-mint')
    ->name('wait-liveness-dump')
    ->ignoreDotFiles(true)
    ->ignoreVCS(true);

return (new PhpCsFixer\Config())
    ->setRiskyAllowed(true)
    ->setFinder($finder)
    ->setRules([
        '@PSR12' => true,
        // Enforce the strict_types declaration already present everywhere.
        'declare_strict_types' => true,
        // Imports: ordered + single-line, no unused — pure source hygiene.
        'no_unused_imports' => true,
        'ordered_imports' => [
            'sort_algorithm' => 'alpha',
            'imports_order' => ['class', 'function', 'const'],
        ],
        'single_line_after_imports' => true,
        'no_leading_import_slash' => true,
        // Array style: short syntax (matches the codebase) + trailing commas
        // in multiline literals (does not change emitted values).
        'array_syntax' => ['syntax' => 'short'],
        'trailing_comma_in_multiline' => ['elements' => ['arrays']],
        'no_whitespace_in_blank_line' => true,
        'no_extra_blank_lines' => true,
        'single_quote' => true,
        'no_trailing_whitespace' => true,
        'blank_line_after_opening_tag' => true,
        'method_chaining_indentation' => true,
    ]);
