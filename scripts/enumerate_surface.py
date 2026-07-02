#!/usr/bin/env python3
"""enumerate_surface.py -- emit port_surface.json for the PHP SignalWire SDK.

Walks ``src/SignalWire/**/*.php`` and extracts public items:
  * `class Foo` / `final class Foo` / `abstract class Foo` -> classes
  * `public function bar(...)` / `public static function bar(...)` -> methods
  * `__construct(...)` -> emitted as `__init__`

Output is JSON in the shape of ``porting-sdk/python_surface.json``::

    {
      "version": "1",
      "generated_from": "signalwire-php @ <git sha>",
      "modules": {
        "signalwire.core.agent_base": {
          "classes": {
            "AgentBase": ["__init__", "set_prompt_text", ...]
          },
          "functions": [...]
        }
      }
    }

Symbol naming contract (matches Rust / C++ ports):
  * Class names kept as-is (`AgentBase`, `FunctionResult`, ...).
  * The Python reference uses `SWMLService`; PHP's class is `Service`
    inside namespace `SignalWire\\SWML`. Renamed via CLASS_RENAME_MAP
    to match Python.
  * Skill class names get `Skill` suffix appended to match Python's
    convention (e.g. PHP `WebSearch` -> Python `WebSearchSkill`).
  * RELAY client class is `Client` in PHP, `RelayClient` in Python.
  * Method names are PHP camelCase -> snake_case translation
    (setPromptText -> set_prompt_text). Acronym runs are preserved
    (`getURL` -> `get_url`).
  * Constructors emitted as `__init__`.

Module path translation: each PHP class is mapped via
``CLASS_MODULE_MAP`` to its Python-canonical module path. Classes
not in the map fall back to native-namespace translation
(``SignalWire\\Foo\\Bar`` -> ``signalwire.foo.bar``).

Regex-based parsing — pragmatic for ~50 PHP files; PHP class+method
declarations are predictable enough for a line scanner.

Usage:
    python3 scripts/enumerate_surface.py             # write port_surface.json
    python3 scripts/enumerate_surface.py --check     # exit 1 on drift
"""

from __future__ import annotations

import argparse
import json
import re
import subprocess
import sys
from collections import defaultdict
from pathlib import Path

REPO_ROOT = Path(__file__).resolve().parent.parent
SRC_DIR = REPO_ROOT / "src" / "SignalWire"


