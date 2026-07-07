#!/usr/bin/env bash
# run-ci.sh — canonical local-and-CI gate runner for signalwire-php.
#
# Same script invoked locally (`bash scripts/run-ci.sh`) AND by the
# GitHub Actions workflow. No drift between local and CI behavior.
#
# The TEST / FMT / LINT gates go through the canonical entry-point scripts —
# scripts/run-tests.sh (phpunit), scripts/run-format.sh (php-cs-fixer), and
# scripts/run-lint.sh (phpstan L9) — which self-bootstrap their tool environment
# (scripts/_env.sh) so they run identically here and from any CWD / bare shell.
#
# GATE SCHEDULING (porting-sdk/scripts/gate_scheduler.sh — CI_PERF S1 + S2):
#   Gates run CONCURRENTLY up to a cap (SW_CI_JOBS, default nproc), scheduled by
#   their DATA dependencies:
#     * S2 concurrent wave: the pure-Python side-effect-free gates (all GEN-FRESH*,
#       DRIFT, NO-CHEAT, EMISSION, SKILL-CONTRACT, SWAIG-COVERAGE, SURFACE-DIFF,
#       DOC-AUDIT, SWAIG-CLI) overlap — they share no mutable state.
#     * S1 fail-fast: heavy gates (TEST, LINT, FMT, REST-COVERAGE, SPEC-PARITY) are
#       deferred behind the cheap wave, so a trivial cheap-gate failure surfaces in
#       seconds; --fail-fast aborts the run before TEST starts.
#   HARD ordering is data-dependency ONLY:
#     * DRIFT reads port_signatures.json that SIGNATURES writes → deps=SIGNATURES.
#     * SURFACE-FRESH + SURFACE-DIFF regenerate port_surface.json in place (and
#       restore it), DOC-AUDIT reads it → all three share res=surface.
#   The pre-spawned shared mock servers (for the parallel TEST gate) are stood up
#   BEFORE scheduling and torn down in an EXIT trap, exactly as before.
#   Per-gate PASS/FAIL + the FAILED_GATES tally preserved exactly; each gate's output
#   captured + replayed atomically.
#
# Flags:
#   --fail-fast   stop launching new gates at the first failure (local dev loop).

set -u
set -o pipefail

PORT_ROOT="$(cd "$(dirname "$0")/.." && pwd)"
mkdir -p "$PORT_ROOT/.sw-tmp"  # repo-local CI scratch (never /tmp)
PORT_NAME="signalwire-php"

# Shared FMT/LINT/TEST tool-environment bootstrap (puts vendor/bin on PATH,
# composer-installs on first miss). The run-{format,lint,tests}.sh scripts source
# this same file, so the FMT/LINT/TEST env lives in ONE place and is identical
# whether a gate runs here or standalone.
# shellcheck source=scripts/_env.sh
source "$(cd "$(dirname "$0")" && pwd)/_env.sh"

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

# signalwire-python root for the Layer-D BEHAVIORAL-* oracle (imports the real
# python SDK). Resolves the same way the EMISSION resolver does: explicit
# $PYTHON_SDK wins, else the workspace-sibling of porting-sdk (CI + local).
resolve_python_sdk() {
    if [ -n "${PYTHON_SDK:-}" ] && [ -d "$PYTHON_SDK/signalwire" ]; then
        echo "$PYTHON_SDK"
        return 0
    fi
    if [ -d "$PORTING_SDK_DIR/../signalwire-python/signalwire" ]; then
        (cd "$PORTING_SDK_DIR/../signalwire-python" && pwd)
        return 0
    fi
    return 1
}
PYTHON_SDK_DIR="$(resolve_python_sdk)" || {
    echo "FATAL: signalwire-python not found, clone it adjacent to porting-sdk" >&2
    echo "       (expected $PORTING_SDK_DIR/../signalwire-python or \$PYTHON_SDK env var)" >&2
    exit 2
}

