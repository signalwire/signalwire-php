#!/usr/bin/env python3
"""enumerate_signatures.py — emit port_signatures.json for the PHP SDK.

Phase 4-PHP of the cross-language signature audit. Pipeline:

    1. Run scripts/signature_dump.php (PHP reflection over every class
       under SignalWire\\) to produce raw JSON.
    2. Apply enumerate_surface.py's translation tables (CLASS_MODULE_MAP,
       MIXIN_PROJECTIONS, METHOD_ALIASES, camel_to_snake) to map onto
       the Python-canonical names.
    3. Translate PHP types to canonical via porting-sdk/type_aliases.yaml
       (php section).

Usage:
    python3 scripts/enumerate_signatures.py
    python3 scripts/enumerate_signatures.py --raw raw.json
    python3 scripts/enumerate_signatures.py --strict
"""

from __future__ import annotations

import argparse
import json
import subprocess
import sys
from pathlib import Path

import yaml

HERE = Path(__file__).resolve().parent
PORT_ROOT = HERE.parent
PSDK = (PORT_ROOT.parent / "porting-sdk").resolve()
if not PSDK.is_dir():
    PSDK = Path("/usr/local/home/devuser/src/porting-sdk")

sys.path.insert(0, str(HERE))
from enumerate_surface import (  # type: ignore
    CLASS_MODULE_MAP, MIXIN_PROJECTIONS, METHOD_ALIASES,
    camel_to_snake, _module_path_for_class, _translate_class,
    _TYPES_SUB_TO_MODULE, _TYPES_RESERVED_UNRENAME,
    _SWML_VERBS_MODULE, _RELAY_PROTO_MODULE,
    _SWAIG_PAYLOAD_SUB_TO_MODULE,
)


class TypeTranslationError(RuntimeError):
    pass


def load_aliases() -> dict[str, str]:
    data = yaml.safe_load((PSDK / "type_aliases.yaml").read_text(encoding="utf-8"))
    return {str(k): str(v) for k, v in data.get("aliases", {}).get("php", {}).items()}


# ---------------------------------------------------------------------------
# Generated-REST typed-param sidecar (§5 unfold).
#
# scripts/generate_rest.py emits src/SignalWire/REST/Namespaces/Generated/
# rest_signatures.json — the canonical typed-param records for every generated
# operation/command/set method. PHP reflection can't express keyword-only kind,
# an ``array``'s element type, or the open ``extras`` dict, so for those methods
# we REPLACE the reflected params with the recorded shape (mirrors Go's
# enumerator struct-unfold). Keyed "<PhpClassName>::<phpMethodName>". The PHP
# signature and this sidecar are derived from the same computed param list in the
# generator, so they never diverge (GEN-FRESH covers the sidecar).
# ---------------------------------------------------------------------------

_SIDECAR_PATH = (
    PORT_ROOT / "src" / "SignalWire" / "REST" / "Namespaces" / "Generated" / "rest_signatures.json"
)


def load_rest_sidecar() -> dict[str, list[dict]]:
    if not _SIDECAR_PATH.is_file():
        return {}
    data = json.loads(_SIDECAR_PATH.read_text(encoding="utf-8"))
    return data.get("methods", {})


# ---------------------------------------------------------------------------
# PHP type translation
# ---------------------------------------------------------------------------


