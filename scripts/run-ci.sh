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
#   8. lint gate                          — phpstan level 9, zero findings (the floor)
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
PARALLEL_PROCS="${PARATEST_PROCS:-8}"
MOCK_PIDS=""
PYTHON_BIN="${MOCK_SIGNALWIRE_PYTHON:-$(command -v python3 || command -v python || echo python3)}"

# pick_free_port — ask the OS for an unused TCP port by binding :0 and reading
# back the assigned port, then closing the socket. There is an inherent (small)
# TOCTOU window between close and the mock's own bind, but the mock spawns
# immediately after and its health-poll fails loud if the bind lost a race, so a
# transient collision surfaces as a clear "did not become ready" rather than a
# silent hang on a wrong/occupied port. Replaces the old hardcoded
# 8768/8778/9778/8788 defaults so parallel runs (CI matrix, multiple local
# checkouts) never collide on a fixed port.
pick_free_port() {
    "$PYTHON_BIN" -c 'import socket
s = socket.socket(socket.AF_INET, socket.SOCK_STREAM)
s.bind(("127.0.0.1", 0))
print(s.getsockname()[1])
s.close()'
}

# Mock ports for the TEST gate: honor an explicit env override, else pick a free
# port dynamically. Exported so every paratest worker probes-and-reuses the one
# server this script spawns (see spawn_mocks) rather than spawning its own. A
# failed pick aborts the run loudly instead of silently falling back to a fixed
# port that might be occupied.
MOCK_SIGNALWIRE_PORT="${MOCK_SIGNALWIRE_PORT:-$(pick_free_port)}"
MOCK_RELAY_PORT="${MOCK_RELAY_PORT:-$(pick_free_port)}"
MOCK_RELAY_HTTP_PORT="${MOCK_RELAY_HTTP_PORT:-$(pick_free_port)}"
if [ -z "$MOCK_SIGNALWIRE_PORT" ] || [ -z "$MOCK_RELAY_PORT" ] || [ -z "$MOCK_RELAY_HTTP_PORT" ]; then
    echo "FATAL: could not allocate a free port for the mock servers" >&2
    exit 2
fi
export MOCK_SIGNALWIRE_PORT MOCK_RELAY_PORT MOCK_RELAY_HTTP_PORT

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

# Gate 5b: REST-COVERAGE — every canonical REST route the SDK implements must be
# exercised with BOTH a success (2xx) AND an error (4xx/5xx) response on the
# correct on-the-wire path (parity). Measured by replaying the mock journal of a
# REST-suite run through porting-sdk's rest_coverage checker. Accepted gaps —
# routes with no SDK method, malformed canonical routes, mock-router collisions —
# are allowlisted: the shared baseline (porting-sdk/REST_COVERAGE_BASELINE.md) +
# this port's REST_COVERAGE_GAPS.md. A stale entry (route now covered) fails the
# gate. Self-contained: spins its own mock on a dedicated port, runs ONLY the
# tests/Rest suite SERIALLY (phpunit, one process) so all traffic lands in one
# journal, then checks that journal. Same shape as python's/go's/java's gate.
rest_coverage_gate() {
    local port
    port="$(pick_free_port)" || return 1
    [ -n "$port" ] || { echo "FATAL: could not allocate a free port for the REST-coverage mock" >&2; return 1; }
    local mock_pkg_parent="$PORTING_SDK_DIR/test_harness/mock_signalwire"
    PYTHONPATH="$mock_pkg_parent${PYTHONPATH:+:$PYTHONPATH}" \
        "$PYTHON_BIN" -m mock_signalwire --host 127.0.0.1 --port "$port" \
        --log-level error >/tmp/rest_cov_mock_php.$$.log 2>&1 &
    local mock_pid=$!
    # shellcheck disable=SC2064
    trap "kill $mock_pid 2>/dev/null" RETURN
    local i
    for i in $(seq 1 60); do
        if probe_mock "http://127.0.0.1:$port/__mock__/health" '"specs_loaded"'; then
            break
        fi
        sleep 0.5
    done
    curl -fsS --max-time 5 -X POST "http://127.0.0.1:$port/__mock__/journal/reset" \
        >/dev/null || return 1
    # Run the REST suite serially against this dedicated mock so the journal is a
    # single complete trace. Each scopedClient test isolates by auth header, so
    # serial is correct (and required for a clean journal replay).
    MOCK_SIGNALWIRE_PORT="$port" vendor/bin/phpunit --no-coverage tests/Rest || return 1
    PYTHONPATH="$mock_pkg_parent${PYTHONPATH:+:$PYTHONPATH}" \
        "$PYTHON_BIN" -m mock_signalwire.rest_coverage \
        --mock-url "http://127.0.0.1:$port" \
        --spec-root "$PORTING_SDK_DIR/rest-apis" \
        --allowlist "$PORTING_SDK_DIR/REST_COVERAGE_BASELINE.md" \
        --allowlist "$PORT_ROOT/REST_COVERAGE_GAPS.md" \
        --gap-baseline "$PORTING_SDK_DIR/REST_COVERAGE_GAP_BASELINE.md"
}
run_gate "REST-COVERAGE" "every implemented REST route covered success+error (parity + allowlist)" \
    rest_coverage_gate

