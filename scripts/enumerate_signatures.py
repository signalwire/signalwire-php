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

sys.path.insert(0, str(HERE))
# PSDK is resolved by the SURFACE enumerator's multi-layout, fail-loud resolver and
# IMPORTED here rather than re-derived. A resolver duplicated across the two
# enumerators is guaranteed future drift (go shipped a rename table in one gate and
# not its twin, so a name folded in one and reddened in the other); sharing is the
# fix. Same reason CLASS_METHOD_ALIASES / ORACLE_ACCESSOR_FOLD are imported below.
from enumerate_surface import (  # type: ignore
    PSDK,
    CLASS_MODULE_MAP, MIXIN_PROJECTIONS, METHOD_ALIASES, CLASS_METHOD_ALIASES,
    ORACLE_ACCESSOR_FOLD,
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
    # Decomposed framework-free validation core — Python ships it as the
    # module-level free function signalwire.core.security.webhook_middleware.
    # validate(method, url, headers, body, *, signing_key) -> optional triple.
    # PHP hosts it as a static method on the WebhookMiddleware class (the same
    # class whose object-shaped process() stays a PHP-idiom PORT_ADDITION) and
    # projects it onto the canonical module-level `validate` name. The param
    # kinds (keyword-only signing_key) + concrete element types PHP reflection
    # erases are re-established via FREE_FUNCTION_PARAM_OVERRIDES /
    # FREE_FUNCTION_RETURN_OVERRIDES below.
    ("SignalWire\\Security\\WebhookMiddleware", "validate"):
        ("signalwire.core.security.webhook_middleware", "validate"),
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
    # RequestOptions envelope helpers (plan 4.2) — Python ships resolve() and
    # status_is_retryable() as module-level free functions in
    # signalwire.rest._request_options. PHP is PSR-4 file-per-class (no
    # module-level functions), so they are hosted as static methods on the
    # RequestOptions value class and projected onto the canonical module-level
    # names. The _EffectiveOptions carrier is a signature-only reference type
    # (not surfaced, like HostAppRouter); re-established via the param/return
    # overrides below since PHP represents effective options as an assoc array.
    ("SignalWire\\REST\\RequestOptions", "resolve"):
        ("signalwire.rest._request_options", "resolve"),
    ("SignalWire\\REST\\RequestOptions", "statusIsRetryable"):
        ("signalwire.rest._request_options", "status_is_retryable"),
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
    # Decomposed webhook validation core. The Python reference declares
    # signing_key keyword-only (`validate(method, url, headers, body, *,
    # signing_key)`) and types headers as dict<string,string>; PHP reflection
    # sees a trailing positional `string $signingKey` and erases the headers
    # element type to bare `array` -> `any`. Re-establish the canonical
    # kind+types so the projected free function reconciles EQUAL with the oracle.
    ("signalwire.core.security.webhook_middleware", "validate"): [
        {"name": "method", "type": "string", "required": True},
        {"name": "url", "type": "string", "required": True},
        {"name": "headers", "type": "dict<string,string>", "required": True},
        {"name": "body", "type": "string", "required": True},
        {"name": "signing_key", "kind": "keyword", "type": "string",
         "required": True},
    ],
    # RequestOptions envelope (plan 4.2). status_is_retryable's ``opts`` is the
    # resolved _EffectiveOptions carrier; PHP represents it as an assoc array
    # (``array`` -> ``any``). Re-establish the oracle's signature-only reference
    # type (the same pattern as HostAppRouter/ConversationRole — a named type the
    # SURFACE oracle does not surface, so referencing it adds no port surface).
    ("signalwire.rest._request_options", "status_is_retryable"): [
        {"name": "method", "type": "string", "required": True},
        {"name": "status", "type": "int", "required": True},
        {"name": "opts",
         "type": "class:signalwire.rest._request_options._EffectiveOptions",
         "required": True},
    ],
    # filter_sensitive_headers is generic in the reference —
    # ``dict[str, _V] -> dict[str, _V]`` over a module-level TypeVar — so the
    # oracle records the value type as the TypeVar's own class identity. PHP's
    # only map type is the bare ``array``, which reflects as ``any`` and erases
    # it. The PHPDoc on SecurityUtils::filterSensitiveHeaders already documents
    # the identity-preserving contract (``@param array<string, mixed>`` ->
    # ``@return array<string, mixed>``: every non-sensitive value is copied
    # through UNCHANGED), which is exactly what the TypeVar encodes. Re-establish
    # the oracle's type so the param keeps COMPARING — ruby and perl already
    # emit this same TypeVar type verbatim from their adapters, so it is a
    # type-map fold, not a language ceiling.
    ("signalwire.core.security.security_utils", "filter_sensitive_headers"): [
        {"name": "headers",
         "type": "dict<string,class:signalwire.core.security.security_utils._V>",
         "required": True},
    ],
}


# Return-type overrides for free-function projections whose PHP reflected
# return type erases the concrete shape the oracle records (PHP's only
# list/map type is bare ``array`` -> ``any``). Keyed by the PROJECTED
# (target_module, target_function). Parallel to FREE_FUNCTION_PARAM_OVERRIDES.
FREE_FUNCTION_RETURN_OVERRIDES: dict[tuple[str, str], str] = {
    # validate() returns None (pass) or a [status, headers, body] triple
    # (reject); PHP's `?array` reflects as optional<any>. Re-establish the
    # oracle's optional<tuple<int,dict<string,string>,string>>.
    ("signalwire.core.security.webhook_middleware", "validate"):
        "optional<tuple<int,dict<string,string>,string>>",
    # infer_schema() reflects a typed handler and returns the SWAIG schema
    # tuple [parameters, required, description, is_typed, has_raw_data]. PHP's
    # only tuple type is the bare ``array`` -> ``any``; the concrete shape is
    # documented on TypeInference::inferSchema via the ``@return array{...}``
    # generic. Re-establish the oracle's fixed schema-contract tuple.
    ("signalwire.core.agent.tools.type_inference", "infer_schema"):
        "tuple<dict<string,dict<string,any>>,list<string>,optional<string>,bool,bool>",
    # RequestOptions.resolve() returns the resolved _EffectiveOptions carrier;
    # PHP returns an assoc array (``array`` -> ``any``). Re-establish the
    # oracle's signature-only reference type (see the status_is_retryable
    # param override above for the rationale).
    ("signalwire.rest._request_options", "resolve"):
        "class:signalwire.rest._request_options._EffectiveOptions",
    # Identity-preserving generic filter (see the param override above): the
    # reference's return type is the SAME ``dict[str, _V]`` as its input.
    ("signalwire.core.security.security_utils", "filter_sensitive_headers"):
        "dict<string,class:signalwire.core.security.security_utils._V>",
}


