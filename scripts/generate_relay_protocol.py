#!/usr/bin/env python3
"""Generate the RELAY-protocol wire-type surface for signalwire-php.

This is the PHP realization of SESSION_CHANGESET_FOR_PORTS.md item I — the
`signalwire.relay.protocol_types_generated` module — mirroring python's
`generate_relay_protocol`, go's ``pkg/relay/protocol_types_generated.go``
(``emitRelayProtocolFile``) and TS's ``protocol.types.generated.ts``.

Source: the canonical porting-sdk ``combined-specs/relay.yaml``, read through the
shared reader ``porting-sdk/scripts/relay_protocol_shapes.py`` (ledger row R11).
That reader serves ``{method: schema_node}`` per phase, merging the shapes carried
on a registered method (``methods.<name>.request.params_dto`` /
``.response.result``) with the six per phase the extractor found for methods the
vendored spec does not register (``<phase>_shapes_unattached.methods.<name>``) —
64 methods per phase either way.

This replaced a directory of standalone per-method JSON-Schema files
(``relay-protocol/<domain>.<method>.(params|result).json``). The method name now
comes from the document's own key rather than from an ``x-method`` field with a
filename fallback, and the phase from the block it was carried in rather than from
a filename suffix. NOT derived from openapi (a separate generator), which is why
this lives in its own script rather than generate_rest.py.

The class name is the PascalCased method identifier — dots and underscores folded
— plus the phase suffix:

  calling.ai_hold   (params phase)  -> class CallingAiHoldParams
  signalwire.connect (result phase) -> class SignalwireConnectResult

The emit/drop rule is the SAME as generate_rest.py's / generate_swml_verbs.py's
wire-type emitter (the shared ``is_object_schema`` test): an OBJECT schema WITH
properties becomes a method-less PHP data class; an object schema with NO
properties (or a non-object / scalar / union) is NOT surfaced — the reference
records those as a module-level ``TypeAlias = dict[str, Any]`` its enumerator
drops, so emitting nothing matches the reference surface.

That drop accounts for 128 candidate shapes -> 123 surfaced classes exactly:
  * 64 params shapes, of which 2 are permissive placeholders with no ``properties``
    (calling.call, calling.conference — registered by the server but never given a
    switchblade Params class) -> 62 classes.
  * 64 result shapes, of which 3 have no ``properties`` (the same two, plus
    signalwire.disconnect, whose Result class is genuinely empty) -> 61 classes.
  62 + 61 = 123 == the oracle's 123 method-less classes exactly (0/0).

(The combined document omits the ``type: object`` the per-file envelope used to
declare; ``is_object_schema``'s ``(type is None and properties)`` branch covers
that, so the emit verdict is unchanged. Pinned by
``porting-sdk/tests/test_relay_protocol_shapes.py``.)

The relay JSON schemas carry no ``$ref`` (every nested object is inline), so the
shared ``php_property_type`` walk collapses inline objects to ``?array`` (matching
go ``map[string]any`` / TS ``Record<string,unknown>`` / python ``dict[str, Any]``)
and needs no components node.

Reserved-word class names (Goto/Return/Switch/Unset — hard PHP keywords) get a
``_`` suffix via generate_rest.type_name (none occur in the relay set today, but
the machinery is shared so a future method named e.g. ``switch`` is handled and
the surface enumerator renames it back).

Output layout: one class per file (PSR-4) under
  src/SignalWire/Relay/Generated/<ClassName>.php
in namespace ``SignalWire\\Relay\\Generated``. The surface enumerator routes every
file under ``Relay/Generated/`` to the oracle module
``signalwire.relay.protocol_types_generated`` BY PATH (winning over
CLASS_MODULE_MAP so an existing Relay SDK class — Call/Client/CallState/… — is
never misrouted, and any relay type name that also exists as a REST/SWML wire
type lands in the right module).

Usage:
    python3 scripts/generate_relay_protocol.py            # write into the repo tree
    python3 scripts/generate_relay_protocol.py --check    # GEN-FRESH: fail if stale
    python3 scripts/generate_relay_protocol.py --out DIR  # scratch: emit into DIR
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
# generators never diverge on the emit rule — exactly like generate_swml_verbs.py.
# ---------------------------------------------------------------------------


def _load_rest_generator():
    here = Path(__file__).resolve().parent
    spec = importlib.util.spec_from_file_location(
        "generate_rest", here / "generate_rest.py"
    )
    if spec is None or spec.loader is None:  # pragma: no cover
        raise SystemExit("generate_relay_protocol.py: cannot load generate_rest.py")
    mod = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(mod)
    return mod


GR = _load_rest_generator()


def resolve_porting_sdk() -> Path:
    return GR.resolve_porting_sdk()


def repo_root() -> Path:
    return Path(__file__).resolve().parents[1]


# ---------------------------------------------------------------------------
# Filename / x-method -> class name.
# ---------------------------------------------------------------------------

# (filename phase tail, PHP class-name suffix). Only params/result are surfaced;
# the ``.event.json`` files (x-phase "event") are a different phase and excluded.
_PHASES = (("params", "Params"), ("result", "Result"))


def _pascal_method(method: str) -> str:
    """PascalCase a RELAY method identifier: dots and underscores are word
    separators, so ``calling.ai_hold`` -> ``CallingAiHold`` and
    ``signalwire.connect`` -> ``SignalwireConnect`` (note: ``signalwire`` folds to
    ``Signalwire``, matching the oracle — this is the wire domain token, not the
    ``SignalWire`` brand). Mirrors go's pascal(strings.ReplaceAll(method,".","_"))."""
    parts = [p for p in re.split(r"[._\-\s]", method) if p]
    return "".join(w[:1].upper() + w[1:] for w in parts)


