#!/usr/bin/env python3
"""Generate the SignalWire REST namespace resource layer for signalwire-php.

This is the PHP realization of porting-sdk/REST_GENERATOR_RULES.md — the
language-neutral contract of the REST resource generator (bases,
x-sdk-resource markup, path composition, command-dispatch, set_methods,
cross-spec client-tree placement, fail-loud invariants).

Inputs (resolved from $PORTING_SDK or the adjacent ../porting-sdk):
    rest-apis/<ns>/openapi.yaml       (+ x-sdk-* markup)
    rest-apis/x-sdk-bases.yaml        (shared base method-sets)
    rest-apis/fabric/x-sdk-bases.yaml (FabricResource)

Outputs: PHP files under src/SignalWire/REST/Namespaces/Generated/ — one file
per generated resource class (PSR-4 file-per-class), one client-tree container
file per namespace group, and ResourceTree.php that the hand RestClient
composes. The hand BASES stay hand-written
(src/SignalWire/REST/{BaseResource,CrudResource,CrudWithAddresses,HttpClient,
RestClient}.php); the generator emits ONLY the per-resource classes that EXTEND
those bases, their declared/command/set methods, and the container tree —
mirroring the Go generator (cmd/generate-rest).

Idiom (PORT_PHILOSOPHY_PHP.md): this turn emits the LOOSE surface — resource
methods take bare `array` bodies / `array $params` query maps — to reproduce
the CURRENT hand src/SignalWire/REST/Namespaces/*.php surface exactly (the
parity target, like Go's loose map[string]any). The named-args upgrade (§5) is
a drift-neutral follow-up (the oracle keys operation/command params by
name+kind, so re-shaping the call site is drift-neutral).

Classes are named by x-sdk-resource.name VERBATIM (the Python oracle canonical
names — DatasphereDocuments, AiAgents, Calling, ShortCodes, VideoRooms, …), so
the PHP adapter (enumerate_surface.py) projects each generated class onto the
same signalwire.rest.namespaces.<ns>_resources_generated.<Name> module the
Go/TS/python oracle produces.

Usage:
    python3 scripts/generate_rest.py                 # write into the repo tree
    python3 scripts/generate_rest.py --check         # GEN-FRESH: fail if stale
    python3 scripts/generate_rest.py --out DIR       # scratch: emit flat into DIR
"""
from __future__ import annotations

import argparse
import os
import sys
from pathlib import Path

try:
    import yaml
except ImportError:  # pragma: no cover
    sys.stderr.write("generate_rest.py requires PyYAML (pip install pyyaml)\n")
    raise


# The 12 real REST spec directories (registry has no own dir — its resources
# live inside relay-rest via namespace: registry; swml-webhooks is types-only).
SPEC_DIRS = [
    "relay-rest", "fabric", "calling", "video", "datasphere",
    "logs", "message", "voice", "fax", "project", "chat", "pubsub",
]

PHP_KEYWORDS = {
    "abstract", "and", "array", "as", "break", "callable", "case", "catch",
    "class", "clone", "const", "continue", "declare", "default", "do", "echo",
    "else", "elseif", "empty", "enddeclare", "endfor", "endforeach", "endif",
    "endswitch", "endwhile", "enum", "eval", "exit", "extends", "final",
    "finally", "fn", "for", "foreach", "function", "global", "goto", "if",
    "implements", "include", "include_once", "instanceof", "insteadof",
    "interface", "isset", "list", "match", "namespace", "new", "or", "print",
    "private", "protected", "public", "readonly", "require", "require_once",
    "return", "static", "switch", "throw", "trait", "try", "unset", "use",
    "var", "while", "xor", "yield", "from",
}


# ---------------------------------------------------------------------------
# Resolution.
# ---------------------------------------------------------------------------

def resolve_porting_sdk() -> Path:
    env = os.environ.get("PORTING_SDK")
    if env and (Path(env) / "rest-apis").is_dir():
        return Path(env).resolve()
    here = Path(__file__).resolve()
    for parent in here.parents:
        cand = parent.parent / "porting-sdk"
        if (cand / "rest-apis").is_dir():
            return cand.resolve()
    raise SystemExit("generate_rest.py: porting-sdk not found (set $PORTING_SDK or clone adjacent)")


def repo_root() -> Path:
    return Path(__file__).resolve().parents[1]


# ---------------------------------------------------------------------------
# Base loading (x-sdk-bases; §2).
# ---------------------------------------------------------------------------

def load_bases(psdk: Path) -> dict[str, list[str]]:
    raw = yaml.safe_load((psdk / "rest-apis" / "x-sdk-bases.yaml").read_text())
    bases = dict(raw.get("x-sdk-bases") or {})
    fab = psdk / "rest-apis" / "fabric" / "x-sdk-bases.yaml"
    if fab.is_file():
        bases.update((yaml.safe_load(fab.read_text()).get("x-sdk-bases") or {}))

    def resolve(name: str, seen: set[str]) -> list[str]:
        if name in seen:
            raise SystemExit(f"x-sdk-bases: cyclic extends at {name}")
        if name not in bases:
            raise SystemExit(f"x-sdk-bases: undefined base {name!r}")
        seen = seen | {name}
        methods: list[str] = []
        ext = bases[name].get("extends")
        if ext:
            methods.extend(resolve(ext, seen))
        methods.extend(list((bases[name].get("methods") or {}).keys()))
        return methods

    return {name: resolve(name, set()) for name in bases}


# ---------------------------------------------------------------------------
# Spec model.
# ---------------------------------------------------------------------------

class Spec:
    def __init__(self, name: str, doc: dict):
        self.name = name
        self.doc = doc
        self.server_path = _url_path(doc["servers"][0]["url"])
        if self.server_path != "/" and self.server_path.endswith("/"):
            raise SystemExit(f"{name}: servers[0].url path {self.server_path!r} has a trailing slash")
        self.namespace_attr = (doc.get("x-sdk-namespace") or {}).get("attr") or ""
        self.ops: dict[str, tuple[str, str, bool]] = {}
        self.op_body: dict[str, dict] = {}  # operationId -> requestBody JSON schema (or {})
        for path, item in (doc.get("paths") or {}).items():
            for verb in ("get", "post", "put", "patch", "delete"):
                o = item.get(verb)
                if o and o.get("operationId"):
                    self.ops[o["operationId"]] = (verb, path, bool(o.get("requestBody")))
                    body = o.get("requestBody") or {}
                    content = body.get("content") or {}
                    media = content.get("application/json") or (next(iter(content.values())) if content else {})
                    self.op_body[o["operationId"]] = (media or {}).get("schema") or {}
        self.schemas = ((doc.get("components") or {}).get("schemas")) or {}

    def resources(self) -> list[tuple[str, dict]]:
        out = []
        for path, item in (self.doc.get("paths") or {}).items():
            r = item.get("x-sdk-resource")
            if r and not r.get("exclude") and r.get("name"):
                out.append((path, r))
        return out


