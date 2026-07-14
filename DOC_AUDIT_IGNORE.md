# DOC_AUDIT_IGNORE — names docs/audits should ignore

Identifiers appearing in `docs/`, `rest/docs/`, `relay/docs/`, and `examples/`
that `scripts/audit_docs.py` should ignore. Each line is of the form
`<name>: <rationale>`. The audit treats the name before the `:` as an exact
identifier. Grouped by category.

See `CHECKLIST.md` (Phase 13 — Doc↔code alignment) for the audit contract.

---

## PHP language / standard library

These are PHP built-ins or stdlib methods that show up in PHP example files
and `` ```php `` code blocks. They are not part of the SignalWire SDK
surface and never will be.

__construct: PHP constructor / `parent::__construct(...)` — language keyword, not an SDK method (dynamic_swml_service example)
sleep: PHP built-in (and SWML verb name; auto-vivified through Document::addVerb)

## SWML auto-vivified verbs

These are SWML verbs from `schema.json` that the `Service` class
auto-vivifies via `Service::__call` (dispatching to `Document::addVerb`,
validated by `Schema::isValidVerb`). They appear in PHP examples as
`$service->answer()` / `$service->hangup()` etc; `audit_docs` sees no
explicit method declaration but the verb is real and runs. The only such verb
needing an ignore entry is `sleep` (listed above under stdlib, since it also
collides with the PHP built-in of the same name); the rest resolve via the
snake→camel surface translation and need no entry.

## Idiom-spelling rename (real method on the surface under its canonical name)

The method IS on `port_surface.json`, but the enumerator canonicalises the PHP
idiom spelling to the Python-canonical name, and the doc-auditor's cosmetic
snake→camel translation can't reverse that rename — so the doc's idiom spelling
doesn't match. Not a missing symbol.

toArray: real public method on `FunctionResult`/`ParameterSchema`/`Device`/POM/`ContextBuilder`; the enumerator canonicalises `toArray`→`to_dict` (matching Python's `to_dict`, per PORT_ADDITIONS.md), so `->toArray()` in docs can't resolve to the recorded `to_dict`

## Doc-negation / field accessor names (mentioned, not called)

These identifiers appear in a doc only to say the SDK does NOT expose them — a
negation the resolver still tries to resolve. No such method exists on the surface.

getId: doc-negation reference — `rest/docs/client-reference.md` says "No `->getId()`" (array-access idiom instead); no such method exists, the name is only mentioned to state it is absent
