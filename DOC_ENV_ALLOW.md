# DOC-ENV allowlist

Justified non-findings for the DOC-ENV gate (`doc_env.py`).
Format: `- <VAR_NAME> — reason (approver, date)`.

- SIGNALWIRE_SPACE_NAME — Not an SDK client config knob. In the Python reference this var is read ONLY by CLI scaffolding / deployment helpers (`cli/init_project.py`, `cli/dokku.py`) to seed a generated project's `.env`; the SDK's REST/RELAY clients read `SIGNALWIRE_SPACE`, not `SIGNALWIRE_SPACE_NAME`. The PHP port has no CLI-scaffolding equivalent, so the SDK core legitimately never reads it. The only mention in a gate-scanned `.md` is `EXAMPLES_RUN_ALLOW.md`, an allowlist note documenting the real deployment credentials the datasphere env-demo examples require (the examples themselves, under `examples/`, read it via `requireEnv('SIGNALWIRE_SPACE_NAME')` — outside the gate's `src/**` glob). It is a deployment/scaffolding env var referenced in a run-note, not an SDK-config doc claim. (approver: PENDING-SIGNOFF, 2026-07-11)