# ---------------------------------------------------------------------------
# Class -> Python module mapping. Mirrors the C++ / Rust ports.
# ---------------------------------------------------------------------------
CLASS_MODULE_MAP: dict[str, str] = {
    # core/agent
    "AgentBase": "signalwire.core.agent_base",

    # core/swml
    "Service": "signalwire.core.swml_service",  # PHP `Service` -> Python `SWMLService`
    "Document": "signalwire.core.swml_builder",
    # PHP's Schema is a singleton sidecar; canonical SchemaUtils ships
    # under signalwire\Utils\SchemaUtils and projects to the Python
    # canonical name.  The legacy Schema mapping is kept as a port-only
    # alias so existing code references continue to resolve.
    "Schema": "signalwire.utils.schema_utils",
    "SchemaUtils": "signalwire.utils.schema_utils",
    "SchemaValidationError": "signalwire.utils.schema_utils",

    # core/swaig
    "FunctionResult": "signalwire.core.function_result",
    "SwaigFunction": "signalwire.core.swaig_function",  # PHP `SwaigFunction` -> Python `SWAIGFunction`
    # Runtime schema-inference helpers. Python ships them as module-level free
    # functions (signalwire.core.agent.tools.type_inference.infer_schema /
    # create_typed_handler_wrapper); PHP hosts them as static methods on a
    # TypeInference class (PSR-4) routed to the same module. The static methods
    # project to the module-level Python free-function names via
    # enumerate_signatures.py FREE_FUNCTION_PROJECTIONS; the surface diff
    # reconciles them as OMISSIONS (host noted) + the TypeInference class as an
    # ADDITION (mirrors the SecurityUtils / UrlValidator host precedent).
    "TypeInference": "signalwire.core.agent.tools.type_inference",

    # core/auth
    "AuthHandler": "signalwire.core.auth_handler",

    # core/swml renderer (standalone renderer the Python reference records)
    "SwmlRenderer": "signalwire.core.swml_renderer",

    # core/contexts
    "Context": "signalwire.core.contexts",
    "ContextBuilder": "signalwire.core.contexts",
    "GatherInfo": "signalwire.core.contexts",
    "GatherQuestion": "signalwire.core.contexts",
    "Step": "signalwire.core.contexts",

    # core/datamap
    "DataMap": "signalwire.core.data_map",

    # core/security
    "SessionManager": "signalwire.core.security.session_manager",
    "WebhookValidator": "signalwire.core.security.webhook_validator",
    "WebhookMiddleware": "signalwire.core.security.webhook_middleware",
    "SecurityUtils": "signalwire.core.security.security_utils",

    # core/skills
    "SkillBase": "signalwire.core.skill_base",
    "SkillManager": "signalwire.core.skill_manager",
    "SkillRegistry": "signalwire.skills.registry",

    # logging
    "Logger": "signalwire.core.logging_config",

    # server
    "AgentServer": "signalwire.agent_server",

    # serverless
    "Adapter": "signalwire.cli.simulation.mock_env",  # platform mode-detection adapter

    # rest core
    "RestClient": "signalwire.rest.client",
    "HttpClient": "signalwire.rest._base",
    "BaseResource": "signalwire.rest._base",
    "ReadResource": "signalwire.rest._base",
    "CrudResource": "signalwire.rest._base",
    "CrudWithAddresses": "signalwire.rest._base",
    "FabricResource": "signalwire.rest._base",
    "FabricResourcePUT": "signalwire.rest._base",
    "SignalWireRestError": "signalwire.rest._base",

    # ---------------------------------------------------------------
    # Generated REST resource layer (adopted from scripts/generate_rest.py).
    # The 47 resource classes live under
    # SignalWire\REST\Namespaces\Generated\<Name> and project onto the Python
    # oracle's `<ns>_resources_generated` modules (mirrors Go/TS/python). The
    # generated PHP subclass emits ONLY its own declared methods; the CRUD
    # methods the oracle records on the subclass (create/update) are inherited
    # from the hand base and injected by the implicit-base projection below
    # (see _REST_IMPLICIT_BASE / INJECT_BY_BASE).
    # ---------------------------------------------------------------
    # relay-rest namespace
    "Addresses": "signalwire.rest.namespaces.relay_rest_resources_generated",
    "ImportedNumbers": "signalwire.rest.namespaces.relay_rest_resources_generated",
    "Lookup": "signalwire.rest.namespaces.relay_rest_resources_generated",
    "Mfa": "signalwire.rest.namespaces.relay_rest_resources_generated",
    "NumberGroups": "signalwire.rest.namespaces.relay_rest_resources_generated",
    "PhoneNumbers": "signalwire.rest.namespaces.relay_rest_resources_generated",
    "Queues": "signalwire.rest.namespaces.relay_rest_resources_generated",
    "Recordings": "signalwire.rest.namespaces.relay_rest_resources_generated",
    "RegistryBrands": "signalwire.rest.namespaces.relay_rest_resources_generated",
    "RegistryCampaigns": "signalwire.rest.namespaces.relay_rest_resources_generated",
    "RegistryNumbers": "signalwire.rest.namespaces.relay_rest_resources_generated",
    "RegistryOrders": "signalwire.rest.namespaces.relay_rest_resources_generated",
    "ShortCodes": "signalwire.rest.namespaces.relay_rest_resources_generated",
    "SipProfile": "signalwire.rest.namespaces.relay_rest_resources_generated",
    "VerifiedCallers": "signalwire.rest.namespaces.relay_rest_resources_generated",
    # fabric namespace
    "AiAgents": "signalwire.rest.namespaces.fabric_resources_generated",
    "CallFlows": "signalwire.rest.namespaces.fabric_resources_generated",
    "ConferenceRooms": "signalwire.rest.namespaces.fabric_resources_generated",
    "CxmlApplications": "signalwire.rest.namespaces.fabric_resources_generated",
    "CxmlScripts": "signalwire.rest.namespaces.fabric_resources_generated",
    "CxmlWebhooks": "signalwire.rest.namespaces.fabric_resources_generated",
    "FabricAddresses": "signalwire.rest.namespaces.fabric_resources_generated",
    "FabricTokens": "signalwire.rest.namespaces.fabric_resources_generated",
    "FreeswitchConnectors": "signalwire.rest.namespaces.fabric_resources_generated",
    "GenericResources": "signalwire.rest.namespaces.fabric_resources_generated",
    "RelayApplications": "signalwire.rest.namespaces.fabric_resources_generated",
    "SipEndpoints": "signalwire.rest.namespaces.fabric_resources_generated",
    "SipGateways": "signalwire.rest.namespaces.fabric_resources_generated",
    "Subscribers": "signalwire.rest.namespaces.fabric_resources_generated",
    "SwmlScripts": "signalwire.rest.namespaces.fabric_resources_generated",
    "SwmlWebhooks": "signalwire.rest.namespaces.fabric_resources_generated",
    # calling (command-dispatch)
    "Calling": "signalwire.rest.namespaces.calling_resources_generated",
    # chat / pubsub token resources
    "Chat": "signalwire.rest.namespaces.chat_resources_generated",
    "PubSub": "signalwire.rest.namespaces.pubsub_resources_generated",
    # datasphere (NOTE: short name `Datasphere` = the skill; the REST resource
    # class is `DatasphereDocuments`, no collision).
    "DatasphereDocuments": "signalwire.rest.namespaces.datasphere_resources_generated",
    # logs family (split across message/voice/fax/logs specs, grouped by client)
    "MessageLogs": "signalwire.rest.namespaces.message_resources_generated",
    "VoiceLogs": "signalwire.rest.namespaces.voice_resources_generated",
    "FaxLogs": "signalwire.rest.namespaces.fax_resources_generated",
    "ConferenceLogs": "signalwire.rest.namespaces.logs_resources_generated",
    # project tokens
    "ProjectTokens": "signalwire.rest.namespaces.project_resources_generated",
    # video namespace
    "VideoRooms": "signalwire.rest.namespaces.video_resources_generated",
    "VideoRoomTokens": "signalwire.rest.namespaces.video_resources_generated",
    "VideoRoomSessions": "signalwire.rest.namespaces.video_resources_generated",
    "VideoRoomRecordings": "signalwire.rest.namespaces.video_resources_generated",
    "VideoConferences": "signalwire.rest.namespaces.video_resources_generated",
    "VideoConferenceTokens": "signalwire.rest.namespaces.video_resources_generated",
    "VideoStreams": "signalwire.rest.namespaces.video_resources_generated",

    # Generated namespace CONTAINER classes → the oracle's
    # `_client_tree_generated` module (mirrors Python's client assembly).
    "FabricNamespace": "signalwire.rest.namespaces._client_tree_generated",
    "VideoNamespace": "signalwire.rest.namespaces._client_tree_generated",
    "LogsNamespace": "signalwire.rest.namespaces._client_tree_generated",
    "RegistryNamespace": "signalwire.rest.namespaces._client_tree_generated",
    "ProjectNamespace": "signalwire.rest.namespaces._client_tree_generated",
    "DatasphereNamespace": "signalwire.rest.namespaces._client_tree_generated",

    # POM (Prompt Object Model) — typed standalone classes
    "PromptObjectModel": "signalwire.pom.pom",
    "Section": "signalwire.pom.pom",

    # Pagination helper
    "PaginatedIterator": "signalwire.rest._pagination",

    # relay
    "Client": "signalwire.relay.client",   # PHP `Relay\Client` -> Python `RelayClient`
    "Call": "signalwire.relay.call",
    "Message": "signalwire.relay.message",
    "Action": "signalwire.relay.call",
    "PlayAction": "signalwire.relay.call",
    "RecordAction": "signalwire.relay.call",
    "CollectAction": "signalwire.relay.call",
    "DetectAction": "signalwire.relay.call",
    "FaxAction": "signalwire.relay.call",
    "TapAction": "signalwire.relay.call",
    "StreamAction": "signalwire.relay.call",
    "PayAction": "signalwire.relay.call",
    "TranscribeAction": "signalwire.relay.call",
    "AIAction": "signalwire.relay.call",
    "Event": "signalwire.relay.event",
    # Typed RELAY event hierarchy (signalwire.relay.event) — the base
    # RelayEvent + 23 per-event-type subclasses, each one its own PSR-4 file
    # under src/SignalWire/Relay/Event/. Routed by name to the oracle module
    # (CLASS_MODULE_MAP wins over the file-path fallback, which would otherwise
    # derive signalwire.relay.event.<snake> from the Relay/Event/ subdir).
    "RelayEvent": "signalwire.relay.event",
    "CallStateEvent": "signalwire.relay.event",
    "CallReceiveEvent": "signalwire.relay.event",
    "PlayEvent": "signalwire.relay.event",
    "RecordEvent": "signalwire.relay.event",
    "CollectEvent": "signalwire.relay.event",
    "ConnectEvent": "signalwire.relay.event",
    "DetectEvent": "signalwire.relay.event",
    "FaxEvent": "signalwire.relay.event",
    "TapEvent": "signalwire.relay.event",
    "StreamEvent": "signalwire.relay.event",
    "SendDigitsEvent": "signalwire.relay.event",
    "DialEvent": "signalwire.relay.event",
    "ReferEvent": "signalwire.relay.event",
    "DenoiseEvent": "signalwire.relay.event",
    "PayEvent": "signalwire.relay.event",
    "QueueEvent": "signalwire.relay.event",
    "EchoEvent": "signalwire.relay.event",
    "TranscribeEvent": "signalwire.relay.event",
    "HoldEvent": "signalwire.relay.event",
    "ConferenceEvent": "signalwire.relay.event",
    "CallingErrorEvent": "signalwire.relay.event",
    "MessageReceiveEvent": "signalwire.relay.event",
    "MessageStateEvent": "signalwire.relay.event",
    # Constants/WebSocket are port-internal — fall through to native translation.

    # prefabs
    "ConciergeAgent": "signalwire.prefabs.concierge",
    "FAQBotAgent": "signalwire.prefabs.faq_bot",
    "InfoGathererAgent": "signalwire.prefabs.info_gatherer",
    "ReceptionistAgent": "signalwire.prefabs.receptionist",
    "SurveyAgent": "signalwire.prefabs.survey",

    # skills (PHP short name -> Python <Name>Skill canonical class)
    "ApiNinjasTrivia": "signalwire.skills.api_ninjas_trivia.skill",
    "ClaudeSkills": "signalwire.skills.claude_skills.skill",
    "CustomSkills": "signalwire.skills.custom_skills.skill",
    "Datasphere": "signalwire.skills.datasphere.skill",
    "DatasphereServerless": "signalwire.skills.datasphere_serverless.skill",
    "Datetime": "signalwire.skills.datetime.skill",
    "GoogleMaps": "signalwire.skills.google_maps.skill",
    "InfoGatherer": "signalwire.skills.info_gatherer.skill",
    "Joke": "signalwire.skills.joke.skill",
    "Math": "signalwire.skills.math.skill",
    "McpGateway": "signalwire.skills.mcp_gateway.skill",
    "NativeVectorSearch": "signalwire.skills.native_vector_search.skill",
    "PlayBackgroundFile": "signalwire.skills.play_background_file.skill",
    "Spider": "signalwire.skills.spider.skill",
    "SwmlTransfer": "signalwire.skills.swml_transfer.skill",
    "WeatherApi": "signalwire.skills.weather_api.skill",
    "WebSearch": "signalwire.skills.web_search.skill",
    "WikipediaSearch": "signalwire.skills.wikipedia_search.skill",

    # Top-level
    "SignalWire": "signalwire",
}