def _url_path(url: str) -> str:
    if "://" in url:
        url = url.split("://", 1)[1]
    i = url.find("/")
    return url[i:] if i >= 0 else "/"


def load_spec(psdk: Path, ns: str) -> Spec:
    return Spec(ns, yaml.safe_load((psdk / "rest-apis" / ns / "openapi.yaml").read_text()))


# ---------------------------------------------------------------------------
# Path composition (§4).
# ---------------------------------------------------------------------------

def join_path(a: str, b: str) -> str:
    if not b:
        return a
    return a.rstrip("/") + "/" + b.lstrip("/")


def collection_segment(anchor: str, markup: dict) -> str:
    if "collection" in markup:
        return markup["collection"]
    p = anchor
    i = p.find("/{")
    if i >= 0:
        p = p[:i]
    return p


def base_path(spec: Spec, anchor: str, markup: dict) -> str:
    return join_path(spec.server_path, collection_segment(anchor, markup))


def relative_tail(spec: Spec, anchor: str, markup: dict, op_path: str):
    coll = collection_segment(anchor, markup)
    full = join_path(spec.server_path, coll)
    absp = join_path(spec.server_path, op_path)
    if coll and absp.startswith(full + "/"):
        return ([s for s in absp[len(full) + 1:].split("/") if s], False)
    if coll and absp == full:
        return ([], False)
    return ([s for s in absp.lstrip("/").split("/") if s], True)


# ---------------------------------------------------------------------------
# Naming.
# ---------------------------------------------------------------------------

def snake_to_camel(snake: str) -> str:
    parts = [p for p in snake.replace("-", "_").replace(".", "_").split("_") if p]
    if not parts:
        return snake
    return parts[0] + "".join(w[:1].upper() + w[1:] for w in parts[1:])


def escape_param(field: str) -> str:
    ident = snake_to_camel(field)
    return ident + "_" if ident in PHP_KEYWORDS else ident


PARAM_ARG_NAME = {
    "id": "id", "queue_id": "queueId", "NumberGroupId": "groupId",
    "documentId": "documentId", "chunkId": "chunkId", "mfa_request_id": "requestId",
    "e164_number": "e164", "fabric_subscriber_id": "subscriberId",
    "ai_agent_id": "id", "cxml_webhook_id": "id", "swml_webhook_id": "id",
    "token_id": "tokenId", "room_id": "roomId", "resource_id": "resourceId",
    "sip_endpoint_id": "sipEndpointId",
}


def arg_for(brace: str) -> str:
    return PARAM_ARG_NAME.get(brace, snake_to_camel(brace) or "id")


def php_str(s: str) -> str:
    return "'" + s.replace("\\", "\\\\").replace("'", "\\'") + "'"


# ---------------------------------------------------------------------------
# Base mapping (§2).
# ---------------------------------------------------------------------------

BASE_PROVIDES = {
    "CrudResource": {"list", "create", "get", "update", "delete"},
    "FabricResource": {"list", "create", "get", "update", "delete", "list_addresses"},
    "ReadResource": {"list", "get"},
    "BaseResource": set(),
}

# The PHP parent class each markup base maps to. Mirrors Python's
# _base hierarchy: BaseResource <- ReadResource <- CrudResource <-
# CrudWithAddresses <- FabricResource / FabricResourcePUT. FabricResource's
# concrete parent is chosen at emit time by update_method (PATCH -> FabricResource,
# PUT -> FabricResourcePUT); the placeholder here is the PATCH default.
EXTENDS = {
    "CrudResource": "CrudResource",
    "FabricResource": "FabricResource",
    "ReadResource": "ReadResource",
    "BaseResource": "BaseResource",
}


def http_receiver(base: str) -> str:
    # ReadResource/CrudResource/CrudWithAddresses expose $this->client;
    # BaseResource exposes $this->http.
    return "http" if base == "BaseResource" else "client"


# ---------------------------------------------------------------------------
# Command-dispatch (§6).
# ---------------------------------------------------------------------------

def command_method_name(cmd: str) -> str:
    # strip leading domain prefix, dots -> underscores, then camelCase.
    s = cmd
    if "." in s:
        s = s.split(".", 1)[1] if s.startswith("calling.") else s.replace(".", "_")
    s = s.replace(".", "_")
    return snake_to_camel(s)


def command_py_name(cmd: str) -> str:
    s = cmd[len("calling."):] if cmd.startswith("calling.") else cmd
    return s.replace(".", "_")


def discriminator_mapping(spec: Spec, schema_name: str) -> list[str]:
    sch = spec.schemas.get(schema_name)
    if sch is None:
        raise SystemExit(f"command-dispatch request {schema_name!r} not in components.schemas")
    mapping = (sch.get("discriminator") or {}).get("mapping")
    if not mapping:
        raise SystemExit(f"command-dispatch request {schema_name!r} has no discriminator.mapping")
    return list(mapping.keys())


# ---------------------------------------------------------------------------
# Typed inputs (§5) — schema → PHP native type + canonical audit type.
# ---------------------------------------------------------------------------
#
# The generated operation/command/set methods take ONE named PHP parameter per
# spec field (the PHP realization of Go's options struct / TS's options object):
# required fields → typed positional-order named params, optional fields →
# ``?T $x = null``, plus a trailing forward-compat ``array $extras = []`` door.
#
# The PHP reflection the signature enumerator reads cannot express (a) keyword-
# only intent, (b) ``array``'s element type, or (c) that ``extras`` is an open
# dict. So the generator ALSO emits a machine-readable sidecar
# (Generated/rest_signatures.json) recording each method's canonical param list
# (name, kind, type, required); the enumerator UNFOLDS the reflected params to
# that recorded shape (mirrors Go's enumerator struct-unfold). Both the PHP
# signature and the sidecar are derived from the same computed param list here,
# so they never diverge (GEN-FRESH covers the sidecar).
#
# Canonical-type rule (proven drift-neutral against the oracle):
#   * required JSON scalar  string/integer/number/boolean → string/int/float/bool
#   * required array                                       → list<any>
#   * required object / $ref / oneOf / anyOf               → dict<string,any>
#   * optional (any JSON type)                             → optional<any>
# Optionals record as optional<any> which is compatible with EVERY concrete
# optional the oracle records (optional<string>, optional<list<string>>,
# optional<class:...>, …); required scalars stay concrete; required object/gen
# refs record as the open dict<string,any> (the oracle's gen:<Name> folds onto
# dict<string,any>), and required lists as list<any> (element any matches any
# concrete element). No field is dropped; nothing invented; no omission.


