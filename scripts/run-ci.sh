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
#
# GATE-INVENTORY NOTE: porting-sdk/GATE_INVENTORY.md is GENERATED (by
# gen_gate_inventory.py) from the REFERENCE port's run-ci.sh (signalwire-typescript),
# NOT from this file. This php run-ci intentionally deviates from that inventory in a
# few port-specific ways, each documented at its gate below: the RELAY behavioral rule
# keeps php's hyphen spelling BEHAVIORAL-WIRE-RELAY; ROUTE-COLLISION runs as a
# standalone spec-aware gate (scripts/route_collision.sh, fed by route_registry.php)
# rather than inside the SURFACE suite; SNIPPET-COMPILE/-RUN + EXAMPLES-RUN are
# nightly-tier (heavy mock-backed execution). A deviation here is not inventory drift —
# it is a per-port idiom/disposition recorded in place. Load-bearing env/mode lines are
# additionally guarded by the WIRED-MODES gate (WIRED_MODES.md).

set -u
set -o pipefail

PORT_ROOT="$(cd "$(dirname "$0")/.." && pwd)"
mkdir -p "$PORT_ROOT/.sw-tmp"  # repo-local CI scratch (never /tmp)
PORT_NAME="signalwire-php"

# Wave-A gate widenings are BLOCKING for php: the red list is burned to zero
# (flat-tts, area_code→areacode, package-name, doc method/ctor idiom), so the
# newly-widened findings count toward the exit code instead of being logged
# report-only. Flip this back to 1 only to temporarily re-open the report-only
# escape hatch while burning a fresh red list.
export SW_WAVE_A_REPORT_ONLY=0

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

# STRICT-MOCKS default (D3): the shared REST mock 400s any wire violation
# (unknown body key, malformed value) by DEFAULT instead of silently journaling
# it — so a wrong wire key surfaces LOUD at PR time (in the TEST gate's own mock
# and any test/gate that spawns one), not just in the REST-COVERAGE journal
# post-pass. `:-1` keeps it a DEFAULT a caller can still override to 0 for a
# deliberate non-strict repro. Exported so every scheduler worker subshell (and
# every mock they spawn) inherits it. This is a WIRED MODE — see WIRED_MODES.md;
# check_wired_modes.py fails the WIRED-MODES gate if this line is ever silently dropped.
export MOCK_SIGNALWIRE_STRICT="${MOCK_SIGNALWIRE_STRICT:-1}"

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

# ---- Part 5: the per-gate --fn helpers are now DEAD — reproduced in the suites -
# surface_fresh_gate (SURFACE-FRESH), surface_diff_gate (SURFACE-DIFF),
# rest_coverage_gate (REST-COVERAGE), spec_parity_gate (SPEC-PARITY), and
# dayone_artifact_deny (ARTIFACT-DENY) used to be defined here as the gate bodies.
# Those exact bodies are now reproduced INSIDE the Part-5 suites (SURFACE →
# scripts/suites/_surface_fresh.py + the surface command table; BEHAVIORAL →
# scripts/suites/_rest_coverage.py + _spec_parity.py; PACKAGE →
# scripts/suites/_artifact_deny.py), so they are no longer defined here.
# pick_free_port() stays (the shared-mock spawn above still uses it); test_gate
# stays (TEST is a native-toolchain stay gate, not a suite member).
# (Byte-identity vs the old per-gate path is proven by porting-sdk's
# tests/test_suite_parity*.py.)

cd "$PORT_ROOT"

echo "==> running CI gates for $PORT_NAME (porting-sdk at $PORTING_SDK_DIR)"

# Stand up the shared mocks ONCE before scheduling (the parallel TEST gate reuses
# them). If they never become ready, TEST will fail loudly (test_gate checks MOCKS_UP).
if spawn_mocks; then
    MOCKS_UP=1
fi

# ---- register gates ----------------------------------------------------------
sched_init "$@"

# TEST stays a standalone native-toolchain gate (paratest against the pre-spawned
# shared mocks). NOT a suite member.
sched_gate TEST defer=1 desc="run-tests.sh --parallel (paratest -p $PARALLEL_PROCS)" \
    --fn test_gate