def translate_php_type(t: str, aliases: dict[str, str], context: str, allows_null: bool = False) -> str:
    if t is None or t == "":
        return "any"
    t = t.strip()

    # Nullable prefix `?T`
    if t.startswith("?"):
        inner = translate_php_type(t[1:], aliases, context, allows_null=False)
        if inner.startswith("optional<"):
            return inner
        return f"optional<{inner}>"

    # Union (T|U|null)
    if "|" in t and not (t.startswith("Closure") or t.startswith("callable")):
        parts = [p.strip() for p in t.split("|")]
        has_null = any(p in ("null", "NULL") for p in parts)
        non_null = [p for p in parts if p not in ("null", "NULL")]
        if len(non_null) == 1:
            inner = translate_php_type(non_null[0], aliases, context)
            return f"optional<{inner}>" if has_null else inner
        canon = [translate_php_type(p, aliases, context) for p in non_null]
        u = f"union<{','.join(canon)}>"
        return f"optional<{u}>" if has_null else u

    # Intersection (T&U) — PHP 8.1
    if "&" in t and not t.startswith("Closure"):
        return "any"

    # Direct alias
    if t in aliases:
        result = aliases[t]
        if allows_null and not result.startswith("optional<"):
            result = f"optional<{result}>"
        return result

    # Last segment
    last = t.rsplit("\\", 1)[-1]
    if last in aliases:
        result = aliases[last]
        if allows_null and not result.startswith("optional<"):
            result = f"optional<{result}>"
        return result

    # SDK class
    if t.startswith("SignalWire\\") or "SignalWire" in t:
        canonical = _translate_php_class_ref(t)
        if allows_null and not canonical.startswith("optional<"):
            canonical = f"optional<{canonical}>"
        return canonical

    # Bare class name (PHP often uses unqualified names within a namespace)
    if last and last[0].isupper():
        canonical = _translate_php_class_ref(last)
        if allows_null and not canonical.startswith("optional<"):
            canonical = f"optional<{canonical}>"
        return canonical

    raise TypeTranslationError(
        f"unknown PHP type {t!r} at {context}; "
        f"add to porting-sdk/type_aliases.yaml under aliases.php"
    )


def _translate_php_class_ref(t: str) -> str:
    # Strip leading backslash + namespace
    name = t.split("\\")[-1] if "\\" in t else t
    canonical_name = _translate_class(name)
    if canonical_name in CLASS_MODULE_MAP:
        return f"class:{CLASS_MODULE_MAP[canonical_name]}.{canonical_name}"
    # Fallback to namespace-derived module path
    parts = t.split("\\") if "\\" in t else ["SignalWire", t]
    if parts[0] == "SignalWire":
        rest = parts[1:-1]
        mod = "signalwire." + ".".join(camel_to_snake(p) for p in rest) if rest else "signalwire"
        return f"class:{mod}.{canonical_name}"
    return f"class:{canonical_name}"


# ---------------------------------------------------------------------------
# Building canonical inventory
# ---------------------------------------------------------------------------


# Free-function projections: lift a static method on an SDK class up to
# a Python module-level free function. Keyed by ("FullyQualified\\Class",
# "phpMethodName"); value is (py_module, py_function_name).
# Example: Python's signalwire.utils.url_validator.validate_url is a free
# function with no enclosing class; PHP exposes it as
# SignalWire\Utils\UrlValidator::validateUrl. Without this projection the
# audit would look for "signalwire.utils.url_validator.validate_url" but
# the port emits "signalwire.utils.url_validator.UrlValidator.validate_url".
# Namespace-qualified disambiguation. When a PHP unqualified class name
# appears in multiple namespaces (e.g. `Datasphere` lives both as a
# REST namespace and as a skill class), keying CLASS_MODULE_MAP by short
# name alone collides. This table is consulted *first* using the full
# `Namespace\\Class` key before falling back to CLASS_MODULE_MAP. Values
# are (target_python_module, optional_renamed_class_name); the second
# element overrides the CLASS_RENAME_MAP for the duration of the lookup
# (set to None to keep the PHP-native class name).
FQN_CLASS_MODULE_MAP: dict[str, tuple[str, str | None]] = {
    # REST namespace classes that share a short name with a skill.
    "SignalWire\\REST\\Namespaces\\Datasphere":
        ("signalwire.rest.namespaces.datasphere", "Datasphere"),
}


