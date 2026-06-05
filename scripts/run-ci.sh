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

# Gate 1: phpunit
run_gate "TEST" "vendor/bin/phpunit" \
    vendor/bin/phpunit

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

if [ -z "$FAILED_GATES" ]; then
    echo "==> CI PASS"
    exit 0
else
    echo "==> CI FAIL (gates:$FAILED_GATES )"
    exit 1
fi