# ---- Part 5 gate SUITES ------------------------------------------------------
# The former per-gate SIGNATURES/DRIFT/SURFACE-*/SEMVER-DIFF/GEN-TYPE-DEGENERACY/
# GEN-IDIOM/GEN-FRESH*/BEHAVIORAL-*/EMISSION/REST-COVERAGE/SPEC-PARITY/SKILL-CONTRACT/
# SWAIG-*/ERROR-ENVELOPE/PAGINATION-WIRED/DOC-WIRE/WAIT-LIVENESS/DOC-*/COUNT-CLAIM/
# ACCESSOR-TRUTH/STATUS-CLAIM/README-INCLUDE/*-LEDGER/ARTIFACT-DENY/RELEASE-FRESH/
# META-CONSISTENT/PACKAGE-SMOKE gates now run under 6 SUITE engines. Each suite emits
# every original gate NAME as a `[SUITE:RULE] ... PASS/FAIL` rule ID (failure
# identity + allowlists + finding output unchanged). A suite exits nonzero iff any of
# its rules fails. Byte-identity vs the old per-gate path is proven by
# porting-sdk/tests/test_suite_parity*.py.
#
# The `--fn` helpers the old gates used (surface_fresh_gate, surface_diff_gate,
# rest_coverage_gate, spec_parity_gate, dayone_artifact_deny) are reproduced INSIDE
# the suites, so they are no longer defined here.
#
# Former single-gate scheduler features preserved by the suites internally:
#   * SIGNATURES→DRIFT ordering + the SURFACE-FRESH/SURFACE-DIFF surface mutex live
#     inside the SURFACE suite (it regenerates + git-restores in order). DOC-AUDIT
#     reads php's on-disk port_surface.json, which SURFACE regenerates+restores — so
#     SURFACE and DOC-TRUTH share res=surface (mutually exclusive, exactly as the old
#     per-gate SURFACE-FRESH/SURFACE-DIFF/DOC-AUDIT surface mutex did).
#   * mixed tiers are split with --rules: BEHAVIORAL + PACKAGE each schedule a per-PR
#     line and a nightly line (php's nightly members broken out below).
# PHP-SPECIFIC vs the ts/go reference: php spells the RELAY rule BEHAVIORAL-WIRE-RELAY
# (HYPHEN, like ts — only go uses the underscore). php schedules NO ROUTE-COLLISION
# (unwired for php — see the note after the standalone block).

# SURFACE (parity spine): SIGNATURES→DRIFT ordered, SURFACE-FRESH regen/restore,
# SURFACE-DIFF (re-enumerating --fn), SEMVER-DIFF, GEN-TYPE-DEGENERACY, GEN-IDIOM —
# all read the one enumeration. res=surface: SURFACE-FRESH/SURFACE-DIFF regenerate
# port_surface.json in place (and restore it), so this must not overlap DOC-TRUTH's
# DOC-AUDIT read of port_surface.json.
sched_gate SURFACE res=surface desc="surface parity suite (SIGNATURES/DRIFT/SURFACE-FRESH/SURFACE-DIFF/SEMVER-DIFF/GEN-TYPE-DEGENERACY/GEN-IDIOM)" \
    -- python3 "$PORTING_SDK_DIR/scripts/suites/surface.py" --port php --repo "$PORT_ROOT"

# GEN (regen-from-specs family): the 5 GEN-FRESH rules. Cheap wave (php's per-gate
# GEN-FRESH* were not deferred — preserved: no defer).
sched_gate GEN desc="generated-code freshness suite (GEN-FRESH/-SWML/-RELAY/-SWAIG/-TESTS)" \
    -- python3 "$PORTING_SDK_DIR/scripts/suites/gen.py" --port php --repo "$PORT_ROOT"

# BEHAVIORAL (one Layer-D pass per rule): the per-PR rules. WAIT-LIVENESS (nightly)
# is the separate line below. defer=1 (the suite carries REST-COVERAGE/SPEC-PARITY,
# which were php's defer members; a full behavioral pass is heavy-wave).
sched_gate BEHAVIORAL defer=1 desc="behavioral suite (BEHAVIORAL-*/EMISSION/ERROR-ENVELOPE/PAGINATION-WIRED/PAGINATION-CORPUS/SECRET-SCRUB/CA-VAR/SECURE-DEFAULT/TLS-VERIFY/DOC-WIRE/REST-COVERAGE/SPEC-PARITY/SKILL-CONTRACT/SWAIG-COVERAGE/SWAIG-CLI)" \
    -- python3 "$PORTING_SDK_DIR/scripts/suites/behavioral.py" --port php --repo "$PORT_ROOT" \
        --rules BEHAVIORAL-WIRE,BEHAVIORAL-SWML,BEHAVIORAL-STATE,BEHAVIORAL-HTTP,BEHAVIORAL-WIRE-RELAY,EMISSION,ERROR-ENVELOPE,PAGINATION-WIRED,PAGINATION-CORPUS,SECRET-SCRUB,CA-VAR,SECURE-DEFAULT,TLS-VERIFY,DOC-WIRE,REST-COVERAGE,SPEC-PARITY,SKILL-CONTRACT,SWAIG-COVERAGE,SWAIG-CLI

