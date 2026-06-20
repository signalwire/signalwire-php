#!/usr/bin/env bash
# run-ci.sh — canonical local-and-CI gate runner for signalwire-php.
#
# Same script invoked locally (`bash scripts/run-ci.sh`) AND by the
# GitHub Actions workflow. No drift between local and CI behavior.
#
# Gates (in order, fail-fast):
#   1. vendor/bin/phpunit                 — language test runner
#   2. signature regen                    — python adapter + signature_dump.php
#   3. drift gate                         — porting-sdk diff_port_signatures.py
#   4. surface-fresh gate                 — porting-sdk check_surface_freshness.py
#                                           (regen port_surface.json in place and
#                                            confirm the committed copy still
#                                            matches, modulo the generated_from
#                                            git-sha; closes the Layer-B-not-gated
#                                            hole where port_surface.json rots)
#   5. no-cheat gate                      — porting-sdk audit_no_cheat_tests.py
#   6. emission gate                      — porting-sdk diff_port_emission.py
#                                           (byte-compare FunctionResult.toArray()
#                                            vs Python to_dict() over the shared
#                                            81-entry corpus; needs no mocks)
#   7. fmt gate                           — php-cs-fixer (local: apply; CI: --dry-run)
#   8. lint gate                          — phpstan level 5, zero findings (the floor)
#   9. doc-audit gate                     — porting-sdk audit_docs.py
#  10. surface-diff gate                  — porting-sdk diff_port_surface.py
#  11. skill-contract gate                — porting-sdk diff_skill_contracts.py

set -u
set -o pipefail

PORT_ROOT="$(cd "$(dirname "$0")/.." && pwd)"
PORT_NAME="signalwire-php"

resolve_porting_sdk() {
    if [ -n "${PORTING_SDK:-}" ] && [ -d "$PORTING_SDK/scripts" ]; then
        echo "$PORTING_SDK"
        return 0
    fi
    if [ -d "$PORT_ROOT/../porting-sdk/scripts" ]; then
        (cd "$PORT_ROOT/../porting-sdk" && pwd)
        return 0
    fi
    return 1
}

PORTING_SDK_DIR="$(resolve_porting_sdk)" || {
    echo "FATAL: porting-sdk not found, clone it adjacent to this repo" >&2
    echo "       (expected $PORT_ROOT/../porting-sdk or \$PORTING_SDK env var)" >&2
    exit 2
}

# ── Mock-server lifecycle (for the PARALLEL test gate) ────────────────────
#
# The mock-backed suites are session-isolated (RELAY journal/scenarios scoped
# by the connect-handshake sessionid; REST journal/scenarios scoped by the
# per-test random project's Authorization header), so they are safe to run
# under file/process parallelism. But paratest runs each test file in its own
# PHP process, and the per-test harness's probe-OR-spawn helper would otherwise
# have several workers race to spawn the mock — and worse, the worker that
# spawned it kills it (via register_shutdown_function) when that worker exits,
# yanking the server out from under still-running workers.
#
# Fix: spawn each mock ONCE here, wait for health, export the fixed ports so
# every worker just probes-and-reuses (never spawns, never registers a kill
# hook), and tear them down in a trap. This mirrors the dotnet lifecycle in
# MOCK_TEST_HARNESS.md.
MOCK_SIGNALWIRE_PORT="${MOCK_SIGNALWIRE_PORT:-8768}"
MOCK_RELAY_PORT="${MOCK_RELAY_PORT:-8778}"
MOCK_RELAY_HTTP_PORT="${MOCK_RELAY_HTTP_PORT:-9778}"
export MOCK_SIGNALWIRE_PORT MOCK_RELAY_PORT MOCK_RELAY_HTTP_PORT

PARALLEL_PROCS="${PARATEST_PROCS:-8}"
MOCK_PIDS=""
PYTHON_BIN="${MOCK_SIGNALWIRE_PYTHON:-$(command -v python3 || command -v python || echo python3)}"

probe_mock() {  # $1 = url, $2 = needle
    curl -fsS --max-time 2 "$1" 2>/dev/null | grep -q "$2"
}