FREE_FUNCTION_PROJECTIONS: dict[tuple[str, str], tuple[str, str]] = {
    ("SignalWire\\Utils\\UrlValidator", "validateUrl"):
        ("signalwire.utils.url_validator", "validate_url"),
    # ExecutionMode helpers — Python ships them as free functions in
    # two distinct modules; PHP groups both static methods on the
    # LoggingConfig class for cohesion.
    ("SignalWire\\Logging\\LoggingConfig", "getExecutionMode"):
        ("signalwire.core.logging_config", "get_execution_mode"),
    ("SignalWire\\Logging\\LoggingConfig", "isServerlessMode"):
        ("signalwire.utils", "is_serverless_mode"),
    # Central logging helpers — Python ships them as module-level free
    # functions in signalwire.core.logging_config; PHP hosts them as static
    # methods on the LoggingConfig class (PSR-4) and projects to the canonical
    # snake_case names.
    ("SignalWire\\Logging\\LoggingConfig", "configureLogging"):
        ("signalwire.core.logging_config", "configure_logging"),
    ("SignalWire\\Logging\\LoggingConfig", "getLogger"):
        ("signalwire.core.logging_config", "get_logger"),
    ("SignalWire\\Logging\\LoggingConfig", "resetLoggingConfiguration"):
        ("signalwire.core.logging_config", "reset_logging_configuration"),
    ("SignalWire\\Logging\\LoggingConfig", "stripControlChars"):
        ("signalwire.core.logging_config", "strip_control_chars"),
    # Runtime schema-inference helpers — Python ships them as module-level free
    # functions in signalwire.core.agent.tools.type_inference; PHP hosts them as
    # static methods on a TypeInference class (PSR-4) and projects to the
    # canonical snake_case names.
    ("SignalWire\\SWAIG\\TypeInference", "inferSchema"):
        ("signalwire.core.agent.tools.type_inference", "infer_schema"),
    ("SignalWire\\SWAIG\\TypeInference", "createTypedHandlerWrapper"):
        ("signalwire.core.agent.tools.type_inference", "create_typed_handler_wrapper"),
    # LiveWire package-level helpers — Python ships function_tool / run_app as
    # module-level free functions in signalwire.livewire; PHP has no module-level
    # free functions (PSR-4 file-per-class), so they are hosted as static methods
    # on the LiveWire facade class and projected onto the canonical module-level
    # names. Mirrors the LoggingConfig / SignalWire host precedent.
    ("SignalWire\\Livewire\\LiveWire", "functionTool"):
        ("signalwire.livewire", "function_tool"),
    ("SignalWire\\Livewire\\LiveWire", "runApp"):
        ("signalwire.livewire", "run_app"),
    # Top-level SignalWire\SignalWire class hosts package-level helpers
    # (RestClient, register_skill, add_skill_directory,
    # list_skills_with_params). Project each onto the canonical
    # signalwire.<name> Python free function. RestClient stays
    # PascalCase to match Python; the rest are already snake_case in PHP.
    ("SignalWire\\SignalWire", "RestClient"):
        ("signalwire", "RestClient"),
    ("SignalWire\\SignalWire", "register_skill"):
        ("signalwire", "register_skill"),
    ("SignalWire\\SignalWire", "add_skill_directory"):
        ("signalwire", "add_skill_directory"),
    ("SignalWire\\SignalWire", "list_skills_with_params"):
        ("signalwire", "list_skills_with_params"),
    ("SignalWire\\SignalWire", "list_skills"):
        ("signalwire", "list_skills"),
    # Context / DataMap module-level factory helpers — Python ships them as
    # module-level free functions; PHP (PSR-4 file-per-class) hosts them as
    # static factories on the Context / DataMap classes and projects onto the
    # canonical module-level names.
    ("SignalWire\\Contexts\\Context", "createSimpleContext"):
        ("signalwire.core.contexts", "create_simple_context"),
    ("SignalWire\\DataMap\\DataMap", "createSimpleApiTool"):
        ("signalwire.core.data_map", "create_simple_api_tool"),
    ("SignalWire\\DataMap\\DataMap", "createExpressionTool"):
        ("signalwire.core.data_map", "create_expression_tool"),
    # Webhook signature validation — Python ships them as module-level free
    # functions (signalwire.core.security.webhook_validator); PHP groups
    # both static methods on a WebhookValidator final class for PSR-4 + IDE
    # discoverability. Project to the Python canonical names.
    ("SignalWire\\Security\\WebhookValidator", "validateWebhookSignature"):
        ("signalwire.core.security.webhook_validator", "validate_webhook_signature"),
    ("SignalWire\\Security\\WebhookValidator", "validateRequest"):
        ("signalwire.core.security.webhook_validator", "validate_request"),
    # Security hygiene helpers — Python ships them as module-level free
    # functions (signalwire.core.security.security_utils); PHP groups the
    # three static methods on a SecurityUtils final class for PSR-4 + IDE
    # discoverability. Project to the Python canonical snake_case names.
    ("SignalWire\\Security\\SecurityUtils", "filterSensitiveHeaders"):
        ("signalwire.core.security.security_utils", "filter_sensitive_headers"),
    ("SignalWire\\Security\\SecurityUtils", "redactUrl"):
        ("signalwire.core.security.security_utils", "redact_url"),
    ("SignalWire\\Security\\SecurityUtils", "isValidHostname"):
        ("signalwire.core.security.security_utils", "is_valid_hostname"),
}