# Param-type remaps for parameters whose concrete element type PHP reflection
# erases. PHP's only list/map type is the bare ``array``, which reflects as the
# canonical ``any`` — but the Python reference types these params concretely
# (``list<string>``, ``list<dict<string,any>>``, ``dict<string,list<string>>``,
# ``optional<list<string>>``, …). The concrete element type IS documented on the
# PHP method via a PHPDoc ``@param list<string> $x`` generic (phpstan L9 reads it),
# but PHP *reflection* — the source `signature_dump.php` uses — cannot see PHPDoc,
# so it drops to ``array``→``any``. This table re-establishes the exact concrete
# type the PHPDoc already records. It is a rename/remap (a real future change to
# the element type still surfaces as drift), NOT an omission (which would blind
# the whole param). The keys mirror the ``@param`` generics on the source method.
# Keyed by (PHP fully-qualified class, PHP method name) -> {snake_param_name: type}.
PARAM_TYPE_REMAPS: dict[tuple[str, str], dict[str, str]] = {
    # HttpClient's query door. ``$params`` is the query-string map the reference
    # types ``dict[str, Any] | None`` (rest/_base.py:285/295); PHP's only map
    # type is the bare ``array``, which reflects as ``any``. The concrete shape
    # is already on the method's ``@param array<string,mixed>|null $params``
    # PHPDoc — re-establish it so the param keeps COMPARING. (``get`` is listed
    # too even though the differ currently excuses it under its CRUD-verb name:
    # that excusal is incidental to the method being spelled ``get``, and the
    # type is just as knowable there.)
    ("SignalWire\\REST\\HttpClient", "get"): {
        "params": "optional<dict<string,any>>",
    },
    ("SignalWire\\REST\\HttpClient", "post"): {
        "params": "optional<dict<string,any>>",
    },
    # RequestOptions envelope (plan 4.2). The oracle types the ``abort_signal``
    # constructor param as the _AbortSignal protocol; PHP's ``$abortSignal`` is a
    # ``callable|object|null`` union (-> ``any``). Re-establish the oracle's
    # signature-only named type (parallel to the abortSignal PROPERTY_TYPE_REMAP;
    # _AbortSignal is not surfaced, so this adds no port surface class).
    ("SignalWire\\REST\\RequestOptions", "__construct"): {
        "abort_signal": "optional<class:signalwire.rest._request_options._AbortSignal>",
    },
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
    # Service::handleRequest — the HTTP request-dispatch entry point (projected to
    # SWMLService.handle_request). PHP reflection erases `array $headers` to bare
    # `array`→`any`; the PHPDoc `@param array<string,string> $headers` records the
    # concrete oracle type dict<string,string>. Re-establish it (the `body` param
    # is a genuine string-vs-dict idiom divergence — the PHP entry point takes the
    # raw wire body string and json_decodes it internally — documented in
    # PORT_SIGNATURE_OMISSIONS.md, PHP-param-shape).
    ("SignalWire\\SWML\\Service", "handleRequest"): {
        "headers": "dict<string,string>",
    },
    # Service::registerRoutingCallback — PHP's bare `callable` type hint reflects
    # to a loose callable; the PHPDoc records the concrete
    # `(array, array): ?string` shape the oracle types as
    # callable<list<dict<string,any>,dict<string,any>>,optional<string>>.
    # Projected onto both SWMLService.register_routing_callback and
    # WebMixin.register_routing_callback.
    ("SignalWire\\SWML\\Service", "registerRoutingCallback"): {
        "callback": "callable<list<dict<string,any>,dict<string,any>>,optional<string>>",
    },
    # SkillManager::loadSkill — Python passes the skill CLASS OBJECT; PHP passes
    # its class-string (`new $skillClass(...)` is the PHP idiom for the same
    # capability) and the PHPDoc records `class-string<SkillBase>`. Re-establish
    # the oracle's class reference so the two compare EQUAL.
    ("SignalWire\\Skills\\SkillManager", "loadSkill"): {
        "skill_class": "optional<class:signalwire.core.skill_base.SkillBase>",
    },
    # --- AgentBase: AI-config / prompt / skill mixin params (projected onto the
    # AIConfigMixin/PromptMixin/PromptManager/SkillMixin targets by MIXIN_PROJECTIONS
    # after this remap runs, so tightening here clears every projected copy too). ---
    ("SignalWire\\Agent\\AgentBase", "setPromptPom"): {
        "pom": "list<dict<string,any>>",
    },
    ("SignalWire\\Agent\\AgentBase", "addSwaigQueryParams"): {
        "params": "dict<string,string>",
    },
    ("SignalWire\\Agent\\AgentBase", "addHints"): {
        "hints": "list<string>",
    },
    ("SignalWire\\Agent\\AgentBase", "setFunctionIncludes"): {
        "includes": "list<dict<string,any>>",
    },
    ("SignalWire\\Agent\\AgentBase", "setInternalFillers"): {
        "fillers": "dict<string,dict<string,list<string>>>",
    },
    # addInternalFiller's `array $fillers` is the per-(function,language) phrase
    # list; the PHPDoc `@param list<string> $fillers` records the concrete type
    # PHP reflection erases. Surfaced once the param-order/required fix aligned
    # this param positionally with the reference's `fillers: list[str]`.
    ("SignalWire\\Agent\\AgentBase", "addInternalFiller"): {
        "fillers": "list<string>",
    },
    ("SignalWire\\Agent\\AgentBase", "setLanguages"): {
        "languages": "list<dict<string,any>>",
    },
    ("SignalWire\\Agent\\AgentBase", "setNativeFunctions"): {
        "functions": "list<string>",
    },
    ("SignalWire\\Agent\\AgentBase", "setPronunciations"): {
        "pronunciations": "list<dict<string,any>>",
    },
    ("SignalWire\\Agent\\AgentBase", "addSkill"): {
        "params": "optional<dict<string,any>>",
    },
    # The four phase-verb adders. Their `$config` was `mixed` until it started
    # flowing into the VALIDATING Service::addVerb, at which point it was
    # narrowed to a real `array` — but PHP reflection erases the generic, so the
    # artifact recorded a bare `any` and TYPE-EROSION counted the reference's
    # `dict<string,any>` as discarded. The concrete type is already on each
    # method's `@param array<string,mixed> $config` PHPDoc (phpstan L9 reads it);
    # re-establish it so the param keeps COMPARING against the reference's
    # `config: dict[str, Any]` (core/agent_base.py:558/628/655).
    ("SignalWire\\Agent\\AgentBase", "addPreAnswerVerb"): {
        "config": "dict<string,any>",
    },
    ("SignalWire\\Agent\\AgentBase", "addPostAnswerVerb"): {
        "config": "dict<string,any>",
    },
    ("SignalWire\\Agent\\AgentBase", "addPostAiVerb"): {
        "config": "dict<string,any>",
    },
    ("SignalWire\\Agent\\AgentBase", "addAnswerVerb"): {
        "config": "dict<string,any>",
    },
    # --- Contexts.Context / Contexts.Step: bullet/filler/context/step lists. ---
    ("SignalWire\\Contexts\\Context", "addBullets"): {"bullets": "list<string>"},
    ("SignalWire\\Contexts\\Context", "addSystemBullets"): {"bullets": "list<string>"},
    ("SignalWire\\Contexts\\Context", "setValidContexts"): {"contexts": "list<string>"},
    ("SignalWire\\Contexts\\Context", "setValidSteps"): {"steps": "list<string>"},
    ("SignalWire\\Contexts\\Context", "setEnterFillers"): {
        "enter_fillers": "dict<string,list<string>>",
    },
    ("SignalWire\\Contexts\\Context", "setExitFillers"): {
        "exit_fillers": "dict<string,list<string>>",
    },
    ("SignalWire\\Contexts\\Context", "addEnterFiller"): {"fillers": "list<string>"},
    ("SignalWire\\Contexts\\Context", "addExitFiller"): {"fillers": "list<string>"},
    ("SignalWire\\Contexts\\Step", "addBullets"): {"bullets": "list<string>"},
    ("SignalWire\\Contexts\\Step", "setValidContexts"): {"contexts": "list<string>"},
    ("SignalWire\\Contexts\\Step", "setValidSteps"): {"steps": "list<string>"},
    # --- DataMap: error-key lists, enum, webhook headers/require_args, expressions. ---
    ("SignalWire\\DataMap\\DataMap", "errorKeys"): {"keys": "list<string>"},
    ("SignalWire\\DataMap\\DataMap", "globalErrorKeys"): {"keys": "list<string>"},
    ("SignalWire\\DataMap\\DataMap", "parameter"): {"enum": "optional<list<string>>"},
    ("SignalWire\\DataMap\\DataMap", "webhook"): {
        "headers": "optional<dict<string,string>>",
        "require_args": "optional<list<string>>",
    },
    ("SignalWire\\DataMap\\DataMap", "webhookExpressions"): {
        "expressions": "list<dict<string,any>>",
    },
    # --- FunctionResult: action/hint/media/tag/toggle lists. ---
    ("SignalWire\\SWAIG\\FunctionResult", "addActions"): {
        "actions": "list<dict<string,any>>",
    },
    ("SignalWire\\SWAIG\\FunctionResult", "addDynamicHints"): {
        "hints": "list<union<dict<string,any>,string>>",
    },
    ("SignalWire\\SWAIG\\FunctionResult", "createPaymentPrompt"): {
        "actions": "list<dict<string,string>>",
    },
    ("SignalWire\\SWAIG\\FunctionResult", "sendSms"): {
        "media": "optional<list<string>>",
        "tags": "optional<list<string>>",
    },
    # --- ToolMixin.on_function_call (projected from Service::onFunctionCall). ---
    ("SignalWire\\SWML\\Service", "onFunctionCall"): {
        "raw_data": "optional<dict<string,any>>",
    },
    # --- SkillBase constructor params dict. ---
    ("SignalWire\\Skills\\SkillBase", "__construct"): {
        "params": "optional<dict<string,any>>",
    },
    # --- POM Section.add_bullets. ---
    ("SignalWire\\POM\\Section", "addBullets"): {"bullets": "list<string>"},
    # --- RelayClient.receive / unreceive context lists. ---
    ("SignalWire\\Relay\\Client", "receive"): {"contexts": "list<string>"},
    ("SignalWire\\Relay\\Client", "unreceive"): {"contexts": "list<string>"},
    # --- SchemaValidationError errors list. ---
    ("SignalWire\\Utils\\SchemaValidationError", "__construct"): {
        "errors": "list<string>",
    },
}