spawn_mocks() {
    local relay_pkg signalwire_pkg
    relay_pkg="$PORTING_SDK_DIR/test_harness/mock_relay"
    signalwire_pkg="$PORTING_SDK_DIR/test_harness/mock_signalwire"

    if ! probe_mock "http://127.0.0.1:$MOCK_SIGNALWIRE_PORT/__mock__/health" '"specs_loaded"'; then
        PYTHONPATH="$signalwire_pkg${PYTHONPATH:+:$PYTHONPATH}" \
            "$PYTHON_BIN" -m mock_signalwire --host 127.0.0.1 \
            --port "$MOCK_SIGNALWIRE_PORT" --log-level error >/dev/null 2>&1 &
        MOCK_PIDS="$MOCK_PIDS $!"
    fi
    if ! probe_mock "http://127.0.0.1:$MOCK_RELAY_HTTP_PORT/__mock__/health" '"schemas_loaded"'; then
        PYTHONPATH="$relay_pkg${PYTHONPATH:+:$PYTHONPATH}" \
            "$PYTHON_BIN" -m mock_relay --host 127.0.0.1 \
            --ws-port "$MOCK_RELAY_PORT" --http-port "$MOCK_RELAY_HTTP_PORT" \
            --log-level error >/dev/null 2>&1 &
        MOCK_PIDS="$MOCK_PIDS $!"
    fi

    local deadline=$(( $(date +%s) + 30 ))
    while [ "$(date +%s)" -lt "$deadline" ]; do
        if probe_mock "http://127.0.0.1:$MOCK_SIGNALWIRE_PORT/__mock__/health" '"specs_loaded"' \
            && probe_mock "http://127.0.0.1:$MOCK_RELAY_HTTP_PORT/__mock__/health" '"schemas_loaded"'; then
            return 0
        fi
        sleep 0.2
    done
    echo "FATAL: mock servers did not become ready" >&2
    return 1
}

kill_mocks() {
    [ -n "$MOCK_PIDS" ] || return 0
    # shellcheck disable=SC2086
    kill $MOCK_PIDS 2>/dev/null || true
    MOCK_PIDS=""
}
trap kill_mocks EXIT INT TERM

FAILED_GATES=""

run_gate() {
    local name="$1"; shift
    local description="$1"; shift
    local logfile
    logfile="$(mktemp)"
    "$@" >"$logfile" 2>&1
    local rc=$?
    if [ "$rc" -eq 0 ]; then
        echo "[$name] $description ... PASS"
        rm -f "$logfile"
        return 0
    fi
    echo "[$name] $description ... FAIL: exit $rc"
    sed 's/^/    /' "$logfile" | tail -40
    rm -f "$logfile"
    FAILED_GATES="$FAILED_GATES $name"
    return $rc
}

# surface_fresh_gate — regenerate port_surface.json in place via the PHP
# surface enumerator and assert the committed copy still matches a fresh regen
# (modulo the volatile generated_from git-sha, which the freshness checker
# strips). Runs as a single command under run_gate so any non-zero step trips
# the gate; the committed file is always restored, pass or fail.
surface_fresh_gate() {
    # 1. Snapshot the committed surface (HEAD), falling back to a working-tree
    #    copy if the file isn't tracked yet. Run in a subshell so the
    #    regen-and-restore stays self-contained (and any set -e here can't
    #    leak into the parent gate loop's fail-fast accounting).
    (
        set -e
        if git show HEAD:port_surface.json > /tmp/committed_surface.json 2>/dev/null; then
            :
        else
            cp port_surface.json /tmp/committed_surface.json
        fi
        # 2. Regenerate port_surface.json in place (the enumerator writes the
        #    file directly; no stdout redirect needed).
        python3 scripts/enumerate_surface.py
        # 3. Compare the committed copy against the fresh regen, modulo
        #    provenance. Restore the committed file regardless of outcome.
        rc=0
        python3 "$PORTING_SDK_DIR/scripts/check_surface_freshness.py" \
            --committed /tmp/committed_surface.json \
            --fresh port_surface.json || rc=$?
        git checkout -- port_surface.json
        exit "$rc"
    )
}

cd "$PORT_ROOT"

echo "==> running CI gates for $PORT_NAME (porting-sdk at $PORTING_SDK_DIR)"

# Gate 1: tests — run the full suite in PARALLEL via paratest (each file in
# its own process). The mock-backed suites are session-isolated, so file
# parallelism is safe; pure-unit files run independently too. We pre-spawn the
# shared mock servers once (see spawn_mocks) so workers probe-and-reuse rather
# than each spawning (and killing) their own.
if spawn_mocks; then
    run_gate "TEST" "paratest -p $PARALLEL_PROCS (parallel)" \
        vendor/bin/paratest --runner=WrapperRunner -p "$PARALLEL_PROCS" --no-coverage
