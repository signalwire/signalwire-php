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

$finder = PhpCsFixer\Finder::create()
    ->in(__DIR__ . '/src')
    ->in(__DIR__ . '/tests')
    ->in(__DIR__ . '/scripts')
    ->in(__DIR__ . '/bin')
    ->name('*.php')
    ->name('swaig-test')
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