# ---------------------------------------------------------------------------
# AI-Chat whole-signature fold (item: ai-chat surface tighten).
#
# The oracle enumerates signalwire.ai_chat.client with an EXACT own-signature per
# class (griffe). PHP reflection over the hand-written AIChat client sees idiom
# the reference doesn't: the readonly $url PROPERTY surfaces as a zero-arg method;
# chat()/summarize() carry extra PHP-idiom convenience params (timeout/reinit on
# chat, a sampling map on summarize) that the reference expresses as auto-create
# kwargs / server-side params; and the constructor's 5th param is PHP's
# readIdleTimeoutSeconds where the reference takes an aiohttp session. The client's
# WIRE behavior is proven independently by the AI-CHAT wire gate, so the signature
# shape is pure idiom — reconcile it by SPLICING each method to its canonical
# oracle signature (AGENT_RULES §2; mirrors the .NET splice + the REST §5 unfold).
#
# Keyed: canonical_class -> {canonical_method -> full signature dict}. When a class
# appears here, its enumerated methods_out is REPLACED by exactly this map, so every
# member the reference records must be PRESENT here or it silently vanishes from the
# port's surface. (Corrected 2026-07-26: this comment used to say the ``url``
# property-method was "spurious" and dropped. That was true only while the oracle did
# not enumerate scalar instance state; class B2 made it FALSE, and the drop was hiding
# a real reader — ``url``, ``AIChatError.code``, ``AIChatError.message``. cpp's AI-Chat
# projection carried the identical stale rationale.) ``close`` is present. __init__
# folds to the oracle's four params (project/token/space/url) verbatim: the PHP
# ctor's trailing optional convenience knob ``readIdleTimeoutSeconds`` (byte-idle
# read timeout; the reference's async DI ``session`` seam has no PHP counterpart)
# is a PHP-idiom trailing optional not part of the reference construction surface,
# so it is folded away here. Everything folds to ZERO drift — no AI-Chat
# signature omission remains.
AICHAT_SIGNATURES: dict[str, dict[str, dict]] = {
    "AIChatClient": {
        "__init__": {
            "params": [
                {"name": "self", "kind": "self"},
                {"name": "project", "type": "optional<string>", "required": False, "default": None},
                {"name": "token", "type": "optional<string>", "required": False, "default": None},
                {"name": "space", "type": "optional<string>", "required": False, "default": None},
                {"name": "url", "type": "optional<string>", "required": False, "default": None},
            ],
            "returns": "void",
        },
        "chat": {
            "params": [
                {"name": "self", "kind": "self"},
                {"name": "conversation_id", "type": "string", "required": True},
                {"name": "message", "type": "string", "required": True},
                {"name": "role", "type": "string", "required": False, "default": "user"},
                {"name": "config_url", "type": "optional<string>", "required": False, "default": None},
                {"name": "user_metadata", "type": "optional<dict<string,any>>", "required": False, "default": None},
                {"name": "timeout", "type": "optional<int>", "required": False, "default": None},
                {"name": "reinit", "type": "bool", "required": False, "default": False},
            ],
            "returns": "class:signalwire.ai_chat.client.ChatResponse",
        },
        "close": {
            "params": [{"name": "self", "kind": "self"}],
            "returns": "void",
        },
        "create_conversation": {
            "params": [
                {"name": "self", "kind": "self"},
                {"name": "conversation_id", "type": "string", "required": True},
                {"name": "config_url", "type": "string", "required": True},
                {"name": "user_message", "type": "optional<string>", "required": False, "default": None},
                {"name": "timeout", "type": "optional<int>", "required": False, "default": None},
                {"name": "user_metadata", "type": "optional<dict<string,any>>", "required": False, "default": None},
                {"name": "reinit", "type": "bool", "required": False, "default": False},
            ],
            "returns": "class:signalwire.ai_chat.client.ConversationInfo",
        },
        "delete": {
            "params": [
                {"name": "self", "kind": "self"},
                {"name": "conversation_id", "type": "string", "required": True},
            ],
            "returns": "bool",
        },
        "end": {
            "params": [
                {"name": "self", "kind": "self"},
                {"name": "conversation_id", "type": "string", "required": True},
            ],
            "returns": "bool",
        },
        "log": {
            "params": [
                {"name": "self", "kind": "self"},
                {"name": "conversation_id", "type": "string", "required": True},
            ],
            "returns": "class:signalwire.ai_chat.client.ChatLog",
        },
        "summarize": {
            "params": [
                {"name": "self", "kind": "self"},
                {"name": "conversation_id", "type": "string", "required": True},
                {"name": "summary_prompt", "type": "optional<string>", "required": False, "default": None},
            ],
            "returns": "string",
        },
        # The resolved endpoint URL. php declares it ``public readonly string
        # $url`` (AIChatClient.php:75) — the reference's ``self.url`` attribute
        # (ai_chat/client.py:152), which the oracle records as a zero-arg accessor.
        # The block comment above still says this property-method is "spurious" and
        # gets DROPPED: that rationale predates class B2 and is now WRONG (cpp's
        # AI-Chat projection carried the identical stale note). A caller supplies
        # ``url`` at construction, so it must be readable back.
        "url": {"params": [{"name": "self", "kind": "self"}], "returns": "any"},
    },
    "AIChatError": {
        "__init__": {
            "params": [
                {"name": "self", "kind": "self"},
                {"name": "code", "type": "optional<int>", "required": True},
                {"name": "message", "type": "string", "required": True},
            ],
            "returns": "void",
        },
        # The reference's public ``self.code`` / ``self.message`` attributes
        # (ai_chat/client.py:67-68), recorded by the oracle as zero-arg accessors.
        # php exposes them as ``getErrorCode()`` / ``getServerMessage()`` — it
        # CANNOT reuse the reference names because ``\RuntimeException`` already
        # declares ``getCode(): int`` (unwidenable to the reference's ``int|None``)
        # and ``getMessage(): string``. Spliced here to the oracle's own shape,
        # with the rename recorded in CLASS_METHOD_ALIASES so both gates agree.
        "code": {"params": [{"name": "self", "kind": "self"}], "returns": "any"},
        "message": {"params": [{"name": "self", "kind": "self"}], "returns": "any"},
    },
    # The three response records now carry their @dataclass PUBLIC FIELDS on the
    # oracle (wave-4 re-drift): each field is recorded as a zero-arg ``(self) ->
    # <type>`` accessor. PHP exposes each as a constructor-promoted ``public
    # readonly`` property carrying the SAME data; splice the field members here so
    # the SIGNATURE gate reconciles EQUAL (parallel to the surface field emit in
    # enumerate_surface.py). Field types mirror the oracle exactly.
    "ConversationInfo": {
        "__init__": {
            "params": [
                {"name": "self", "kind": "self"},
                {"name": "id", "type": "string", "required": True},
                {"name": "status", "type": "string", "required": True},
                {"name": "initial_message", "type": "optional<string>", "required": False, "default": None},
            ],
            "returns": "void",
        },
        "id": {"params": [{"name": "self", "kind": "self"}], "returns": "string"},
        "status": {"params": [{"name": "self", "kind": "self"}], "returns": "string"},
        "initial_message": {"params": [{"name": "self", "kind": "self"}], "returns": "optional<string>"},
    },
    "ChatResponse": {
        "__init__": {
            "params": [
                {"name": "self", "kind": "self"},
                {"name": "text", "type": "string", "required": True},
                {"name": "conversation_id", "type": "string", "required": True},
                {"name": "user_event", "type": "optional<dict<string,any>>", "required": False, "default": None},
            ],
            "returns": "void",
        },
        "text": {"params": [{"name": "self", "kind": "self"}], "returns": "string"},
        "conversation_id": {"params": [{"name": "self", "kind": "self"}], "returns": "string"},
        "user_event": {"params": [{"name": "self", "kind": "self"}], "returns": "optional<dict<string,any>>"},
    },
    "ChatLog": {
        "__init__": {
            "params": [
                {"name": "self", "kind": "self"},
                {"name": "messages", "type": "list<dict<string,any>>", "required": False, "default": "list()"},
                {"name": "call_timeline", "type": "list<dict<string,any>>", "required": False, "default": "list()"},
            ],
            "returns": "void",
        },
        "messages": {"params": [{"name": "self", "kind": "self"}], "returns": "list<dict<string,any>>"},
        "call_timeline": {"params": [{"name": "self", "kind": "self"}], "returns": "list<dict<string,any>>"},
    },
    # NOTE: the five code-mapped error subclasses (AuthenticationError/
    # ChatInProgressError/ConversationNotFoundError/RateLimitError/SummaryError)
    # inherit AIChatError's constructor and declare no own methods; the oracle
    # signatures OMIT them entirely (they are ABSENT, not empty shells). PHP
    # reflection likewise yields no own methods, so the enumerator's existing
    # "if not methods_out: continue" drops them before emit — no override needed
    # and no class shell must be manufactured.
}

