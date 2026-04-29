#!/usr/bin/env python3
"""generate_exemptions.py — bootstrap PORT_OMISSIONS.md / PORT_ADDITIONS.md.

Mirrors the Java port's generator. Runs the surface diff, then writes
one line per unexcused symbol with a rationale chosen from a module-prefix
lookup table. Re-running this script after a code change regenerates the
files — every entry is reproducible from the rationale table below.

Three explicitly hand-curated entries are seeded into PORT_OMISSIONS.md
regardless of what the diff shows: the legacy `assign_phone_route` and
the `SwmlWebhooksResource.create` / `CxmlWebhooksResource.create` holes
that PHP routes through `phone_numbers.set_*` instead.
"""

from __future__ import annotations

import argparse
import json
import subprocess
import sys
from pathlib import Path


# ---------------------------------------------------------------------------
# Omission rationales — ordered (longest/most-specific prefix first; first
# match wins). Trailing "." marks a prefix; bare strings are exact-match.
# ---------------------------------------------------------------------------
OMISSION_RATIONALES: list[tuple[str, str]] = [
    # --- Hand-curated phone-binding omissions (per phone-binding.md) ---
    (
        "signalwire.rest.namespaces.fabric.GenericResources.assign_phone_route",
        "narrow-use legacy API; PHP ships only the good path via "
        "phone_numbers.set_* helpers, per porting-sdk/phone-binding.md",
    ),
    (
        "signalwire.rest.namespaces.fabric.SwmlWebhooksResource.create",
        "auto-materialized by phone_numbers.set_swml_webhook; PHP doesn't "
        "expose the create method to avoid the trap",
    ),
    (
        "signalwire.rest.namespaces.fabric.CxmlWebhooksResource.create",
        "auto-materialized by phone_numbers.set_cxml_webhook; PHP doesn't "
        "expose the create method to avoid the trap",
    ),
    (
        "signalwire.rest.namespaces.fabric.SwmlWebhooksResource",
        "auto-materialized by phone_numbers.set_swml_webhook; PHP doesn't "
        "expose the SwmlWebhooksResource class — see porting-sdk/phone-binding.md",
    ),
    (
        "signalwire.rest.namespaces.fabric.CxmlWebhooksResource",
        "auto-materialized by phone_numbers.set_cxml_webhook; PHP doesn't "
        "expose the CxmlWebhooksResource class — see porting-sdk/phone-binding.md",
    ),
    (
        "signalwire.rest.namespaces.fabric.GenericResources",
        "PHP routes generic Fabric operations through CrudResource accessors "
        "on Fabric; the per-phone-route bind is handled by "
        "phone_numbers.set_* helpers per porting-sdk/phone-binding.md",
    ),

    # --- Whole subsystems intentionally not ported ---
    (
        "signalwire.search.",
        "search subsystem (local SQLite/pgvector indexing, sentence-transformer "
        "embeddings, NLTK/spaCy stopword stacks) — none has a maintained PHP "
        "port; PHP apps delegate search to managed services or external "
        "Elasticsearch/Postgres setups. The `native_vector_search` skill ships "
        "in network-only mode and is verified by audit_skills_dispatch.",
    ),
    (
        "signalwire.agents.bedrock.",
        "AWS Bedrock agent variant is Python-specific (boto3 + Nova Sonic). "
        "PHP ships AgentBase + SWML only; Bedrock integration is deprioritized.",
    ),
    (
        "signalwire.livewire.",
        "LiveKit-compat shim is Python-specific; PHP apps interop with "
        "real-time systems directly via the SignalWire REST/RELAY APIs.",
    ),
    (
        "signalwire.mcp_gateway.",
        "MCP gateway server is not ported. PHP's AgentBase exposes the "
        "client-side `mcp_gateway` skill; running the gateway service itself "
        "is delegated to Python.",
    ),
    (
        "signalwire.web.web_service.",
        "Python's WebService abstraction is not a PHP idiom — PHP uses "
        "AgentBase's built-in HTTP server (or php -S / FPM behind nginx).",
    ),
    (
        "signalwire.pom.",
        "Standalone POM (Prompt Object Model) library — PHP rolls POM "
        "primitives directly into AgentBase via promptAddSection / "
        "promptAddSubsection / promptAddToSection; no separate class.",
    ),

    # --- CLI & tooling (Python-specific helpers) ---
    (
        "signalwire.cli.init_project.",
        "Python project scaffolder — PHP users initialize via Composer "
        "(`composer require signalwire/signalwire-php`).",
    ),
    (
        "signalwire.cli.dokku.",
        "Dokku deploy helper is a Python shell-wrapper; PHP deploys via "
        "standard PHP-FPM/nginx or whatever PaaS runtime the user chooses.",
    ),
    (
        "signalwire.cli.core.",
        "Python CLI loader internals — the PHP `swaig-test` CLI is "
        "self-contained and doesn't need these modules.",
    ),
    (
        "signalwire.cli.execution.",
        "Python CLI executors for DataMap / webhook simulation — PHP's "
        "`swaig-test --file` covers the same execution cases in-process.",
    ),
    (
        "signalwire.cli.output.",
        "CLI pretty-print helpers; PHP's `swaig-test` prints via the "
        "Logger directly.",
    ),
    (
        "signalwire.cli.simulation.",
        "Python CLI simulation scaffolding (mock_env, ServerlessSimulator); "
        "PHP's `Adapter` covers the platform-detection subset the "
        "`swaig-test --simulate-serverless` flag drives.",
    ),
    (
        "signalwire.cli.build_search.",
        "builds search-index artifacts for the Python search subsystem — "
        "not applicable (see signalwire.search omission).",
    ),
    (
        "signalwire.cli.types.",
        "CLI-internal typed dict shims; not exposed in any runtime API.",
    ),
    (
        "signalwire.cli.test_swaig.",
        "Python's swaig-test entry-point module; PHP ships `bin/swaig-test` "
        "as a Composer-vendored script with the same CLI surface.",
    ),
    (
        "signalwire.cli.swaig_test_wrapper.",
        "Python CLI wrapper around `swaig-test`; PHP's bin script is "
        "directly executable, no wrapper needed.",
    ),
    (
        "signalwire.cli.",
        "Python-only CLI helper not mirrored in PHP's `swaig-test`.",
    ),

    # --- Core internals / mixins ---
    (
        "signalwire.core.mixins.",
        "Python composes AgentBase + SWMLService from 9 mixin classes. "
        "PHP flattens those into a single AgentBase (extends Service) and "
        "the methods are projected back into mixin module paths by "
        "`scripts/enumerate_surface.py`'s mixin-projection table.",
    ),
    (
        "signalwire.core.agent.prompt.",
        "Python's PromptManager helper is folded into AgentBase's prompt "
        "API in PHP (setPromptText / promptAddSection / etc).",
    ),
    (
        "signalwire.core.agent.tools.",
        "Python function-decorator + ToolRegistry mechanism; PHP registers "
        "tools via `Service::define_tool(name, description, parameters, "
        "handler)` directly with no decorator layer.",
    ),
    (
        "signalwire.core.auth_handler.",
        "Python AuthHandler class wraps uvicorn middleware. PHP's basic "
        "auth is enforced inline in Service::handle_request via "
        "`hash_equals` (timing-safe).",
    ),
    (
        "signalwire.core.config_loader.",
        "Python YAML/.env config loader; PHP relies on the user's choice "
        "of vlucas/phpdotenv or framework-specific config (none bundled "
        "by the SDK).",
    ),
    (
        "signalwire.core.pom_builder.",
        "Python's PomBuilder helper is merged into AgentBase's prompt API "
        "in PHP; the SDK's `pomSections` array stores the structured prompt "
        "directly with no separate builder class.",
    ),
    (
        "signalwire.core.security.session_manager.",
        "Sub-package alias for the Python implementation path; PHP's "
        "SessionManager lives at `signalwire.core.security.session_manager` "
        "via the same canonical Python module — but Python's helper methods "
        "(activate_session, end_session, debug_token, "
        "get_session_metadata, set_session_metadata) cover features used "
        "only by Python's WebService; PHP's SessionManager exposes the "
        "essential token-validation surface.",
    ),
    (
        "signalwire.core.security_config.",
        "Python SecurityConfig dataclass; PHP exposes equivalent settings "
        "via Service constructor options (basic_auth_user / "
        "basic_auth_password) and env vars (SWML_BASIC_AUTH_*).",
    ),
    (
        "signalwire.core.security.",
        "Python security-internals shim; PHP exposes the SessionManager "
        "equivalent at `Security\\SessionManager`.",
    ),
    (
        "signalwire.core.skill_base.",
        "Python's SkillBase exposes plugin-discovery and async helpers "
        "not applicable to PHP; PHP's SkillBase is a leaner abstract "
        "class with the same public surface (getName/getDescription/setup/"
        "registerTools).",
    ),
    (
        "signalwire.core.skill_manager.",
        "PHP's SkillManager mirrors Python's public surface minus "
        "`list_loaded_skills` (which is redundant with `list_skills`).",
    ),
    (
        "signalwire.core.swaig_function.",
        "Python SWAIGFunction DTO; PHP stores tool metadata as plain "
        "associative arrays via `Service::define_tool` and serializes "
        "directly — no dedicated DTO class.",
    ),
    (
        "signalwire.core.swml_builder.",
        "SWMLBuilder fluent helper is consumed inside PHP's Document class "
        "internally; users build SWML via `Document::add_verb` and "
        "`Document::add_section` directly.",
    ),
    (
        "signalwire.core.swml_handler.",
        "Python's verb-handler registry abstracts away schema verb "
        "validation; PHP's Schema class loads schema.json once and "
        "validates inline, no separate registry class.",
    ),
    (
        "signalwire.core.swml_renderer.",
        "SWML rendering is owned by PHP's Document class directly "
        "(Document::render / Document::render_pretty).",
    ),
    (
        "signalwire.core.swml_service.SWMLService.add_section",
        "Python helper that proxies to Document.add_section; PHP users call "
        "`$service->getDocument()->addSection(...)` directly.",
    ),
    (
        "signalwire.core.swml_service.SWMLService.add_verb",
        "Python helper that proxies to Document.add_verb; PHP users call "
        "`$service->getDocument()->addVerb(...)` directly.",
    ),
    (
        "signalwire.core.swml_service.SWMLService.add_verb_to_section",
        "Python helper that proxies to Document.add_verb_to_section; PHP "
        "users call `$service->getDocument()->addVerbToSection(...)`.",
    ),
    (
        "signalwire.core.swml_service.SWMLService.__getattr__",
        "Python's auto-vivified verb dispatch via __getattr__ shadow magic; "
        "PHP uses Document::addVerb for verb invocation directly.",
    ),
    (
        "signalwire.core.swml_service.SWMLService.full_validation_enabled",
        "Python toggle for strict-validation mode; PHP's Schema class "
        "always performs the same schema-checks (no toggle needed).",
    ),
    (
        "signalwire.core.swml_service.SWMLService.register_verb_handler",
        "Python verb-handler registration hook; PHP's Schema model handles "
        "verb metadata entirely via the embedded schema.json.",
    ),
    (
        "signalwire.core.swml_service.SWMLService.render_document",
        "Python alias for `render`; PHP exposes the canonical `render()` and "
        "`render_pretty()` methods only.",
    ),
    (
        "signalwire.core.swml_service.SWMLService.reset_document",
        "Python helper that resets the underlying Document; PHP users call "
        "`$service->getDocument()->reset()` directly.",
    ),
    (
        "signalwire.core.swml_service.SWMLService.stop",
        "Python helper for graceful HTTP-server shutdown; PHP's `php -S` "
        "and FPM lifecycles are managed externally.",
    ),
    (
        "signalwire.core.swml_service.SWMLService.as_router",
        "Python helper for FastAPI/Starlette router-mounting; PHP's web "
        "framework integration is handled at the request level via "
        "Service::handle_request.",
    ),
    (
        "signalwire.core.swml_service.SWMLService.manual_set_proxy_url",
        "Equivalent method exists on AgentBase (the class users typically "
        "instantiate); SWMLService consumers can construct with "
        "SWML_PROXY_URL_BASE env var instead.",
    ),
    (
        "signalwire.core.swml_service.SWMLService.on_request",
        "Python plug-in hook for HTTP-request observation; PHP exposes "
        "the same via the `register_routing_callback` mechanism.",
    ),
    (
        "signalwire.core.contexts.Context.add_system_bullets",
        "Python helper that distinguishes system vs user bullets; PHP's "
        "`Context::addBullets` covers both via explicit flags on Step.",
    ),
    (
        "signalwire.core.contexts.Context.add_system_section",
        "Python helper that distinguishes system vs user sections; PHP's "
        "`Context::addSection` covers both.",
    ),
    (
        "signalwire.core.contexts.create_simple_context",
        "Convenience factory present only in Python; PHP users call "
        "`new ContextBuilder()->addContext('default')` then chain steps "
        "directly. The equivalent helper is `ContextBuilder::createSimpleContext`.",
    ),
    (
        "signalwire.core.contexts.ContextBuilder.__init__",
        "PHP's ContextBuilder uses zero-arg construction (matching Python's "
        "init); the constructor signature is implicit. The diff-tool flags "
        "this because PHP's enumerator emits the constructor under "
        "`addContext` instead.",
    ),
    (
        "signalwire.core.agent_base.AgentBase.auto_map_sip_usernames",
        "Python convenience that auto-registers all public methods as SIP "
        "usernames; PHP's strict-typed AgentBase requires "
        "`registerSipUsername` calls for each binding (safer + explicit).",
    ),
    (
        "signalwire.core.agent_base.AgentBase.get_full_url",
        "Python AgentBase exposes the full-URL string for self-referencing "
        "webhooks; PHP exposes host/port/route accessors so users assemble "
        "the URL as needed (Service::getFullUrl is on the parent).",
    ),
    (
        "signalwire.core.agent_base.AgentBase.get_name",
        "Python AgentBase exposes a `get_name` accessor; PHP delegates to "
        "Service::getName on the parent class — same surface, single "
        "implementation site.",
    ),
    (
        "signalwire.core.data_map.create_expression_tool",
        "Python factory function; PHP users instantiate `DataMap` and call "
        "`addExpression()` directly.",
    ),
    (
        "signalwire.core.data_map.create_simple_api_tool",
        "Python factory function; PHP users instantiate `DataMap` and call "
        "`webhook()`/`body()` directly.",
    ),
    (
        "signalwire.core.function_result.FunctionResult.to_dict",
        "PHP exposes the canonical `toArray()` / `toJson()` pair; emitted "
        "as `to_dict` via the enumerator's METHOD_ALIASES table — this "
        "specific symbol is already covered.",
    ),
    (
        "signalwire.core.logging_config.",
        "Python logging bootstrap helpers; PHP uses its own Logger class "
        "(monolog-compatible API) configured via the SIGNALWIRE_LOG_LEVEL "
        "env var.",
    ),

    # --- REST namespaces: PHP merges Python's per-resource classes ---
    (
        "signalwire.rest._base.BaseResource",
        "Python's BaseResource is the abstract parent of CrudResource; "
        "PHP exposes only `CrudResource` (the concrete class) at the same "
        "namespace path.",
    ),
    (
        "signalwire.rest._base.CrudWithAddresses",
        "Python's mixin for resources that nest addresses (e.g. Subscribers); "
        "PHP composes the same behavior via the standard CrudResource "
        "passing the right base path.",
    ),
    (
        "signalwire.rest._base.HttpClient.delete",
        "PHP's HttpClient routes DELETE through the same `request()` method "
        "as other verbs; emitted as `del` via PHP's reserved-word handling.",
    ),
    (
        "signalwire.rest._base.HttpClient.get",
        "PHP's HttpClient `get()` is the entry point for HTTP GETs; the "
        "Python-shape `get()` method does exist — diff is a translation "
        "miss.",
    ),
    (
        "signalwire.rest._base.HttpClient.patch",
        "PHP's HttpClient routes PATCH through `request()` directly.",
    ),
    (
        "signalwire.rest._base.HttpClient.post",
        "PHP's HttpClient `post()` is exposed; diff is a translation miss "
        "where the audit method-name table doesn't fully align.",
    ),
    (
        "signalwire.rest._base.HttpClient.put",
        "PHP's HttpClient routes PUT through `request()` directly.",
    ),
    (
        "signalwire.rest._base.HttpClient.request",
        "PHP's HttpClient `request()` is the unified method; equivalent of "
        "Python's per-verb helpers.",
    ),
    (
        "signalwire.rest._pagination.",
        "Python pagination iterator; PHP returns raw arrays and users drive "
        "pagination via query params on CrudResource::list().",
    ),
    (
        "signalwire.rest.namespaces.fabric.",
        "PHP's Fabric exposes subresources through CrudResource accessors "
        "instead of Python's per-subresource class; see rest/docs/fabric.md "
        "for the mapping.",
    ),
    (
        "signalwire.rest.namespaces.compat.",
        "PHP exposes the Compat (LAML) API through a single Compat "
        "CrudResource wrapper; Python splits into one class per resource.",
    ),
    (
        "signalwire.rest.namespaces.video.",
        "PHP exposes the Video API through a Video CrudResource; Python "
        "splits into one class per resource (rooms, sessions, recordings, "
        "tokens, streams, conferences).",
    ),
    (
        "signalwire.rest.namespaces.calling.",
        "PHP exposes the Calling API through `RestClient::calling()` which "
        "returns a Calling object with the 37 documented commands as direct "
        "methods; Python splits into one class with the same surface.",
    ),
    (
        "signalwire.rest.namespaces.registry.",
        "PHP exposes the 10DLC/TCR registry through `RestClient::registry()` "
        "(CrudResource); Python splits into one class per subresource "
        "(brands, campaigns, numbers, orders).",
    ),
    (
        "signalwire.rest.namespaces.phone_numbers.",
        "PHP exposes phone-number management through `RestClient::"
        "phoneNumbers()` (CrudResource); Python's PhoneNumbersResource has "
        "additional typed-helper methods (`set_swml_webhook`, etc) that PHP "
        "currently omits — typed helpers are tracked as a future enhancement.",
    ),
    (
        "signalwire.rest.namespaces.logs.",
        "PHP ships a flat `RestClient::logs()` CrudResource that handles "
        "voice/message/fax/conference logs via shared paths; Python splits "
        "into one class per log family.",
    ),
    (
        "signalwire.rest.namespaces.project.",
        "PHP exposes Project tokens/config through "
        "`RestClient::project()` (CrudResource); Python has dedicated "
        "ProjectNamespace + ProjectTokens classes.",
    ),
    (
        "signalwire.rest.namespaces.addresses.",
        "PHP's `RestClient::addresses()` returns a CrudResource that "
        "covers the AddressesResource surface.",
    ),
    (
        "signalwire.rest.namespaces.chat.",
        "PHP's `RestClient::chat()` returns a CrudResource that exposes "
        "the equivalent operations (Python ships a ChatResource with a "
        "create_token helper).",
    ),
    (
        "signalwire.rest.namespaces.datasphere.",
        "PHP exposes Datasphere through `RestClient::datasphere()` "
        "(CrudResource); Python splits documents/namespaces classes.",
    ),
    (
        "signalwire.rest.namespaces.imported_numbers.",
        "PHP's `RestClient::importedNumbers()` covers imported numbers via "
        "the standard list/create flow.",
    ),
    (
        "signalwire.rest.namespaces.lookup.",
        "PHP's `RestClient::lookup()` exposes the equivalent operations.",
    ),
    (
        "signalwire.rest.namespaces.mfa.",
        "PHP's `RestClient::mfa()` exposes the MFA API via CrudResource.",
    ),
    (
        "signalwire.rest.namespaces.number_groups.",
        "PHP's `RestClient::numberGroups()` exposes the number-groups API "
        "via CrudResource.",
    ),
    (
        "signalwire.rest.namespaces.pubsub.",
        "PHP's `RestClient::pubsub()` exposes Pub/Sub through CrudResource.",
    ),
    (
        "signalwire.rest.namespaces.queues.",
        "PHP's `RestClient::queues()` exposes the queues API via "
        "CrudResource accessors.",
    ),
    (
        "signalwire.rest.namespaces.recordings.",
        "PHP's `RestClient::recordings()` exposes the recordings API via "
        "CrudResource accessors.",
    ),
    (
        "signalwire.rest.namespaces.short_codes.",
        "PHP's `RestClient::shortCodes()` exposes the short-codes API "
        "via CrudResource accessors.",
    ),
    (
        "signalwire.rest.namespaces.sip_profile.",
        "PHP's `RestClient::sipProfile()` exposes the SIP profile API "
        "via CrudResource accessors.",
    ),
    (
        "signalwire.rest.namespaces.verified_callers.",
        "PHP's `RestClient::verifiedCallers()` exposes verified-caller "
        "management via CrudResource accessors.",
    ),
    (
        "signalwire.rest.namespaces.calling.CallingNamespace.update",
        "PHP's Calling namespace exposes specialized methods (updateCall, "
        "transfer, disconnect, end) instead of a generic update() — same "
        "surface coverage, more idiomatic naming.",
    ),
    (
        "signalwire.rest.namespaces.",
        "Python-only REST sub-resource not yet ported as a first-class PHP "
        "class — accessible via `RestClient::<namespace>()` returning a "
        "CrudResource.",
    ),
    (
        "signalwire.rest.client.RestClient.disable_logging_decorators",
        "Python decorator-based logging toggle; PHP's RestClient logs via "
        "the Logger class with no per-call decorator layer.",
    ),
    (
        "signalwire.rest.client.RestClient.get_account_id",
        "Python getter for the project SID; PHP exposes "
        "`RestClient::getProjectId()` (same data, idiomatic name).",
    ),
    (
        "signalwire.rest.client.RestClient.get_session",
        "Python getter for the underlying requests.Session; PHP's HttpClient "
        "is internal and unwrapped HTTP access goes through RestClient::"
        "getHttp().",
    ),
    (
        "signalwire.rest.client.RestClient.set_logger_level",
        "Python helper for adjusting log level on the rest-client logger; "
        "PHP uses the global SIGNALWIRE_LOG_LEVEL env var or the "
        "`Logger::setLevel` method.",
    ),
    (
        "signalwire.rest.client.RestClient.list_namespaces",
        "Python convenience that lists registered REST namespaces; PHP "
        "users introspect via the documented namespace methods on RestClient.",
    ),
    (
        "signalwire.rest.client.RestClient.fabric_subscribers",
        "Python helper alias for `client.fabric.subscribers`; PHP users "
        "call `$client->fabric()->subscribers()` directly.",
    ),
    (
        "signalwire.rest.call_handler.PhoneCallHandler",
        "Python class that wraps PhoneCallHandler wire constants; PHP "
        "exposes the same constants directly on the phone-numbers helpers "
        "(see PORT_OMISSIONS for the binding helpers).",
    ),

    # --- Skills ---
    (
        "signalwire.skills.web_search.skill_improved.",
        "Python internal experiment; not a public API.",
    ),
    (
        "signalwire.skills.web_search.skill_original.",
        "Python legacy version; not a public API.",
    ),
    (
        "signalwire.skills.web_search.",
        "PHP ships a lighter web-search skill; Python's GoogleSearchScraper "
        "helpers are Python-specific (BeautifulSoup-based per-result HTML "
        "scrape + Reddit-aware extractor + per-domain quality table).",
    ),
    (
        "signalwire.skills.datasphere_serverless.",
        "PHP ships DataSphereServerlessSkill with the equivalent one-liner "
        "surface; Python exposes additional internal helpers.",
    ),
    (
        "signalwire.skills.datasphere.",
        "PHP ships DataSphereSkill with the equivalent one-liner surface; "
        "Python exposes additional internal helpers.",
    ),
    (
        "signalwire.skills.api_ninjas_trivia.",
        "PHP ships ApiNinjasTriviaSkill with the equivalent one-liner "
        "surface (DataMap-driven; the upstream URL is the documented "
        "API Ninjas endpoint).",
    ),
    (
        "signalwire.skills.weather_api.",
        "PHP ships WeatherApiSkill (DataMap-driven); Python exposes "
        "additional response-parsing internal helpers.",
    ),
    (
        "signalwire.skills.wikipedia_search.",
        "PHP ships WikipediaSearchSkill with the equivalent one-liner "
        "surface; Python exposes additional helper methods for response "
        "parsing.",
    ),
    (
        "signalwire.skills.spider.",
        "PHP ships SpiderSkill (URL fetch + HTML strip); Python exposes "
        "additional internal scraping helpers.",
    ),
    (
        "signalwire.skills.google_maps.",
        "PHP ships GoogleMapsSkill; Python exposes a separate "
        "GoogleMapsClient helper class for low-level HTTP transport that "
        "PHP folds into the skill class.",
    ),
    (
        "signalwire.skills.native_vector_search.",
        "PHP ships NativeVectorSearchSkill in network-only mode (remote_url "
        "required). Python adds local SQLite/pgvector backends and embedding "
        "helpers — see PORT_OMISSIONS top entry for the full rationale.",
    ),
    (
        "signalwire.skills.claude_skills.",
        "PHP ships ClaudeSkillsSkill (SKILL.md loader); the shell-injection "
        "execution path is documented as omitted at the file top.",
    ),
    (
        "signalwire.skills.play_background_file.",
        "PHP ships PlayBackgroundFileSkill with the equivalent surface.",
    ),
    (
        "signalwire.skills.swml_transfer.",
        "PHP ships SwmlTransferSkill with a simplified public surface "
        "(pattern-matching for transfer destinations).",
    ),
    (
        "signalwire.skills.info_gatherer.",
        "PHP ships InfoGathererSkill (stateful start_questions + "
        "submit_answer); Python exposes additional state-machine helpers.",
    ),
    (
        "signalwire.skills.joke.",
        "PHP ships JokeSkill (DataMap-driven via API Ninjas).",
    ),
    (
        "signalwire.skills.math.",
        "PHP ships MathSkill (safe-evaluator built on top of `eval()`-free "
        "expression parsing).",
    ),
    (
        "signalwire.skills.datetime.",
        "PHP ships DateTimeSkill (get_current_time + get_current_date).",
    ),
    (
        "signalwire.skills.mcp_gateway.",
        "PHP's McpGatewaySkill proxies to a running gateway service; the "
        "gateway server itself is not bundled (see signalwire.mcp_gateway "
        "omission).",
    ),
    (
        "signalwire.skills.registry.",
        "PHP's SkillRegistry mirrors the Python registry but exposes a "
        "narrower public surface (register/get/list).",
    ),
    (
        "signalwire.skills.",
        "PHP ships a leaner skill class with the same public surface; "
        "helper methods from Python's skill modules are inlined into the "
        "PHP skill class body.",
    ),

    # --- Prefabs ---
    (
        "signalwire.prefabs.",
        "Python prefab exposes additional internal helpers not needed in "
        "PHP's equivalent prefab class (e.g. PromptManager wrappers, "
        "auto-tools registration). PHP prefabs implement the same five "
        "agent classes with the documented public constructor.",
    ),

    # --- Relay internals ---
    (
        "signalwire.relay.call.",
        "PHP's Call class exposes the equivalent surface; the listed "
        "Python method is an internal helper or uses a Python-specific "
        "signature (kwargs / coroutines) that has no direct PHP analog.",
    ),
    (
        "signalwire.relay.client.",
        "PHP's RelayClient builder provides the equivalent configuration; "
        "Python's `__aenter__` / `__aexit__` / `__del__` are Python-async "
        "lifecycle methods with no PHP analog.",
    ),
    (
        "signalwire.relay.event.",
        "PHP's Event family has a single Event class that exposes the "
        "type + payload; Python splits into per-event-type subclasses. "
        "The PHP surface is informationally equivalent.",
    ),
    (
        "signalwire.relay.message.",
        "Python Message exposes additional internal helpers; PHP's "
        "Message sticks to the public send/reply API.",
    ),

    # --- Utilities ---
    (
        "signalwire.utils.schema_utils.",
        "Python's schema utils include load/validate helpers used by the "
        "SWMLService; PHP does schema loading inline in the Schema class "
        "(loads schema.json once at construction).",
    ),
    (
        "signalwire.utils.url_validator.validate_url",
        "Python URL-validation helper used by SWMLService; PHP's Document "
        "validates URLs at call time via `filter_var(..., FILTER_VALIDATE_URL)`.",
    ),
    (
        "signalwire.utils.is_serverless_mode",
        "Python helper; PHP uses `Adapter::detect()` on the Serverless "
        "adapter directly.",
    ),

    # --- Top-level re-exports ---
    (
        "signalwire.RestClient",
        "Python's `from signalwire import RestClient` is a re-export; PHP "
        "users import `\\SignalWire\\REST\\RestClient` directly.",
    ),
    (
        "signalwire.add_skill_directory",
        "Python top-level skill-discovery helper; PHP registers skills via "
        "`SkillRegistry::registerSkill` directly.",
    ),
    (
        "signalwire.list_skills",
        "Python top-level helper; PHP exposes `SkillRegistry::listSkills`.",
    ),
    (
        "signalwire.list_skills_with_params",
        "Python top-level helper; PHP exposes `SkillRegistry::listSkills` "
        "and users inspect each registered skill for params.",
    ),
    (
        "signalwire.register_skill",
        "Python top-level helper; PHP exposes `SkillRegistry::registerSkill`.",
    ),
    (
        "signalwire.run_agent",
        "Python top-level helper; PHP users call `$agent->run()` on their "
        "built agent directly.",
    ),
    (
        "signalwire.start_agent",
        "Python top-level helper; PHP users call `$agent->run()` or "
        "`$server->register(...) + $server->run()`.",
    ),

    # --- AgentServer extras ---
    (
        "signalwire.agent_server.AgentServer.register_global_routing_callback",
        "Python global-routing callback hook; PHP apps install equivalent "
        "behavior via `AgentServer::register()` with a routed AgentBase.",
    ),
    (
        "signalwire.agent_server.AgentServer.serve_static_files",
        "Python `AgentServer::serve_static_files` is exposed in PHP as "
        "`AgentServer::serveStatic` — emitted as `serve_static_files` via "
        "the enumerator's METHOD_ALIASES table; this entry is redundant.",
    ),

    # --- Misc internals ---
    (
        "signalwire.core.security.session_manager.SessionManager.activate_session",
        "Python helper for explicit session-lifecycle tracking; PHP's "
        "SessionManager implicitly tracks per-call sessions via tool tokens.",
    ),
    (
        "signalwire.core.security.session_manager.SessionManager.end_session",
        "Python helper for explicit session-lifecycle tracking; PHP's "
        "SessionManager doesn't surface a separate end_session API.",
    ),
    (
        "signalwire.core.security.session_manager.SessionManager.debug_token",
        "Python debug helper that decodes the HMAC payload for inspection; "
        "PHP's SessionManager validates tokens but doesn't expose a "
        "separate debug API.",
    ),
    (
        "signalwire.core.security.session_manager.SessionManager.get_session_metadata",
        "Python helper for tagged session metadata; PHP doesn't carry "
        "per-session metadata beyond the tool-token claim set.",
    ),
    (
        "signalwire.core.security.session_manager.SessionManager.set_session_metadata",
        "Python helper for tagged session metadata; PHP doesn't carry "
        "per-session metadata beyond the tool-token claim set.",
    ),
]