# ---------------------------------------------------------------------------
# Mixin projection (mirrors C++ port).
# Python's AgentBase / SWMLService surface is split across 9 mixin classes.
# PHP flattens those onto AgentBase (extends Service). To make the diff line
# up, we project each mixin's expected method list back into port_surface.json
# by picking matching methods off AgentBase / SWMLService when present.
# ---------------------------------------------------------------------------
MIXIN_PROJECTIONS: dict[tuple[str, str], tuple[str, list[str]]] = {
    # (target_module, target_class) -> (source_class, [method_names])
    # source_class is looked up under its own module after the main pass.
    ("signalwire.core.mixins.ai_config_mixin", "AIConfigMixin"): (
        "AgentBase",
        [
            "add_function_include", "add_hint", "add_hints", "add_internal_filler",
            "add_language", "add_mcp_server", "add_pattern_hint", "add_pronunciation",
            "enable_debug_events", "enable_mcp_server",
            "get_language_params",
            "set_function_includes", "set_global_data", "set_internal_fillers",
            "set_language_params", "set_languages", "set_multilingual",
            "set_native_functions",
            "set_param", "set_params",
            "set_post_prompt_llm_params", "set_prompt_llm_params",
            "set_pronunciations", "update_global_data",
        ],
    ),
    ("signalwire.core.mixins.auth_mixin", "AuthMixin"): (
        "SWMLService",
        ["get_basic_auth_credentials", "validate_basic_auth"],
    ),
    ("signalwire.core.mixins.mcp_server_mixin", "MCPServerMixin"): (
        "AgentBase", [],
    ),
    ("signalwire.core.mixins.prompt_mixin", "PromptMixin"): (
        "AgentBase",
        [
            "contexts", "define_contexts", "get_post_prompt", "get_prompt",
            "prompt_add_section", "prompt_add_subsection", "prompt_add_to_section",
            "prompt_has_section", "reset_contexts", "set_post_prompt",
            "set_prompt_pom", "set_prompt_text",
        ],
    ),
    # Python additionally extracted a ``PromptManager`` class that
    # PromptMixin delegates to. The user-facing surface is identical
    # (``agent.prompt_manager.X`` ≡ ``agent.X``). Project the same set of
    # AgentBase methods to PromptManager so the cross-language audit
    # treats both paths as covered.
    ("signalwire.core.agent.prompt.manager", "PromptManager"): (
        "AgentBase",
        [
            "__init__",
            "define_contexts", "get_contexts", "get_post_prompt", "get_prompt",
            "get_raw_prompt",
            "prompt_add_section", "prompt_add_subsection", "prompt_add_to_section",
            "prompt_has_section", "set_post_prompt", "set_prompt_pom",
            "set_prompt_text",
        ],
    ),
    ("signalwire.core.mixins.serverless_mixin", "ServerlessMixin"): (
        "AgentBase",
        ["handle_serverless_request"],
    ),
    ("signalwire.core.mixins.skill_mixin", "SkillMixin"): (
        "AgentBase",
        ["add_skill", "has_skill", "list_skills", "remove_skill"],
    ),
    ("signalwire.core.mixins.state_mixin", "StateMixin"): (
        "AgentBase",
        ["validate_tool_token"],
    ),
    ("signalwire.core.mixins.tool_mixin", "ToolMixin"): (
        "SWMLService",
        ["define_tool", "define_tools", "on_function_call",
         "register_swaig_function", "tool"],
    ),
    ("signalwire.core.agent.tools.registry", "ToolRegistry"): (
        "SWMLService",
        ["__init__", "define_tool", "register_swaig_function",
         "has_function", "get_function", "get_all_functions",
         "remove_function"],
    ),
    ("signalwire.core.mixins.auth_mixin", "AuthMixin"): (
        "SWMLService",
        ["validate_basic_auth", "get_basic_auth_credentials"],
    ),
    ("signalwire.core.mixins.web_mixin", "WebMixin"): (
        "SWMLService",
        ["as_router", "enable_debug_routes", "get_app", "manual_set_proxy_url",
         "on_request", "on_swml_request", "register_routing_callback", "run",
         "serve", "set_dynamic_config_callback", "setup_graceful_shutdown"],
    ),
}


