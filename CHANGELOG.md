# Changelog

All notable changes to the SignalWire PHP SDK (`signalwire/sdk`) are documented
in this file.

## [3.3.0] - 2026-07-18

### REST
- Added **`SignalWireRestTransportError`** — a member of the
  `SignalWireRestError` family raised when a REST request never reaches a
  response (connection refused, DNS failure, connection reset, TLS error). It
  carries `status_code = null` and the underlying transport message as `body`,
  so a caller catching `SignalWireRestError` handles both HTTP-error and
  transport-error cases with one `catch`.

### RELAY
- `liveTranscribe()` / `liveTranslate()` now wrap the caller's action under the
  required `params.action` envelope on the wire, matching the authoritative
  `calling.live_transcribe` / `live_translate` schema (previously the action was
  forwarded flat, producing a frame the server rejects).

## [3.2.0] - 2026-07-14

### REST
- Added the **Messages** resource (`$client->messages()`) — send and redact
  messages over the native `/api/messaging/messages` API: `create(...)` (POST
  `/api/messaging/messages`, send an outbound SMS/MMS) and `update($messageId,
  $body)` (PATCH `/api/messaging/messages/{message_id}`, redact a message body).
  A send/redact subset of CRUD (no list/get/delete). Distinct from the message
  *logs* namespace (`$client->logs()->messages`). Both routes are covered by
  success and error wire tests over the shared mock. Generated from the canonical
  `rest-apis/messages` spec via the spec-driven REST generator.

## [3.1.0] - 2026-07-14

### REST
- Added the **Projects** resource (`$client->projects()`) — full CRUD over the
  native `/api/projects` project-management API (list/get/create/update/delete of
  projects and subprojects), plus `rotateSigningKey($id)` (POST
  `/api/projects/{id}/signing-key/rotate`). Distinct from the singular
  `$client->project()` token namespace. Every route is covered by success and
  error wire tests over the shared mock. Generated from the canonical
  `rest-apis/projects` spec via the spec-driven REST generator.

## [3.0.2] - 2026-07-13

First release cut off the cross-port parity surface. The public API is now
generated from, and continuously verified against, the shared SignalWire wire
specification (the Python SDK is the reference oracle), so this port is
functionally at parity with the other language SDKs.

### REST
- `RestClient` and its resource namespaces (`fabric`, `calling`, `video`,
  `messaging`, `chat`, `fax`, `datasphere`, `pubsub`, `logs`, `project`, and the
  relay-rest resources) are generated from the canonical REST specs; every
  implemented route is covered by success and error wire tests.
- `list()` returns a lazy `PaginatedIterator`: iterating it yields every result
  across pages, transparently following the `links.next` cursor and stopping when
  a page comes back empty (no next link or an empty page ends iteration).
- REST errors carry the full `(status, body, url, method)` envelope and are raised
  on any HTTP status `>= 400`.

### SWML / SWAIG / RELAY
- SWML verbs, RELAY protocol payloads, and SWAIG payloads are generated from the
  authoritative schemas and validated for wire-shape parity with the reference.
- `FunctionResult` exposes the full set of engine SWAIG actions.

### Packaging / release
- PHP floor is `>= 8.2` (see `composer.json`; the CI matrix tests 8.2/8.3/8.4).
- Release-readiness is now enforced in CI: signature/surface drift, a SemVer floor
  (`port_signatures.baseline.json`), documentation-vs-code truth gates, and a
  gated publish workflow.

## [1.1.0] - 2026-03-17

- Earlier pre-parity release.