def resolve_schema(spec: Spec, schema: dict | None, seen=None) -> dict:
    """Follow a $ref (within components.schemas), and an ``allOf`` wrapping a
    single member (the OpenAPI idiom for "ref + description"), to the concrete
    schema. Used to recover the PHP native scalar of a $ref-to-scalar field."""
    if not schema:
        return {}
    if seen is None:
        seen = set()
    ref = schema.get("$ref")
    if ref:
        leaf = ref.rsplit("/", 1)[-1]
        if leaf in seen:
            return {}
        seen.add(leaf)
        return resolve_schema(spec, spec.schemas.get(leaf), seen)
    # allOf: [<single member>] — a ref decorated with description/examples.
    allof = schema.get("allOf")
    if allof and len(allof) == 1 and not schema.get("properties") and not schema.get("type"):
        return resolve_schema(spec, allof[0], seen)
    return schema


def _is_named_ref(schema: dict) -> bool:
    """True when the field is (or wraps) a $ref to a NAMED component schema —
    the oracle records these as ``gen:<Name>`` (which folds onto dict<string,any>
    in the diff), regardless of whether the referent is a scalar newtype (uuid/
    jwt) or an object. The canonical record must therefore be dict<string,any>
    to match; the PHP NATIVE type still resolves to the concrete scalar/array so
    callers get idiomatic usage (the sidecar carries the audit type, not the PHP
    type)."""
    if not schema:
        return False
    if schema.get("$ref"):
        return True
    allof = schema.get("allOf")
    if allof and len(allof) == 1 and not schema.get("properties") and not schema.get("type"):
        return _is_named_ref(allof[0])
    return False


def _json_type(schema: dict) -> str | None:
    t = schema.get("type")
    if isinstance(t, list):
        # nullable via ["string", "null"] — pick the non-null scalar
        non_null = [x for x in t if x != "null"]
        return non_null[0] if non_null else None
    return t


_SCALAR_PHP = {"string": "string", "integer": "int", "number": "float", "boolean": "bool"}
_SCALAR_CANON = {"string": "string", "integer": "int", "number": "float", "boolean": "bool"}


def php_param_type(spec: Spec, schema: dict, required: bool) -> str:
    """The PHP native type for a body field. Optionals are nullable ``?T``."""
    resolved = resolve_schema(spec, schema)
    jt = _json_type(resolved)
    if jt in _SCALAR_PHP:
        base = _SCALAR_PHP[jt]
    else:
        # array / object / $ref-to-object / oneOf / anyOf / unknown → array
        base = "array"
    return base if required else "?" + base


def php_doc_type(spec: Spec, schema: dict, required: bool) -> str | None:
    """PHPDoc value type for a param whose PHP type is ``array``/``?array``
    (PHPStan L9 requires an iterable value type). ``None`` for scalar params
    (their native type suffices — no docblock needed)."""
    resolved = resolve_schema(spec, schema)
    jt = _json_type(resolved)
    if jt in _SCALAR_PHP:
        return None
    if jt == "array":
        base = "list<mixed>"
    else:
        base = "array<string,mixed>"
    return base + "|null" if not required else base


def canonical_type(spec: Spec, schema: dict, required: bool) -> str:
    """The canonical audit type the sidecar records for a body field.

    Optionals → ``optional<any>`` (compatible with EVERY concrete optional the
    oracle records). Required NAMED-$ref fields → ``dict<string,any>`` (folds
    onto the oracle's ``gen:<Name>`` — even for scalar newtypes like uuid/jwt,
    since the diff has no gen↔scalar fold). Required inline scalar/array/object
    → their concrete open form."""
    if not required:
        return "optional<any>"
    if _is_named_ref(schema):
        return "dict<string,any>"
    resolved = resolve_schema(spec, schema)
    jt = _json_type(resolved)
    if jt in _SCALAR_CANON:
        return _SCALAR_CANON[jt]
    if jt == "array":
        return "list<any>"
    # inline object / oneOf / anyOf → an open JSON object.
    return "dict<string,any>"


def object_body_fields(spec: Spec, body_schema: dict) -> list[tuple[str, dict, bool]]:
    """Return [(wire_name, field_schema, required)] for an object request body,
    flattening allOf and following $refs. Fields keep spec declaration order
    (required-vs-optional ordering is applied by the caller)."""
    resolved = resolve_schema(spec, body_schema)
    props: dict[str, dict] = {}
    required: set[str] = set(resolved.get("required") or [])
    for name, psc in (resolved.get("properties") or {}).items():
        props.setdefault(name, psc)
    for br in resolved.get("allOf") or []:
        rb = resolve_schema(spec, br)
        required |= set(rb.get("required") or [])
        for name, psc in (rb.get("properties") or {}).items():
            props.setdefault(name, psc)
    return [(name, psc, name in required) for name, psc in props.items()]


def command_param_fields(spec: Spec, command_schema: dict) -> tuple[list[tuple[str, dict, bool]], bool]:
    """§6 union-flatten: return ([(wire_name, schema, required)], has_id).

    The command schema's ``params`` sub-schema may itself be an anyOf/oneOf of
    variant param schemas; expose the UNION of all variants' fields, a field
    required only if EVERY variant requires it. ``has_id`` is whether the
    command schema declares an ``id`` property (→ a leading call_id positional)."""
    cs = resolve_schema(spec, command_schema)
    has_id = "id" in (cs.get("properties") or {})
    params_schema = (cs.get("properties") or {}).get("params")
    if params_schema is None:
        return [], has_id
    ps = resolve_schema(spec, params_schema)
    variants: list[dict] = []
    for comb in ("anyOf", "oneOf"):
        if comb in ps:
            variants = [resolve_schema(spec, v) for v in ps[comb]]
            break
    if not variants:
        variants = [ps]
    all_props: dict[str, dict] = {}
    req_sets: list[set[str]] = []
    for v in variants:
        req_sets.append(set(v.get("required") or []))
        for name, psc in (v.get("properties") or {}).items():
            all_props.setdefault(name, psc)
    req_all = set.intersection(*req_sets) if req_sets else set()
    return [(name, psc, name in req_all) for name, psc in all_props.items()], has_id


