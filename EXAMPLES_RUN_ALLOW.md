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
- examples/quickstart_rest.php — issues live fabric()->aiAgents()->create() + calling()->play() REST calls on load; RestClient derives its base URL from SIGNALWIRE_SPACE with no plain-HTTP mock override, so it 401s/can't reach the loopback REST mock (mirrors python quickstart_rest.py allow, mike 2026-07-08)
- examples/quickstart_relay.php — connect() opens a live WebSocket to SIGNALWIRE_SPACE and throws on failure; the shared harness runs only mock_signalwire (REST), no mock_relay, so this canonical README RELAY quickstart needs a real relay endpoint (approver: user, 2026-07-09)
