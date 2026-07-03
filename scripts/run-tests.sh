#!/usr/bin/env bash
# run-tests.sh — the TEST entry point for signalwire-php (tool: phpunit).
#
# The SINGLE entry point for the test suite, callable from ANY directory by run-ci,
# an agent, or a human — the tool environment is self-bootstrapped (scripts/_env.sh)
# so it never depends on the caller's shell setup. See
# porting-sdk/RUN_LINT_FORMAT_SPEC.md.
#
# Runs the full phpunit suite; exits non-zero on any failure. The mock-backed
# suites self-bootstrap the shared mock servers via the per-test harness (which
# picks a free port and self-terminates on parent death), so no mock lifecycle is
# needed here.
#
# Modes:
#   bash scripts/run-tests.sh              # run the full suite (phpunit).
#   bash scripts/run-tests.sh <filter>     # pass <filter> to phpunit --filter to
#                                          #   run a subset (a test name / regex).
#   bash scripts/run-tests.sh --parallel   # run the full suite under paratest
#                                          #   (file parallelism). This is the path
#                                          #   run-ci.sh uses after it has pre-spawned
#                                          #   the shared mock servers and exported
#                                          #   their ports (the harness reuses the
#                                          #   exported port). Use plain mode (no
#                                          #   flag) from a bare shell — the per-test
#                                          #   harness then spawns/reuses a mock
#                                          #   itself, so it Just Works from any CWD.

set -euo pipefail

# shellcheck source=scripts/_env.sh
source "$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)/_env.sh"

if [ "${1:-}" = "--parallel" ]; then
    procs="${PARATEST_PROCS:-8}"
    echo "==> TEST (paratest -p $procs, parallel) — repo: $REPO_ROOT"
    sw_php_tool paratest --runner=WrapperRunner -p "$procs" --no-coverage
elif [ -n "${1:-}" ]; then
    echo "==> TEST (phpunit --filter '$1') — repo: $REPO_ROOT"
    sw_php_tool phpunit --no-coverage --filter "$1"
else
    echo "==> TEST (phpunit, full suite) — repo: $REPO_ROOT"
    sw_php_tool phpunit --no-coverage
fi
