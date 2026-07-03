#!/usr/bin/env bash
# run-lint.sh — the LINT entry point for signalwire-php (tool: phpstan level 9).
#
# The SINGLE entry point for linting, callable from ANY directory by run-ci, an
# agent, or a human — the tool environment is self-bootstrapped (scripts/_env.sh)
# so it never depends on the caller's shell setup. See
# porting-sdk/RUN_LINT_FORMAT_SPEC.md.
#
# Runs phpstan at level 9 (the MAX level) as the blocking quality floor: ZERO
# findings required, NO ignore-baseline. Reports findings and exits non-zero on any
# finding. Level, paths (src/ + scripts/ + tests/), and the phpstan-phpunit
# type-inference extension live in phpstan.neon.
#
# phpstan has no autofix, so this script is report-only (there is no --fix mode;
# the spec's optional --fix applies only to linters that support autofix).
#
# --memory-limit=1G: phpstan defaults to php.ini's memory_limit (128M on a stock
# CLI install), too low for a full level-9 analysis of src/ + scripts/ + tests/
# (it OOMs mid-run with "Result is incomplete because of severe errors", which
# reads like a finding but isn't). Pin a generous limit so the gate's result
# depends on the code, not the host php.ini. This is NOT a suppression: no
# baseline, no @phpstan-ignore — the analysis still runs to completion at level 9
# and must report zero findings.

set -euo pipefail

# shellcheck source=scripts/_env.sh
source "$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)/_env.sh"

if [ -n "${1:-}" ]; then
    echo "usage: $0" >&2
    echo "  (phpstan has no autofix; this gate is report-only)" >&2
    exit 2
fi

echo "==> LINT (phpstan level 9, zero findings) — repo: $REPO_ROOT"
sw_php_tool phpstan analyse --no-progress --memory-limit=1G