sched_gate BEHAVIORAL-NIGHTLY tier=nightly defer=1 desc="behavioral suite, nightly rules (WAIT-LIVENESS/SECRET-SCRUB-LIVE)" \
    -- python3 "$PORTING_SDK_DIR/scripts/suites/behavioral.py" --port php --repo "$PORT_ROOT" \
        --rules WAIT-LIVENESS,SECRET-SCRUB-LIVE

# DOC-TRUTH (one markdown walk): DOC-AUDIT/DOC-LINKS/DOC-LANG-PURITY/DOC-ENV/
# COUNT-CLAIM/ACCESSOR-TRUTH/STATUS-CLAIM/README-INCLUDE. res=surface: DOC-AUDIT
# reads php's on-disk port_surface.json, which the SURFACE suite regenerates.
sched_gate DOC-TRUTH res=surface desc="doc-truth suite (DOC-AUDIT/DOC-LINKS/DOC-LANG-PURITY/DOC-ENV/COUNT-CLAIM/ACCESSOR-TRUTH/STATUS-CLAIM/README-INCLUDE)" \
    -- python3 "$PORTING_SDK_DIR/scripts/suites/doc_truth.py" --port php --repo "$PORT_ROOT"

# LEDGER: SUPPRESSION-LEDGER + IGNORE-LEDGER-VERIFY.
sched_gate LEDGER res=dayone desc="ledger governance suite (SUPPRESSION-LEDGER/IGNORE-LEDGER-VERIFY)" \
    -- python3 "$PORTING_SDK_DIR/scripts/suites/ledger.py" --port php --repo "$PORT_ROOT"

# PACKAGE: per-PR rules (ARTIFACT-DENY/RELEASE-FRESH); nightly rules (PACKAGE-SMOKE/
# META-CONSISTENT) on the separate line below.
sched_gate PACKAGE res=dayone desc="package suite, per-PR rules (ARTIFACT-DENY/RELEASE-FRESH)" \
    -- python3 "$PORTING_SDK_DIR/scripts/suites/package.py" --port php --repo "$PORT_ROOT" \
        --rules ARTIFACT-DENY,RELEASE-FRESH

sched_gate PACKAGE-NIGHTLY tier=nightly defer=1 res=dayone desc="package suite, nightly rules (PACKAGE-SMOKE/META-CONSISTENT)" \
    -- python3 "$PORTING_SDK_DIR/scripts/suites/package.py" --port php --repo "$PORT_ROOT" \
        --rules PACKAGE-SMOKE,META-CONSISTENT

# ---- gates that stay standalone (native toolchains + singletons) -------------
sched_gate NO-CHEAT desc="audit_no_cheat_tests" \
    -- python3 "$PORTING_SDK_DIR/scripts/audit_no_cheat_tests.py" --root "$PORT_ROOT"

sched_gate COORDINATED-PASS desc="a non-main porting-sdk pin must be declared on the PR (Coordinated-With: line or coordinated-pass label)" \
    -- python3 "$PORTING_SDK_DIR/scripts/coordinated_pass.py" --porting-sdk "$PORTING_SDK_DIR"

sched_gate FMT defer=1 desc="run-format.sh (local: apply; CI: --check)" \
    -- bash scripts/run-format.sh ${CI:+--check}

sched_gate LINT defer=1 desc="run-lint.sh (phpstan level 9, zero findings)" \
    -- bash scripts/run-lint.sh

# PUBLIC-JARGON stays standalone (public phpDoc analysis, not a suite family).
sched_gate PUBLIC-JARGON res=dayone desc="no internal porting jargon in public phpDoc doc-comments" \
    -- python3 "$PORTING_SDK_DIR/scripts/public_jargon.py" --port php --repo "$PORT_ROOT"

# ROOT-HYGIENE stays standalone (repo-root source analysis).
sched_gate ROOT-HYGIENE res=dayone desc="no audit/scratch clutter tracked at repo root (allowlist ROOT_HYGIENE_ALLOW.md)" \
    -- python3 "$PORTING_SDK_DIR/scripts/root_hygiene.py" --port php --repo "$PORT_ROOT"

# DEAD-PUBLIC-ERROR stays standalone (source analysis of exported error types — not a
# doc-truth/behavioral rule). ERROR-ENVELOPE/PAGINATION-WIRED/DOC-WIRE run under the
# BEHAVIORAL suite; DOC-ENV/COUNT-CLAIM/ACCESSOR-TRUTH/STATUS-CLAIM under DOC-TRUTH.
sched_gate DEAD-PUBLIC-ERROR desc="exported error types are raised/caught/user-signalled (no dead error surface)" \
    -- python3 "$PORTING_SDK_DIR/scripts/dead_public_error.py" --port php --repo "$PORT_ROOT"

