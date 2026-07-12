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
toArray: PHP collection idiom — many doc examples assume arrays support toArray() (cast to array)
sleep: PHP built-in (and SWML verb name; auto-vivified through Document::addVerb)
protocol: relay-event field accessor name (`event.protocol`) — not a method call
state: relay-event field accessor name (`event.state`) — not a method call
callId: relay-event field accessor name (`event.callId`) — not a method call
device: relay-event subfield (`event.device`) — not a method call

## SWML auto-vivified verbs

These are SWML verbs from `schema.json` that the Document class
auto-vivifies via `__call`. They appear in PHP examples as
`$service->answer()` / `$service->hangup()` etc; the call dispatches
through Service::__call to Document::addVerb. The verb is real and runs;
audit_docs sees no explicit method declaration.

addApplication: SWML <application> verb — auto-vivified by Document::__call
connectWs: SWML <connect_ws> / <connect> verb — auto-vivified
disconnectWs: SWML <disconnect_ws> / <disconnect> verb — auto-vivified

## REST CrudResource dynamic methods

PHP exposes most REST namespaces as `CrudResource` instances via
`RestClient::<namespace>()`. Per-resource sub-operations (`listMembers`,
`createCampaign`, `assignPhoneRoute`, etc) are dispatched dynamically by
the underlying HTTP path. The methods are real; the surface enumerator
doesn't pick them up because they're URL-driven, not declared methods.

basicAuthPassword: SWMLService accessor (camelCase form of `basicAuthPassword` config field)
basicAuthUser: SWMLService accessor (camelCase form of `basicAuthUser` config field)
getId: REST resource-id accessor in error responses

## SWML / web-service helpers documented but not on the public surface

                          — internal to the Adapter class

## Real public methods the audit resolver can't match by `Class::method()` static-call form

These ARE public methods present in `port_surface.json` and called correctly in docs; the
doc-audit resolver matches instance-call `$obj->method()` but not the `Class::method()`
static-reference form the doc uses, so it can't resolve them. The method is real; this is a
resolver-syntax limitation, not doc rot or a missing symbol.

serveStatic: `AgentServer::serveStatic(string $directory, string $urlPrefix)` — real public method (src/SignalWire/Server/AgentServer.php:257), on the surface + python-oracle (serve_static); referenced as `AgentServer::serveStatic()` in docs/web_service.md
