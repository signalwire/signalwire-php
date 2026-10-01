#!/usr/bin/env python3
"""Generate the typed SWAIG read-side payload surface for signalwire-php.

This is the PHP realization of SESSION_CHANGESET_FOR_PORTS.md item D1 — the three
``signalwire.core.*_generated`` SWAIG payload modules — mirroring python's
``generate_swaig_request`` / ``generate_post_prompt`` / ``generate_swaig_actions``
(in porting-sdk/scripts/generate_python_rest_types.py), go's ``pkg/swaig/*_generated.go``
and TS's ``SwaigContracts.generated.ts`` / ``SwaigActions.generated.ts``.

Source: the vendored porting-sdk ``swaig-specs/*.yaml`` (VENDORED from mod_openai —
the authoritative SWAIG wire spec):

  * ``swaig-request.yaml``  -> signalwire.core.swaig_request_generated  (2 classes)
        SwaigRequest (+ the inline ``argument`` object lifted to SwaigArgument).
  * ``post-prompt.yaml``    -> signalwire.core.post_prompt_generated    (14 classes)
        one class per components/schemas OBJECT schema; the ``PostPromptCallLogEntry``
        oneOf alias is NOT surfaced (the reference records it as a module-level
        TypeAlias its enumerator drops), so 15 schemas - 1 alias = 14.
  * ``swaig-response.yaml`` -> signalwire.core.swaig_actions_generated  (6 classes)
        one ``<Action>`` class per action key whose value is an object-with-properties
        (a bare object OR an object variant of a oneOf): context_switch ->
        ContextSwitchAction, hold -> HoldAction, playback_bg -> PlaybackBgAction,
        transfer -> TransferAction, PLUS the two ENVELOPE schemas SwaigAction and
        SwaigResponse. The ergonomic ``_SwaigActions`` builder base is NOT emitted —
        it is ``_``-prefixed in the reference and outside the surface oracle.

  2 + 14 + 6 = 22 classes == the surface oracle EXACTLY (0 missing / 0 extra).

Every emitted class is a method-less PHP data DTO: public typed nullable properties
carrying the snake wire key, no methods. The emit/drop rule + property typing reuse
the SHARED helpers from generate_rest.py (is_object_schema, type_name,
php_property_type, php_property_name) exactly like generate_swml_verbs.py /
generate_relay_protocol.py, so the generators never diverge on the emit rule.

These are READ-side payloads (open shapes; no ``extras`` door). Property types are
pure idiom — the surface records only the class name; the signature enumerator emits
each property as a zero-arg member and the diff tool's gen-payload fold canonicalizes
the module, so a class-typed field (PostPrompt.call_log, SwaigRequest.argument, …)
compares by (class, field), while a scalar field is excused as a port-side state
accessor. Nothing is emitted for scalar/array/union aliases.

Output layout: one class per file (PSR-4) under a per-module subdir
  src/SignalWire/SWAIG/Generated/PostPrompt/<ClassName>.php     (namespace ...\\PostPrompt)
  src/SignalWire/SWAIG/Generated/SwaigRequest/<ClassName>.php   (namespace ...\\SwaigRequest)
  src/SignalWire/SWAIG/Generated/SwaigActions/<ClassName>.php   (namespace ...\\SwaigActions)
so the surface + signature enumerators route each subdir BY PATH to its
``signalwire.core.<post_prompt|swaig_request|swaig_actions>_generated`` oracle module
(winning over CLASS_MODULE_MAP so the hand-written SWAIG SDK classes — FunctionResult,
ParameterSchema, RecordFormat — one/two levels up are never misrouted, and a payload
type name that also exists elsewhere lands in the right module).

Usage:
    python3 scripts/generate_swaig_payloads.py            # write into the repo tree
    python3 scripts/generate_swaig_payloads.py --check    # GEN-FRESH: fail if stale
    python3 scripts/generate_swaig_payloads.py --out DIR  # scratch: emit into DIR
"""

from __future__ import annotations

import argparse
import importlib.util
import re
import sys
from pathlib import Path


# ---------------------------------------------------------------------------
# Reuse the shared emit helpers from generate_rest.py (is_object_schema,
# type_name, php_property_type, php_property_name). Import by path so the
# generators never diverge on the emit rule — exactly like generate_swml_verbs.py
# and generate_relay_protocol.py.
# ---------------------------------------------------------------------------