def is_object_body(spec: Spec, body_schema: dict) -> bool:
    """True when the request body is a typed OBJECT (properties / $ref to an
    object) → closed typed params. False for a top-level union body (anyOf/
    oneOf with no single object) → a single positional ``body`` param (§5.2)."""
    if not body_schema:
        return False
    if "anyOf" in body_schema or "oneOf" in body_schema:
        return False
    resolved = resolve_schema(spec, body_schema)
    if "anyOf" in resolved or "oneOf" in resolved:
        return False
    if resolved.get("properties") or resolved.get("allOf"):
        return True
    return _json_type(resolved) == "object"


def ordered_fields(fields: list[tuple[str, dict, bool]]) -> list[tuple[str, dict, bool]]:
    """Required-first, then optional; stable within each group (spec order)."""
    req = [f for f in fields if f[2]]
    opt = [f for f in fields if not f[2]]
    return req + opt


# Sidecar accumulator: (ClassName, phpMethodName) -> [param records without self].
# Each record: {"name", "kind", "type", "required", ["default"]}.
_SIDECAR: dict[tuple[str, str], list[dict]] = {}


def _register_sidecar(cls: str, php_method: str, records: list[dict]) -> None:
    _SIDECAR[(cls, php_method)] = records


def body_params(spec: Spec, cls: str, php_method: str,
                fields: list[tuple[str, dict, bool]],
                leading: list[dict]) -> tuple[list[str], list[str], list[dict], list[str]]:
    """Build named PHP params + body-assembly PHP + sidecar records + PHPDoc
    ``@param`` lines for a set of body fields. ``leading`` is the already-built
    sidecar records for positional id/call_id args (their PHP params are built
    by the caller).

    Returns (php_params_for_fields, body_build_lines, sidecar_records, doc_lines)."""
    php_params: list[str] = []
    build: list[str] = []
    doc: list[str] = []
    records: list[dict] = list(leading)
    build.append("        $body = [];")
    for wire_name, schema, required in ordered_fields(fields):
        ident = escape_param(wire_name)
        pt = php_param_type(spec, schema, required)
        ct = canonical_type(spec, schema, required)
        dt = php_doc_type(spec, schema, required)
        if dt:
            doc.append(f"     * @param {dt} ${ident}")
        rec: dict = {"name": wire_name, "kind": "keyword", "type": ct, "required": required}
        if required:
            php_params.append(f"{pt} ${ident}")
            build.append(f"        $body[{php_str(wire_name)}] = ${ident};")
        else:
            php_params.append(f"{pt} ${ident} = null")
            rec["default"] = None
            build.append(f"        if (${ident} !== null) {{")
            build.append(f"            $body[{php_str(wire_name)}] = ${ident};")
            build.append("        }")
        records.append(rec)
    # trailing forward-compat door (the oracle's keyword ``extras`` + the
    # Python-reference ``**kwargs`` catch-all). The single PHP ``array $extras``
    # arg realizes BOTH — the oracle records ``extras`` (keyword optional-dict)
    # AND a trailing ``kwargs`` (var_keyword) on object-body operation methods,
    # so both records are emitted (the var_keyword needs no distinct PHP param).
    php_params.append("array $extras = []")
    doc.append("     * @param array<string,mixed> $extras Forward-compat body fields.")
    records.append({
        "name": "extras", "kind": "keyword",
        "type": "optional<dict<string,any>>", "required": False, "default": None,
    })
    records.append({
        "name": "kwargs", "kind": "var_keyword", "type": "any",
        "required": False, "default": {},
    })
    build.append("        $body = array_merge($body, $extras);")
    _register_sidecar(cls, php_method, records)
    return php_params, build, records, doc


# ---------------------------------------------------------------------------
# Emitters.
# ---------------------------------------------------------------------------

GEN_HEADER = """<?php

declare(strict_types=1);

// Code generated by scripts/generate_rest.py; DO NOT EDIT.
//
// AUTO-GENERATED from porting-sdk/rest-apis/ (x-sdk-* markup) — regenerate with:
//   python3 scripts/generate_rest.py
//
// {desc}

namespace SignalWire\\REST\\Namespaces\\Generated;
"""


def method_call_path(spec: Spec, anchor: str, markup: dict, op_path: str):
    """Return (id_args, php_path_expr)."""
    segs, sibling = relative_tail(spec, anchor, markup, op_path)
    id_args: list[str] = []
    pieces: list[str] = []
    for s in segs:
        if s.startswith("{") and s.endswith("}"):
            arg = arg_for(s[1:-1])
            while arg in id_args:
                arg += "2"
            id_args.append(arg)
            pieces.append("$" + arg)
        else:
            pieces.append(php_str(s))
    if sibling:
        full = join_path(spec.server_path, op_path.lstrip("/"))
        expr = abs_php_path(full, id_args)
    elif not pieces:
        expr = "$this->basePath"
    else:
        expr = "$this->path(" + ", ".join(pieces) + ")"
    return id_args, expr


def abs_php_path(full: str, id_args: list[str]) -> str:
    """Build a PHP string-concat expression for a sibling absolute path,
    substituting {brace} with the positional $id_args in order."""
    out = []
    literal = []
    ai = 0
    i = 0
    while i < len(full):
        if full[i] == "{":
            j = full.find("}", i)
            if literal:
                out.append(php_str("".join(literal)))
                literal = []
            if ai < len(id_args):
                out.append("$" + id_args[ai])
                ai += 1
            i = j + 1
            continue
        literal.append(full[i])
        i += 1
    if literal:
        out.append(php_str("".join(literal)))
    return " . ".join(out) if out else "''"