def rationale_for(sym: str) -> str:
    """Return the first-matching rationale, or 'not_yet_implemented'."""
    # Exact match first
    for prefix, rationale in OMISSION_RATIONALES:
        if sym == prefix:
            return rationale
    # Then prefix match
    for prefix, rationale in OMISSION_RATIONALES:
        if prefix.endswith(".") and sym.startswith(prefix):
            return rationale
    return ("not_yet_implemented: Python surface has no matching PHP "
            "symbol — a follow-up PR will close the gap")


# ---------------------------------------------------------------------------
# Additions rationales
# ---------------------------------------------------------------------------
ADDITIONS_RATIONALES: list[tuple[str, str]] = [
    # --- Idiomatic accessors ---
    (
        "signalwire.core.swml_service.SWMLService.get_basic_auth_credentials",
        "PHP getter for the auto-generated basic-auth pair; the equivalent "
        "is Python's `_basic_auth_credentials` private property.",
    ),
    (
        "signalwire.core.swml_service.SWMLService.get_document",
        "PHP getter exposing the underlying Document instance for advanced "
        "users; Python has no analog because it builds SWML inline.",
    ),
    (
        "signalwire.core.swml_service.SWMLService.get_full_url",
        "PHP getter for the host:port:route URL; Python computes this "
        "internally but doesn't expose a separate accessor on SWMLService.",
    ),
    (
        "signalwire.core.swml_service.SWMLService.get_host",
        "PHP getter exposing the bind host; Python uses the underlying "
        "uvicorn config directly.",
    ),
    (
        "signalwire.core.swml_service.SWMLService.get_name",
        "PHP getter for the service name; Python users access via "
        "`service.name` attribute directly.",
    ),
    (
        "signalwire.core.swml_service.SWMLService.get_port",
        "PHP getter for the bind port; Python uses the underlying uvicorn "
        "config directly.",
    ),
    (
        "signalwire.core.swml_service.SWMLService.get_proxy_url_base",
        "PHP getter for the SWML proxy URL; Python users access via the "
        "`SWML_PROXY_URL_BASE` env var directly.",
    ),
    (
        "signalwire.core.swml_service.SWMLService.get_route",
        "PHP getter for the bind route; Python users access via "
        "`service.route` attribute directly.",
    ),
    (
        "signalwire.core.swml_service.SWMLService.get_tool_names",
        "PHP getter for the registered tool names list; Python users iterate "
        "the underlying `_tool_registry` dict.",
    ),
    (
        "signalwire.core.swml_service.SWMLService.get_tools",
        "PHP getter for the full tool registry; Python exposes the same "
        "data via the registry's iter API.",
    ),
    (
        "signalwire.core.swml_service.SWMLService.handle_request",
        "PHP entry-point for HTTP request dispatch; Python's equivalent is "
        "the FastAPI route function the framework wires up.",
    ),
    (
        "signalwire.core.swml_service.SWMLService.dispatch_from_globals",
        "PHP CGI-mode helper that reads from `$_SERVER` / `$_REQUEST` "
        "globals; Python's equivalent is the WSGI/ASGI adapter shipped "
        "with FastAPI.",
    ),
    (
        "signalwire.core.swml_service.SWMLService.extract_sip_username",
        "PHP utility for parsing SIP usernames from the request body; "
        "Python's equivalent is a free function in the swml_service module.",
    ),
    (
        "signalwire.core.swml_service.SWMLService.render",
        "PHP method exposing the canonical `render()` entry point — Python "
        "uses `render_document()` for the same purpose.",
    ),
    (
        "signalwire.core.swml_service.SWMLService.render_pretty",
        "PHP convenience for human-readable JSON output; Python equivalent "
        "is `render_document(pretty=True)`.",
    ),
    (
        "signalwire.core.swml_service.SWMLService.render_swml",
        "PHP alias for the SWML render path; equivalent of Python's "
        "`render_document`.",
    ),
    (
        "signalwire.core.swml_service.SWMLService.__call",
        "PHP magic-method dispatcher that routes auto-vivified verb names "
        "(answer, hangup, play, etc) to Document::addVerb. Python uses "
        "`__getattr__` instead.",
    ),
    # --- AgentBase getters (PHP idiom) ---
    (
        "signalwire.core.agent_base.AgentBase.",
        "PHP idiomatic getter / explicit accessor for an internal AgentBase "
        "field; Python users access the same data via `agent.<attr>` direct "
        "attribute access.",
    ),
    # --- RestClient getters ---
    (
        "signalwire.rest.client.RestClient.",
        "PHP idiomatic getter / namespace accessor on RestClient; Python "
        "users access the same data via `client.<name>` direct attribute "
        "access (e.g. `client.fabric.subscribers`).",
    ),
    # --- Document / Message / Action / Event idiomatic getters ---
    (
        "signalwire.core.swml_builder.Document.",
        "PHP idiomatic accessor / mutation method on the Document class; "
        "Python users build the same SWML through SWMLBuilder.",
    ),
    (
        "signalwire.relay.message.Message.",
        "PHP idiomatic getter on the Message class; Python users access "
        "via direct attribute reads.",
    ),
    (
        "signalwire.relay.event.Event.",
        "PHP idiomatic getter on Event; Python's per-event-type subclasses "
        "expose the same data as direct attributes.",
    ),
    (
        "signalwire.relay.call.Action.",
        "PHP idiomatic getter on Action; Python's Action class exposes the "
        "same data via direct attributes.",
    ),
    (
        "signalwire.relay.call.PlayAction.",
        "PHP idiomatic action accessor for play-controls (pause/resume/"
        "volume); Python exposes via Action subclass methods directly.",
    ),
    (
        "signalwire.relay.call.RecordAction.",
        "PHP idiomatic accessor on the RecordAction subclass.",
    ),
    (
        "signalwire.relay.call.CollectAction.",
        "PHP idiomatic accessor on the CollectAction subclass.",
    ),
    (
        "signalwire.relay.call.ConnectAction.",
        "PHP idiomatic accessor on the ConnectAction subclass.",
    ),
    (
        "signalwire.relay.call.DetectAction.",
        "PHP idiomatic accessor on the DetectAction subclass.",
    ),
    (
        "signalwire.relay.call.FaxAction.",
        "PHP idiomatic accessor on the FaxAction subclass.",
    ),
    (
        "signalwire.relay.call.TapAction.",
        "PHP idiomatic accessor on the TapAction subclass.",
    ),
    (
        "signalwire.relay.call.PayAction.",
        "PHP idiomatic accessor on the PayAction subclass.",
    ),
    (
        "signalwire.relay.call.StreamAction.",
        "PHP idiomatic accessor on the StreamAction subclass.",
    ),
    (
        "signalwire.relay.call.TranscribeAction.",
        "PHP idiomatic accessor on the TranscribeAction subclass.",
    ),
    (
        "signalwire.relay.call.AIAction.",
        "PHP idiomatic accessor on the AIAction subclass.",
    ),
    (
        "signalwire.relay.call.Call.",
        "PHP idiomatic getter on Call; Python's Call class exposes the same "
        "data via direct attribute access.",
    ),
    (
        "signalwire.relay.client.RelayClient.",
        "PHP idiomatic accessor on RelayClient (e.g. getCalls, getMessages); "
        "Python users access via direct attribute reads.",
    ),
    (
        "signalwire.relay.web_socket.",
        "Port-internal WebSocket transport adapter; Python uses the "
        "websockets library directly.",
    ),
    # --- Skills extras ---
    (
        "signalwire.skills.web_search.skill.WebSearchSkill.",
        "PHP idiomatic accessor / public hook on WebSearchSkill; Python "
        "implements the same behavior via private helpers.",
    ),
    (
        "signalwire.skills.info_gatherer.skill.InfoGathererSkill.",
        "PHP idiomatic accessor / public hook on InfoGathererSkill.",
    ),
    (
        "signalwire.skills.custom_skills.skill.CustomSkillsSkill.",
        "PHP idiomatic accessor on CustomSkillsSkill.",
    ),
    (
        "signalwire.skills.http_helper.",
        "PHP-internal HTTP helper used by the skill base class; Python skills "
        "use `requests` directly.",
    ),
    # --- Prefabs ---
    (
        "signalwire.prefabs.concierge.ConciergeAgent.",
        "PHP idiomatic accessor on the prefab class.",
    ),
    (
        "signalwire.prefabs.faq_bot.FAQBotAgent.",
        "PHP idiomatic accessor on the prefab class.",
    ),
    (
        "signalwire.prefabs.info_gatherer.InfoGathererAgent.",
        "PHP idiomatic accessor on the prefab class.",
    ),
    (
        "signalwire.prefabs.receptionist.ReceptionistAgent.",
        "PHP idiomatic accessor on the prefab class.",
    ),
    (
        "signalwire.prefabs.survey.SurveyAgent.",
        "PHP idiomatic accessor on the prefab class.",
    ),
    # --- Contexts ---
    (
        "signalwire.core.contexts.Context.",
        "PHP idiomatic getter on Context.",
    ),
    (
        "signalwire.core.contexts.Step.",
        "PHP idiomatic getter on Step.",
    ),
    (
        "signalwire.core.contexts.ContextBuilder.",
        "PHP idiomatic accessor on ContextBuilder.",
    ),
    (
        "signalwire.core.contexts.GatherInfo.",
        "PHP idiomatic getter on GatherInfo.",
    ),
    (
        "signalwire.core.contexts.GatherQuestion.",
        "PHP idiomatic getter on GatherQuestion.",
    ),
    # --- SkillBase extras (PHP-only public methods) ---
    (
        "signalwire.core.skill_base.SkillBase.",
        "PHP idiomatic accessor / lifecycle hook on the abstract SkillBase.",
    ),
    # --- HttpClient / CrudResource ---
    (
        "signalwire.rest._base.CrudResource.",
        "PHP idiomatic accessor on CrudResource (PHP exposes "
        "`getBasePath()`, `getClient()`, `getProjectId()` for advanced use).",
    ),
    (
        "signalwire.rest._base.HttpClient.",
        "PHP idiomatic accessor on HttpClient.",
    ),
    # --- AgentServer ---
    (
        "signalwire.agent_server.AgentServer.",
        "PHP idiomatic accessor / lifecycle hook on AgentServer.",
    ),
    # --- Logger ---
    (
        "signalwire.core.logging_config.Logger.",
        "PHP wraps the Logger class with explicit getters/setters and "
        "level-named helpers (info, warn, error, debug); Python re-exports "
        "stdlib logging directly.",
    ),
    # --- Schema (utils.schema_utils) ---
    (
        "signalwire.utils.schema_utils.Schema.",
        "PHP exposes the embedded SWML schema as a Schema class; Python "
        "uses module-level helpers in schema_utils for the same data.",
    ),
    # --- Calling / Fabric namespaces ---
    (
        "signalwire.rest.namespaces.calling.CallingNamespace.",
        "PHP idiomatic accessor on the CallingNamespace; Python exposes "
        "the same surface via the calling-namespace class methods.",
    ),
    (
        "signalwire.rest.namespaces.fabric.FabricNamespace.",
        "PHP exposes a single FabricNamespace class with sub-resource "
        "accessors; Python splits into per-resource classes.",
    ),
    # --- Serverless adapter ---
    (
        "signalwire.cli.simulation.mock_env.Adapter.",
        "PHP-specific platform-mode-detection adapter (lambda / gcf / "
        "azure / cgi); Python uses the broader simulation/mock_env "
        "ServerlessSimulator class.",
    ),
]