def _load_rest_generator():
    here = Path(__file__).resolve().parent
    spec = importlib.util.spec_from_file_location(
        "generate_rest", here / "generate_rest.py"
    )
    if spec is None or spec.loader is None:  # pragma: no cover
        raise SystemExit("generate_swaig_payloads.py: cannot load generate_rest.py")
    mod = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(mod)
    return mod


GR = _load_rest_generator()


def resolve_porting_sdk() -> Path:
    return GR.resolve_porting_sdk()


def repo_root() -> Path:
    return Path(__file__).resolve().parents[1]


def _load_yaml(path: Path) -> dict:
    import yaml  # type: ignore[import-untyped]

    return yaml.safe_load(path.read_text())


# ---------------------------------------------------------------------------
# Emit.
# ---------------------------------------------------------------------------

SWAIG_HEADER = """<?php

declare(strict_types=1);

// Code generated by scripts/generate_swaig_payloads.py; DO NOT EDIT.
//
// AUTO-GENERATED from porting-sdk/swaig-specs/{spec} — regenerate with:
//   python3 scripts/generate_swaig_payloads.py
//
// {desc}

namespace SignalWire\\SWAIG\\Generated\\{sub};
"""


def _emit_class(
    php_name: str,
    properties: dict,
    schemas: dict,
    spec: str,
    sub: str,
    source_desc: str,
) -> str:
    """Emit one method-less PHP data class for a SWAIG payload object schema. Property
    typing reuses generate_rest.php_property_type (pure idiom — the surface records
    only the class name; types keep the DTO PHPStan-L9-clean). Property types never
    reference another generated class (mirrors the REST/SWML/RELAY wire-type emitters:
    an object / $ref-to-object / union field collapses to ``?array``)."""
    lines: list[str] = []
    lines.append("/**")
    lines.append(f" * {php_name} — generated SWAIG payload wire type ({source_desc}).")
    lines.append(" *")
    lines.append(
        " * Pure data DTO: public typed properties named for the snake_case wire keys"
    )
    lines.append(
        " * they carry. It declares no methods — the values ARE the interface."
    )
    lines.append(" */")
    lines.append(f"class {php_name}")
    lines.append("{")
    body: list[str] = []
    used: set[str] = set()
    for wire_key, psc in properties.items():
        prop = GR.php_property_name(wire_key)
        while prop in used:
            prop += "_"
        used.add(prop)
        php_type, doc = GR.php_property_type(
            psc if isinstance(psc, dict) else {}, schemas
        )
        if doc is not None:
            body.append(f"    /** @var {doc} */")
        elif prop != wire_key:
            body.append(f"    /** wire key: {wire_key} */")
        body.append(f"    public {php_type} ${prop} = null;")
        body.append("")
    if body and body[-1] == "":
        body.pop()
    lines.extend(body)
    lines.append("}")
    desc = f"Generated SWAIG payload wire type from porting-sdk/swaig-specs/{spec}."
    return (
        SWAIG_HEADER.format(spec=spec, desc=desc, sub=sub)
        + "\n"
        + "\n".join(lines)
        + "\n"
    )


# ---------------------------------------------------------------------------
# Per-spec builders. Each returns a dict {relative_path: source} where the path is
# ``<Sub>/<Class>.php`` (the PSR-4 subdir routes the enumerators to the oracle module).
# ---------------------------------------------------------------------------


def _build_swaig_request(psdk: Path) -> dict[str, str]:
    """swaig-request.yaml -> SwaigRequest (+ lifted SwaigArgument). Mirrors python's
    generate_swaig_request: the inline ``argument`` object is lifted to a named
    SwaigArgument data class; SwaigRequest itself is the top-level object schema."""
    spec_file = "swaig-request.yaml"
    sub = "SwaigRequest"
    spec = _load_yaml(psdk / "swaig-specs" / spec_file)
    schema = spec["components"]["schemas"]["SwaigRequest"]
    props = schema.get("properties", {})

    outs: dict[str, str] = {}

    # Lift the inline ``argument`` object to a named SwaigArgument class (matches the
    # reference; it is the one nested object worth a name).
    arg = props.get("argument")
    if isinstance(arg, dict) and arg.get("properties"):
        outs[f"{sub}/SwaigArgument.php"] = _emit_class(
            "SwaigArgument",
            arg["properties"],
            {},
            spec_file,
            sub,
            "inline swaig-request `argument` object",
        )

    outs[f"{sub}/SwaigRequest.php"] = _emit_class(
        "SwaigRequest",
        props,
        {},
        spec_file,
        sub,
        "swaig-request `SwaigRequest` schema",
    )
    return outs


