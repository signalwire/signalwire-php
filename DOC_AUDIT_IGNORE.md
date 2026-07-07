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

getMessage: \Throwable::getMessage() — exception inspection in error-handling examples
toArray: PHP collection idiom — many doc examples assume arrays support toArray() (cast to array)
from: PHP enum::from() / fluent argument naming — not a SDK method
to: PHP fluent argument / cast keyword — not a SDK method
sleep: PHP built-in (and SWML verb name; auto-vivified through Document::addVerb)
protocol: relay-event field accessor name (`event.protocol`) — not a method call
state: relay-event field accessor name (`event.state`) — not a method call
url: relay-message field accessor name — not a method call
media: relay-message field accessor name — not a method call
callId: relay-event field accessor name (`event.callId`) — not a method call
phoneNumber: REST resource path identifier — used in docs as a URL segment, not a method
device: relay-event subfield (`event.device`) — not a method call
route: SWML route field name in JSON — not a method call

## SWML auto-vivified verbs

These are SWML verbs from `schema.json` that the Document class
auto-vivifies via `__call`. They appear in PHP examples as
`$service->answer()` / `$service->hangup()` etc; the call dispatches
through Service::__call to Document::addVerb. The verb is real and runs;
audit_docs sees no explicit method declaration.

addApplication: SWML <application> verb — auto-vivified by Document::__call
connectWs: SWML <connect_ws> / <connect> verb — auto-vivified
disconnectWs: SWML <disconnect_ws> / <disconnect> verb — auto-vivified
addMcpServer: AgentBase MCP-server config helper — exposed via promptManager
enableMcpServer: AgentBase MCP-server config helper

## REST CrudResource dynamic methods

PHP exposes most REST namespaces as `CrudResource` instances via
`RestClient::<namespace>()`. Per-resource sub-operations (`listMembers`,
`createCampaign`, `assignPhoneRoute`, etc) are dispatched dynamically by
the underlying HTTP path. The methods are real; the surface enumerator
doesn't pick them up because they're URL-driven, not declared methods.

addMembership: Fabric subscriber-membership helper
assignDomainApplication: Fabric domain-application assignment
assignPhoneRoute: deprecated phone-route helper (kept for back-compat doc)
basicAuthPassword: SWMLService accessor (camelCase form of `basicAuthPassword` config field)
basicAuthUser: SWMLService accessor (camelCase form of `basicAuthUser` config field)
call: REST resource-name fragment (`client.call(...)`) and rest/docs/namespaces.md docs reference
createCampaign: Registry namespace dynamic method (10DLC campaigns)
createOrder: Registry namespace dynamic method (10DLC orders)
createEmbedToken: Fabric token-creation helper
createGuestToken: Fabric token-creation helper
createInviteToken: Fabric token-creation helper
createSubscriberToken: Fabric token-creation helper
createSipEndpoint: Fabric SIP-endpoint helper
createStream: Compat stream-creation helper
createToken: Compat token-creation helper
deleteSipEndpoint: Fabric SIP-endpoint helper
deployVersion: Fabric SWML-version deploy helper
getCode: REST error-response accessor (HTTP status code on SignalWireRestError)
getId: REST resource-id accessor in error responses
getNextMember: Compat queue next-member helper
getSipEndpoint: Fabric SIP-endpoint helper
listAddresses: Fabric addresses listing
listCampaigns: Registry namespace dynamic method
listChunks: Datasphere documents chunks helper
listConferenceTokens: Fabric conference-token helper
listEvents: Compat events listing
listMembers: Compat conference-members listing
listMemberships: Fabric subscriber-memberships listing
listNumbers: Registry/numbers listing
listOrders: Registry orders listing
listRecordings: Compat recording listing
listSipEndpoints: Fabric SIP-endpoint listing
listVersions: Fabric SWML-version listing
purchase: Compat phone-number purchase helper
search: Datasphere/native_vector_search query helper (also a generic verb name)
sms: Compat SMS resource dispatch (`client.sms(...)`)
submitVerification: VerifiedCallers verification-submission helper
verify: VerifiedCallers verification-token helper

## SWML / web-service helpers documented but not on the public surface

handleServerlessRequest: serverless adapter helper (Adapter::dispatch)
                          — internal to the Adapter class
resetDocument: Document::reset alias used in dynamic_swml_service example
setQuestionCallback: dynamic-info-gatherer callback hook (per-request question hook)
