# EXAMPLES_RUN allowlist

Examples that legitimately need real credentials or a live third-party endpoint
and so cannot run against the shared mock harness. Each entry names the concrete
external dependency and the approver. The EXAMPLES-RUN gate skips these (it does
not fail them). Anything NOT here must load + start against the mock.

- examples/RelayAuditHarness.php — needs real SIGNALWIRE_RELAY_HOST audit-fixture (spun up by porting-sdk audit_relay_handshake.py), not mockable (approver: user, 2026-07-07)
- examples/RestAuditHarness.php — needs real REST_OPERATION + REST_FIXTURE_URL audit-fixture (spun up by porting-sdk audit_rest_transport.py), not mockable (approver: user, 2026-07-07)
- examples/SkillsAuditHarness.php — needs real SKILL_NAME + SKILL_FIXTURE_URL audit-fixture (spun up by porting-sdk audit_skills_dispatch.py), not mockable (approver: user, 2026-07-07)
- examples/datasphere_serverless_env_demo.php — needs real SIGNALWIRE_SPACE_NAME + DATASPHERE_DOCUMENT_ID datasphere creds, not mockable (approver: user, 2026-07-07)
- examples/datasphere_webhook_env_demo.php — needs real SIGNALWIRE_SPACE_NAME + DATASPHERE_DOCUMENT_ID datasphere creds, not mockable (approver: user, 2026-07-07)
- examples/joke_agent.php — needs real API_NINJAS_KEY creds, not mockable (approver: user, 2026-07-07)
- examples/joke_skill_demo.php — needs real API_NINJAS_KEY creds, not mockable (approver: user, 2026-07-07)
- examples/web_search_agent.php — needs real GOOGLE_SEARCH_API_KEY + GOOGLE_SEARCH_ENGINE_ID creds, not mockable (approver: user, 2026-07-07)
- examples/quickstart_relay.php — connect() opens a live WebSocket to SIGNALWIRE_SPACE and throws on failure; the shared harness runs only mock_signalwire (REST), no mock_relay, so this canonical README RELAY quickstart needs a real relay endpoint (approver: user, 2026-07-09)
- examples/relay_answer_and_welcome.php — connect() opens a live RELAY WebSocket to SIGNALWIRE_SPACE; the shared harness runs only mock_signalwire (REST), no mock_relay, so this needs a real relay endpoint (same class + reason as the owner-approved quickstart_relay.php / perl relay_answer_and_welcome.pl, approver: user, 2026-07-09)
- relay/examples/relay_answer_and_welcome.php — connect() opens a live RELAY WebSocket to SIGNALWIRE_SPACE; the shared harness runs only mock_signalwire (REST), no mock_relay, so this needs a real relay endpoint (same class + reason as the owner-approved quickstart_relay.php, approver: user, 2026-07-09)
- relay/examples/relay_dial_and_play.php — connect() opens a live RELAY WebSocket + requires a real RELAY_FROM_NUMBER/RELAY_TO_NUMBER on your project; the shared harness runs only mock_signalwire (REST), no mock_relay, so this needs a real relay endpoint (same class + reason as the owner-approved quickstart_relay.php, approver: user, 2026-07-09)
- relay/examples/relay_ivr_connect.php — connect() opens a live RELAY WebSocket to SIGNALWIRE_SPACE; the shared harness runs only mock_signalwire (REST), no mock_relay, so this needs a real relay endpoint (same class + reason as the owner-approved quickstart_relay.php, approver: user, 2026-07-09)