def _build_post_prompt(psdk: Path) -> dict[str, str]:
    """post-prompt.yaml -> one class per components/schemas OBJECT schema. The
    ``PostPromptCallLogEntry`` oneOf alias (and any other non-object combinator) is
    NOT surfaced — the reference records it as a module-level TypeAlias its enumerator
    drops, so emitting nothing matches the reference surface (15 schemas - 1 alias = 14)."""
    spec_file = "post-prompt.yaml"
    sub = "PostPrompt"
    spec = _load_yaml(psdk / "swaig-specs" / spec_file)
    schemas = spec["components"]["schemas"]

    outs: dict[str, str] = {}
    emitted: set[str] = set()
    for raw_name, node in schemas.items():
        if not isinstance(node, dict):
            continue
        # Same object-vs-alias split as the REST/SWML/RELAY wire-type emitters: an
        # object WITH properties -> data class; a oneOf/anyOf/allOf combinator or a
        # scalar/array -> a TypeAlias the reference enumerator drops (emit nothing).
        if not GR.is_object_schema(node):
            continue
        php_name = GR.type_name(raw_name)
        if php_name in emitted:
            continue
        emitted.add(php_name)
        outs[f"{sub}/{php_name}.php"] = _emit_class(
            php_name,
            node.get("properties") or {},
            schemas,
            spec_file,
            sub,
            f"post-prompt components/schemas {raw_name!r}",
        )
    return outs


def _build_swaig_actions(psdk: Path) -> dict[str, str]:
    """swaig-response.yaml -> one ``<Action>`` class per action key whose value is an
    object-with-properties (a bare object OR the object variant(s) of a oneOf).
    Mirrors python's generate_swaig_actions ``_value_type`` object-lift: only inline
    object variants become named classes; scalar/const/array branches and the
    ``items`` object of an array-typed action (toggle_functions) do NOT.

    The naming mirrors the reference exactly: the FIRST object variant is
    ``<Verb>Action``; a second object variant would be ``<Verb>Action2`` (none occur
    in the current spec).

    The ``SwaigAction`` / ``SwaigResponse`` ENVELOPE schemas ARE emitted too (see the
    envelope block at the end). They were not, on the reading that they sat outside
    the cross-port surface oracle; porting-sdk 4ddda70 made that false by teaching the
    reference generator to resolve post-prompt.yaml's cross-file $ref into them, so
    both now carry oracle members and their absence read as 5 missing-port drifts. The
    ergonomic ``_SwaigActions`` builder base stays unemitted — it is ``_``-prefixed in
    the reference and genuinely outside the surface."""
    spec_file = "swaig-response.yaml"
    sub = "SwaigActions"
    spec = _load_yaml(psdk / "swaig-specs" / spec_file)
    actions = spec["components"]["schemas"]["SwaigAction"]["properties"]

    def _is_obj(s: object) -> bool:
        return (
            isinstance(s, dict)
            and s.get("type") == "object"
            and bool(s.get("properties"))
        )

    outs: dict[str, str] = {}
    emitted: set[str] = set()
    # verb -> the FIRST lifted <Verb>Action class, for the SwaigAction envelope below.
    lifted: dict[str, str] = {}
    for verb in sorted(actions):
        schema = actions[verb]
        if not isinstance(schema, dict):
            continue
        # The object variants of this action's value: a bare object-with-properties,
        # or the object branch(es) of a oneOf. (An array-typed action's inline
        # ``items`` object is NOT a top-level value variant — matches the reference.)
        branches = schema.get("oneOf") or ([schema] if _is_obj(schema) else [])
        obj_i = 0
        for b in branches:
            if not _is_obj(b):
                continue
            obj_i += 1
            action_name = (
                _pascal_verb(verb) + "Action" + ("" if obj_i == 1 else str(obj_i))
            )
            php_name = GR.type_name(action_name)
            if php_name in emitted:
                continue
            emitted.add(php_name)
            lifted.setdefault(verb, php_name)
            outs[f"{sub}/{php_name}.php"] = _emit_class(
                php_name,
                b.get("properties") or {},
                {},
                spec_file,
                sub,
                f"swaig-response action {verb!r} value object",
            )

    # The response ENVELOPE types, mirroring the reference's generate_swaig_actions.
    # SwaigAction is the action OBJECT (one or more action keys set at once; the
    # engine dispatches every recognized key) and SwaigResponse is the
    # {response, action, post_process} body a handler returns. They live in THIS
    # module because it owns swaig-response.yaml — which is what makes
    # ``swaig-response.yaml#/components/schemas/SwaigResponse`` resolvable from
    # post-prompt.yaml's post_response / delayed_post_response.
    action_fields: list[tuple[str, str, str | None]] = []
    for verb in sorted(actions):
        schema = actions[verb]
        if not isinstance(schema, dict):
            continue
        php_type, doc = _envelope_property_type(schema, lifted.get(verb))
        action_fields.append((verb, php_type, doc))
    outs[f"{sub}/SwaigAction.php"] = _emit_envelope_class(
        "SwaigAction",
        action_fields,
        spec_file,
        sub,
        "swaig-response `SwaigAction` envelope schema",
    )

    # SwaigResponse.action is `SwaigAction | list[SwaigAction]` in the reference. PHP
    # has no generic array TYPE HINT, so the list branch is a native `array` carrying a
    # `list<SwaigAction>` PHPDoc — which is also what PHPStan L9 requires (a bare
    # `array` is missingType.iterableValue). The native union still NAMES SwaigAction,
    # which is what the oracle keys on; the docblock is what keeps the element type.
    resp = spec["components"]["schemas"]["SwaigResponse"]
    resp_fields: list[tuple[str, str, str | None]] = []
    for wire_key, psc in (resp.get("properties") or {}).items():
        if wire_key == "action":
            resp_fields.append(
                (
                    wire_key,
                    "SwaigAction|array|null",
                    "SwaigAction|list<SwaigAction>|null",
                )
            )
            continue
        php_type, doc = GR.php_property_type(psc if isinstance(psc, dict) else {}, {})
        resp_fields.append((wire_key, php_type, doc))
    outs[f"{sub}/SwaigResponse.php"] = _emit_envelope_class(
        "SwaigResponse",
        resp_fields,
        spec_file,
        sub,
        "swaig-response `SwaigResponse` envelope schema",
    )
    return outs