# Gate 5c: SPEC-PARITY — the routes the SDK actually IMPLEMENTS must equal the
# canonical spec route set, modulo porting-sdk/SPEC_IMPLEMENTATION_GAPS.md. This
# is the spec-first guard REST-COVERAGE can't give: REST-COVERAGE only proves
# *tested* routes match the spec, so a route the SDK implements that the spec
# doesn't define (or a canonical route never implemented) slips past it. Set B is
# built by scripts/route_registry.php — it drives the live RestClient through a
# recording HttpClient (records (method, path), returns []) and reflects over
# every namespace/sub-resource public method, invoking each with sentinel args,
# so it sees every dispatched route whether or not it's tested. The shared
# porting-sdk diff consumes that JSON via --registry-json.
spec_parity_gate() {
    local mock_pkg_parent="$PORTING_SDK_DIR/test_harness/mock_signalwire"
    export PYTHONPATH="$mock_pkg_parent${PYTHONPATH:+:$PYTHONPATH}"
    local registry
    registry="$(mktemp)"
    # SIGNALWIRE_LOG_MODE=off so the SDK logger doesn't pollute stdout JSON.
    SIGNALWIRE_LOG_MODE=off php "$PORT_ROOT/scripts/route_registry.php" >"$registry" 2>/dev/null || {
        rm -f "$registry"; return 1
    }
    "$PYTHON_BIN" "$PORTING_SDK_DIR/scripts/diff_spec_implementation.py" \
        --registry-json "$registry" \
        --gaps "$PORTING_SDK_DIR/SPEC_IMPLEMENTATION_GAPS.md"
    local rc=$?
    rm -f "$registry"
    return $rc
}
run_gate "SPEC-PARITY" "implemented routes == canonical spec (modulo SPEC_IMPLEMENTATION_GAPS.md)" \
    spec_parity_gate

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

# Gate 8: LINT — the language lint gate (php: phpstan level 9, zero findings).
# This is the blocking quality floor: phpstan.neon analyses src/ + scripts/ at
# level 9 (the MAX level — full mixed-type strictness) with ZERO genuine
# findings and NO ignore-baseline. The burndown took it 41 → 0 (level 5),
# 372 → 0 (level 6, bare-array value-type block), then the three deferred
# level-7+ item-groups were cleared at the source: duck-typed receivers got
# minimal INTERNAL interfaces (Agent\AgentInterface for skills, Relay\
# RelayClientLike for Call/Action, SWML\RequestHandlerLike for the serverless
# Adapter — the TS RelayClientLike pattern); the FaxAction|T startAction return
# got a genuine `assert($action instanceof $actionClass)` invariant; and the
# curl CURLOPT_URL non-empty-string strictness was carried from its real source
# (RestClient establishes non-empty baseUrl, HttpClient keeps the non-empty-
# string type to the curl call) with a genuine empty-input guard at HttpHelper's
# external entry. Level 8→9 then cleared ~375 mixed-narrowing findings, each
# fixed by genuine runtime narrowing (is_string/is_array/...) or precise array
# shapes sourced from the python reference / SWML schema — no @phpstan-ignore,
# no baseline, no silencing casts, no mixed-widening.
# Mirrors the go golangci / rust clippy blocking-lint gate.
#
# --memory-limit=512M: phpstan defaults to php.ini's memory_limit, which on a
# stock CLI install is 128M — too low for a full level-9 analysis of src/ +
# scripts/ (it OOMs mid-run with "Result is incomplete because of severe
# errors", not a real finding). Pin a generous limit so the gate's result
# depends on the code, not the host php.ini. This is not a suppression: no
# baseline, no @phpstan-ignore — the analysis still runs to completion at
# level 9 and must report zero findings.
run_gate "LINT" "phpstan level 9 zero findings (lint gate)" \
    vendor/bin/phpstan analyse --no-progress --memory-limit=512M