# ── Mock-server lifecycle (for the PARALLEL test gate) ────────────────────
# The mock-backed suites are session-isolated, so file parallelism is safe. We
# spawn each mock ONCE here, wait for health, export the fixed ports so every
# paratest worker probes-and-reuses (never spawns/kills its own), and tear them
# down in a trap. This mirrors the dotnet lifecycle in MOCK_TEST_HARNESS.md.
PARALLEL_PROCS="${PARATEST_PROCS:-8}"
MOCK_PIDS=""
PYTHON_BIN="${MOCK_SIGNALWIRE_PYTHON:-$(command -v python3 || command -v python || echo python3)}"

pick_free_port() {
    "$PYTHON_BIN" -c 'import socket
s = socket.socket(socket.AF_INET, socket.SOCK_STREAM)
s.bind(("127.0.0.1", 0))
print(s.getsockname()[1])
s.close()'
}

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

# shellcheck source=/dev/null
source "$PORTING_SDK_DIR/scripts/gate_scheduler.sh"

# ---- gate helper functions ---------------------------------------------------

# ARTIFACT-DENY (day-one) — feed the AUTHORITATIVE published-package listing to
# artifact_deny.py --listing. Packagist/Composer serve the "dist" tarball produced
# by `git archive HEAD`, which honours `.gitattributes export-ignore` (composer
# archive of the working dir does NOT — it ships vendor/, caches, and .sw-tmp). So
# `git archive HEAD` IS the real package listing; pipe its paths in.
dayone_artifact_deny() {
    git -C "$PORT_ROOT" archive --format=tar HEAD \
        | tar -t \
        | python3 "$PORTING_SDK_DIR/scripts/artifact_deny.py" --port php --listing -
}

# TEST — run the full suite in PARALLEL via paratest against the pre-spawned shared
# mocks (stood up below before sched_run). $MOCKS_UP gates whether the mocks came up.
MOCKS_UP=0
test_gate() {
    if [ "$MOCKS_UP" -ne 1 ]; then
        echo "mock servers did not become ready — TEST cannot run" >&2
        return 1
    fi
    PARATEST_PROCS="$PARALLEL_PROCS" bash scripts/run-tests.sh --parallel
}

# SURFACE-FRESH — regenerate port_surface.json in place, assert the committed copy
# still matches a fresh regen (modulo the generated_from git-sha), restore the file.
surface_fresh_gate() {
    (
        set -e
        if git show HEAD:port_surface.json > "$PORT_ROOT/.sw-tmp/committed_surface.json" 2>/dev/null; then
            :
        else
            cp port_surface.json "$PORT_ROOT/.sw-tmp/committed_surface.json"
        fi
        python3 scripts/enumerate_surface.py
        rc=0
        python3 "$PORTING_SDK_DIR/scripts/check_surface_freshness.py" \
            --committed "$PORT_ROOT/.sw-tmp/committed_surface.json" \
            --fresh port_surface.json || rc=$?
        git checkout -- port_surface.json
        exit "$rc"
    )
}

# REST-COVERAGE — spins its own dedicated mock, runs tests/Rest serially, replays
# the journal (independent of the shared TEST mocks above).
rest_coverage_gate() {
    local port
    port="$(pick_free_port)" || return 1
    [ -n "$port" ] || { echo "FATAL: could not allocate a free port for the REST-coverage mock" >&2; return 1; }
    local mock_pkg_parent="$PORTING_SDK_DIR/test_harness/mock_signalwire"
    PYTHONPATH="$mock_pkg_parent${PYTHONPATH:+:$PYTHONPATH}" \
        "$PYTHON_BIN" -m mock_signalwire --host 127.0.0.1 --port "$port" \
        --log-level error >"$PORT_ROOT/.sw-tmp/rest_cov_mock_php.$$.log" 2>&1 &
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
    MOCK_SIGNALWIRE_PORT="$port" vendor/bin/phpunit --no-coverage tests/Rest || return 1
    PYTHONPATH="$mock_pkg_parent${PYTHONPATH:+:$PYTHONPATH}" \
        "$PYTHON_BIN" -m mock_signalwire.rest_coverage \
        --mock-url "http://127.0.0.1:$port" \
        --spec-root "$PORTING_SDK_DIR/rest-apis" \
        --allowlist "$PORTING_SDK_DIR/REST_COVERAGE_BASELINE.md" \
        --allowlist "$PORT_ROOT/REST_COVERAGE_GAPS.md" \
        --gap-baseline "$PORTING_SDK_DIR/REST_COVERAGE_GAP_BASELINE.md"
}