def _envelope_property_type(schema: dict, lifted: str | None) -> tuple[str, str | None]:
    """The PHP property type for one ENVELOPE field of SwaigAction / SwaigResponse.

    This is the ONE place a generated SWAIG payload property may NAME another
    generated class, and it is required rather than stylistic: the reference
    records ``SwaigAction.context_switch`` as
    ``union<string,class:...ContextSwitchAction>`` and ``SwaigResponse.action`` as
    ``union<class:...SwaigAction,list<class:...SwaigAction>>``. The shared
    ``GR.php_property_type`` deliberately collapses every object / $ref / union
    field to ``?array`` (see its docstring) — correct for the per-action value
    classes, but on the envelope it erases the very ``class:`` token the oracle
    keys on, and the field then reads to DRIFT as missing-port even though the
    property exists. go hit exactly this (signalwire-go 41a012c) and fixed it the
    same way: keep the lifted class in the envelope field's type.

    ``lifted`` is the ``<Verb>Action`` class this action's inline object branch was
    lifted to, or None when the action has no object variant. The emitted type is a
    native PHP 8 union of the scalar branches plus that class, which
    ``translate_php_type`` canonicalizes to the reference's union verbatim. When
    there is no lifted class the shared helper is used unchanged, so a scalar-only
    action stays exactly what it was — those fields carry no ``class:`` and the
    oracle does not record them.
    """
    if lifted is None:
        return GR.php_property_type(schema, {})

    branches = schema.get("oneOf") or [schema]
    parts: list[str] = []
    for b in branches:
        if not isinstance(b, dict):
            continue
        if b.get("type") == "object" and b.get("properties"):
            continue  # the lifted class stands in for this branch
        php, _doc = GR.php_property_type(b, {})
        scalar = php.lstrip("?")
        if scalar not in parts:
            parts.append(scalar)
    parts.append(lifted)
    return "|".join(parts) + "|null", None