else
    FAILED_GATES="$FAILED_GATES TEST"
fi

# Gate 2: signature regen
run_gate "SIGNATURES" "regenerate port_signatures.json" \
    python3 scripts/enumerate_signatures.py

# Gate 3: drift gate
run_gate "DRIFT" "diff_port_signatures vs python reference" \
    python3 "$PORTING_SDK_DIR/scripts/diff_port_signatures.py" \
        --reference "$PORTING_SDK_DIR/python_signatures.json" \
        --port-signatures "$PORT_ROOT/port_signatures.json" \
        --surface-omissions "$PORT_ROOT/PORT_OMISSIONS.md" \
        --surface-additions "$PORT_ROOT/PORT_ADDITIONS.md" \
        --omissions "$PORT_ROOT/PORT_SIGNATURE_OMISSIONS.md"

# Gate 4: surface-fresh — regenerate port_surface.json (Layer B) and assert the
# committed copy matches a fresh regen modulo the generated_from git-sha. Closes
# the hole where the drift gate only polices Layer A and port_surface.json rots.
run_gate "SURFACE-FRESH" "check_surface_freshness vs fresh regen" \
    surface_fresh_gate

# Gate 5: no-cheat
run_gate "NO-CHEAT" "audit_no_cheat_tests" \
    python3 "$PORTING_SDK_DIR/scripts/audit_no_cheat_tests.py" --root "$PORT_ROOT"

# Gate 6: emission — byte-compare the native FunctionResult serialisation against
# Python's to_dict() across the shared corpus. Pure serialisation: no mock
# servers, no network. The dump command runs with cwd = the port root.
run_gate "EMISSION" "diff_port_emission vs python to_dict()" \
    python3 "$PORTING_SDK_DIR/scripts/diff_port_emission.py" \
        --dump-cmd "php scripts/emit_corpus.php" \
        --port-repo "$PORT_ROOT"

# Gate 7: FMT — the language format gate (php: php-cs-fixer). Source-style only
# and proven surface/emission-neutral (a reformat leaves port_signatures.json +
# port_surface.json byte-identical modulo the generated_from git-sha, and
# EMISSION 81/81 — verified during the FMT rollout). The .php-cs-fixer.php config
# is scoped to formatting/whitespace/import-ordering so it cannot rewrite
# identifiers, string contents, or array values. Mirrors the ruby/go FMT shape:
#   * LOCAL ($CI unset)  → `fix`: reformats your working tree in place so you
#     never hand-run it; notes if it changed files.
#   * CI ($CI=true)      → `fix --dry-run --diff` (read-only): FAILS if any
#     unformatted source reached CI.
# PHP_CS_FIXER_IGNORE_ENV=1 keeps php-cs-fixer from refusing on a newer PHP
# runtime than it formally supports — the rule set used here is version-stable.
fmt_gate() {
    if [ -n "${CI:-}" ]; then
        PHP_CS_FIXER_IGNORE_ENV=1 vendor/bin/php-cs-fixer fix --dry-run --diff
    else
        PHP_CS_FIXER_IGNORE_ENV=1 vendor/bin/php-cs-fixer fix >/dev/null
        if ! git diff --quiet 2>/dev/null; then
            echo "    (FMT auto-applied formatting to your working tree — review & stage)"
        fi
        # A residual issue php-cs-fixer can't fix must still fail the gate.
        PHP_CS_FIXER_IGNORE_ENV=1 vendor/bin/php-cs-fixer fix --dry-run
    fi
}
run_gate "FMT" "php-cs-fixer (local: apply; CI: --dry-run --diff)" fmt_gate

# Gate 8: LINT — the language lint gate (php: phpstan level 5, zero findings).
# This is the blocking quality floor: phpstan.neon analyses src/ + scripts/ at
# level 5 (the highest level reachable with ZERO genuine findings without an
# ignore-baseline) and the burndown took it 41 → 0 with every fix at the source
# (no @phpstan-ignore, no baseline, no silencing casts). Proven neutral:
# port_signatures.json byte-identical, port_surface.json differs only in the
# generated_from git-sha, EMISSION 81/81. Mirrors the go golangci / rust clippy
# blocking-lint gate.
run_gate "LINT" "phpstan level 5 zero findings (lint gate)" \
    vendor/bin/phpstan analyse --no-progress

if [ -z "$FAILED_GATES" ]; then
    echo "==> CI PASS"
    exit 0
else
    echo "==> CI FAIL (gates:$FAILED_GATES )"
    exit 1
fi