_AICHAT_SIG_DROP = frozenset({
    "AuthenticationError", "ChatInProgressError", "ConversationNotFoundError",
    "RateLimitError", "SummaryError",
})




# Var-keyword drop — mirror the oracle's policy of dropping a bare ``**kwargs``
# var_keyword (enumerate_python_signatures.py: `if var_keyword: continue`). A
# hand-written PHP method whose ONLY declared param realizes Python's trailing
# ``**kwargs`` catch-all (as a single ``array $params``) enumerates that param,
# but the reference records NOTHING for it. Strip the named trailing param so the
# enumerated signature == the oracle's ``(self)``. Keyed by (full_php, native)
# → the trailing param name to drop.
#
# Scoped to the BedrockAgent no-op overrides of the AIConfigMixin setters: the
# reference declares them ``set_prompt_llm_params(self, **params) -> None`` /
# ``set_post_prompt_llm_params(self, **params) -> None`` (pure **kwargs), so the
# oracle records only ``self``. The PHP overrides take ``array $params`` (the
# **kwargs realization) — drop it to match. (The AIConfigMixin BASE copies keep
# a required ``array $params`` and are excused via PORT_SIGNATURE_OMISSIONS as
# the documented set_*_llm_params required-array idiom; these concrete no-op
# overrides return void/self and carry no distinct wire surface, so the
# oracle-mirror drop is exact.)
# Also scoped to the two HAND-WRITTEN REST read methods whose reference
# signatures are ``paginate(self, *, request_options=None, **params)`` and
# ``list_addresses(self, resource_id, *, request_options=None, **params)``
# (rest/_base.py:357 / :454). The oracle drops the bare ``**params`` and records
# ``request_options`` as the last param. PHP realizes that same query-door as a
# concrete ``array $params`` positioned BEFORE ``$requestOptions``, so reflection
# alone reports an extra param and — because the diff matches params BY POSITION
# — reads PHP's ``$params = []`` against the reference's
# ``request_options = None`` as a default-mismatch. This is the identical fold
# the GENERATED fabric resources already get for free: generate_rest.py's §5.3
# GET query door registers a sidecar of ``[...id..., request_options]`` and omits
# the ``array $params`` record, which is why CallFlows::listAddresses compares
# clean while the hand-written base does not. Dropping ``params`` here applies
# the same rule to the hand-written base; ``request_options`` still compares.
VAR_KEYWORD_DROP_METHODS: dict[tuple[str, str], str] = {
    ("SignalWire\\Agents\\BedrockAgent", "setPromptLlmParams"): "params",
    ("SignalWire\\Agents\\BedrockAgent", "setPostPromptLlmParams"): "params",
    ("SignalWire\\REST\\ReadResource", "paginate"): "params",
    ("SignalWire\\REST\\CrudWithAddresses", "listAddresses"): "params",
}


# Param-KIND remaps for params the reference declares KEYWORD-ONLY (Python
# ``*,``) that PHP can only express as a trailing optional positional. PHP has
# no keyword-only parameters — a caller reaches them via a NAMED ARGUMENT
# (``foo(bar: $x)``), which is the exact capability Python's ``*,`` grants — so
# the kind difference is pure idiom and is folded HERE, at the enumerator, not
# excused. This is the same fold the GENERATED REST resources already get from
# generate_rest.py's sidecar (which records ``"kind": "keyword"`` for
# ``request_options``); these entries extend it to the hand-written classes and
# to the two RELAY methods whose single option the reference keyword-gates.
# Keyed by (PHP fully-qualified class, PHP method name) -> {snake_param: kind}.
PARAM_KIND_REMAPS: dict[tuple[str, str], dict[str, str]] = {
    # rest/_base.py:357 / :454 — ``*, request_options=None``.
    ("SignalWire\\REST\\ReadResource", "paginate"): {
        "request_options": "keyword",
    },
    ("SignalWire\\REST\\CrudWithAddresses", "listAddresses"): {
        "request_options": "keyword",
    },
    # relay/call.py:620 — ``play_silence(duration, *, on_completed=None)``.
    ("SignalWire\\Relay\\Call", "playSilence"): {
        "on_completed": "keyword",
    },
    # relay/call.py:1567 — ``user_event(*, event=None, **kwargs)``.
    ("SignalWire\\Relay\\Call", "userEvent"): {
        "event": "keyword",
    },
}