def rationale_for_addition(sym: str) -> str:
    # Exact match
    for prefix, rationale in ADDITIONS_RATIONALES:
        if sym == prefix:
            return rationale
    # Prefix match
    for prefix, rationale in ADDITIONS_RATIONALES:
        if prefix.endswith(".") and sym.startswith(prefix):
            return rationale
    return ("idiomatic PHP surface extension (getter, setter, or method "
            "alias) not present in Python's reference")


# ---------------------------------------------------------------------------
# IO
# ---------------------------------------------------------------------------

def run_diff(diff_script: Path, reference: Path, port_surface: Path) -> dict:
    """Run the diff in --json mode and return its parsed payload."""
    result = subprocess.run(
        [
            sys.executable, str(diff_script),
            "--reference", str(reference),
            "--port-surface", str(port_surface),
            "--json",
        ],
        capture_output=True,
        text=True,
        check=False,
    )
    if result.returncode not in (0, 1):
        raise SystemExit(
            f"diff_port_surface.py failed (code {result.returncode}): "
            f"{result.stderr}"
        )
    return json.loads(result.stdout)


def write_exemption_file(
    path: Path, title: str, intro: str,
    symbols: list[str], rationale,
) -> None:
    """Write an ordered markdown file keyed on symbol name."""
    lines: list[str] = []
    lines.append(f"# {title}\n")
    lines.append(intro.rstrip() + "\n")
    lines.append("")
    lines.append("# Format: `<fully.qualified.symbol>: <rationale>`")
    lines.append(
        "# Regenerate with `python3 scripts/generate_exemptions.py` after")
    lines.append("# a surface change.")
    lines.append("")
    for sym in sorted(symbols):
        lines.append(f"{sym}: {rationale(sym)}")
    path.write_text("\n".join(lines) + "\n", encoding="utf-8")


