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
    # PHP's Schema is the schema-utils helper class
    "Schema": "signalwire.utils.schema_utils",

    # core/swaig
    "FunctionResult": "signalwire.core.function_result",

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
    "CrudResource": "signalwire.rest._base",
    "SignalWireRestError": "signalwire.rest._base",

    # rest namespaces (PHP only ships dedicated classes for Calling and Fabric;
    # the rest are accessed as CrudResource via RestClient::namespace()).
    "Calling": "signalwire.rest.namespaces.calling",
    "Fabric": "signalwire.rest.namespaces.fabric",

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
            "set_function_includes", "set_global_data", "set_internal_fillers",
            "set_languages", "set_native_functions", "set_param", "set_params",
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
    ("signalwire.core.mixins.web_mixin", "WebMixin"): (
        "AgentBase",
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
    # REST namespace classes (Python uses descriptive *Resource / *Namespace names)
    "Calling": "CallingNamespace",
    "Fabric": "FabricNamespace",  # not in Python; emitted into fabric.py module
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
RE_CLASS = re.compile(
    r"^\s*(?:final\s+|abstract\s+)?class\s+([A-Za-z_]\w*)"
)
RE_PUBLIC_METHOD = re.compile(
    r"^\s*public\s+(?:static\s+)?function\s+(\w+)\s*\("
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


def _module_path_for_class(name: str, file_relative: Path) -> str:
    """Map a PHP class to its Python-canonical module path."""
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


def _parse_file(path: Path) -> tuple[set[str], dict[str, set[str]], set[str]]:
    """Return (free_functions, class_methods, defined_classes).

    free_functions: top-level public function names declared outside any class
                    (rare in this SDK; PHP usually wraps everything in classes).
    class_methods: {class_name: {method_names...}} — already snake_cased.
    defined_classes: class names declared in this file.
    """
    free_fns: set[str] = set()
    methods: dict[str, set[str]] = defaultdict(set)
    classes: set[str] = set()
    try:
        text = path.read_text(encoding="utf-8", errors="replace")
    except OSError:
        return free_fns, dict(methods), classes

    lines = text.splitlines()
    cur_class: str | None = None
    cur_class_brace: int = 0
    brace_depth = 0
    in_block_comment = False

    for raw_line in lines:
        # Strip block comments naively (sufficient for this SDK)
        line = raw_line
        if in_block_comment:
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
                line = line[:start]
                in_block_comment = True
                break
            line = line[:start] + line[end + 2:]
        # Strip line comments
        ls_idx = line.find("//")
        if ls_idx != -1:
            line = line[:ls_idx]
        # Strip # comments (PHP allows them)
        hash_idx = line.find("#")
        if hash_idx != -1:
            # avoid stripping inside strings
            line_stripped = line[:hash_idx]
            line = line_stripped

        # Class declaration
        m_class = RE_CLASS.match(line)
        if m_class:
            cur_class = m_class.group(1)
            classes.add(cur_class)
            # Brace might appear later on the same or following line; we'll
            # enter the class scope as soon as `{` is seen.
            cur_class_brace = -1
            # Continue to count braces on this line below.

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
            if cur_class is not None:
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

    return free_fns, dict(methods), classes


def build_surface() -> dict:
    modules: dict[str, dict] = defaultdict(
        lambda: {"classes": defaultdict(list), "functions": []}
    )
    sha = _git_sha()
    files = _walk_source_files()

    # First pass: collect class declarations + their files
    class_defining_files: dict[str, Path] = {}
    for path in files:
        free_fns, methods, classes = _parse_file(path)
        rel = path.relative_to(REPO_ROOT)
        for cls in classes:
            class_defining_files.setdefault(cls, rel)
        if free_fns:
            mod = _module_path_for_class("__module__", rel)
            modules[mod]["functions"].extend(sorted(free_fns))

    # Second pass: collect methods per class
    for path in files:
        free_fns, methods, classes = _parse_file(path)
        rel = path.relative_to(REPO_ROOT)
        for cls, meth_set in methods.items():
            module_path = _module_path_for_class(
                cls, class_defining_files.get(cls, rel)
            )
            translated = _translate_class(cls)
            existing = set(modules[module_path]["classes"].get(translated, []))
            existing.update(meth_set)
            modules[module_path]["classes"][translated] = sorted(existing)
        # Also add classes that have no methods (rare but possible)
        for cls in classes:
            if cls not in methods:
                module_path = _module_path_for_class(cls, rel)
                translated = _translate_class(cls)
                modules[module_path]["classes"].setdefault(translated, [])

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
        source_methods = (
            agent_base_methods if source_cls == "AgentBase"
            else swml_service_methods
        )
        present = sorted(m for m in expected if m in source_methods)
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