# Gate 9: DOC-AUDIT — every method/class referenced in docs/ + examples/ fenced
# code blocks must resolve to a real symbol in the port surface (catches
# phantom-API doc promises). Uses the committed port_surface.json (the
# SURFACE-FRESH gate above already proved it is fresh) + DOC_AUDIT_IGNORE.md for
# intentional non-symbol references (PHP stdlib, SWML auto-vivified verbs, REST
# CrudResource URL-driven dynamic methods). Mirrors the ruby/go DOC-AUDIT gate.
run_gate "DOC-AUDIT" "audit_docs vs port_surface.json" \
    python3 "$PORTING_SDK_DIR/scripts/audit_docs.py" \
        --root "$PORT_ROOT" \
        --surface "$PORT_ROOT/port_surface.json" \
        --ignore "$PORT_ROOT/DOC_AUDIT_IGNORE.md"

# Gate 10: surface-diff — diff the port's public surface against the Python
# reference (omissions + additions). The signature DRIFT gate (Layer A) checks
# method *signatures*; this checks surface *membership* — it catches public
# symbols the port has that Python doesn't (helpers leaked onto the surface by a
# refactor) and vice-versa. Regenerate the surface in place via the PHP surface
# enumerator, diff against python_surface.json, then restore the committed copy
# unconditionally (pass or fail). Mirrors the ruby/go SURFACE-DIFF gate. NB the
# enumerator correctly enters Action.php's 11 co-located RELAY action classes
# (PSR-4 multi-class file) via its brace-depth scoping + CLASS_MODULE_MAP, so
# they appear in port_surface.json and do not read as phantom omissions.
surface_diff_gate() {
    git show HEAD:port_surface.json > /tmp/committed_surface_diff.json 2>/dev/null \
        || cp "$PORT_ROOT/port_surface.json" /tmp/committed_surface_diff.json
    python3 scripts/enumerate_surface.py
    local regen_rc=$?
    if [ "$regen_rc" -ne 0 ]; then
        git checkout -- port_surface.json 2>/dev/null
        return $regen_rc
    fi
    python3 "$PORTING_SDK_DIR/scripts/diff_port_surface.py" \
        --reference "$PORTING_SDK_DIR/python_surface.json" \
        --port-surface "$PORT_ROOT/port_surface.json" \
        --omissions "$PORT_ROOT/PORT_OMISSIONS.md" \
        --additions "$PORT_ROOT/PORT_ADDITIONS.md"
    local check_rc=$?
    git checkout -- port_surface.json 2>/dev/null
    return $check_rc
}
run_gate "SURFACE-DIFF" "diff_port_surface vs python reference" \
    surface_diff_gate

# Gate 11: SKILL-CONTRACT — the surface/drift/emission gates see signatures +
# symbol names + FunctionResult.toArray(); NONE sees a built-in skill's SWAIG
# tool contract ({name, parameters, required, enum} each skill registers). This
# differ closes that gap: it builds the Python oracle by instantiating each
# covered reference skill, runs the PHP skill-dump program (scripts/emit_skills.php,
# which reads the SAME shared corpus via skill_contract_corpus.py), and
# structurally compares the two. DESCRIPTIONS + implementation (handler vs
# DataMap) are not compared — only name/param-name/param-type/enum/required.
# Mirrors the ruby/go SKILL-CONTRACT gate. Same prereqs as EMISSION
# (signalwire-python adjacent; no network).
run_gate "SKILL-CONTRACT" "diff_skill_contracts vs python reference" \
    python3 "$PORTING_SDK_DIR/scripts/diff_skill_contracts.py" \
        --dump-cmd "php scripts/emit_skills.php" \
        --port-repo "$PORT_ROOT"

if [ -z "$FAILED_GATES" ]; then
    echo "==> CI PASS"
    exit 0
else
    echo "==> CI FAIL (gates:$FAILED_GATES )"
    exit 1
fi