def emit_method(spec: Spec, anchor: str, markup: dict, base: str,
                method_snake: str, op_id: str) -> str:
    if op_id not in spec.ops:
        raise SystemExit(f"{markup['name']}.{method_snake}: op {op_id!r} not in spec")
    verb, op_path, has_body = spec.ops[op_id]
    id_args, path_expr = method_call_path(spec, anchor, markup, op_path)
    recv = http_receiver(base)
    name = snake_to_camel(method_snake)
    cls = markup["name"]

    # Leading positional id args (path params) — positional in the sidecar.
    id_records = [{"name": a, "kind": "positional", "type": "string", "required": True}
                  for a in id_args]
    id_params = ["string $" + a for a in id_args]
    write_verb = verb in ("post", "put", "patch")
    doc = ["    /**"]
    body_ml: list[str] = []

    if write_verb and has_body:
        body_schema = spec.op_body.get(op_id) or {}
        if is_object_body(spec, body_schema):
            # §5.1 object body → one named PHP param per spec field + extras.
            fields = object_body_fields(spec, body_schema)
            field_php, build, _, field_doc = body_params(spec, cls, name, fields, id_records)
            params = id_params + field_php
            body_ml = build
            body_arg = "$body"
            doc.extend(field_doc)
        else:
            # §5.2 union body → a single positional ``body`` param.
            params = id_params + ["array $body"]
            _register_sidecar(cls, name, id_records + [
                {"name": "body", "kind": "positional", "type": "dict<string,any>", "required": True},
            ])
            body_arg = "$body"
            doc.append("     * @param array<string,mixed> $body JSON request body.")
        verb_fn = {"post": "post", "put": "put", "patch": "patch"}[verb]
        if body_ml:
            call_line = f"        return $this->{recv}->{verb_fn}({path_expr}, {body_arg});"
        else:
            call_line = f"        return $this->{recv}->{verb_fn}({path_expr}, {body_arg});"
    elif write_verb:
        # write verb, no body → empty body.
        params = id_params
        _register_sidecar(cls, name, list(id_records))
        verb_fn = {"post": "post", "put": "put", "patch": "patch"}[verb]
        call_line = f"        return $this->{recv}->{verb_fn}({path_expr}, []);"
    elif verb == "get":
        # §5.3 GET query door — a trailing var_keyword ``params`` map.
        params = id_params + ["array $params = []"]
        _register_sidecar(cls, name, id_records + [
            {"name": "params", "kind": "var_keyword", "type": "any", "required": False, "default": {}},
        ])
        doc.append("     * @param array<string,mixed> $params Query-string parameters.")
        call_line = f"        return $this->{recv}->get({path_expr}, $params);"
    else:  # delete
        params = id_params
        _register_sidecar(cls, name, list(id_records))
        call_line = f"        return $this->{recv}->delete({path_expr});"

    sig = ", ".join(params)
    doc.append("     * @return array<string,mixed>")
    doc.append("     */")
    lines = "\n".join(doc) + "\n"
    lines += f"    public function {name}({sig}): array\n    {{\n"
    for bl in body_ml:
        lines += bl + "\n"
    lines += call_line + "\n    }\n"
    return lines


def emit_set_method(spec: Spec, markup: dict, sm_name: str, sm: dict,
                    update_schema_fields: set[str], field_schemas: dict[str, dict]) -> str:
    handler = sm.get("handler")
    if not handler:
        raise SystemExit(f"{markup['name']}.{sm_name}: set_method missing handler")
    cls = markup["name"]
    name = snake_to_camel(sm_name)
    args = sm.get("args") or {}
    # resource_id is a leading positional string (matches the oracle).
    params = ["string $resourceId"]
    records: list[dict] = [
        {"name": "resource_id", "kind": "positional", "type": "string", "required": True},
    ]
    required_lines = []
    optional_lines = []
    arg_doc: list[str] = []
    for arg_name, arg in args.items():
        field = arg.get("field")
        if not field:
            raise SystemExit(f"{markup['name']}.{sm_name}: arg {arg_name!r} missing field")
        if field not in update_schema_fields:
            raise SystemExit(
                f"{markup['name']}.{sm_name}: arg field {field!r} not in update request schema"
            )
        ident = escape_param(arg_name)
        required = bool(arg.get("required"))
        fschema = field_schemas.get(field, {})
        pt = php_param_type(spec, fschema, required)
        ct = canonical_type(spec, fschema, required)
        dt = php_doc_type(spec, fschema, required)
        if dt:
            arg_doc.append(f"     * @param {dt} ${ident}")
        # set_method args are POSITIONAL in the oracle (they wrap update()).
        rec: dict = {"name": arg_name, "kind": "positional", "type": ct, "required": required}
        if required:
            params.append(f"{pt} ${ident}")
            required_lines.append(f"            {php_str(field)} => ${ident},")
        else:
            params.append(f"{pt} ${ident} = null")
            rec["default"] = None
            optional_lines.append((ident, field))
        records.append(rec)
    params.append("array $extra = []")
    arg_doc.append("     * @param array<string,mixed> $extra")
    # trailing var_keyword door (the oracle's ``extra``).
    records.append({"name": "extra", "kind": "var_keyword", "type": "any",
                    "required": False, "default": {}})
    _register_sidecar(cls, name, records)
    sig = ", ".join(params)

    body = []
    body.append("    /**")
    body.extend(arg_doc)
    body.append("     * @return array<string,mixed>")
    body.append("     */")
    body.append(f"    public function {name}({sig}): array")
    body.append("    {")
    body.append("        $body = [")
    body.append(f"            'call_handler' => {php_str(handler)},")
    body.extend(required_lines)
    body.append("        ];")
    for ident, field in optional_lines:
        body.append(f"        if (${ident} !== null) {{")
        body.append(f"            $body[{php_str(field)}] = ${ident};")
        body.append("        }")
    body.append("        return $this->update($resourceId, array_merge($body, $extra));")
    body.append("    }")
    return "\n".join(body) + "\n"


def schema_fields(spec: Spec, schema: dict, seen=None) -> set[str]:
    """Collect property names of an object schema, flattening allOf/anyOf/oneOf
    and following $refs within components.schemas."""
    if schema is None:
        return set()
    if seen is None:
        seen = set()
    ref = schema.get("$ref")
    if ref:
        leaf = ref.rsplit("/", 1)[-1]
        if leaf in seen:
            return set()
        seen.add(leaf)
        return schema_fields(spec, spec.schemas.get(leaf), seen)
    out = set(((schema.get("properties")) or {}).keys())
    for comb in ("allOf", "anyOf", "oneOf"):
        for br in schema.get(comb) or []:
            out |= schema_fields(spec, br, seen)
    return out


def update_request_fields(spec: Spec, anchor: str, markup: dict) -> set[str]:
    """The update op's requestBody schema fields (for set_methods validation).

    The update op is the item-level put/patch under `<collection>/{id}` (NOT the
    anchor collection path, which only has list/create). Locate it by scanning
    the spec paths for the collection prefix + a single {param} tail."""
    coll = collection_segment(anchor, markup)
    want_verb = "put" if markup.get("update_method") == "PUT" else "patch"
    for path, item in (spec.doc.get("paths") or {}).items():
        # item-level path: <collection>/{something}
        if not path.startswith(coll + "/{"):
            continue
        if path.count("/{") != 1 or not path.endswith("}"):
            continue
        op = item.get(want_verb) or item.get("put") or item.get("patch")
        if not op:
            continue
        content = (op.get("requestBody") or {}).get("content") or {}
        for media in content.values():
            sch = media.get("schema")
            if sch:
                return schema_fields(spec, sch)
    return set()


