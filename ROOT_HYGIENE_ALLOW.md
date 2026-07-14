# ROOT_HYGIENE_ALLOW.md

Repo-root files excused from the `root_hygiene` gate. Each is a load-bearing
porting-audit contract file that porting-sdk's shared audit scripts read at the
repo root by relative path; moving them under `eng/` would break the shared
audit pipeline (which this repo cannot edit). Verified reader per file below.

- CHECKLIST.md — required audit-contract file read by porting-sdk audit scripts (orchestrator, 2026-07-06)
- DOC_AUDIT_IGNORE.md — required audit-contract file read by porting-sdk audit scripts (audit_docs.py, ignore_ledger_verify.py) (orchestrator, 2026-07-06)
- PORT_ADDITIONS.md — required audit-contract file read by porting-sdk audit scripts (diff_port_signatures.py) (orchestrator, 2026-07-06)
- PORT_OMISSIONS.md — required audit-contract file read by porting-sdk audit scripts (diff_port_signatures.py) (orchestrator, 2026-07-06)
- PORT_SIGNATURE_OMISSIONS.md — required audit-contract file read by porting-sdk audit scripts (diff_port_signatures.py) (orchestrator, 2026-07-06)
- PORT_TEST_OMISSIONS.md — required audit-contract file read by porting-sdk audit scripts (orchestrator, 2026-07-06)
- PORT_EXAMPLE_OMISSIONS.md — required audit-contract file read by porting-sdk audit scripts (audit_example_parity.py) (orchestrator, 2026-07-06)
- REST_COVERAGE_GAPS.md — required audit-contract file read by porting-sdk audit scripts (orchestrator, 2026-07-06)
- PROGRESS.md — required porting-process progress file referenced by CLAUDE.md and the artifact_deny ledger (orchestrator, 2026-07-06)
- audit_coverage.json — required audit-contract file read by porting-sdk audit scripts (audit_coverage_map.py) (orchestrator, 2026-07-06)
- audit_coverage_baseline.json — required audit-contract file read by porting-sdk audit scripts (audit_coverage_map.py) (orchestrator, 2026-07-06)
- port_signatures.json — required audit-contract file read by porting-sdk audit scripts (diff_port_signatures.py, run-ci.sh, and 8 others) (orchestrator, 2026-07-06)
- port_signatures.baseline.json — release-floor snapshot read at root by scripts/run-ci.sh SEMVER-DIFF and porting-sdk semver_diff.py (BASELINE_FILE), the authoritative SemVer floor (orchestrator, 2026-07-14)
- port_surface.json — required audit-contract file read by porting-sdk audit scripts (audit_docs.py, ignore_ledger_verify.py, run-ci.sh) (orchestrator, 2026-07-06)
- ARTIFACT_DENY_ALLOW.md — gate-control file for the artifact_deny gate (orchestrator, 2026-07-06)
- META_CONSISTENT_ALLOW.md — gate-control file for the meta_consistent gate (orchestrator, 2026-07-06)
- ROOT_HYGIENE_ALLOW.md — gate-control file for this root_hygiene gate (orchestrator, 2026-07-06)
- EXAMPLES_RUN_ALLOW.md — gate-control allowlist read at repo root by porting-sdk examples_run.py (approver: user 2026-07-06 audit-contract class; ruby already has it)