# Return-type remaps for methods whose concrete return element type PHP
# reflection erases (bare ``array`` → ``any``) but the Python reference types
# concretely. Same rationale as PARAM_TYPE_REMAPS: the ``@return`` generic on
# the PHP source records the exact shape (phpstan L9 reads it), PHP reflection
# cannot. Re-establishing the concrete return here keeps a real future change
# surfacing as drift. Keyed by (PHP fully-qualified class, PHP method name) ->
# canonical return type string.
RETURN_TYPE_REMAPS: dict[tuple[str, str], str] = {
    # as_router() — Python's named "embed my routes in a host app" return type is
    # ``signalwire.core.web.HostAppRouter``; the signature oracle records it as the
    # return of both SWMLService.as_router and the projected WebMixin.as_router. PHP
    # has no framework router: asRouter() returns the Service itself (which
    # implements RequestHandlerLike — the mountable/dispatchable unit), so PHP
    # reflection sees ``$this`` → the concrete Service class. Record the canonical
    # named return type HERE so PHP reconciles EQUAL with Python's
    # ``as_router() -> HostAppRouter`` contract. HostAppRouter is a signature-only
    # named type (NOT in the surface oracle — it is deliberately not surfaced, exactly
    # like the ConversationRole/StringFormat aliases in PROPERTY_TYPE_REMAPS), so
    # referencing it here adds NO surface class and does not perturb SURFACE-DIFF.
    # The remap is applied during class enumeration (before mixin projection), so the
    # single entry flows to both SWMLService.as_router and WebMixin.as_router. Mirrors
    # go, whose port_signatures.json records the same HostAppRouter return.
    # Annotation-only: no new class, no surface change, no runtime change.
    ("SignalWire\\SWML\\Service", "asRouter"):
        "class:signalwire.core.web.HostAppRouter",
}


# Property-type remaps for GENERATED wire-type class fields whose schema $ref is
# a scalar string-Literal TypeAlias (ConversationRole, StringFormat). The
# reference SIGNATURE oracle (griffe) records the field type as the named alias
# (``class:…ConversationRole``) even though the alias itself is a scalar that the
# SURFACE oracle deliberately drops (so no PHP class is — or may be — emitted for
# it: surfacing one would break SURFACE-DIFF). PHP keeps the runtime property as
# a plain ``?string`` (the wire value IS a string constrained to the literal set)
# and records the canonical named-alias type HERE — exactly mirroring go's
# ``gen:"class:…ConversationRole"`` struct tag on a ``type ConversationRole string``
# field (go likewise does not surface the alias as a public type). Annotation-only:
# no new class, no surface change, no runtime change. Keyed by (PHP FQCN, property).
PROPERTY_TYPE_REMAPS: dict[tuple[str, str], str] = {
    ("SignalWire\\SWML\\Generated\\ConversationMessage", "role"):
        "class:signalwire.core.swml_verbs_generated.ConversationRole",
    ("SignalWire\\SWML\\Generated\\StringProperty", "format"):
        "class:signalwire.core.swml_verbs_generated.StringFormat",
    # RequestOptions envelope (plan 4.2). The oracle records the ``abort_signal``
    # field as the _AbortSignal protocol (anything with is_set()); PHP's public
    # ``$abortSignal`` is a ``callable|object|null`` (union -> ``any``). Record
    # the oracle's named protocol type here — _AbortSignal is a signature-only
    # reference (not surfaced), so this adds no port surface class.
    ("SignalWire\\REST\\RequestOptions", "abortSignal"):
        "optional<class:signalwire.rest._request_options._AbortSignal>",
}


# ---------------------------------------------------------------------------
# Construction contract (porting-sdk ALLOWLIST_DISCIPLINE.md §10)
# ---------------------------------------------------------------------------

# A construction param whose PHP spelling genuinely differs from the reference's.
# ADAPTER_CONTRACT rule 3: names are canonicalized to the reference spelling AT
# ADAPTER TIME, and a genuine rename is a RENAME-table entry — never an omission.
# Keyed by canonical "module.Class" (or ``None`` for the class-agnostic default),
# mapping the PHP-side canonical (already snake_case) name -> reference name.
#
# ``basic_auth_user`` / ``basic_auth_password``: the reference takes a single
# ``basic_auth: optional<tuple<string,string>>``. PHP has no tuple type, so the
# pair is expressed as two nullable strings — the same §7 typed-split row java
# has. Fold BOTH onto ``basic_auth`` so the capability compares present rather
# than reading as one missing param plus two extras. (First-listed wins; the
# password half is dropped as a duplicate of the same reference configurable.)
_CONSTRUCTION_PARAM_RENAMES: dict[str | None, dict[str, str]] = {
    None: {
        "basic_auth_user": "basic_auth",
        "basic_auth_password": "basic_auth",
    },
    # src/SignalWire/REST/HttpClient.php:62 — same two configurables, PHP spelling.
    # ``$projectId`` is the SignalWire project id (reference ``project``) and
    # ``$baseUrl`` is the API host the client talks to (reference ``host``).
    "signalwire.rest._base.HttpClient": {
        "project_id": "project",
        "base_url": "host",
    },
}

# The reference type for a param the rename table folds into a different shape
# than PHP's own reflected type (the typed-split above): PHP's ``?string`` halves
# reconstruct the reference's 2-tuple, so record the reference's type for the
# folded name rather than the half's.
_CONSTRUCTION_FOLDED_TYPE: dict[str, str] = {
    "basic_auth": "optional<tuple<string,string>>",
}

# Classes whose PHP constructor takes an untyped ``array $options`` / ``array
# $params`` bag. The bag KEYS are the real named configurables — that is PHP's
# options-object idiom — but reflection sees only ``array``, so the key set
# cannot be recovered mechanically. Declare it here, read off the constructor
# body, so the contract compares the actual capability instead of one opaque
# ``options`` param. Keyed by canonical "module.Class".
_OPTIONS_BAG_CONSTRUCTS: dict[str, dict[str, dict]] = {
    # The two MIXIN_PROJECTIONS targets that have no PHP class of their own:
    # Python extracted PromptManager/ToolRegistry out of AgentBase and constructs
    # each FROM the agent (``PromptManager(agent)``), while PHP keeps the same
    # capability on AgentBase and the enumerator projects the methods across
    # (see MIXIN_PROJECTIONS). Their construction contract is therefore that
    # same single ``agent`` handle — declared here so the projection is in
    # LOCKSTEP on the construction node too, not silently missing.
    "signalwire.core.agent.prompt.manager.PromptManager": {
        "agent": {"type": "class:signalwire.core.agent_base.AgentBase",
                  "required": True},
    },
    "signalwire.core.agent.tools.registry.ToolRegistry": {
        "agent": {"type": "class:signalwire.core.agent_base.AgentBase",
                  "required": True},
    },
    # src/SignalWire/Relay/Client.php::__construct — $options['<key>'] reads.
    "signalwire.relay.client.RelayClient": {
        "project": {"type": "optional<string>", "required": False},
        "token": {"type": "optional<string>", "required": False},
        "contexts": {"type": "optional<list<string>>", "required": False},
        "jwt_token": {"type": "optional<string>", "required": False},
        "host": {"type": "optional<string>", "required": False},
    },
    # src/SignalWire/Pom/Section.php::__construct(?string $title, array $params)
    # — the reference's four keyword-only params (body/bullets/numbered/
    # numberedBullets) are $params['<key>'] reads; $title stays a real param.
    "signalwire.pom.pom.Section": {
        "title": {"type": "optional<string>", "required": False},
        "body": {"type": "string", "required": False},
        "bullets": {"type": "optional<list<string>>", "required": False},
        "numbered": {"type": "optional<bool>", "required": False},
        "numberedBullets": {"type": "bool", "required": False},
    },
    # src/SignalWire/Relay/Message.php::__construct — $params['<key>'] reads.
    # ``id``/``from``/``to`` are accepted as wire-payload aliases of
    # message_id/from_number/to_number; the canonical spelling is recorded.
    "signalwire.relay.message.Message": {
        "message_id": {"type": "string", "required": False},
        "context": {"type": "string", "required": False},
        "direction": {"type": "string", "required": False},
        "from_number": {"type": "string", "required": False},
        "to_number": {"type": "string", "required": False},
        "body": {"type": "string", "required": False},
        "media": {"type": "optional<list<string>>", "required": False},
        "tags": {"type": "optional<list<string>>", "required": False},
        "state": {"type": "string", "required": False},
        "reason": {"type": "string", "required": False},
    },
}

