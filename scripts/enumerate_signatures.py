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
)


class TypeTranslationError(RuntimeError):
    pass


def load_aliases() -> dict[str, str]:
    data = yaml.safe_load((PSDK / "type_aliases.yaml").read_text(encoding="utf-8"))
    return {str(k): str(v) for k, v in data.get("aliases", {}).get("php", {}).items()}


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


def collect(raw: dict, aliases: dict) -> tuple[dict, list]:
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
        # Try BOTH the original and canonical names against CLASS_MODULE_MAP.
        # CLASS_MODULE_MAP is mostly keyed by PHP-native names (Service, Client),
        # not Python-canonical names (SWMLService, RelayClient).
        if php_name in CLASS_MODULE_MAP:
            mod = CLASS_MODULE_MAP[php_name]
        elif canonical_name in CLASS_MODULE_MAP:
            mod = CLASS_MODULE_MAP[canonical_name]
        else:
            mod = _module_path_for_class(canonical_name, file_relative)

        methods_out: dict = {}
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
            ctx = f"{mod}.{canonical_name}.{method_canonical}"
            try:
                sig = build_signature(m, aliases, ctx)
            except TypeTranslationError as e:
                failures.append(str(e))
                continue
            if method_canonical in methods_out:
                continue
            methods_out[method_canonical] = sig

        # Properties → zero-arg "method" entries
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

    # Mixin projection — pick matching methods off AgentBase or SWMLService
    # depending on the (source_cls) the projection points at.
    ab_entry = out_modules.get("signalwire.core.agent_base", {}).get("classes", {}).get("AgentBase")
    sm_entry = out_modules.get("signalwire.core.swml_service", {}).get("classes", {}).get("SWMLService")
    ab_methods = (ab_entry or {}).get("methods", {})
    sm_methods = (sm_entry or {}).get("methods", {})

    if ab_methods or sm_methods:
        projected_ab: set[str] = set()
        for (target_mod, target_cls), (source_cls, expected) in MIXIN_PROJECTIONS.items():
            source_methods = sm_methods if source_cls == "SWMLService" else ab_methods
            present = {m: source_methods[m] for m in expected if m in source_methods}
            if not present:
                continue
            out_modules.setdefault(target_mod, {"classes": {}})
            out_modules[target_mod]["classes"].setdefault(target_cls, {"methods": {}})
            out_modules[target_mod]["classes"][target_cls]["methods"].update(present)
            # Only de-duplicate against AgentBase — SWMLService methods that
            # are also projected to a mixin still belong on SWMLService.
            if source_cls != "SWMLService":
                projected_ab.update(present)
        for n in projected_ab:
            ab_methods.pop(n, None)
        if ab_entry is not None and not ab_methods:
            out_modules["signalwire.core.agent_base"]["classes"].pop("AgentBase", None)
            if not out_modules["signalwire.core.agent_base"]["classes"]:
                out_modules.pop("signalwire.core.agent_base")

    sorted_modules = {}
    for k in sorted(out_modules):
        entry = out_modules[k]
        sorted_modules[k] = {
            "classes": {
                cls: {"methods": dict(sorted(entry["classes"][cls]["methods"].items()))}
                for cls in sorted(entry["classes"])
            }
        }
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

    canonical, failures = collect(raw, aliases)
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