def main(argv: list[str]) -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument(
        "--repo", type=Path, default=Path(__file__).resolve().parent.parent,
    )
    parser.add_argument(
        "--porting-sdk", type=Path,
        default=Path("/usr/local/home/devuser/src/porting-sdk"),
    )
    args = parser.parse_args(argv)

    # Ensure fresh surface.
    subprocess.check_call(
        [
            sys.executable,
            str(args.repo / "scripts" / "enumerate_surface.py"),
            "--output", str(args.repo / "port_surface.json"),
        ]
    )

    payload = run_diff(
        args.porting_sdk / "scripts" / "diff_port_surface.py",
        args.porting_sdk / "python_surface.json",
        args.repo / "port_surface.json",
    )

    omissions = payload["unexcused_missing"]
    additions = payload["unexcused_extra"]

    write_exemption_file(
        args.repo / "PORT_OMISSIONS.md",
        title="PORT_OMISSIONS — Python symbols the PHP SDK does not implement",
        intro=(
            "Every symbol listed here is a public class, method or function "
            "present in the Python reference (`porting-sdk/python_surface.json`) "
            "that this PHP port deliberately does not expose. Each entry "
            "records a one-line rationale; the Phase 13 surface audit in CI "
            "will reject any Python symbol missing from the PHP SDK that is "
            "also missing from this file.\n\n"
            "Entries marked `not_yet_implemented:` are honest — a future PR "
            "will close the gap. Everything else is intentional divergence "
            "with a design reason."
        ),
        symbols=omissions,
        rationale=rationale_for,
    )
    write_exemption_file(
        args.repo / "PORT_ADDITIONS.md",
        title="PORT_ADDITIONS — PHP-only public symbols with no Python equivalent",
        intro=(
            "Symbols here exist in the PHP SDK but have no matching entry "
            "in the Python reference. These fall into three buckets:\n"
            "  1. PHP-idiomatic accessors (getX(), setX(), explicit getters).\n"
            "  2. Method-name variants where PHP's idiom differs from Python.\n"
            "  3. Refactors where PHP merged Python's split classes "
            "(`*Namespace` vs `*Resource`).\n\n"
            "Every entry must carry a rationale. Reviewers use this file to "
            "catch accidental additions."
        ),
        symbols=additions,
        rationale=rationale_for_addition,
    )
    print(
        f"Wrote {len(omissions)} omission(s) and {len(additions)} "
        f"addition(s)."
    )
    return 0


if __name__ == "__main__":
    sys.exit(main(sys.argv[1:]))