# ``**kwargs``-forwarding subclasses (ALLOWLIST_DISCIPLINE.md §11, idiom-completion).
#
# The reference's prefab/derived agents declare only their OWN new params and then
# forward everything else to the base:
#
#     def __init__(self, venue_name, ..., name="concierge", route="/concierge",
#                  **kwargs):                       # prefabs/concierge.py:45-55
#         super().__init__(name=name, route=route, use_pom=True, **kwargs)
#
# So `host`, `port`, `basic_auth`, `auto_answer`, `record_call`, `use_pom`, … ARE
# configurable on the reference's ConciergeAgent — the oracle simply cannot expand
# `**kwargs`, so it records none of them. PHP has no `**kwargs`: the only way to
# offer the same capability is to RE-DECLARE the base params explicitly and pass
# them up. That is the idiomatic completion of the same contract member, not port-only
# surface, so those params are attributed to the BASE's construction entry (where they
# are already compared) rather than read as extras on the subclass.
#
# Keyed by canonical "module.Class" -> the canonical base whose construction contract
# absorbs the forwarded params. Verified against the reference source: every entry's
# reference twin ends its ``__init__`` with ``super().__init__(..., **kwargs)``.
_KWARGS_FORWARDING_BASE: dict[str, str] = {
    "signalwire.agents.bedrock.BedrockAgent": "signalwire.core.agent_base.AgentBase",
    "signalwire.prefabs.concierge.ConciergeAgent": "signalwire.core.agent_base.AgentBase",
    "signalwire.prefabs.faq_bot.FAQBotAgent": "signalwire.core.agent_base.AgentBase",
    "signalwire.prefabs.info_gatherer.InfoGathererAgent": "signalwire.core.agent_base.AgentBase",
    "signalwire.prefabs.receptionist.ReceptionistAgent": "signalwire.core.agent_base.AgentBase",
    "signalwire.prefabs.survey.SurveyAgent": "signalwire.core.agent_base.AgentBase",
}

def _load_reference_construction() -> dict:
    """The reference's ``construction`` node — the ONLY authority on whether the
    oracle flattened a base class's params into a subclass.

    Raises rather than defaulting: a missing node would silently turn every
    oracle-gated decision below into a guess, and a wrong guess here fabricates
    or deletes contract params. Fail loud instead.
    """
    path = PSDK / "python_signatures.json"
    data = json.loads(path.read_text(encoding="utf-8"))
    node = data.get("construction")
    if not node:
        raise RuntimeError(
            f"{path} has no `construction` node — cannot decide per class whether "
            f"the reference flattens a base's params into its subclass. Refusing "
            f"to guess (ALLOWLIST_DISCIPLINE.md §10)."
        )
    return node


def _oracle_flattens(ref_construction: dict, cls_key: str, base_key: str) -> set[str]:
    """Return the base params the ORACLE records on ``cls_key`` itself.

    This is the split the ruby lane proved cannot be assumed, because the
    reference has TWO subclass shapes and the oracle records them differently:

      * ``@dataclass``-style — Python flattens the base's fields into the
        generated ``__init__`` and the oracle records ALL of them (php's Action
        subclasses: the oracle records ``call`` + ``control_id`` on AIAction even
        though its Python ``__init__`` adds only ``control_id``). The port's
        inherited-ctor params are contract here — FOLD IN.
      * ``**kwargs``-forwarding — the reference ALSO calls ``super().__init__``,
        but the oracle records ONLY the child's own params (the 6 agent
        subclasses record just ``name``/``route`` of AgentBase's 22). The port's
        re-declared base params belong to the BASE's entry — DO NOT fold in;
        folding there fabricates ~20 bogus extras per subclass.

    Only the oracle can answer it: the split lives in the REFERENCE's shapes, not
    in anything observable from PHP. Fails loud if either class is unknown to the
    reference, rather than silently choosing a direction.
    """
    if cls_key not in ref_construction:
        raise RuntimeError(
            f"construction: {cls_key} is not in the reference construction node; "
            f"cannot determine whether the oracle flattens {base_key} into it."
        )
    if base_key not in ref_construction:
        raise RuntimeError(
            f"construction: base {base_key} is not in the reference construction "
            f"node; cannot compute its overlap with {cls_key}."
        )
    return set(ref_construction[cls_key]["params"]) & set(
        ref_construction[base_key]["params"]
    )

# Classes whose constructor mixes REAL named params with an untyped bag carrying
# the rest. Unlike _OPTIONS_BAG_CONSTRUCTS (which REPLACES the reflected set), these
# entries MERGE: the reflected named params stay, the bag's opaque ``array`` param is
# dropped, and its keys are added. Keyed by canonical "module.Class" ->
# (reflected param name to drop, {key: spec}).
_PARTIAL_BAG_CONSTRUCTS: dict[str, tuple[str, dict[str, dict]]] = {
    # src/SignalWire/Prefabs/ConciergeAgent.php:41 — ``array $venueInfo`` carries
    # exactly the reference's six venue params as $venueInfo['<key>'] reads
    # (lines 53-104); name/route/host/... stay real named params.
    "signalwire.prefabs.concierge.ConciergeAgent": ("venue_info", {
        "venue_name": {"type": "string", "required": True},
        "services": {"type": "list<string>", "required": True},
        "amenities": {"type": "dict<string,dict<string,string>>", "required": True},
        "hours_of_operation": {"type": "optional<dict<string,string>>", "required": False},
        "special_instructions": {"type": "optional<list<string>>", "required": False},
        "welcome_message": {"type": "optional<string>", "required": False},
    }),
}

# Constructor params that are PLUMBING, not configurable capability: a handle the
# SDK itself threads in when it builds the object (the owning client, the HTTP
# transport, the parent call's ids). They are not something a user configures, so
# they are not part of the construction contract in any port.
_CONSTRUCTION_NON_PARAMS = frozenset({"self", "cls"})