# Free-function projections sometimes need their parameter shape rewritten
# to match Python's variadic ``*args, **kwargs`` conventions. PHP doesn't
# have a syntactic ``**kwargs`` — it uses two parallel ``array``
# parameters — so the source-side gets ``positional`` kinds with type
# ``any`` while Python emits ``var_positional`` / ``var_keyword``. This
# table rewrites the projected signature to canonical kind+type so the
# cross-language audit treats them as compatible.
FREE_FUNCTION_PARAM_OVERRIDES: dict[tuple[str, str], list[dict]] = {
    ("signalwire", "RestClient"): [
        {"name": "args", "kind": "var_positional", "type": "list<any>",
         "required": False, "default": "()"},
        {"name": "kwargs", "kind": "var_keyword", "type": "dict<string,any>",
         "required": False, "default": {}},
    ],
}


# Param-type remaps for parameters whose concrete element type PHP reflection
# erases. PHP's only list type is the bare ``array``, which reflects as the
# canonical ``any`` — but the Python reference types these RELAY message-event
# constructor params concretely as ``list[str]`` (the `@param list<string>`
# PHPDoc records the same intent, invisible to reflection). This is a
# rename/remap (it re-establishes the concrete identity so a real future change
# still surfaces as drift), NOT an omission (which would blind the whole param).
# Keyed by (PHP fully-qualified class, PHP method name) -> {param_name: type}.
PARAM_TYPE_REMAPS: dict[tuple[str, str], dict[str, str]] = {
    ("SignalWire\\Relay\\Event\\MessageReceiveEvent", "__construct"): {
        "media": "list<string>",
        "tags": "list<string>",
    },
    ("SignalWire\\Relay\\Event\\MessageStateEvent", "__construct"): {
        "media": "list<string>",
        "tags": "list<string>",
    },
    # PomBuilder::fromSections takes a list of section dicts; PHP's bare `array`
    # erases the concrete element type the oracle records (list<dict<string,any>>).
    ("SignalWire\\POM\\PomBuilder", "fromSections"): {
        "sections": "list<dict<string,any>>",
    },
}