# ---- §C1 doc/example/CLI execution gates -------------------------------------
# SNIPPET-COMPILE (php -l on every fenced block) is HEAVY → tier=nightly; DOC-CLI
# stays per-PR (cheap CLI-parse). SNIPPET-RUN executes each php doc snippet against
# the shared mock (STRICT-MOCKS: MOCK_RELAY_STRICT=1) → nightly heavy wave.
# EXAMPLES-RUN loads/starts every shipped example against the mock (STRICT-MOCKS) →
# nightly, defer, blocking.
sched_gate SNIPPET-COMPILE tier=nightly desc="documented code snippets syntax-check (php -l)" \
    -- python3 "$PORTING_SDK_DIR/scripts/snippet_compile.py" --port php --repo "$PORT_ROOT"

sched_gate DOC-CLI desc="documented swaig-test invocations parse against the real CLI" \
    -- python3 "$PORTING_SDK_DIR/scripts/doc_cli.py" --port php --repo "$PORT_ROOT"

sched_gate SNIPPET-RUN tier=nightly defer=1 desc="php doc snippets run to a zero exit against the mock (STRICT-MOCKS: MOCK_RELAY_STRICT=1)" \
    -- env MOCK_RELAY_STRICT=1 python3 "$PORTING_SDK_DIR/scripts/snippet_run.py" --port php --repo "$PORT_ROOT"

sched_gate EXAMPLES-RUN tier=nightly defer=1 desc="shipped examples load/start against the mock (modulo EXAMPLES_RUN_ALLOW.md; STRICT-MOCKS: MOCK_RELAY_STRICT=1)" \
    -- env MOCK_RELAY_STRICT=1 python3 "$PORTING_SDK_DIR/scripts/examples_run.py" --port php --repo "$PORT_ROOT"

# DOC-SURFACE (plan §6.3): docblock coverage floor on the hand-written public API
# surface (generated code is excluded — it's documented by the generator). The
# floor is pinned in .doc_surface_floor (84.3% today) and ratchets up via
# --write-floor; report-only at graduation, so a doc regression is visible without
# failing the run yet (never-regress is enforced once the floor flips blocking).
# GUARDED: doc_surface.py ships on the porting-sdk plan branch; until it merges to
# porting-sdk main (which CI clones), skip-with-pass rather than red on a not-yet-
# landed sibling script. Remove the guard once it's on porting-sdk main.
sched_gate DOC-SURFACE res=dayone desc="docblock coverage floor on the public API surface (report-only, ratchets via .doc_surface_floor)" \
    -- bash -c 'if [ -f "$1/scripts/doc_surface.py" ]; then python3 "$1/scripts/doc_surface.py" --port php --repo "$2" --report-only; else echo "[doc-surface] doc_surface.py not on porting-sdk main yet — skip-pass (plan-branch dep)"; fi' _ "$PORTING_SDK_DIR" "$PORT_ROOT"

# WIRED-MODES (Part 1.6 / D7): the merge-coherence guard — greps this run-ci.sh for
# every load-bearing env/mode line declared in WIRED_MODES.md (strict-mocks exports)
# and fails loud if a merge ever silently drops one, so a wired mode can't vanish and
# leave a gate green-but-vacuous.
sched_gate WIRED-MODES desc="load-bearing run-ci modes (WIRED_MODES.md) all present" \
    -- python3 "$PORTING_SDK_DIR/scripts/check_wired_modes.py" --port php --repo .

# ROUTE-COLLISION (spec-aware): build php's route_registry.php → feed the SPEC-AWARE
# route_collision.py (a split is a finding ONLY when the dispatched path diverges from
# the SPEC path for the method's operationId). php's 2 splits (callFlows /
# conferenceRooms list_addresses under the singular call_flow/conference_room sub-paths)
# are spec-faithful platform routing (fabric/openapi.yaml x-sdk mounts), so the
# spec-aware engine clears them WITHOUT any allowlist — no ROUTE_COLLISION_ALLOW.md
# exists or is needed. Now WIRED BLOCKING (the previous "unwired for php" rationale
# evaporated with the spec-aware engine). res=surface: it reads port_surface.json,
# which the SURFACE suite regenerates+restores.
sched_gate ROUTE-COLLISION res=surface desc="no split routes / duplicate CRUD bases (spec-aware; fed by route_registry.php)" \
    -- bash scripts/route_collision.sh

sched_run
rc=$?
if [ "$rc" -eq 0 ]; then
    echo "==> CI PASS"
else
    echo "==> CI FAIL (gates:$FAILED_GATES )"
fi
exit "$rc"