def build_construction(
    ctor_params: dict[str, list[dict]],
    php_to_canonical: dict[str, str],
    php_parents: dict[str, list[str]],
) -> dict:
    """Return ``{"module.Class": {"params": {name: {type, required}}}}``.

    A NAME-KEYED, unordered SET of configurable construction parameters — see
    porting-sdk ALLOWLIST_DISCIPLINE.md §10. Order, arity, and mechanism are
    idiom; the named set is the capability. This is why the node exists
    separately from ``modules``: ``compare_param`` matches BY POSITION and
    ignores names, which is meaningless against a 22-param kwargs constructor,
    so one blanket ``__init__`` omission used to hide every parameter at once.

    Three sources, in precedence order:

      1. ``_OPTIONS_BAG_CONSTRUCTS`` — the class's ctor takes an untyped
         ``array $options``; the bag's keys are the configurables and cannot be
         reflected, so they are declared.
      2. the class's OWN ``__construct`` params (PHP 8 named arguments ARE the
         named set — no builder needed).
      3. the nearest ANCESTOR that declares a ``__construct``, because a PHP
         subclass with no constructor of its own inherits the parent's, and a
         caller genuinely configures it through those same names.

    Names are canonicalized to the reference spelling via
    ``_CONSTRUCTION_PARAM_RENAMES`` (ADAPTER_CONTRACT rule 3). ``required``
    mirrors the PHP signature and is compared as contract: a port that makes a
    defaulted reference param required breaks a valid reference program, and one
    that defaults a required param silently accepts an under-specified
    construction.
    """
    out: dict = {}

    def _params_from(raw_params: list[dict], cls_key: str) -> dict:
        renames = dict(_CONSTRUCTION_PARAM_RENAMES.get(None, {}))
        renames.update(_CONSTRUCTION_PARAM_RENAMES.get(cls_key, {}))
        params: dict = {}
        for p in raw_params:
            if not isinstance(p, dict):
                continue
            if (p.get("kind") or "positional") in _CONSTRUCTION_NON_PARAMS:
                continue
            if p.get("kind") in ("var_keyword", "var_positional"):
                continue
            name = p.get("name")
            if not name or name.startswith("_"):
                continue
            name = renames.get(name, name)
            if name in params:
                # A typed-split fold (two PHP params -> one reference param):
                # the halves describe one configurable, so keep the first.
                continue
            params[name] = {
                "type": _CONSTRUCTION_FOLDED_TYPE.get(name, p.get("type", "any")),
                "required": bool(p.get("required", True)),
            }
        return params

    # Short name -> the FQNs declaring it, for resolving the short-named parent
    # chain signature_dump.php emits.
    by_short: dict[str, list[str]] = {}
    for fqn in php_to_canonical:
        by_short.setdefault(fqn.rsplit("\\", 1)[-1], []).append(fqn)

    def _resolve_parent(child_fqn: str, parent_short: str) -> str | None:
        """Resolve a short-named parent to its FQN.

        Same-namespace first (PHP resolves an unqualified extends against the
        current namespace), then a globally unique match. An ambiguous
        cross-namespace short name resolves to nothing rather than guessing —
        the generated REST Types tree repeats ~300 class names, and picking one
        arbitrarily would attach the wrong constructor.
        """
        candidates = by_short.get(parent_short, [])
        if len(candidates) == 1:
            return candidates[0]
        ns = child_fqn.rsplit("\\", 1)[0] if "\\" in child_fqn else ""
        same_ns = [c for c in candidates if c.rsplit("\\", 1)[0] == ns]
        return same_ns[0] if len(same_ns) == 1 else None

    def _inherited_key(php_fqn: str) -> str | None:
        """Nearest ancestor that declares its own constructor, canonically keyed."""
        for parent_short in php_parents.get(php_fqn, []):
            parent_fqn = _resolve_parent(php_fqn, parent_short)
            if parent_fqn is None:
                continue
            pk = php_to_canonical.get(parent_fqn)
            if pk and pk in ctor_params:
                return pk
        return None

    # Declared bags first — some (the projection targets) have no PHP class of
    # their own, so they are not reachable from php_to_canonical.
    for cls_key, bag in _OPTIONS_BAG_CONSTRUCTS.items():
        out[cls_key] = {"params": {k: dict(v) for k, v in sorted(bag.items())}}

    for php_name, cls_key in php_to_canonical.items():
        if cls_key in _OPTIONS_BAG_CONSTRUCTS:
            continue
        raw = ctor_params.get(cls_key)
        if raw is None:
            inherited = _inherited_key(php_name)
            if inherited is None:
                continue
            raw = ctor_params[inherited]
        params = _params_from(raw, cls_key)
        partial = _PARTIAL_BAG_CONSTRUCTS.get(cls_key)
        if partial is not None:
            drop_name, bag_keys = partial
            params.pop(drop_name, None)
            for k, v in bag_keys.items():
                params.setdefault(k, dict(v))
        if params:
            out[cls_key] = {"params": dict(sorted(params.items()))}

    # §11 idiom-completion fold, ORACLE-GATED: a ``**kwargs``-forwarding
    # subclass's re-declared base params belong to the BASE's construction entry,
    # where they are already compared. Which base params the subclass KEEPS is not
    # assumed — it is read from the reference construction node per class, because
    # only the oracle knows whether the reference flattened the base into this
    # subclass (see _oracle_flattens). Applied after the main pass so the base
    # entry is populated.
    ref_construction = _load_reference_construction()
    for cls_key, base_key in _KWARGS_FORWARDING_BASE.items():
        entry = out.get(cls_key)
        base = out.get(base_key)
        if not entry or not base:
            continue
        keep = _oracle_flattens(ref_construction, cls_key, base_key)
        base_names = set(base["params"])
        entry["params"] = {
            n: v for n, v in entry["params"].items()
            if n not in base_names or n in keep
        }

    return dict(sorted(out.items()))