def collect(raw: dict, aliases: dict, rest_sidecar: dict[str, list[dict]] | None = None) -> tuple[dict, list]:
    if rest_sidecar is None:
        rest_sidecar = {}
    out_modules: dict = {}
    failures: list = []

    for type_entry in raw.get("types", []):
        ns = type_entry.get("namespace", "")
        php_name = type_entry.get("name", "")
        if not php_name:
            continue
        if type_entry.get("kind") in ("trait",):
            # Traits are mixed into classes; their methods get reported on
            # the using class's own reflection. Skipping avoids duplicates.
            continue

        canonical_name = _translate_class(php_name)
        # Compute file_relative for module resolution
        file_relative = Path(ns.replace("SignalWire\\", "").replace("\\", "/")) / php_name
        # Generated wire-type classes (SignalWire\REST\Namespaces\Generated\Types\
        # <Sub>\...) route by their <Sub> namespace segment to the oracle's
        # <ns>_types_generated module — this MUST win over CLASS_MODULE_MAP because
        # a type name can collide with an SDK class (DataMap/Section/Document) or
        # recur across namespaces (AIObject). Reserved-keyword class names
        # generate_rest.py suffixed with `_` are renamed back to the bare oracle
        # leaf. Mirrors enumerate_surface.py's path-based routing.
        types_mod = None
        if "\\REST\\Namespaces\\Generated\\Types\\" in f"{ns}\\":
            sub = ns.rsplit("\\", 1)[-1]
            types_mod = _TYPES_SUB_TO_MODULE.get(sub)
        # Generated SWML-verbs config classes (SignalWire\SWML\Generated\...) route
        # by PATH to the single oracle module signalwire.core.swml_verbs_generated —
        # same rationale as the Types route: a config type name collides with an SDK
        # builder (DataMap/Section/Document) or recurs as a REST wire type
        # (AIObject/Cond), so path MUST win over CLASS_MODULE_MAP. Reserved-keyword
        # names the generator suffixed with `_` (Goto_/…) rename back to the bare leaf.
        elif ns == "SignalWire\\SWML\\Generated":
            types_mod = _SWML_VERBS_MODULE
        # Generated RELAY-protocol wire-type classes (SignalWire\Relay\Generated\...)
        # route by PATH to the single oracle module
        # signalwire.relay.protocol_types_generated. Scoped strictly to the
        # \Relay\Generated namespace so the hand-written Relay SDK classes one level
        # up (Call/Client/Event/…) keep their CLASS_MODULE_MAP routing.
        elif ns == "SignalWire\\Relay\\Generated":
            types_mod = _RELAY_PROTO_MODULE
        # Generated SWAIG-payload classes (SignalWire\SWAIG\Generated\<Sub>\...) route
        # by PATH: the <Sub> namespace segment (PostPrompt / SwaigRequest / SwaigActions)
        # → its signalwire.core.*_generated oracle module. Same rationale as the Types
        # route: the path MUST win over CLASS_MODULE_MAP so a payload type name never
        # misroutes onto a hand SWAIG SDK class one/two levels up. The diff tool's
        # gen-payload fold then keys a class-typed field by (class, field) cross-port.
        elif ns.startswith("SignalWire\\SWAIG\\Generated\\"):
            sub = ns.rsplit("\\", 1)[-1]
            types_mod = _SWAIG_PAYLOAD_SUB_TO_MODULE.get(sub)
        # (types_mod, when set, wins below over CLASS_MODULE_MAP.)
        # FQN_CLASS_MODULE_MAP wins when set — disambiguates short-name
        # collisions between e.g. REST namespace classes and skills.
        full_php_for_lookup = f"{ns}\\{php_name}" if ns else php_name
        if types_mod is not None:
            mod = types_mod
            canonical_name = _TYPES_RESERVED_UNRENAME.get(php_name, canonical_name)
        elif full_php_for_lookup in FQN_CLASS_MODULE_MAP:
            mod, override_name = FQN_CLASS_MODULE_MAP[full_php_for_lookup]
            if override_name is not None:
                canonical_name = override_name
        # Try BOTH the original and canonical names against CLASS_MODULE_MAP.
        # CLASS_MODULE_MAP is mostly keyed by PHP-native names (Service, Client),
        # not Python-canonical names (SWMLService, RelayClient).
        elif php_name in CLASS_MODULE_MAP:
            mod = CLASS_MODULE_MAP[php_name]
        elif canonical_name in CLASS_MODULE_MAP:
            mod = CLASS_MODULE_MAP[canonical_name]
        else:
            mod = _module_path_for_class(canonical_name, file_relative)

        # PHP fully-qualified class name used to key FREE_FUNCTION_PROJECTIONS.
        full_php = f"{ns}\\{php_name}" if ns else php_name
        # Some classes exist solely to host static methods that get lifted
        # to module-level Python free functions (e.g. UrlValidator). Skip
        # the class shell entirely when *every* declared method on the
        # class is free-function-projected. If the class has at least one
        # non-projected method (e.g. SignalWire\SignalWire::getLogger), we
        # keep the class shell so port-only methods stay surfaced.
        projected_method_names = {
            method
            for (cls, method) in FREE_FUNCTION_PROJECTIONS.keys()
            if cls == full_php
        }
        declared_methods = [
            m.get("name", "")
            for m in type_entry.get("methods", [])
            if not m.get("name", "").startswith("__")
        ]
        is_freefn_only_class = (
            bool(projected_method_names)
            and all(m in projected_method_names for m in declared_methods)
        )

        methods_out: dict = {}
        free_functions_out: list[tuple[str, str, dict]] = []  # (target_mod, target_fn, sig)
        for m in type_entry.get("methods", []):
            native = m.get("name", "")
            if native == "__construct":
                method_canonical = "__init__"
            elif native == "__toString":
                method_canonical = "__str__"
            elif native == "__destruct":
                method_canonical = "__del__"
            elif native.startswith("__"):
                continue
            else:
                snake = camel_to_snake(native)
                method_canonical = METHOD_ALIASES.get(snake, snake)

            ff_key = (full_php, native)
            if ff_key in FREE_FUNCTION_PROJECTIONS:
                target_mod, target_fn = FREE_FUNCTION_PROJECTIONS[ff_key]
                ctx = f"{target_mod}.{target_fn}"
                try:
                    sig = build_signature(m, aliases, ctx)
                except TypeTranslationError as e:
                    failures.append(str(e))
                    continue
                # Strip implicit ``self`` — free functions have no receiver.
                params = sig.get("params", [])
                if params and params[0].get("kind") == "self":
                    sig["params"] = params[1:]
                # Apply variadic-shape override for projections whose PHP
                # signature uses ``array $args = [], array $kwargs = []``
                # but the canonical Python signature is
                # ``(*args, **kwargs)``.
                override = FREE_FUNCTION_PARAM_OVERRIDES.get((target_mod, target_fn))
                if override is not None:
                    sig["params"] = [dict(p) for p in override]
                free_functions_out.append((target_mod, target_fn, sig))
                continue

            ctx = f"{mod}.{canonical_name}.{method_canonical}"
            try:
                sig = build_signature(m, aliases, ctx)
            except TypeTranslationError as e:
                failures.append(str(e))
                continue
            # Classmethod-factory receiver: the Python reference declares the
            # typed RELAY event factory `from_payload` as a @classmethod, so it
            # carries a `cls` receiver. PHP expresses the same factory as a
            # `static` method (no implicit receiver), which build_signature
            # (correctly, for a genuine @staticmethod) records with no receiver
            # — producing a spurious param-count drift vs the reference's `cls`.
            # Inject the `cls` receiver for this classmethod-analog so the two
            # line up (the diff already reconciles cls<->self). Scoped to the
            # specific (module, method) classmethod-factory pairs the oracle
            # records with a `cls` receiver, so no genuine PHP @staticmethod is
            # affected:
            #   - relay.event  from_payload  (typed RELAY event factory)
            #   - pom_builder  from_sections (PomBuilder classmethod factory)
            _classmethod_factories = {
                ("signalwire.relay.event", "from_payload"),
                ("signalwire.core.pom_builder", "from_sections"),
            }
            if (
                (mod, method_canonical) in _classmethod_factories
                and m.get("is_static", False)
                and not (sig.get("params") and sig["params"][0].get("kind") in ("self", "cls"))
            ):
                sig["params"] = [{"name": "cls", "kind": "cls"}] + sig.get("params", [])
            # Concrete-collection param remap: re-establish a param's concrete
            # element type where PHP's bare ``array`` erased it (see
            # PARAM_TYPE_REMAPS). Matched on the reflected param name.
            remap = PARAM_TYPE_REMAPS.get((full_php, native))
            if remap:
                for prm in sig.get("params", []):
                    new_type = remap.get(prm.get("name", ""))
                    if new_type is not None:
                        prm["type"] = new_type
            # §5 unfold: a generated REST operation/command/set method takes its
            # wire fields as named PHP params (options-struct idiom); PHP
            # reflection can't recover keyword kind / element types / the open
            # ``extras`` dict, so REPLACE the reflected params with the generator's
            # canonical records. Keyed by PHP class + PHP method name.
            sidecar_key = f"{php_name}::{native}"
            if sidecar_key in rest_sidecar:
                records = [dict(r) for r in rest_sidecar[sidecar_key]]
                sig["params"] = [{"name": "self", "kind": "self"}] + records
            if method_canonical in methods_out:
                continue
            methods_out[method_canonical] = sig

        # Properties → zero-arg "method" entries (skipped for free-function-
        # only classes; their state is not part of the Python surface).
        if not is_freefn_only_class:
            for p in type_entry.get("properties", []):
                pname = p.get("name", "")
                snake = camel_to_snake(pname)
                method_canonical = METHOD_ALIASES.get(snake, snake)
                if method_canonical in methods_out:
                    continue
                ctx = f"{mod}.{canonical_name}.{method_canonical}"
                try:
                    ret = translate_php_type(p.get("type", "mixed"), aliases, ctx + "[->]")
                except TypeTranslationError as e:
                    failures.append(str(e))
                    continue
                params_out = []
                if not p.get("is_static", False):
                    params_out.append({"name": "self", "kind": "self"})
                methods_out[method_canonical] = {"params": params_out, "returns": ret}

        # Emit any lifted free functions before the class itself.
        for target_mod, target_fn, sig in free_functions_out:
            out_modules.setdefault(target_mod, {"classes": {}})
            out_modules[target_mod].setdefault("functions", {})
            out_modules[target_mod]["functions"][target_fn] = sig

        # Free-function-only classes: do NOT emit a class shell, even with
        # synthesized __init__. The Python reference has no class — only a
        # module-level function — so emitting one would create drift.
        if is_freefn_only_class:
            continue

        if not methods_out:
            continue

        # Synthesize __init__ when PHP class has no explicit __construct.
        # Every PHP class IS constructible (PHP supplies a default
        # constructor); without this, those classes show as missing-port
        # __init__ in the cross-language audit.
        if "__init__" not in methods_out:
            methods_out["__init__"] = {
                "params": [{"name": "self", "kind": "self"}],
                "returns": "void",
            }

        out_modules.setdefault(mod, {"classes": {}})
        out_modules[mod]["classes"][canonical_name] = {
            "methods": dict(sorted(methods_out.items())),
        }

    # Free functions declared in SignalWire\* namespaces (e.g.
    # SignalWire\Contexts\create_simple_context). Map them onto
    # canonical Python module paths just like classes.
    for fn in raw.get("functions", []):
        ns = fn.get("namespace", "")
        php_name = fn.get("name", "")
        if not php_name:
            continue
        # Convert namespace `SignalWire\Contexts` -> `signalwire.contexts`
        parts = [p for p in ns.split("\\") if p]
        if not parts or parts[0].lower() != "signalwire":
            continue
        rest = parts[1:]
        if rest:
            mod = "signalwire." + ".".join(camel_to_snake(p) for p in rest)
        else:
            mod = "signalwire"
        # Special case: signalwire.contexts -> signalwire.core.contexts to
        # match the canonical Python module path.
        if mod == "signalwire.contexts":
            mod = "signalwire.core.contexts"
        py_name = camel_to_snake(php_name)
        py_name = METHOD_ALIASES.get(py_name, py_name)
        ctx = f"{mod}.{py_name}"
        try:
            sig = build_signature({"name": php_name, "is_static": True,
                                   "parameters": fn.get("parameters", []),
                                   "return_type": fn.get("return_type", "mixed"),
                                   "return_allows_null": fn.get("return_allows_null", False)},
                                   aliases, ctx)
            # Free functions have no `self`.
            params = sig.get("params", [])
            if params and params[0].get("kind") == "self":
                sig["params"] = params[1:]
        except TypeTranslationError as e:
            failures.append(str(e))
            continue
        out_modules.setdefault(mod, {"classes": {}})
        out_modules[mod].setdefault("functions", {})
        out_modules[mod]["functions"][py_name] = sig

    # Mixin projection — pick matching methods off AgentBase or SWMLService
    # depending on the (source_cls) the projection points at.
    ab_entry = out_modules.get("signalwire.core.agent_base", {}).get("classes", {}).get("AgentBase")
    sm_entry = out_modules.get("signalwire.core.swml_service", {}).get("classes", {}).get("SWMLService")
    ab_methods = (ab_entry or {}).get("methods", {})
    sm_methods = (sm_entry or {}).get("methods", {})

    if ab_methods or sm_methods:
        projected_ab: set[str] = set()
        for (target_mod, target_cls), (source_cls, expected) in MIXIN_PROJECTIONS.items():
            primary = sm_methods if source_cls == "SWMLService" else ab_methods
            secondary = ab_methods if source_cls == "SWMLService" else sm_methods
            present: dict = {}
            ab_picked: list[str] = []
            for m in expected:
                if m in primary:
                    present[m] = primary[m]
                elif m in secondary:
                    # Cross-source pickup: PHP only declares the method on
                    # the alternate base class. WebMixin's manual_set_proxy_url
                    # is a typical case — Python projects it under the mixin
                    # while PHP declares it on AgentBase.
                    present[m] = secondary[m]
                    if source_cls == "SWMLService":
                        ab_picked.append(m)
            if not present:
                continue
            out_modules.setdefault(target_mod, {"classes": {}})
            out_modules[target_mod]["classes"].setdefault(target_cls, {"methods": {}})
            out_modules[target_mod]["classes"][target_cls]["methods"].update(present)
            # Only de-duplicate against AgentBase — SWMLService methods that
            # are also projected to a mixin still belong on SWMLService.
            if source_cls != "SWMLService":
                projected_ab.update(present)
            # When the SWMLService-source projection cross-picks an
            # AgentBase method, also mark it for AgentBase de-dup so it
            # doesn't show up in two places.
            for m in ab_picked:
                projected_ab.add(m)
        for n in projected_ab:
            ab_methods.pop(n, None)
        if ab_entry is not None and not ab_methods:
            out_modules["signalwire.core.agent_base"]["classes"].pop("AgentBase", None)
            if not out_modules["signalwire.core.agent_base"]["classes"]:
                out_modules.pop("signalwire.core.agent_base")

    sorted_modules = {}
    for k in sorted(out_modules):
        entry = out_modules[k]
        out_entry: dict = {}
        if entry.get("classes"):
            out_entry["classes"] = {
                cls: {"methods": dict(sorted(entry["classes"][cls]["methods"].items()))}
                for cls in sorted(entry["classes"])
            }
        if entry.get("functions"):
            out_entry["functions"] = {
                fn: entry["functions"][fn] for fn in sorted(entry["functions"])
            }
        if out_entry:
            sorted_modules[k] = out_entry
    return {
        "version": "2",
        "generated_from": "signalwire-php via PHP Reflection",
        "modules": sorted_modules,
    }, failures


