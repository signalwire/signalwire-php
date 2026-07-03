#!/usr/bin/env bash
# run-format.sh — the FMT entry point for signalwire-php (tool: php-cs-fixer).
#
# The SINGLE entry point for formatting, callable from ANY directory by run-ci, an
# agent, or a human — the tool environment is self-bootstrapped (scripts/_env.sh)
# so it never depends on the caller's shell setup. See
# porting-sdk/RUN_LINT_FORMAT_SPEC.md.
#
# Modes:
#   bash scripts/run-format.sh            # DEFAULT: APPLY — reformat the tree in
#                                         #   place (php-cs-fixer fix). Exit 0 on
#                                         #   success even if it changed files.
#   bash scripts/run-format.sh --check    # VERIFY-ONLY (CI) — do not modify; exit
#                                         #   non-zero if anything is unformatted
#                                         #   (php-cs-fixer fix --dry-run --diff).
#
# Scope (src/ + scripts/ + tests/ + the generated trees) and the rule set are
# defined once in .php-cs-fixer.php, so this script passes MODE flags only. The
# rule set is scoped to formatting/whitespace/import-ordering — it cannot rewrite
# identifiers, string contents, or array values.
#
# Idempotent: a second run right after the first produces no diff.

set -euo pipefail

# shellcheck source=scripts/_env.sh
source "$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)/_env.sh"

MODE="apply"
if [ "${1:-}" = "--check" ]; then
    MODE="check"
elif [ -n "${1:-}" ]; then
    echo "usage: $0 [--check]" >&2
    exit 2
fi

if [ "$MODE" = "check" ]; then
    echo "==> FMT check (php-cs-fixer --dry-run, read-only) — repo: $REPO_ROOT"
    # --dry-run is read-only; any unformatted source → non-zero.
    sw_php_tool php-cs-fixer fix --dry-run --diff
else
    echo "==> FMT apply (php-cs-fixer fix) — repo: $REPO_ROOT"
    # Reformat in place; report if it changed files, but still succeed (a
    # formatting APPLY that changed files is a success).
    sw_php_tool php-cs-fixer fix >/dev/null || true
    if ! (cd "$REPO_ROOT" && git diff --quiet 2>/dev/null); then
        echo "    (FMT applied formatting to your working tree — review & stage)"
    fi
    # A residual issue php-cs-fixer can't fix must still fail the gate.
    sw_php_tool php-cs-fixer fix --dry-run
fi