# ---------------------------------------------------------------------------
# Class rename map: PHP class name -> Python canonical class name.
# ---------------------------------------------------------------------------
CLASS_RENAME_MAP: dict[str, str] = {
    "Service": "SWMLService",
    "Client": "RelayClient",
    # PHP StudlyCaps `SwaigFunction` -> Python canonical `SWAIGFunction`
    # (acronym-cased). A pure spelling/identity difference — reconciled in the
    # adapter rename table, never an omission (RULES.md §4).
    "SwaigFunction": "SWAIGFunction",
    # Internal duck-type contracts (the TS RelayClientLike pattern) fold onto
    # the canonical concrete class they abstract, so any signature that
    # references one as a param/return type compares against the Python-
    # equivalent class instead of a PHP-only interface name. The interfaces
    # themselves are @internal and excluded from the dumps (see
    # signature_dump.php / _parse_file's @internal handling) — this is a
    # rename, not an omission.
    "AgentInterface": "AgentBase",
    "RelayClientLike": "RelayClient",
    "RequestHandlerLike": "SWMLService",
    # REST: the generated resource + container classes are named VERBATIM by the
    # oracle canonical names (scripts/generate_rest.py emits `class PhoneNumbers`,
    # `class FabricNamespace`, `class Calling`, …), so they need NO rename — the
    # CLASS_MODULE_MAP above routes them to the right `_resources_generated` /
    # `_client_tree_generated` module, so they need NO rename.
    # Datasphere is special — same short name used by both a REST skill and a
    # REST namespace container. The REST container class is `DatasphereNamespace`
    # (no rename needed). The bare `Datasphere` short name is the skill, renamed
    # below. (Don't add `Datasphere -> DatasphereNamespace` here.)
    # Skill renames -- append Skill suffix to match Python's <Name>Skill convention
    "ApiNinjasTrivia": "ApiNinjasTriviaSkill",
    "ClaudeSkills": "ClaudeSkillsSkill",
    "CustomSkills": "CustomSkillsSkill",
    "Datasphere": "DataSphereSkill",
    "DatasphereServerless": "DataSphereServerlessSkill",
    "Datetime": "DateTimeSkill",
    "GoogleMaps": "GoogleMapsSkill",
    "InfoGatherer": "InfoGathererSkill",
    "Joke": "JokeSkill",
    "Math": "MathSkill",
    "McpGateway": "MCPGatewaySkill",
    "NativeVectorSearch": "NativeVectorSearchSkill",
    "PlayBackgroundFile": "PlayBackgroundFileSkill",
    "Spider": "SpiderSkill",
    "SwmlTransfer": "SWMLTransferSkill",
    "WeatherApi": "WeatherApiSkill",
    "WebSearch": "WebSearchSkill",
    "WikipediaSearch": "WikipediaSearchSkill",
    # Logger-style facade
    "Logger": "Logger",  # no rename; declared module is logging_config
}


# Files to skip — vendor, examples, tests, generated
SKIP_PATH_RE = re.compile(
    r"(?:^|/)(?:vendor|tests|examples|build|bin|cli)(?:/|$)"
)


# Recognise namespace and class declarations
RE_NAMESPACE = re.compile(r"^\s*namespace\s+([\\\w]+)\s*;")
# Matches a class-like declaration: `class`, `final class`, `abstract class`,
# or a PHP 8.1 `enum` (with or without a `: backingType`). Enums are included
# so their public methods land on the enum (mirroring the reflection-based
# `isEnum()` handling in enumerate_signatures.py) instead of leaking out as
# `__module__` free functions. The enum's auto-generated cases/from/cases()/
# tryFrom() are not `public function` declarations, so they're naturally
# excluded — only hand-written public methods on the enum are captured.
RE_CLASS = re.compile(
    r"^\s*(?:final\s+|abstract\s+)?(?:class|enum)\s+([A-Za-z_]\w*)"
)
# Capture the immediate parent of a `class Foo extends \Ns\Bar` declaration
# (last path segment). Used by the REST implicit-base projection: a generated
# resource subclass inherits create/update from its hand base, which the
# oracle records on the subclass; the enumerator only sees the subclass file,
# so it injects the inherited methods keyed on this parent name.
RE_CLASS_EXTENDS = re.compile(
    r"^\s*(?:final\s+|abstract\s+)?class\s+[A-Za-z_]\w*\s+extends\s+([\\\w]+)"
)
# Interfaces are class-like scopes too. They were absent from this SDK until
# the internal duck-type contracts (AgentInterface, RelayClientLike,
# RequestHandlerLike — the TS RelayClientLike pattern) were added. We must
# recognise them as a scope so their `public function` declarations are NOT
# mistaken for `__module__` free functions; whether a given interface lands on
# the public surface is decided separately via its `@internal` marker (see
# _parse_file). A method declaration in an interface body has no `{ ... }`, so
# it does not perturb brace tracking.
RE_INTERFACE = re.compile(
    r"^\s*interface\s+([A-Za-z_]\w*)"
)
# Traits are class-like scopes but are NOT independent surface — they are mixed
# INTO a using class (e.g. the generated ResourceTree is composed into
# RestClient), and the using class re-exposes their methods. So a trait's own
# scope is tracked (to keep its methods from leaking as `__module__` free
# functions) but excluded from the enumerated surface — the same treatment as
# an @internal interface. This mirrors enumerate_signatures.py skipping
# `kind: trait` and the Python reference keeping `_GeneratedResourceTree` off
# the surface.
RE_TRAIT = re.compile(
    r"^\s*trait\s+([A-Za-z_]\w*)"
)
# In-class trait composition (`use ResourceTree;`) — a bare, single-segment
# capitalised name (no leading backslash / namespace path, which would be a
# top-of-file import, not a trait mix-in). A class that composes a trait
# re-exposes the trait's public methods, so the enumerator flattens them onto
# the using class (see build_surface). This is what makes the generated
# ResourceTree accessors (calling/fabric/video/...) part of RestClient's
# surface, matching the real callable API.
RE_USE_TRAIT = re.compile(
    r"^\s*use\s+([A-Z]\w*)\s*;"
)
RE_PUBLIC_METHOD = re.compile(
    # Matches `public function`, `public static function`, and
    # `abstract public function` (the abstract methods on an abstract base —
    # e.g. SkillBase::setup / registerTools — are part of the class's public
    # surface: every concrete subclass implements them, and go/TS surface the
    # corresponding SkillBase.setup / register_tools members). PHP also allows
    # the modifiers in either order (`public abstract`), so accept both.
    r"^\s*(?:abstract\s+)?public\s+(?:abstract\s+)?(?:static\s+)?function\s+(\w+)\s*\("
)


def _git_sha() -> str:
    try:
        out = subprocess.check_output(
            ["git", "-C", str(REPO_ROOT), "rev-parse", "HEAD"],
            text=True,
            stderr=subprocess.DEVNULL,
        )
        return out.strip()
    except (subprocess.CalledProcessError, FileNotFoundError):
        return "unknown"


# Per-method name aliases. PHP's idioms differ from Python in a handful of
# spots. Map PHP-snake_case (post-camel-translation) -> Python canonical name.
METHOD_ALIASES: dict[str, str] = {
    # PHP toArray() == Python's to_dict()
    "to_array": "to_dict",
    # PHP AgentServer serve_static == Python serve_static_files
    # (only on AgentServer; we apply globally — no collision elsewhere)
    "serve_static": "serve_static_files",
    # Python ``AgentBase.pom`` is a @property; PHP exposes it as the
    # getter ``getPom()``. Project the PHP getter onto the Python name.
    "get_pom": "pom",
    # Python ``SWMLService.schema_utils`` is a public attribute exposed
    # via PHP's ``getSchemaUtils()`` getter. Strip the get_ prefix.
    "get_schema_utils": "schema_utils",
}


def camel_to_snake(name: str) -> str:
    """Translate PHP camelCase / PascalCase to Python snake_case.

    Preserves acronym runs:
        getURL        -> get_url
        toJson        -> to_json
        setPromptText -> set_prompt_text
        addAIVerb     -> add_ai_verb
        cleanup       -> cleanup
    """
    # Insert _ between lowercase/digit followed by uppercase
    s1 = re.sub(r"([a-z0-9])([A-Z])", r"\1_\2", name)
    # Insert _ between sequences of uppercase letters and an uppercase
    # followed by a lowercase (so HTTPHandler -> HTTP_Handler -> http_handler)
    s2 = re.sub(r"([A-Z]+)([A-Z][a-z])", r"\1_\2", s1)
    return s2.lower()