def build_signature(method: dict, aliases: dict, context: str) -> dict:
    params_out: list = []
    is_static = method.get("is_static", False)
    if not is_static:
        params_out.append({"name": "self", "kind": "self"})

    for p in method.get("parameters", []):
        ctx = f"{context}[{p.get('name')}]"
        canon_type = translate_php_type(
            p.get("type", "mixed"), aliases, ctx, allows_null=p.get("allows_null", False),
        )
        param: dict = {
            "name": camel_to_snake(p.get("name", "")),
            "type": canon_type,
        }
        if p.get("is_variadic"):
            param["kind"] = "var_positional"
        if p.get("has_default") or p.get("is_optional"):
            param["required"] = False
            if p.get("has_default"):
                param["default"] = p.get("default")
            else:
                param["default"] = None
        else:
            param["required"] = True
        params_out.append(param)

    if method.get("name") == "__construct":
        return_canon = "void"
    else:
        return_canon = translate_php_type(
            method.get("return_type", "mixed"), aliases, context + "[->]",
            allows_null=method.get("return_allows_null", False),
        )
    return {"params": params_out, "returns": return_canon}


# ---------------------------------------------------------------------------
# CLI
# ---------------------------------------------------------------------------


def run_dump() -> dict:
    cp = subprocess.run(
        ["php", str(HERE / "signature_dump.php")],
        cwd=PORT_ROOT, capture_output=True, text=True, timeout=120,
    )
    if cp.returncode != 0:
        raise RuntimeError(f"signature_dump.php failed:\n{cp.stderr}\n{cp.stdout}")
    return json.loads(cp.stdout)


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--raw", type=Path, default=None)
    parser.add_argument("--out", type=Path, default=PORT_ROOT / "port_signatures.json")
    parser.add_argument("--strict", action="store_true")
    args = parser.parse_args()

    aliases = load_aliases()
    if args.raw and args.raw.is_file():
        raw = json.loads(args.raw.read_text(encoding="utf-8"))
    else:
        raw = run_dump()

    rest_sidecar = load_rest_sidecar()
    canonical, failures = collect(raw, aliases, rest_sidecar)
    if failures:
        print(f"enumerate_signatures: {len(failures)} translation failure(s)", file=sys.stderr)
        for f in failures[:30]:
            print(f"  - {f}", file=sys.stderr)
        if len(failures) > 30:
            print(f"  ... ({len(failures) - 30} more)", file=sys.stderr)
        if args.strict:
            return 1

    args.out.write_text(json.dumps(canonical, indent=2, sort_keys=False) + "\n", encoding="utf-8")
    n_mods = len(canonical["modules"])
    n_methods = sum(sum(len(c["methods"]) for c in m.get("classes", {}).values()) for m in canonical["modules"].values())
    print(f"enumerate_signatures: wrote {args.out} ({n_mods} modules, {n_methods} methods)")
    return 0


if __name__ == "__main__":
    sys.exit(main())
