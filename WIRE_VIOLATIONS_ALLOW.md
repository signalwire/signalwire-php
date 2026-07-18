# WIRE_VIOLATIONS_ALLOW.md — signed exceptions to the STRICT-MOCKS wire-truth gate

The STRICT-MOCKS consumer (`porting-sdk/scripts/assert_no_wire_violations.py`, wired
into REST-COVERAGE / EXAMPLES-RUN / SNIPPET-RUN) reads the mock journal after a run
and fails on ANY `wire_violation` — a request/frame that put a shape on the wire the
OpenAPI/RELAY spec does not declare (an undeclared query param, an unknown body key,
an unknown frame field). A wire violation is a spec bug or a real defect; the fix is
to make the wire match the spec, NOT to allowlist it.

This file exists for the rare, genuinely-justified exception, and each entry needs a
human-signed reason. Format (one per line):

    - <kind>:<name> — reason (approver, date)

where `<kind>` is the violation kind (`unknown_query_param`, `unknown_body_key`,
`unknown_frame_field`, `duplicate_command_id`) and `<name>` is the offending
key/param name. A bare `kind:name` with no ` — reason` is NOT matched, so it cannot
silently widen the allowlist.

## Currently empty

No entries. The wired gates (REST-COVERAGE / EXAMPLES-RUN / SNIPPET-RUN) run wire-clean
against the reference.

Known spec gaps were surfaced during the STRICT-MOCKS bring-up but are NOT allowlisted
here (a name-only token like `unknown_query_param:page_size` would over-broadly mask
any future real violation on the many endpoints that DO declare `page_size`). Instead
the hand-authored tests that deliberately exercise these gaps are tagged
`#[Group('wire-regression-pin')]` and excluded from the wire-truth selector in
`rest_coverage_gate` (`scripts/run-ci.sh`) — they still run under the plain TEST gate
and still count toward REST-COVERAGE's route-hit tally, they just don't feed the
wire-truth consumer a violation from a known, tracked gap:

  * `page_size` on `relay-rest.list_recordings` (`tests/Rest/SmallNamespacesMockTest.php`
    — `recordingsList`) — the spec's `list_recordings` op had `parameters: []` while
    every sibling `list_*` op declares `page_size`. Owner-approved to FIX THE SPEC (add
    `page_size`); fixed on porting-sdk branch `fix/recordings-pagination-spec` (not yet
    merged to `main` as of this writing — re-drift once it lands and this pin can be
    un-tagged).
  * `cursor` + `page_size` on `fabric.list_fabric_addresses`
    (`tests/Rest/PaginationMockTest.php` — `nextPagesThroughAllItems`,
    `resourcePaginateWalksAllPagesFollowingCursor`, `resourcePaginateForwardsInitialParams`)
    — same class: the fabric list ops have `parameters: []`, but the server returns a
    `links.next` cursor URL that the SDK's generic `PaginatedIterator` replays as a
    `?cursor=` param. Same owner+prime-rails adjudication as recordings; tracked
    separately.
  * `page_size` on `fabric.list_subscribers` / `fabric.list_subscriber_addresses`
    (`tests/Rest/BaseResourceTest.php` — `crudWithAddressesInheritsCrudList`,
    `crudWithAddressesListAddressesCallsAddressesSubpath`) — same fabric-pagination
    spec-gap family as `list_fabric_addresses` above (these tests construct a generic
    `CrudWithAddresses` against the Subscribers base path to probe the base-class
    query-param-forwarding mechanism, which happens to collide with the real routes).