# ---------------------------------------------------------------------------
# Generated REST wire-type surface (item A/H — REAL types, not `array`).
#
# scripts/generate_rest.py emits one PHP data class / enum per components/schemas
# object into src/SignalWire/REST/Namespaces/Generated/Types/<Sub>/<Name>.php. The
# reference records these as method-less type definitions in
# signalwire.rest.namespaces.<ns>_types_generated. Each <Sub> subdir maps 1:1 to a
# <ns>_types_generated module. Routing is BY FILE PATH (not class name) because the
# same type name recurs across namespaces (AIObject in calling AND fabric; the shared
# Types_StatusCodes_* error types in every namespace) and even collides with existing
# SDK class names (DataMap/Document/Section) — so the path-based route MUST win over
# CLASS_MODULE_MAP for these files (§H item 3). The SURFACE-DIFF gen-type leaf fold
# collapses the cross-module duplicates to one gen-type.<Leaf> on both ref and port.
# ---------------------------------------------------------------------------
_TYPES_DIR_MARKER = "REST/Namespaces/Generated/Types/"
_TYPES_SUB_TO_MODULE: dict[str, str] = {
    "RelayRest": "signalwire.rest.namespaces.relay_rest_types_generated",
    "Fabric": "signalwire.rest.namespaces.fabric_types_generated",
    "Calling": "signalwire.rest.namespaces.calling_types_generated",
    "Video": "signalwire.rest.namespaces.video_types_generated",
    "Datasphere": "signalwire.rest.namespaces.datasphere_types_generated",
    "Logs": "signalwire.rest.namespaces.logs_types_generated",
    "Message": "signalwire.rest.namespaces.message_types_generated",
    "Voice": "signalwire.rest.namespaces.voice_types_generated",
    "Fax": "signalwire.rest.namespaces.fax_types_generated",
    "Project": "signalwire.rest.namespaces.project_types_generated",
    "Chat": "signalwire.rest.namespaces.chat_types_generated",
    "PubSub": "signalwire.rest.namespaces.pubsub_types_generated",
    "SwmlWebhooks": "signalwire.rest.namespaces.swml_webhooks_types_generated",
}
# Hard-reserved PHP keyword class names generate_rest.py suffixed with `_`; map back
# to the bare oracle leaf (the type-name analog of the reserved-word field rename).
# SCOPED to the Types/ files so a non-type class named `Return_` (none today) is
# unaffected.
_TYPES_RESERVED_UNRENAME: dict[str, str] = {
    "Goto_": "Goto",
    "Return_": "Return",
    "Switch_": "Switch",
    "Unset_": "Unset",
}


def _types_subdir(file_relative: Path) -> str | None:
    """If ``file_relative`` is a generated wire-type file
    (REST/Namespaces/Generated/Types/<Sub>/…), return <Sub>; else None."""
    rel = file_relative.as_posix()
    idx = rel.find(_TYPES_DIR_MARKER)
    if idx == -1:
        return None
    tail = rel[idx + len(_TYPES_DIR_MARKER):]
    sub = tail.split("/", 1)[0]
    return sub or None


# ---------------------------------------------------------------------------
# Generated SWML-verbs CONFIG surface (item D2 — core.swml_verbs_generated).
#
# scripts/generate_swml_verbs.py emits one method-less PHP data class per
# schema.json $defs OBJECT schema (133) + one <Verb>Config class per flattened
# SWMLMethod verb (22) = 155, into src/SignalWire/SWML/Generated/<Name>.php. The
# Python reference records these as the 155 method-less type definitions in
# signalwire.core.swml_verbs_generated. Routing is BY FILE PATH (not class name):
# 125 of the 155 leaf names ALSO exist as REST wire types (AIObject/Cond/Section/
# DataMap …), and several collide with existing SDK class names (Document/DataMap/
# Section), so the path-based route MUST win over CLASS_MODULE_MAP for these files
# — exactly like the REST Types routing above (§H item 3). The module string ends
# in ``.swml_verbs_generated`` so the SURFACE-DIFF gen-type leaf fold recognises it
# and collapses the cross-module duplicates (this module ↔ every <ns>_types_generated
# copy) to one gen-type.<Leaf> on both the reference and the port.
# ---------------------------------------------------------------------------
_SWML_VERBS_DIR_MARKER = "SWML/Generated/"
_SWML_VERBS_MODULE = "signalwire.core.swml_verbs_generated"


def _is_swml_verbs_file(file_relative: Path) -> bool:
    """True when ``file_relative`` is a generated SWML-verbs config file
    (src/SignalWire/SWML/Generated/<Name>.php)."""
    return _SWML_VERBS_DIR_MARKER in file_relative.as_posix()


# ---------------------------------------------------------------------------
# Generated RELAY-protocol wire-type surface (item I — relay.protocol_types_generated).
#
# scripts/generate_relay_protocol.py emits one method-less PHP data class per RELAY
# WS method+phase object schema (123) into src/SignalWire/Relay/Generated/<Name>.php.
# The Python reference records these as the 123 method-less type definitions in
# signalwire.relay.protocol_types_generated. Routing is BY FILE PATH (not class
# name) — and MUST be scoped strictly to the Relay/Generated/ subdir so the existing
# Relay SDK classes one level up (Call/Client/CallState/Event/AIAction/…, routed by
# CLASS_MODULE_MAP to signalwire.relay.call/client/event) are NOT misrouted. A
# relay-protocol type name that also exists as a REST/SWML wire type would collide
# with CLASS_MODULE_MAP, so the path route MUST win over it for these files (§H
# item 3), exactly like the REST Types + SWML Generated routing above.
# ---------------------------------------------------------------------------
_RELAY_PROTO_DIR_MARKER = "Relay/Generated/"
_RELAY_PROTO_MODULE = "signalwire.relay.protocol_types_generated"


def _is_relay_proto_file(file_relative: Path) -> bool:
    """True when ``file_relative`` is a generated RELAY-protocol wire-type file
    (src/SignalWire/Relay/Generated/<Name>.php)."""
    return _RELAY_PROTO_DIR_MARKER in file_relative.as_posix()