def collect(raw: dict, aliases: dict, rest_sidecar: dict[str, list[dict]] | None = None) -> tuple[dict, list]:
    if rest_sidecar is None:
        rest_sidecar = {}
    out_modules: dict = {}
    failures: list = []
    # Construction-contract bookkeeping (ALLOWLIST_DISCIPLINE.md §10). Filled as
    # classes are processed, consumed by build_construction() at the end:
    #   ctor_params  canonical "module.Class" -> the class's OWN __init__ params
    #                (captured HERE, before the mixin projection can move or pop
    #                __init__ off AgentBase — see build_construction docstring)
    #   class_parents  canonical "module.Class" -> [canonical parent, ...], so a
    #                subclass that declares no __construct inherits the parent's
    #                construction parameters, which is exactly PHP's semantics.
    ctor_params: dict[str, list[dict]] = {}
    php_to_canonical: dict[str, str] = {}
    php_parents: dict[str, list[str]] = {}

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
                # Class-scoped accessor rename (getter -> reference attribute
                # name), scoped to the declaring PHP class. Mirrors the surface
                # enumerator's CLASS_METHOD_ALIASES so both gates rename in lockstep.
                method_canonical = CLASS_METHOD_ALIASES.get(
                    (php_name, method_canonical), method_canonical)

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
                ret_override = FREE_FUNCTION_RETURN_OVERRIDES.get((target_mod, target_fn))
                if ret_override is not None:
                    sig["returns"] = ret_override
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
            # Keyword-only kind remap: PHP reaches a Python ``*,`` param via a
            # NAMED ARGUMENT, the same capability — fold the kind (see
            # PARAM_KIND_REMAPS). Matched on the reflected (snake) param name.
            kind_remap = PARAM_KIND_REMAPS.get((full_php, native))
            if kind_remap:
                for prm in sig.get("params", []):
                    new_kind = kind_remap.get(prm.get("name", ""))
                    if new_kind is not None:
                        prm["kind"] = new_kind
            # Concrete-collection return remap: re-establish the return element
            # type where PHP's bare ``array`` erased it (see RETURN_TYPE_REMAPS).
            ret_remap = RETURN_TYPE_REMAPS.get((full_php, native))
            if ret_remap is not None:
                sig["returns"] = ret_remap
            # Var-keyword drop: mirror the oracle dropping a bare ``**kwargs``.
            # Strip the named trailing param that realizes Python's **kwargs so
            # the enumerated signature == the oracle's ``(self)``.
            drop_name = VAR_KEYWORD_DROP_METHODS.get((full_php, native))
            if drop_name is not None:
                sig["params"] = [
                    p for p in sig.get("params", [])
                    if not (p.get("kind") != "self" and p.get("name") == drop_name)
                ]
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
                # Path-routed generated wire-type classes (types_mod set: the
                # REST Types / SWML-verbs / RELAY-proto / SWAIG-payload modules)
                # carry the EXACT wire-key field name on their PHP property
                # ($SWAIG, $allOf, $oneOf, $anyOf, $numberedBullets, …). The
                # reference oracle (griffe over the generated TypedDicts) records
                # those field names verbatim — NOT snake-folded — so a blanket
                # camel_to_snake would mangle them (SWAIG→swaig, allOf→all_of)
                # into a spurious missing-port drift. Preserve verbatim for these
                # generated classes; hand-written SDK classes still snake-fold.
                if types_mod is not None:
                    snake = pname
                else:
                    snake = camel_to_snake(pname)
                method_canonical = METHOD_ALIASES.get(snake, snake)
                # Class-scoped rename, same table the METHOD path uses. A public
                # PROPERTY is a reader too, so a property whose php spelling was
                # forced off the reference name (RelayError::$relayCode, because
                # \Throwable already declares getCode()) folds onto the reference
                # attribute here — a rename keeps comparing (AGENT_RULES §2).
                method_canonical = CLASS_METHOD_ALIASES.get(
                    (php_name, method_canonical), method_canonical)
                if method_canonical in methods_out:
                    continue
                ctx = f"{mod}.{canonical_name}.{method_canonical}"
                try:
                    ret = translate_php_type(p.get("type", "mixed"), aliases, ctx + "[->]")
                except TypeTranslationError as e:
                    failures.append(str(e))
                    continue
                prop_remap = PROPERTY_TYPE_REMAPS.get((full_php, pname))
                if prop_remap is not None:
                    ret = prop_remap
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

        # Flatten the inherited ReadResource::paginate() onto each concrete
        # read-only resource. PHP reflection only reports own-declared methods,
        # so paginate() (defined once on the ReadResource base) is invisible per
        # subclass — but the Python reference oracle records it per-subclass on
        # exactly the classes whose DIRECT base is ReadResource (the read-only
        # resources: FabricAddresses/FaxLogs/MessageLogs/VideoRoomSessions/
        # VoiceLogs — NOT the CrudResource/FabricResource subclasses). Mirror
        # that: inject the real inherited method where the direct parent is
        # ReadResource. (list/get are crud_base-satisfied by the diff; paginate
        # is outside _CRUD_METHODS so it needs the explicit per-subclass entry.)
        parents = type_entry.get("parents", [])
        if (
            parents
            and parents[0] == "ReadResource"
            and mod.endswith("_resources_generated")
            and "paginate" not in methods_out
        ):
            methods_out["paginate"] = {
                "params": [
                    {"name": "self", "kind": "self"},
                    # The inherited ReadResource::paginate(array $params = [],
                    # ?RequestOptions $requestOptions = null) forwards a per-call
                    # transport override to every page fetch. The reference oracle
                    # records paginate as (self, request_options) — the bare
                    # **params var_keyword is dropped — so mirror exactly that.
                    {
                        "name": "request_options", "kind": "keyword",
                        "type": "optional<class:signalwire.rest._request_options.RequestOptions>",
                        "required": False, "default": None,
                    },
                ],
                "returns": "class:signalwire.rest._pagination.PaginatedIterator",
            }

        # AI-Chat whole-signature fold: when this class is an AIChat class,
        # REPLACE the reflected methods_out with the canonical oracle signatures
        # (splice) — dropping the spurious ``url`` property-method and adding the
        # ``close`` lifecycle member — so every AIChat method folds to zero drift
        # (AGENT_RULES §2). No AI-Chat signature omission remains.
        if mod == "signalwire.ai_chat.client":
            # The five code-mapped AIChat error subclasses inherit AIChatError's
            # constructor and define nothing of their own; the oracle signatures
            # OMIT them entirely. PHP reflection reports the inherited __construct,
            # so drop the class shell here to match the reference (their SURFACE
            # presence — method-less — is emitted by enumerate_surface.py).
            if canonical_name in _AICHAT_SIG_DROP:
                continue
            if canonical_name in AICHAT_SIGNATURES:
                methods_out = dict(AICHAT_SIGNATURES[canonical_name])
        out_modules.setdefault(mod, {"classes": {}})
        out_modules[mod]["classes"][canonical_name] = {
            "methods": dict(sorted(methods_out.items())),
        }

        # Construction contract: remember this class's OWN constructor params and
        # its inheritance edge, keyed canonically. Recorded here (not re-derived
        # from out_modules later) because the mixin projection below both POPS
        # AgentBase's __init__ and re-hosts it on PromptManager.
        canonical_key = f"{mod}.{canonical_name}"
        # Keyed by PHP FQN — a SHORT name is ambiguous (the generated REST Types
        # tree repeats ~300 names across the per-namespace subtrees). ``parents``
        # from signature_dump.php are short names, so build_construction resolves
        # them namespace-first (see _resolve_parent).
        php_to_canonical[full_php] = canonical_key
        php_parents[full_php] = [
            p for p in (type_entry.get("parents") or []) if isinstance(p, str)
        ]
        own_init = next(
            (m for m in type_entry.get("methods", []) if m.get("name") == "__construct"),
            None,
        )
        if own_init is not None and "__init__" in methods_out:
            ctor_params[canonical_key] = methods_out["__init__"].get("params", [])

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
        # A projection SOURCE is looked up under its php spelling but the TARGET
        # names are reference spellings, so a member php exposes via an accessor
        # (AgentBase::getAgent standing in for PromptManager.agent) must be
        # matched under the FOLDED name or the projection silently drops it.
        # Keyed by the TARGET class, whose reference member set defines the names.
        # Mirrors the surface enumerator's ``_projection_source``.
        def _fold_source(src: dict, target: tuple[str, str]) -> dict:
            renames = ORACLE_ACCESSOR_FOLD.get(target)
            if not renames:
                return src
            out = dict(src)
            for acc, field in renames.items():
                if acc in src and field not in out:
                    out[field] = src[acc]
            return out

        for (target_mod, target_cls), (source_cls, expected) in MIXIN_PROJECTIONS.items():
            _tgt = (target_mod, target_cls)
            _ab = _fold_source(ab_methods, _tgt)
            _sm = _fold_source(sm_methods, _tgt)
            primary = _sm if source_cls == "SWMLService" else _ab
            secondary = _ab if source_cls == "SWMLService" else _sm
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

    # ORACLE-GATED ACCESSOR FOLD — the SIGNATURE twin of the surface enumerator's
    # ``_fold_accessors``. PHP exposes a reference ATTRIBUTE either as a bare public
    # property (which signature_dump.php already reflects under the bare name) or as
    # a ``getX()`` accessor over a private field; the accessor IS the read-back, so it
    # folds onto the reference attribute name. A rename keeps comparing; an omission
    # is a permanent blind spot (AGENT_RULES §2).
    #
    # Derived from the shared ``ORACLE_ACCESSOR_FOLD`` table IMPORTED from
    # enumerate_surface — not a second copy. A rename table in one gate and not its
    # twin folds a name in one and reds it in the other (go shipped exactly that).
    #
    # Applied here on the EMITTED tree, keyed by (emitted module, emitted class), so
    # the key space matches the lookup space — the keying bug typescript and go both
    # paid for. A fold that would COLLIDE with a member the port already records
    # under the reference name is skipped (the bare property already satisfies it;
    # the accessor stays a distinct member rather than silently overwriting it).
    for _mod, _entry in out_modules.items():
        for _cls, _ce in _entry.get("classes", {}).items():
            _renames = ORACLE_ACCESSOR_FOLD.get((_mod, _cls))
            if not _renames:
                continue
            _methods = _ce.get("methods")
            if not isinstance(_methods, dict):
                continue
            for _acc, _field in _renames.items():
                if _acc in _methods and _field not in _methods:
                    _methods[_field] = _methods.pop(_acc)

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
        "construction": build_construction(ctor_params, php_to_canonical, php_parents),
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