def update_field_schemas(spec: Spec, anchor: str, markup: dict) -> dict[str, dict]:
    """{field_name: field_schema} for the update-request object (set_methods
    type the wrapper args from their bound update field). Flattens allOf and
    follows $refs, same reach as ``update_request_fields``."""
    coll = collection_segment(anchor, markup)
    want_verb = "put" if markup.get("update_method") == "PUT" else "patch"
    for path, item in (spec.doc.get("paths") or {}).items():
        if not path.startswith(coll + "/{"):
            continue
        if path.count("/{") != 1 or not path.endswith("}"):
            continue
        op = item.get(want_verb) or item.get("put") or item.get("patch")
        if not op:
            continue
        content = (op.get("requestBody") or {}).get("content") or {}
        for media in content.values():
            sch = media.get("schema")
            if sch:
                out: dict[str, dict] = {}
                for name, psc, _ in object_body_fields(spec, sch):
                    out[name] = psc
                return out
    return {}


def emit_command_dispatch(spec: Spec, anchor: str, markup: dict) -> str:
    name = markup["name"]
    request = markup.get("request")
    if not request:
        raise SystemExit(f"{name}: command-dispatch requires request")
    commands = discriminator_mapping(spec, request)
    # base path: the call-commands op path, else /calls under the server prefix.
    op = spec.ops.get("call-commands")
    if op:
        base = join_path(spec.server_path, op[1].lstrip("/"))
    else:
        base = join_path(spec.server_path, anchor.lstrip("/"))

    lines = []
    lines.append(f"/**\n * {name} — command-dispatch resource ({spec.name} spec).\n *\n"
                 f" * Each method POSTs {{command, params, id?}} to {base}.\n */")
    lines.append(f"class {name}")
    lines.append("{")
    lines.append("    private \\SignalWire\\REST\\HttpClient $http;")
    lines.append("")
    lines.append(f"    private const BASE_PATH = {php_str(base)};")
    lines.append("")
    lines.append("    public function __construct(\\SignalWire\\REST\\HttpClient $http)")
    lines.append("    {")
    lines.append("        $this->http = $http;")
    lines.append("    }")
    lines.append("")
    lines.append("    public function getBasePath(): string")
    lines.append("    {")
    lines.append("        return self::BASE_PATH;")
    lines.append("    }")
    lines.append("")
    lines.append("    /**")
    lines.append("     * @param array<string,mixed> $params")
    lines.append("     * @return array<string,mixed>")
    lines.append("     */")
    lines.append("    private function execute(string $command, ?string $callId, array $params = []): array")
    lines.append("    {")
    lines.append("        $body = ['command' => $command, 'params' => $params];")
    lines.append("        if ($callId !== null) {")
    lines.append("            $body['id'] = $callId;")
    lines.append("        }")
    lines.append("        return $this->http->post(self::BASE_PATH, $body);")
    lines.append("    }")
    mapping = (spec.schemas.get(request).get("discriminator") or {}).get("mapping") or {}
    for cmd in commands:
        mname = command_method_name(cmd)
        cmd_schema_ref = mapping.get(cmd) or {}
        cmd_leaf = cmd_schema_ref.rsplit("/", 1)[-1] if cmd_schema_ref else ""
        cmd_schema = spec.schemas.get(cmd_leaf, {})
        fields, with_id = command_param_fields(spec, cmd_schema)

        # Leading positional call_id (when the command schema has an ``id``).
        records: list[dict] = []
        if with_id:
            records.append({"name": "call_id", "kind": "positional",
                            "type": "string", "required": True})
        # command params → keyword named params.
        field_php: list[str] = []
        field_doc: list[str] = []
        build: list[str] = ["        $params = [];"]
        for wire_name, schema, required in ordered_fields(fields):
            ident = escape_param(wire_name)
            pt = php_param_type(spec, schema, required)
            ct = canonical_type(spec, schema, required)
            dt = php_doc_type(spec, schema, required)
            if dt:
                field_doc.append(f"     * @param {dt} ${ident}")
            rec: dict = {"name": wire_name, "kind": "keyword", "type": ct, "required": required}
            if required:
                field_php.append(f"{pt} ${ident}")
                build.append(f"        $params[{php_str(wire_name)}] = ${ident};")
            else:
                field_php.append(f"{pt} ${ident} = null")
                rec["default"] = None
                build.append(f"        if (${ident} !== null) {{")
                build.append(f"            $params[{php_str(wire_name)}] = ${ident};")
                build.append("        }")
            records.append(rec)
        # trailing forward-compat door.
        field_php.append("array $extras = []")
        field_doc.append("     * @param array<string,mixed> $extras Forward-compat command params.")
        records.append({"name": "extras", "kind": "keyword",
                        "type": "optional<dict<string,any>>", "required": False, "default": None})
        build.append("        $params = array_merge($params, $extras);")
        _register_sidecar(name, mname, records)

        id_php = ["string $callId"] if with_id else []
        sig = ", ".join(id_php + field_php)
        call_arg = "$callId" if with_id else "null"
        lines.append("")
        lines.append("    /**")
        lines.extend(field_doc)
        lines.append("     * @return array<string,mixed>")
        lines.append("     */")
        lines.append(f"    public function {mname}({sig}): array")
        lines.append("    {")
        lines.extend(build)
        lines.append(f"        return $this->execute({php_str(cmd)}, {call_arg}, $params);")
        lines.append("    }")
    lines.append("}")
    return GEN_HEADER.format(desc=f"Generated command-dispatch resource for the {spec.name!r} namespace.") + "\n" + "\n".join(lines) + "\n"