# ---------------------------------------------------------------------------
# Generated SWAIG read-side payload surface (item D1 — the three
# signalwire.core.*_generated SWAIG payload modules).
#
# scripts/generate_swaig_payloads.py emits one method-less PHP data class per SWAIG
# payload object schema into src/SignalWire/SWAIG/Generated/<Sub>/<Name>.php, where
# <Sub> is one of PostPrompt / SwaigRequest / SwaigActions. The Python reference
# records these as the 14 + 2 + 4 = 20 method-less type definitions in
# signalwire.core.post_prompt_generated / .swaig_request_generated /
# .swaig_actions_generated. Routing is BY FILE PATH (the <Sub> subdir → the oracle
# module), same rationale as the REST Types + SWML Generated + RELAY Generated routes
# above: a payload type name can collide with a hand-written SWAIG SDK class one/two
# levels up (FunctionResult/ParameterSchema/RecordFormat) or recur elsewhere, so the
# path route MUST win over CLASS_MODULE_MAP (§H item 3). The module strings end in the
# ``*_generated`` markers the diff tool's gen-payload fold recognises, so a class-typed
# field compares by (class, field) cross-port regardless of file grouping.
# ---------------------------------------------------------------------------
_SWAIG_PAYLOAD_DIR_MARKER = "SWAIG/Generated/"
_SWAIG_PAYLOAD_SUB_TO_MODULE: dict[str, str] = {
    "PostPrompt": "signalwire.core.post_prompt_generated",
    "SwaigRequest": "signalwire.core.swaig_request_generated",
    "SwaigActions": "signalwire.core.swaig_actions_generated",
}


def _swaig_payload_subdir(file_relative: Path) -> str | None:
    """If ``file_relative`` is a generated SWAIG-payload file
    (SWAIG/Generated/<Sub>/…), return <Sub>; else None."""
    rel = file_relative.as_posix()
    idx = rel.find(_SWAIG_PAYLOAD_DIR_MARKER)
    if idx == -1:
        return None
    tail = rel[idx + len(_SWAIG_PAYLOAD_DIR_MARKER):]
    sub = tail.split("/", 1)[0]
    return sub or None


def _module_path_for_class(name: str, file_relative: Path) -> str:
    """Map a PHP class to its Python-canonical module path."""
    # Generated wire-type files route by PATH (their <Sub> subdir → the oracle
    # <ns>_types_generated module), winning over CLASS_MODULE_MAP so a type name
    # that recurs across namespaces / collides with an SDK class name lands in the
    # right module.
    sub = _types_subdir(file_relative)
    if sub is not None and sub in _TYPES_SUB_TO_MODULE:
        return _TYPES_SUB_TO_MODULE[sub]
    # Generated SWML-verbs config files route by PATH to the single oracle module
    # signalwire.core.swml_verbs_generated (same rationale as the REST Types route
    # — name recurrence/collision means path MUST win over CLASS_MODULE_MAP).
    if _is_swml_verbs_file(file_relative):
        return _SWML_VERBS_MODULE
    # Generated RELAY-protocol wire-type files route by PATH to the single oracle
    # module signalwire.relay.protocol_types_generated — scoped strictly to the
    # Relay/Generated/ subdir so the hand-written Relay SDK classes one level up
    # keep their CLASS_MODULE_MAP routing.
    if _is_relay_proto_file(file_relative):
        return _RELAY_PROTO_MODULE
    # Generated SWAIG-payload files route by PATH: the <Sub> subdir (PostPrompt /
    # SwaigRequest / SwaigActions) → its signalwire.core.*_generated module, winning
    # over CLASS_MODULE_MAP so a payload type name never misroutes onto a hand SWAIG
    # SDK class (FunctionResult/ParameterSchema/RecordFormat) or elsewhere.
    swaig_sub = _swaig_payload_subdir(file_relative)
    if swaig_sub is not None and swaig_sub in _SWAIG_PAYLOAD_SUB_TO_MODULE:
        return _SWAIG_PAYLOAD_SUB_TO_MODULE[swaig_sub]
    if name in CLASS_MODULE_MAP:
        return CLASS_MODULE_MAP[name]
    # Fallback: derive from file path. e.g. src/SignalWire/Foo/Bar.php
    # -> signalwire.foo.bar (lowercased segments).
    parts = file_relative.with_suffix("").parts
    # Strip leading "src" / "SignalWire" if present.
    while parts and parts[0] in ("src", "SignalWire"):
        parts = parts[1:]
    if not parts:
        return "signalwire"
    # Last segment is the file basename; drop it (we use class name).
    if len(parts) > 1:
        parts = parts[:-1]
    # Lowercase the segments to match Python's snake_case module dirs.
    leaf = camel_to_snake(name)
    base = "signalwire." + ".".join(s.lower() for s in parts) if parts else "signalwire"
    return f"{base}.{leaf}"


def _translate_class(name: str) -> str:
    return CLASS_RENAME_MAP.get(name, name)


def _translate_class_in_file(name: str, file_relative: Path) -> str:
    """Class-name translation aware of the file's location. For a generated wire-
    type file OR a generated SWML-verbs config file, a reserved-keyword class name
    the generator suffixed with ``_`` (Goto_/Return_/Switch_/Unset_) is renamed
    back to the bare oracle leaf; scoped to those generated files so a non-type
    class of the same spelling is unaffected."""
    in_generated_type_file = (
        _types_subdir(file_relative) is not None
        or _is_swml_verbs_file(file_relative)
        or _is_relay_proto_file(file_relative)
        or _swaig_payload_subdir(file_relative) is not None
    )
    if in_generated_type_file and name in _TYPES_RESERVED_UNRENAME:
        return _TYPES_RESERVED_UNRENAME[name]
    return _translate_class(name)


def _walk_source_files() -> list[Path]:
    files: list[Path] = []
    if not SRC_DIR.is_dir():
        return files
    for p in SRC_DIR.rglob("*.php"):
        rel = p.relative_to(REPO_ROOT)
        if SKIP_PATH_RE.search("/" + str(rel)):
            continue
        files.append(p)
    return sorted(files)