# ---------------------------------------------------------------------------
# Emit.
# ---------------------------------------------------------------------------

RELAY_HEADER = """<?php

declare(strict_types=1);

// Code generated by scripts/generate_relay_protocol.py; DO NOT EDIT.
//
// AUTO-GENERATED from porting-sdk/combined-specs/relay.yaml — regenerate with:
//   python3 scripts/generate_relay_protocol.py
//
// {desc}

namespace SignalWire\\Relay\\Generated;
"""


def _emit_class(php_name: str, properties: dict, source_desc: str) -> str:
    """Emit one method-less PHP data class for a RELAY params/result object schema.
    Property typing reuses generate_rest.php_property_type (pure idiom — the surface
    records only the class name; types keep the DTO PHPStan-L9-clean). The relay
    schemas carry no ``$ref``, so no components node is needed (inline objects
    collapse to ``?array``)."""
    lines: list[str] = []
    lines.append("/**")
    lines.append(f" * {php_name} — generated RELAY protocol wire type ({source_desc}).")
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
        php_type, doc = GR.php_property_type(psc if isinstance(psc, dict) else {}, {})
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
    desc = "Generated RELAY protocol wire type from porting-sdk/combined-specs/relay.yaml."
    return RELAY_HEADER.format(desc=desc) + "\n" + "\n".join(lines) + "\n"


def _load_relay_shapes(psdk: Path):
    """The shared porting-sdk reader for ``combined-specs/relay.yaml`` (ledger R11).

    Loaded by FILE PATH — the same way this script already loads generate_rest.py —
    because porting-sdk is a sibling checkout, not an installed package.
    """
    path = psdk / "scripts" / "relay_protocol_shapes.py"
    if not path.is_file():
        raise SystemExit(
            f"generate_relay_protocol.py: {path} not found (need porting-sdk adjacency)"
        )
    spec = importlib.util.spec_from_file_location("relay_protocol_shapes", path)
    if spec is None or spec.loader is None:  # pragma: no cover
        raise SystemExit(f"generate_relay_protocol.py: cannot load {path}")
    mod = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(mod)
    return mod


def build_outputs(psdk: Path) -> dict[str, str]:
    RPS = _load_relay_shapes(psdk)

    outs: dict[str, str] = {}
    emitted_names: set[str] = set()

    # Params first, then result — each mapping already ordered by method name — to
    # reproduce the reference decl order (Params block, then Result block).
    for phase, suffix in _PHASES:
        for method, node in RPS.shapes(psdk, phase).items():
            php_name = GR.type_name(_pascal_method(method) + suffix)
            # Same object-vs-alias split as the REST / SWML wire-type emitters: an
            # object WITH properties -> data class; an empty-object / scalar / union
            # placeholder -> a `TypeAlias = dict[str,Any]` the reference enumerator
            # drops, so we emit nothing (keeps the surface at the oracle's 123).
            if not GR.is_object_schema(node):
                continue
            if php_name in emitted_names:
                continue
            emitted_names.add(php_name)
            outs[f"{php_name}.php"] = _emit_class(
                php_name, RPS.properties(node), f"method {method!r}, {phase}"
            )

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
        out_dir = repo_root() / "src" / "SignalWire" / "Relay" / "Generated"

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
                f"GEN-FRESH FAIL: {len(stale)} generated RELAY-protocol file(s) stale:\n"
            )
            for s in stale:
                sys.stderr.write(f"  - {s}\n")
            return 1
        print(
            "GEN-FRESH: generated RELAY-protocol files match porting-sdk/combined-specs/relay.yaml."
        )
        return 0

    out_dir.mkdir(parents=True, exist_ok=True)
    for fn, src in outs.items():
        p = out_dir / fn
        p.parent.mkdir(parents=True, exist_ok=True)
        p.write_text(src)
    print(f"generated {len(outs)} RELAY-protocol file(s) into {out_dir}")
    return 0


if __name__ == "__main__":
    raise SystemExit(main(sys.argv[1:]))