def emit_resource(spec: Spec, anchor: str, markup: dict) -> str:
    name = markup["name"]
    base = markup["base"]
    if markup.get("kind") == "command-dispatch":
        return emit_command_dispatch(spec, anchor, markup)
    if base not in EXTENDS:
        raise SystemExit(f"{name}: unknown base {base!r}")

    # §9: write-capable bases require update_method matching the spec verb.
    if base in ("CrudResource", "FabricResource"):
        upd = markup.get("update_method")
        if not upd:
            raise SystemExit(f"{name}: {base} requires update_method")
        item = spec.doc["paths"][anchor]
        spec_verb = "PUT" if item.get("put") else ("PATCH" if item.get("patch") else None)
        if spec_verb and upd != spec_verb:
            raise SystemExit(f"{name}: update_method {upd} != spec update verb {spec_verb}")

    extends = EXTENDS[base]
    # FabricResource picks its concrete PHP parent by the update verb:
    # PATCH -> FabricResource, PUT -> FabricResourcePUT (both extend
    # CrudWithAddresses, mirroring Python's _base).
    if base == "FabricResource":
        extends = "FabricResourcePUT" if markup.get("update_method") == "PUT" else "FabricResource"
    bp = base_path(spec, anchor, markup)
    recv = http_receiver(base)

    lines = []
    lines.append(f"/**\n * {name} — generated from x-sdk-resource {name!r} ({spec.name} spec, base {base}).\n */")
    lines.append(f"class {name} extends \\SignalWire\\REST\\{extends}")
    lines.append("{")

    # Constructor bakes the base path (§4).
    #   * CrudResource takes (http, basePath, updateMethod) — the verb is a
    #     per-resource arg (PATCH default, PUT override).
    #   * FabricResource / FabricResourcePUT bake the verb into the base CLASS
    #     itself (FabricResource=PATCH, FabricResourcePUT=PUT), so the subclass
    #     passes just (http, basePath) — the concrete parent is chosen by
    #     `extends` above.
    #   * ReadResource / BaseResource take (http, basePath).
    if base == "CrudResource":
        upd = markup.get("update_method", "PATCH")
        lines.append("    public function __construct(\\SignalWire\\REST\\HttpClient $http)")
        lines.append("    {")
        lines.append(f"        parent::__construct($http, {php_str(bp)}, {php_str(upd)});")
        lines.append("    }")
    else:
        lines.append("    public function __construct(\\SignalWire\\REST\\HttpClient $http)")
        lines.append("    {")
        lines.append(f"        parent::__construct($http, {php_str(bp)});")
        lines.append("    }")

    provided = BASE_PROVIDES[base]
    declared = markup.get("methods") or {}

    # ReadResource's list/get are inherited from the hand-written PHP
    # ReadResource base (mirroring Python's ReadResource) — NOT emitted into
    # the subclass body, so the surface enumerator records them on the base
    # only, matching the oracle (a ReadResource subclass records only its own
    # declared methods).

    for method_snake, spec_ref in declared.items():
        op_id = spec_ref.get("op")
        if not op_id:
            raise SystemExit(f"{name}.{method_snake}: method markup missing op")
        # A declared method the base already provides is inherited — EXCEPT
        # list_addresses re-declared with a sibling/override path (fabric
        # singular resources), which must shadow the base. And EXCEPT declared
        # list/get/create/update/delete on a BaseResource resource, which the
        # base does NOT provide, so they are emitted.
        if method_snake in provided:
            if method_snake == "list_addresses":
                verb, op_path, _ = spec.ops[op_id]
                _, sibling = relative_tail(spec, anchor, markup, op_path)
                if not sibling:
                    continue
                # sibling override: fall through and emit
            else:
                continue
        lines.append("")
        lines.append(emit_method(spec, anchor, markup, base, method_snake, op_id).rstrip("\n"))

    # set_methods (§7): require a CRUD base.
    set_methods = markup.get("set_methods") or {}
    if set_methods:
        if base not in ("CrudResource", "FabricResource"):
            raise SystemExit(f"{name}: set_methods require a CRUD base, got {base}")
        upd_fields = update_request_fields(spec, anchor, markup)
        upd_field_schemas = update_field_schemas(spec, anchor, markup)
        for sm_name, sm in set_methods.items():
            lines.append("")
            lines.append(emit_set_method(spec, markup, sm_name, sm, upd_fields, upd_field_schemas).rstrip("\n"))

    lines.append("}")
    return GEN_HEADER.format(desc=f"Generated REST resource for the {spec.name!r} namespace.") + "\n" + "\n".join(lines) + "\n"


# ---------------------------------------------------------------------------
# Client tree (§8).
# ---------------------------------------------------------------------------

# Container attr -> (PHP container class, RestClient accessor). Reproduces the
# hand FabricNamespace/VideoNamespace surface via the oracle _client_tree names.
CONTAINERS = {
    "fabric": ("FabricNamespace", "fabric"),
    "video": ("VideoNamespace", "video"),
    "logs": ("LogsNamespace", "logs"),
    "registry": ("RegistryNamespace", "registry"),
    "project": ("ProjectNamespace", "project"),
    "datasphere": ("DatasphereNamespace", "datasphere"),
}

# Accessor-name overrides — mirrors the Python reference generator's
# ``_ATTR_OVERRIDE`` table (scripts/generate_python_rest_types.py). Where the
# mechanical "snake_case, strip container prefix" derivation does not match the
# canonical accessor the reference client tree exposes, the override pins it.
# These are reference FACTS (the client-tree accessor names callers use), so
# they belong in the emitter, not an omission. Values are the canonical
# snake_case accessor; the PHP method name is snake_to_camel of it (a no-op for
# the single-word overrides here).
ATTR_OVERRIDE = {
    "GenericResources": "resources", "FabricAddresses": "addresses",
    "FabricTokens": "tokens", "DatasphereDocuments": "documents",
    "ProjectTokens": "tokens", "PubSub": "pubsub",
    "MessageLogs": "messages", "VoiceLogs": "voice", "FaxLogs": "fax",
    "ConferenceLogs": "conferences",
}


def container_accessor(markup: dict, name: str, container: str) -> str:
    """Accessor name inside the container: the attr override, else the
    reference ATTR_OVERRIDE, else snake->camel of the class name with the
    container prefix stripped."""
    if markup.get("attr"):
        return snake_to_camel(markup["attr"])
    if name in ATTR_OVERRIDE:
        return snake_to_camel(ATTR_OVERRIDE[name])
    # strip container-name prefix from the class name (VideoRooms -> rooms).
    lead = container[:1].upper() + container[1:]
    stem = name[len(lead):] if name.startswith(lead) else name
    # camelCase the pascal stem
    return stem[:1].lower() + stem[1:] if stem else name[:1].lower() + name[1:]


def resolve_placement(specs: list[Spec]):
    placed = []
    for spec in specs:
        for anchor, markup in spec.resources():
            container = markup.get("namespace") or spec.namespace_attr or ""
            placed.append((spec, anchor, markup, container))
    return placed


def emit_container(container: str, members: list[tuple[str, str]]) -> str:
    """members: list of (accessor, class_name)."""
    cls, _ = CONTAINERS[container]
    lines = []
    lines.append(f"/**\n * {cls} — generated container grouping the {container} namespace resources (§8).\n */")
    lines.append(f"class {cls}")
    lines.append("{")
    lines.append("    private \\SignalWire\\REST\\HttpClient $http;")
    for accessor, class_name in members:
        lines.append(f"    private ?{class_name} ${accessor} = null;")
    lines.append("")
    lines.append("    public function __construct(\\SignalWire\\REST\\HttpClient $http)")
    lines.append("    {")
    lines.append("        $this->http = $http;")
    lines.append("    }")
    for accessor, class_name in members:
        lines.append("")
        lines.append(f"    public function {accessor}(): {class_name}")
        lines.append("    {")
        lines.append(f"        if ($this->{accessor} === null) {{")
        lines.append(f"            $this->{accessor} = new {class_name}($this->http);")
        lines.append("        }")
        lines.append(f"        return $this->{accessor};")
        lines.append("    }")
    lines.append("}")
    return GEN_HEADER.format(desc=f"Generated REST client container for the {container} namespace (§8).") + "\n" + "\n".join(lines) + "\n"