def _parse_file(
    path: Path,
) -> tuple[
    set[str],
    dict[str, set[str]],
    set[str],
    dict[str, set[str]],
    dict[str, set[str]],
]:
    """Return (free_functions, class_methods, defined_classes,
    trait_methods, class_uses).

    free_functions: top-level public function names declared outside any class
                    (rare in this SDK; PHP usually wraps everything in classes).
    class_methods: {class_name: {method_names...}} — already snake_cased.
    defined_classes: class names declared in this file.
    trait_methods: {trait_name: {method_names...}} — a trait's own public
                   methods (snake_cased). Kept separate from class_methods so a
                   trait never surfaces on its own, only flattened onto the
                   class(es) that `use` it.
    class_uses: {class_name: {trait_name...}} — in-class trait compositions.
    """
    free_fns: set[str] = set()
    methods: dict[str, set[str]] = defaultdict(set)
    classes: set[str] = set()
    trait_methods: dict[str, set[str]] = defaultdict(set)
    class_uses: dict[str, set[str]] = defaultdict(set)
    try:
        text = path.read_text(encoding="utf-8", errors="replace")
    except OSError:
        return free_fns, dict(methods), classes, dict(trait_methods), dict(class_uses)

    lines = text.splitlines()
    cur_class: str | None = None
    cur_class_brace: int = 0
    # When the current scope is an `@internal` interface, its methods are
    # excluded from the surface entirely (they are an internal duck-type
    # contract, not exported API) — but the scope is still tracked so its
    # methods do not leak as `__module__` free functions.
    cur_excluded: bool = False
    # When the current scope is a trait, its name (so its public methods are
    # collected into trait_methods for later flattening onto using classes).
    cur_trait: str | None = None
    brace_depth = 0
    in_block_comment = False
    # True when the immediately-preceding docblock carried an `@internal` tag;
    # consumed by the next class/interface declaration.
    internal_pending = False

    for raw_line in lines:
        # Strip block comments naively (sufficient for this SDK)
        line = raw_line
        if in_block_comment:
            if "@internal" in raw_line:
                internal_pending = True
            end = line.find("*/")
            if end != -1:
                line = line[end + 2:]
                in_block_comment = False
            else:
                continue
        # Find /* ... */ openers in the line
        while True:
            start = line.find("/*")
            if start == -1:
                break
            end = line.find("*/", start + 2)
            if end == -1:
                if "@internal" in line[start:]:
                    internal_pending = True
                line = line[:start]
                in_block_comment = True
                break
            if "@internal" in line[start:end]:
                internal_pending = True
            line = line[:start] + line[end + 2:]
        # Strip line comments (`//` and PHP's `#`), but ONLY when the marker is
        # NOT inside a quoted string — otherwise a regex/URL literal like
        # `'#^https?://#i'` truncates the line and drops the trailing `{`,
        # which desyncs brace-depth and prematurely closes the class scope
        # (dropping later public methods, e.g. skill getHints()). Scan
        # character-by-character tracking single/double-quote state.
        _in_s = _in_d = False
        _cut = -1
        _j = 0
        _n = len(line)
        while _j < _n:
            _ch = line[_j]
            if _ch == "\\" and (_in_s or _in_d):
                _j += 2
                continue
            if _ch == "'" and not _in_d:
                _in_s = not _in_s
            elif _ch == '"' and not _in_s:
                _in_d = not _in_d
            elif not _in_s and not _in_d:
                if _ch == "#":
                    _cut = _j
                    break
                if _ch == "/" and _j + 1 < _n and line[_j + 1] == "/":
                    _cut = _j
                    break
            _j += 1
        if _cut != -1:
            line = line[:_cut]

        # Class declaration
        m_class = RE_CLASS.match(line)
        if m_class:
            cur_class = m_class.group(1)
            cur_excluded = False
            cur_trait = None
            classes.add(cur_class)
            internal_pending = False
            # Brace might appear later on the same or following line; we'll
            # enter the class scope as soon as `{` is seen.
            cur_class_brace = -1
            # Continue to count braces on this line below.

        # Interface declaration — a class-like scope. If marked `@internal`,
        # exclude it (and its methods) from the surface; it is consumed only
        # internally and must not appear as exported API.
        m_iface = RE_INTERFACE.match(line)
        if m_iface:
            cur_class = m_iface.group(1)
            cur_excluded = internal_pending
            cur_trait = None
            if not cur_excluded:
                classes.add(cur_class)
            internal_pending = False
            cur_class_brace = -1

        # Trait declaration — a class-like scope that is composed INTO a using
        # class (the using class re-exposes its methods). Track the scope so
        # its methods don't leak as `__module__` free functions, but exclude it
        # from the surface entirely (see RE_TRAIT).
        m_trait = RE_TRAIT.match(line)
        if m_trait:
            cur_class = m_trait.group(1)
            cur_trait = m_trait.group(1)
            cur_excluded = True
            internal_pending = False
            cur_class_brace = -1

        # In-class trait composition (`use SomeTrait;`). Record it so the
        # trait's public methods are flattened onto this class later. Only
        # inside a real (non-excluded) class scope; a bare single-segment name
        # (top-of-file `use Foo\Bar;` imports carry a backslash and don't match
        # RE_USE_TRAIT).
        if cur_class is not None and not cur_excluded and cur_trait is None:
            m_use = RE_USE_TRAIT.match(line)
            if m_use:
                class_uses[cur_class].add(m_use.group(1))

        # Public method
        m_method = RE_PUBLIC_METHOD.match(line)
        if m_method:
            method_name = m_method.group(1)
            # Constructor naming
            if method_name == "__construct":
                py_name = "__init__"
            elif method_name == "__toString":
                py_name = "__str__"
            elif method_name == "__destruct":
                py_name = "__del__"
            else:
                py_name = camel_to_snake(method_name)
            # Apply Python-canonical aliasing where PHP idiom differs.
            py_name = METHOD_ALIASES.get(py_name, py_name)
            if cur_trait is not None:
                # Trait body — collect the method for flattening onto the
                # class(es) that `use` this trait; the trait itself never
                # surfaces.
                trait_methods[cur_trait].add(py_name)
            elif cur_excluded:
                # Internal interface body — neither surface method nor a
                # leaked free function.
                pass
            elif cur_class is not None:
                methods[cur_class].add(py_name)
            else:
                free_fns.add(py_name)

        # Track braces (very simple — sufficient because PHP files are
        # one-class-per-file in this SDK except Action.php and Adapter.php
        # which open all classes at the top level)
        opens = line.count("{")
        closes = line.count("}")
        for _ in range(opens):
            brace_depth += 1
            if cur_class is not None and cur_class_brace == -1:
                cur_class_brace = brace_depth  # entering class body
        for _ in range(closes):
            brace_depth -= 1
            if cur_class is not None and brace_depth < cur_class_brace:
                cur_class = None
                cur_class_brace = 0
                cur_excluded = False
                cur_trait = None

    return free_fns, dict(methods), classes, dict(trait_methods), dict(class_uses)


# Per-base implicit-method injection for the generated REST resource subclasses
# (the create/update the oracle records on the subclass but PHP inherits from
# the hand base). Keyed by the PHP `extends` parent (last path segment).
REST_IMPLICIT_BASE_METHODS: dict[str, set[str]] = {
    "CrudResource": {"create", "update"},
    "FabricResource": {"create", "update"},
    "FabricResourcePUT": {"create", "update"},
    # ReadResource / BaseResource contribute nothing (list/get live on the
    # ReadResource base and are not recorded on the subclass).
}

# Only project onto classes that live in the generated resource module tree.
_GENERATED_NS_MARKER = "REST/Namespaces/Generated/"


def _inject_extends(modules: dict, files: list[Path]) -> None:
    """Inject inherited create/update onto generated REST resource subclasses,
    keyed on each class's `extends` parent. See the caller for the rationale."""
    for path in files:
        rel_str = str(path.relative_to(REPO_ROOT))
        if _GENERATED_NS_MARKER not in rel_str.replace("\\", "/"):
            continue
        try:
            text = path.read_text(encoding="utf-8", errors="replace")
        except OSError:
            continue
        for line in text.splitlines():
            m = RE_CLASS_EXTENDS.match(line)
            if not m:
                continue
            parent = m.group(1).rsplit("\\", 1)[-1]
            inject = REST_IMPLICIT_BASE_METHODS.get(parent)
            if not inject:
                continue
            # The class name is captured by RE_CLASS on the same line.
            mc = RE_CLASS.match(line)
            if not mc:
                continue
            cls = mc.group(1)
            module_path = CLASS_MODULE_MAP.get(cls)
            if not module_path:
                continue
            translated = _translate_class(cls)
            existing = set(modules[module_path]["classes"].get(translated, []))
            existing.update(inject)
            modules[module_path]["classes"][translated] = sorted(existing)


