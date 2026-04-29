# PORT_EXAMPLE_OMISSIONS.md

Per Phase 13 of the porting checklist, every Python example must have a
port-equivalent under `examples/` OR be recorded here with a one-line
rationale. The audit tool that validates this is
`/usr/local/home/devuser/src/porting-sdk/scripts/audit_example_parity.py`.

Format: one Markdown list item per omission, normalized stem only.

## Omitted examples

- `local_search_agent` — depends on Python's `signalwire.search`
  package (sqlite/pgvector index builder, query embeddings via
  sentence-transformers). PHP has no first-class equivalent for either
  the embedding model or the index builder, so the local search
  backend is documented as an omission in `PORT_OMISSIONS.md`. The
  network-mode (`remote_url`) path of the `native_vector_search`
  skill is fully implemented and is exercised by audit_skills_dispatch.