def flat_accessor(name: str) -> str:
    if name in ATTR_OVERRIDE:
        return snake_to_camel(ATTR_OVERRIDE[name])
    return name[:1].lower() + name[1:]


def emit_resource_tree(placed) -> str:
    """Emit ResourceTree: a trait the hand RestClient composes, providing a
    lazy accessor per FLAT resource + per CONTAINER. Mirrors Go's
    _GeneratedResourceTree (kept off the enumerated surface via the underscore
    module + adapter — see report)."""
    flats = []           # (accessor, class)
    containers_seen = []  # ordered container attrs
    seen_c = set()
    for spec, anchor, markup, container in placed:
        name = markup["name"]
        if not container:
            flats.append((flat_accessor(name), name))
        else:
            if container not in seen_c:
                seen_c.add(container)
                containers_seen.append(container)

    lines = []
    lines.append("/**\n * ResourceTree — generated lazy accessors for every flat REST resource\n"
                 " * plus the namespace containers (§8). The hand RestClient composes this via\n"
                 " * `use ResourceTree;`. Placement resolved from x-sdk-namespace.attr +\n"
                 " * per-resource x-sdk-resource.namespace/attr; base paths per §4.\n */")
    lines.append("trait ResourceTree")
    lines.append("{")
    # property declarations
    for accessor, cls in flats:
        lines.append(f"    private ?{cls} ${accessor} = null;")
    for c in containers_seen:
        clsname, acc = CONTAINERS[c]
        lines.append(f"    private ?{clsname} ${acc} = null;")
    lines.append("")
    lines.append("    abstract protected function generatedHttpClient(): \\SignalWire\\REST\\HttpClient;")
    for accessor, cls in flats:
        lines.append("")
        lines.append(f"    public function {accessor}(): {cls}")
        lines.append("    {")
        lines.append(f"        if ($this->{accessor} === null) {{")
        lines.append(f"            $this->{accessor} = new {cls}($this->generatedHttpClient());")
        lines.append("        }")
        lines.append(f"        return $this->{accessor};")
        lines.append("    }")
    for c in containers_seen:
        clsname, acc = CONTAINERS[c]
        lines.append("")
        lines.append(f"    public function {acc}(): {clsname}")
        lines.append("    {")
        lines.append(f"        if ($this->{acc} === null) {{")
        lines.append(f"            $this->{acc} = new {clsname}($this->generatedHttpClient());")
        lines.append("        }")
        lines.append(f"        return $this->{acc};")
        lines.append("    }")
    lines.append("}")
    return GEN_HEADER.format(desc="Generated REST resource tree trait the hand RestClient composes (§8).") + "\n" + "\n".join(lines) + "\n"


# ---------------------------------------------------------------------------
# Driver.
# ---------------------------------------------------------------------------

def build_outputs(psdk: Path) -> dict[str, str]:
    load_bases(psdk)  # validate x-sdk-bases (fail loud); not otherwise needed
    _SIDECAR.clear()
    specs = [load_spec(psdk, ns) for ns in SPEC_DIRS]
    outs: dict[str, str] = {}
    for spec in specs:
        for anchor, markup in spec.resources():
            src = emit_resource(spec, anchor, markup)
            outs[markup["name"] + ".php"] = src
    placed = resolve_placement(specs)
    # containers
    by_container: dict[str, list[tuple[str, str]]] = {}
    order: list[str] = []
    for spec, anchor, markup, container in placed:
        if not container:
            continue
        if container not in by_container:
            by_container[container] = []
            order.append(container)
        acc = container_accessor(markup, markup["name"], container)
        by_container[container].append((acc, markup["name"]))
    for container in order:
        if container not in CONTAINERS:
            raise SystemExit(f"container attr {container!r} has no PHP container class (add to CONTAINERS)")
        cls, _ = CONTAINERS[container]
        outs[cls + ".php"] = emit_container(container, by_container[container])
    outs["ResourceTree.php"] = emit_resource_tree(placed)

    # Sidecar (§5): the canonical typed-param records the signature enumerator
    # UNFOLDS onto the reflected PHP methods (PHP reflection can't express
    # keyword-only kind, array element types, or the open ``extras`` dict).
    # Keyed "<ClassName>::<phpMethod>". Deterministic ordering for GEN-FRESH.
    sidecar: dict[str, list[dict]] = {}
    for (cls, php_method) in sorted(_SIDECAR.keys()):
        sidecar[f"{cls}::{php_method}"] = _SIDECAR[(cls, php_method)]
    import json as _json
    outs["rest_signatures.json"] = _json.dumps(
        {
            "_comment": "Code generated by scripts/generate_rest.py; DO NOT EDIT. "
                        "Canonical typed-param records for generated REST operation/"
                        "command/set methods; consumed by scripts/enumerate_signatures.py "
                        "to unfold the reflected PHP params onto the Python oracle shape.",
            "methods": sidecar,
        },
        indent=2, sort_keys=False,
    ) + "\n"
    return outs


def main(argv: list[str]) -> int:
    ap = argparse.ArgumentParser(description=__doc__)
    ap.add_argument("--check", action="store_true", help="GEN-FRESH: exit non-zero if stale")
    ap.add_argument("--out", default="", help="scratch: emit flat into this dir")
    args = ap.parse_args(argv)

    psdk = resolve_porting_sdk()
    outs = build_outputs(psdk)

    if args.out:
        out_dir = Path(args.out)
    else:
        out_dir = repo_root() / "src" / "SignalWire" / "REST" / "Namespaces" / "Generated"

    if args.check:
        stale = []
        for fn, src in outs.items():
            p = out_dir / fn
            if not p.is_file() or p.read_text() != src:
                stale.append(str(p))
        if stale:
            sys.stderr.write("GEN-FRESH FAIL: %d generated REST file(s) stale:\n" % len(stale))
            for s in stale:
                sys.stderr.write("  - %s\n" % s)
            return 1
        print("GEN-FRESH: generated REST files match the canonical specs.")
        return 0

    out_dir.mkdir(parents=True, exist_ok=True)
    for fn, src in outs.items():
        (out_dir / fn).write_text(src)
        print(f"generated {out_dir / fn}")
    return 0


if __name__ == "__main__":
    raise SystemExit(main(sys.argv[1:]))