def build_surface() -> dict:
    modules: dict[str, dict] = defaultdict(
        lambda: {"classes": defaultdict(list), "functions": []}
    )
    sha = _git_sha()
    files = _walk_source_files()

    # First pass: collect class declarations + their files, and the global
    # trait -> methods map (a trait can be defined in one file and `use`d in
    # another, so both must be gathered before flattening in the second pass).
    class_defining_files: dict[str, Path] = {}
    all_trait_methods: dict[str, set[str]] = defaultdict(set)
    for path in files:
        free_fns, methods, classes, trait_methods, class_uses = _parse_file(path)
        rel = path.relative_to(REPO_ROOT)
        for cls in classes:
            class_defining_files.setdefault(cls, rel)
        for trait, meth_set in trait_methods.items():
            all_trait_methods[trait].update(meth_set)
        if free_fns:
            mod = _module_path_for_class("__module__", rel)
            modules[mod]["functions"].extend(sorted(free_fns))

    # Second pass: collect methods per class, flattening any `use`d trait's
    # public methods onto the using class (the class re-exposes them as its own
    # callable API — e.g. RestClient composes the generated ResourceTree, so
    # calling()/fabric()/video()/... are part of RestClient's surface).
    for path in files:
        free_fns, methods, classes, trait_methods, class_uses = _parse_file(path)
        rel = path.relative_to(REPO_ROOT)
        for using_cls, traits in class_uses.items():
            for trait in traits:
                if trait in all_trait_methods:
                    methods.setdefault(using_cls, set()).update(
                        all_trait_methods[trait]
                    )
        for cls, meth_set in methods.items():
            # Route by the CURRENT file's path, not the first-seen defining file:
            # a class name can recur across files (the SWML `Document`/`Section`/
            # `DataMap` builders vs the same-named generated wire-type data classes
            # in Types/<Sub>/), and each file's methods must land in ITS module.
            module_path = _module_path_for_class(cls, rel)
            translated = _translate_class_in_file(cls, rel)
            existing = set(modules[module_path]["classes"].get(translated, []))
            existing.update(meth_set)
            modules[module_path]["classes"][translated] = sorted(existing)
        # Also add classes that have no methods (the generated wire-type data
        # classes / enums are exactly this — method-less type definitions). Use
        # the CURRENT file's path (`rel`, not the first-seen defining file) so a
        # type name that recurs across namespaces (AIObject in Types/Calling AND
        # Types/Fabric, the shared Types_StatusCodes_* in every namespace) is
        # routed to EACH namespace's <ns>_types_generated module — the reference
        # duplicates them the same way and the gen-type fold collapses both.
        for cls in classes:
            if cls not in methods:
                module_path = _module_path_for_class(cls, rel)
                translated = _translate_class_in_file(cls, rel)
                modules[module_path]["classes"].setdefault(translated, [])

    # REST implicit-base projection (mirrors Go's implicitBaseMethods()).
    #
    # The generated resource subclass (SignalWire\REST\Namespaces\Generated\*)
    # emits ONLY its own declared methods; the CRUD methods the oracle records
    # on the subclass are INHERITED from the hand-written PHP base. The
    # enumerator sees only the subclass file, so it injects the inherited
    # methods the oracle records, keyed on the subclass's `extends` parent:
    #   * extends CrudResource / FabricResource / FabricResourcePUT
    #       -> inject create, update   (the two typed-override CRUD methods the
    #          Python reference re-declares on each such subclass; delete/get/
    #          list stay on the base classes and are NOT recorded on the
    #          subclass — see rest._base CrudResource=[create,delete,update],
    #          ReadResource=[get,list]).
    #   * extends ReadResource / BaseResource -> inject nothing (a ReadResource
    #     subclass records only its own declared methods; list/get are on the
    #     ReadResource base).
    _inject_extends(modules, files)

    # Apply mixin projections — pick matching methods off AgentBase / SWMLService
    # and emit them under each Python mixin module path so the diff lines up.
    # Mirrors the C++ port's mixin-projection pattern.
    def _lookup_class_methods(class_name: str) -> set[str]:
        for mod_entry in modules.values():
            cls_methods = mod_entry["classes"].get(class_name)
            if cls_methods:
                return set(cls_methods)
        return set()

    agent_base_methods = _lookup_class_methods("AgentBase")
    swml_service_methods = _lookup_class_methods("SWMLService")

    for (target_mod, target_cls), (source_cls, expected) in MIXIN_PROJECTIONS.items():
        primary = (
            agent_base_methods if source_cls == "AgentBase"
            else swml_service_methods
        )
        secondary = (
            swml_service_methods if source_cls == "AgentBase"
            else agent_base_methods
        )
        # Try the configured primary source first, fall back to the
        # alternate base class. WebMixin's manual_set_proxy_url is a
        # typical case — Python projects it under the mixin while PHP
        # declares it on AgentBase.
        present = sorted(m for m in expected if m in primary or m in secondary)
        modules[target_mod]["classes"][target_cls] = present

    # Add the top-level signalwire module re-exports (mirrors Python's
    # `signalwire/__init__.py` flat surface). The PHP SDK exposes
    # `SignalWire\SignalWire` as a static facade, but Python's reference
    # also lists module-level helpers (run_agent, start_agent, list_skills,
    # ...). Those are documented in PORT_OMISSIONS.md as PHP not having
    # module-level free functions; the static class methods on SignalWire.php
    # are emitted under the `signalwire` module's classes already.

    # Stable sort
    out_modules: dict = {}
    for mod_name in sorted(modules.keys()):
        entry = modules[mod_name]
        out_modules[mod_name] = {
            "classes": {k: sorted(set(v)) for k, v in sorted(entry["classes"].items())},
            "functions": sorted(set(entry["functions"])),
        }

    return {
        "version": "1",
        "generated_from": f"signalwire-php @ {sha}",
        "modules": out_modules,
    }


def main(argv: list[str]) -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--output", default=str(REPO_ROOT / "port_surface.json"))
    parser.add_argument(
        "--check",
        action="store_true",
        help="Exit 1 if the on-disk file would change (CI mode).",
    )
    args = parser.parse_args(argv)

    surface = build_surface()
    rendered = json.dumps(surface, indent=2, sort_keys=False) + "\n"
    out_path = Path(args.output)

    if args.check:
        if not out_path.exists():
            print(
                f"enumerate_surface: {out_path} does not exist", file=sys.stderr
            )
            return 1
        on_disk = out_path.read_text(encoding="utf-8")
        if on_disk != rendered:
            print(
                f"enumerate_surface: {out_path} is out of date — re-run without --check",
                file=sys.stderr,
            )
            return 1
        print("enumerate_surface: up to date.")
        return 0

    out_path.write_text(rendered, encoding="utf-8")
    n_classes = sum(len(m["classes"]) for m in surface["modules"].values())
    n_methods = sum(
        sum(len(v) for v in m["classes"].values())
        for m in surface["modules"].values()
    )
    print(
        f"enumerate_surface: wrote {out_path} "
        f"({len(surface['modules'])} modules, "
        f"{n_classes} classes, {n_methods} methods)"
    )
    return 0


if __name__ == "__main__":
    sys.exit(main(sys.argv[1:]))