def _emit_envelope_class(
    php_name: str,
    fields: list[tuple[str, str, str | None]],
    spec: str,
    sub: str,
    source_desc: str,
) -> str:
    """Emit one envelope data class from pre-typed ``(wire_key, php_type, doc)``
    fields. Same shape as ``_emit_class`` — a method-less DTO of public typed
    nullable properties — but the caller supplies the types, because an envelope
    field may name a lifted class (see ``_envelope_property_type``)."""
    lines: list[str] = []
    lines.append("/**")
    lines.append(f" * {php_name} — generated SWAIG payload wire type ({source_desc}).")
    lines.append(" *")
    lines.append(
        " * Pure data DTO: public typed properties named for the snake_case wire keys"
    )
    lines.append(
        " * they carry. It declares no methods — the values ARE the interface."
    )
    lines.append(" */")
    lines.append(f"class {php_name}")
    lines.append("{")
    body: list[str] = []
    used: set[str] = set()
    for wire_key, php_type, doc in fields:
        prop = GR.php_property_name(wire_key)
        while prop in used:
            prop += "_"
        used.add(prop)
        if doc is not None:
            body.append(f"    /** @var {doc} */")
        elif prop != wire_key:
            body.append(f"    /** wire key: {wire_key} */")
        body.append(f"    public {php_type} ${prop} = null;")
        body.append("")
    if body and body[-1] == "":
        body.pop()
    lines.extend(body)
    lines.append("}")
    desc = f"Generated SWAIG payload wire type from porting-sdk/swaig-specs/{spec}."
    return (
        SWAIG_HEADER.format(spec=spec, desc=desc, sub=sub)
        + "\n"
        + "\n".join(lines)
        + "\n"
    )


def _pascal_verb(verb: str) -> str:
    """PascalCase a snake action key: ``context_switch`` -> ``ContextSwitch``
    (matches the reference ``pascal(verb)`` naming so the leaf token is identical
    cross-port)."""
    parts = [p for p in re.split(r"[._\-\s]", verb) if p]
    return "".join(w[:1].upper() + w[1:] for w in parts)


def build_outputs(psdk: Path) -> dict[str, str]:
    specs_dir = psdk / "swaig-specs"
    if not specs_dir.is_dir():
        raise SystemExit(
            f"generate_swaig_payloads.py: {specs_dir} not found (need porting-sdk adjacency)"
        )
    outs: dict[str, str] = {}
    outs.update(_build_post_prompt(psdk))
    outs.update(_build_swaig_request(psdk))
    outs.update(_build_swaig_actions(psdk))
    return outs


# ---------------------------------------------------------------------------
# Driver.
# ---------------------------------------------------------------------------


def main(argv: list[str]) -> int:
    ap = argparse.ArgumentParser(description=__doc__)
    ap.add_argument(
        "--check", action="store_true", help="GEN-FRESH: exit non-zero if stale"
    )
    ap.add_argument("--out", default="", help="scratch: emit into this dir")
    args = ap.parse_args(argv)

    psdk = resolve_porting_sdk()
    outs = build_outputs(psdk)

    if args.out:
        out_dir = Path(args.out)
    else:
        out_dir = repo_root() / "src" / "SignalWire" / "SWAIG" / "Generated"

    if args.check:
        stale: list[str] = []
        for fn, src in outs.items():
            p = out_dir / fn
            if not p.is_file() or p.read_text() != src:
                stale.append(str(p))
        expected = set(outs.keys())
        if out_dir.is_dir():
            for p in sorted(out_dir.rglob("*.php")):
                rel = p.relative_to(out_dir).as_posix()
                if rel not in expected:
                    stale.append(f"{p} (leftover — not in generator output)")
        if stale:
            sys.stderr.write(
                f"GEN-FRESH FAIL: {len(stale)} generated SWAIG-payload file(s) stale:\n"
            )
            for s in stale:
                sys.stderr.write(f"  - {s}\n")
            return 1
        print(
            "GEN-FRESH: generated SWAIG-payload files match porting-sdk/swaig-specs/*.yaml."
        )
        return 0

    out_dir.mkdir(parents=True, exist_ok=True)
    for fn, src in outs.items():
        p = out_dir / fn
        p.parent.mkdir(parents=True, exist_ok=True)
        p.write_text(src)
    print(f"generated {len(outs)} SWAIG-payload file(s) into {out_dir}")
    return 0


if __name__ == "__main__":
    raise SystemExit(main(sys.argv[1:]))
