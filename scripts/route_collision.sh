#!/usr/bin/env bash
# route_collision.sh — the php ROUTE-COLLISION gate body.
#
# Builds php's route-registry (the routes the live RestClient actually
# dispatches — scripts/route_registry.php, "Set B") into a temp JSON, then runs
# porting-sdk's SPEC-AWARE route_collision.py against it: a ROUTE-SPLIT is a finding
# ONLY when the dispatched path does not match the spec path for the method's
# operationId. php's 2 splits (callFlows / conferenceRooms list_addresses under the
# singular call_flow/conference_room sub-paths) are spec-faithful platform routing
# (fabric/openapi.yaml x-sdk mounts), so the spec-aware check clears them WITHOUT any
# allowlist — no ROUTE_COLLISION_ALLOW.md entry exists or is needed.
#
# Exits with route_collision.py's code (0 = clean). Run from the repo root, invoked
# as a blocking gate by scripts/run-ci.sh.
set -u
set -o pipefail

PORT_ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$PORT_ROOT"

# Resolve porting-sdk the same way run-ci.sh does (env override, else adjacency).
if [ -n "${PORTING_SDK:-}" ] && [ -d "$PORTING_SDK/scripts" ]; then
    PSDK="$PORTING_SDK"
elif [ -d "$PORT_ROOT/../porting-sdk/scripts" ]; then
    PSDK="$(cd "$PORT_ROOT/../porting-sdk" && pwd)"
else
    echo "route_collision.sh: porting-sdk not found (set \$PORTING_SDK or clone adjacent)" >&2
    exit 2
fi

mkdir -p "$PORT_ROOT/.sw-tmp"
reg="$(mktemp "$PORT_ROOT/.sw-tmp/php_route_registry.XXXXXX.json")"
trap 'rm -f "$reg"' EXIT

# Capture ONLY the registry JSON (stdout); route_registry.php's diagnostics go to
# stderr. A non-zero here (incomplete Set B) is a hard failure.
if ! php scripts/route_registry.php >"$reg"; then
    echo "route_collision.sh: route_registry.php failed (incomplete route registry)" >&2
    exit 1
fi

# The spec-aware check defaults --spec-root to <psdk>/rest-apis; pass it explicitly
# so the resolution is independent of the invocation's CWD.
exec python3 "$PSDK/scripts/route_collision.py" \
    --port php --repo "$PORT_ROOT" \
    --registry-json "$reg" \
    --surface "$PORT_ROOT/port_surface.json" \
    --spec-root "$PSDK/rest-apis"