# SPEC-PARITY — implemented routes == canonical spec. route_registry.php drives the
# live RestClient through a recording HttpClient and captures every dispatched route.
spec_parity_gate() {
    local mock_pkg_parent="$PORTING_SDK_DIR/test_harness/mock_signalwire"
    export PYTHONPATH="$mock_pkg_parent${PYTHONPATH:+:$PYTHONPATH}"
    local registry
    registry="$(mktemp)"
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

# SURFACE-DIFF — diff the port's public surface against the Python reference.
# Regenerate in place, diff, restore unconditionally.
surface_diff_gate() {
    git show HEAD:port_surface.json > "$PORT_ROOT/.sw-tmp/committed_surface_diff.json" 2>/dev/null \
        || cp "$PORT_ROOT/port_surface.json" "$PORT_ROOT/.sw-tmp/committed_surface_diff.json"
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

cd "$PORT_ROOT"

echo "==> running CI gates for $PORT_NAME (porting-sdk at $PORTING_SDK_DIR)"

# Stand up the shared mocks ONCE before scheduling (the parallel TEST gate reuses
# them). If they never become ready, TEST will fail loudly (test_gate checks MOCKS_UP).
if spawn_mocks; then
    MOCKS_UP=1
fi

# ---- register gates ----------------------------------------------------------
sched_init "$@"

sched_gate TEST defer=1 desc="run-tests.sh --parallel (paratest -p $PARALLEL_PROCS)" \
    --fn test_gate

sched_gate SIGNATURES desc="regenerate port_signatures.json" \
    -- python3 scripts/enumerate_signatures.py

sched_gate DRIFT deps=SIGNATURES desc="diff_port_signatures vs python reference" \
    -- python3 "$PORTING_SDK_DIR/scripts/diff_port_signatures.py" \
        --reference "$PORTING_SDK_DIR/python_signatures.json" \
        --port-signatures "$PORT_ROOT/port_signatures.json" \
        --surface-omissions "$PORT_ROOT/PORT_OMISSIONS.md" \
        --surface-additions "$PORT_ROOT/PORT_ADDITIONS.md" \
        --omissions "$PORT_ROOT/PORT_SIGNATURE_OMISSIONS.md"

sched_gate SURFACE-FRESH res=surface desc="check_surface_freshness vs fresh regen" \
    --fn surface_fresh_gate

sched_gate GEN-FRESH desc="generated REST tree + rest_signatures.json match canonical specs (--check)" \
    -- python3 scripts/generate_rest.py --check

sched_gate GEN-FRESH-SWML desc="generated SWML-verbs config tree matches schema.json \$defs (--check)" \
    -- python3 scripts/generate_swml_verbs.py --check

sched_gate GEN-FRESH-RELAY desc="generated RELAY-protocol tree matches relay-protocol/*.json (--check)" \
    -- python3 scripts/generate_relay_protocol.py --check

sched_gate GEN-FRESH-SWAIG desc="generated SWAIG-payload tree matches swaig-specs/*.yaml (--check)" \
    -- python3 scripts/generate_swaig_payloads.py --check

sched_gate GEN-FRESH-TESTS desc="generated REST wire-test suite matches the oracle (--check)" \
    -- python3 scripts/generate_rest_tests.py --check

sched_gate NO-CHEAT desc="audit_no_cheat_tests" \
    -- python3 "$PORTING_SDK_DIR/scripts/audit_no_cheat_tests.py" --root "$PORT_ROOT"

sched_gate REST-COVERAGE defer=1 desc="every implemented REST route covered success+error (parity + allowlist)" \
    --fn rest_coverage_gate

sched_gate SPEC-PARITY defer=1 desc="implemented routes == canonical spec (modulo SPEC_IMPLEMENTATION_GAPS.md)" \
    --fn spec_parity_gate

sched_gate EMISSION desc="diff_port_emission vs python to_dict()" \
    -- python3 "$PORTING_SDK_DIR/scripts/diff_port_emission.py" \
        --dump-cmd "php scripts/emit_corpus.php" \
        --port-repo "$PORT_ROOT"

sched_gate BEHAVIORAL-WIRE desc="diff_port_wire vs python oracle (Layer D)" \
    -- python3 "$PORTING_SDK_DIR/scripts/diff_port_wire.py" \
        --port php --python-sdk "$PYTHON_SDK_DIR" \
        --dump-cmd "php scripts/wire_dump.php"

sched_gate BEHAVIORAL-SWML desc="diff_port_swml vs python oracle (Layer D)" \
    -- python3 "$PORTING_SDK_DIR/scripts/diff_port_swml.py" \
        --port php --python-sdk "$PYTHON_SDK_DIR" \
        --dump-cmd "php scripts/swml_dump.php"

sched_gate BEHAVIORAL-STATE desc="diff_port_state vs python oracle (Layer D)" \
    -- python3 "$PORTING_SDK_DIR/scripts/diff_port_state.py" \
        --port php --python-sdk "$PYTHON_SDK_DIR" \
        --dump-cmd "php scripts/state_dump.php"

sched_gate BEHAVIORAL-HTTP desc="diff_port_http vs python oracle (Layer D)" \
    -- python3 "$PORTING_SDK_DIR/scripts/diff_port_http.py" \
        --port php --python-sdk "$PYTHON_SDK_DIR" \
        --dump-cmd "php scripts/http_dump.php"

sched_gate BEHAVIORAL-WIRE-RELAY desc="diff_port_wire_relay vs python oracle (Layer D)" \
    -- python3 "$PORTING_SDK_DIR/scripts/diff_port_wire_relay.py" \
        --port php --python-sdk "$PYTHON_SDK_DIR" \
        --dump-cmd "php scripts/wire_relay_dump.php"

sched_gate FMT defer=1 desc="run-format.sh (local: apply; CI: --check)" \
    -- bash scripts/run-format.sh ${CI:+--check}

sched_gate LINT defer=1 desc="run-lint.sh (phpstan level 9, zero findings)" \
    -- bash scripts/run-lint.sh

sched_gate DOC-AUDIT res=surface desc="audit_docs vs port_surface.json" \
    -- python3 "$PORTING_SDK_DIR/scripts/audit_docs.py" \
        --root "$PORT_ROOT" \
        --surface "$PORT_ROOT/port_surface.json" \
        --ignore "$PORT_ROOT/DOC_AUDIT_IGNORE.md"

sched_gate SURFACE-DIFF res=surface desc="diff_port_surface vs python reference" \
    --fn surface_diff_gate

sched_gate SKILL-CONTRACT desc="diff_skill_contracts vs python reference" \
    -- python3 "$PORTING_SDK_DIR/scripts/diff_skill_contracts.py" \
        --dump-cmd "php scripts/emit_skills.php" \
        --port-repo "$PORT_ROOT"

sched_gate SWAIG-CLI desc="swaig-test shared mini-contract (verbs/serverless-reject/default-action)" \
    -- python3 "$PORTING_SDK_DIR/scripts/audit_swaig_cli_contract.py" \
        --port php \
        --cmd "php $PORT_ROOT/bin/swaig-test" \
        --require-url-model \
        --default-action-argv='--url|http://user:pass@127.0.0.1:1/' \
        --no-serverless-argv='--url|http://user:pass@127.0.0.1:1/|--simulate-serverless|lambda|--list-tools'

sched_gate SWAIG-COVERAGE desc="every engine SWAIG action emittable (modulo allowlist)" \
    -- python3 "$PORTING_SDK_DIR/scripts/swaig_coverage.py" --check \
        --emission "$PORT_ROOT/src/SignalWire/SWAIG/FunctionResult.php"

sched_gate DOC-LANG-PURITY res=dayone desc="no python-verbatim docs in a non-python port" \
    -- python3 "$PORTING_SDK_DIR/scripts/doc_lang_purity.py" --port php --repo "$PORT_ROOT"
sched_gate DOC-LINKS res=dayone desc="every relative markdown link resolves to a tracked file" \
    -- python3 "$PORTING_SDK_DIR/scripts/doc_links.py" --port php --repo "$PORT_ROOT"

sched_gate README-INCLUDE res=dayone desc="doc code blocks are byte-identical to their gate-compiled fixture regions" \
    -- python3 "$PORTING_SDK_DIR/scripts/readme_include.py" --port php --repo "$PORT_ROOT"
sched_gate ROOT-HYGIENE res=dayone desc="no audit/scratch clutter tracked at repo root (allowlist ROOT_HYGIENE_ALLOW.md)" \
    -- python3 "$PORTING_SDK_DIR/scripts/root_hygiene.py" --port php --repo "$PORT_ROOT"
sched_gate IGNORE-LEDGER-VERIFY res=dayone desc="no laundered false-absence entries in DOC_AUDIT_IGNORE.md" \
    -- python3 "$PORTING_SDK_DIR/scripts/ignore_ledger_verify.py" --port php --repo "$PORT_ROOT"
sched_gate META-CONSISTENT res=dayone desc="package metadata consistency" \
    -- python3 "$PORTING_SDK_DIR/scripts/meta_consistent.py" --port php --repo "$PORT_ROOT"
sched_gate ARTIFACT-DENY res=dayone desc="no porting artifacts in the PUBLISHED package (authoritative listing)" \
    --fn dayone_artifact_deny

# ---- expansion gates (GATE_EXPANSION_PLAN Tier 5 + release) -------------------
# Blocking, non-report-only. Backlog burned to zero + allowlists approved
# (2026-07-07), so these enforce so it can't re-rot. Modeled on the day-one wiring.
#   GEN-TYPE-DEGENERACY — generated typed surface has no bare loose-alias escape
#     hatches / no private type leaked into a public field (php: no generated-alias
#     construct → skips clean).
#   PUBLIC-JARGON — no porting-project internal jargon leaks into public phpDoc.
#   GEN-IDIOM — the generated REST/payload tree is NOT lint-excluded (phpstan L9
#     actually runs over it).
#   RELEASE-FRESH — report-only for php: php has NO publish/release workflow, so
#     there is no publish path to gate (absence is a flagged gap, not a RED). Wired
#     report-only per the brief; flip off --report-only once php gains a publish wf.
# ROUTE-COLLISION is NOT wired for php — see the note after this block.
sched_gate GEN-TYPE-DEGENERACY res=dayone desc="generated typed surface: no loose-alias / private-type-in-public-field" \
    -- python3 "$PORTING_SDK_DIR/scripts/gen_type_degeneracy.py" --port php --repo "$PORT_ROOT"
sched_gate PUBLIC-JARGON res=dayone desc="no internal porting jargon in public phpDoc doc-comments" \
    -- python3 "$PORTING_SDK_DIR/scripts/public_jargon.py" --port php --repo "$PORT_ROOT"
sched_gate GEN-IDIOM res=dayone desc="generated code is NOT lint-excluded (phpstan runs over it)" \
    -- python3 "$PORTING_SDK_DIR/scripts/gen_idiom.py" --port php --repo "$PORT_ROOT"
sched_gate RELEASE-FRESH res=dayone desc="release hygiene (report-only: php has no publish workflow to gate)" \
    -- python3 "$PORTING_SDK_DIR/scripts/release_fresh.py" --port php --repo "$PORT_ROOT" --report-only

# ROUTE-COLLISION — NOT wired for php. The gate has no default registry command for
# php, so standalone it exits 1 ("pass --registry-json with a pre-built registry").
# php's scripts/route_registry.php (used by SPEC-PARITY) emits a compatible
# {"routes":[{method,path_template,via}]} shape, so the gate CAN run when fed it —
# but doing so currently reports 2 real ROUTE-SPLIT findings (callFlows /
# conferenceRooms list_addresses / listAddresses dispatch to the SINGULAR
# call_flow/conference_room addresses path, diverging from the plural collection
# base — the L12 singular-vs-plural archetype). Wiring it blocking would require
# either resolving those splits or a human-approved ROUTE_COLLISION_ALLOW.md entry;
# per the wiring brief it is SKIPPED for php as a follow-up (registry-mechanism
# decision + disposition of the 2 splits).

sched_run
rc=$?
if [ "$rc" -eq 0 ]; then
    echo "==> CI PASS"
else
    echo "==> CI FAIL (gates:$FAILED_GATES )"
fi
exit "$rc"
