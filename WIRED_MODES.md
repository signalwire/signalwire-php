# Wired modes — signalwire-php

Load-bearing env/mode lines that MUST be present in `scripts/run-ci.sh`. These are
not gates themselves — they are the ENV/MODE context that makes a gate actually
check anything. A gate that runs with its strict mode silently un-exported is
"green and vacuous". The strict-mocks × Part-5 merge race dropped exactly such
lines from several ports' run-ci; nothing caught it because they aren't gates.

`porting-sdk/scripts/check_wired_modes.py --port php --repo .` greps run-ci.sh for
each required pattern below and FAILS LOUD on any missing one — so a future merge
that drops a wired mode reds this check instead of silently shipping a vacuous gate.

Each entry is `` - `<python-regex>` — reason``; prose/blank/`#` lines are ignored,
so this file doubles as human documentation of the port's wired modes.

## Required run-ci.sh patterns

- `env MOCK_RELAY_STRICT=1` — RELAY strict mode: the RELAY mock 400s any inbound frame that violates the wire spec, so a wrong frame fails LOUD (EXAMPLES-RUN / SNIPPET-RUN nightly gates run under it) instead of being silently journaled.
- `export MOCK_SIGNALWIRE_STRICT` — REST 400 strict default (D3): the REST mock 400s any wire violation (unknown body key / malformed value) by default, so a wrong wire key surfaces at PR time in the TEST gate's own mock and any test/gate that spawns one, not just in the REST-COVERAGE journal post-pass.
