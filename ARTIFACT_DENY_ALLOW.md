# Artifact-deny allowlist (php)

These files are TRACKED in the repo (they are the porting-audit contract + in-repo
audit tooling) but are EXCLUDED from the published Composer/Packagist distribution
by `.gitattributes` `export-ignore`. Composer/Packagist serve the "dist" tarball
produced by `git archive`, which honours `export-ignore`, so none of these enter
the published `signalwire/sdk` package. The `git ls-files` proxy flags them; the
authoritative package listing is clean:

    git archive --worktree-attributes --format=tar HEAD | tar -t \
      | python3 ../porting-sdk/scripts/artifact_deny.py --port php --listing -
    => [artifact-deny] php: clean

Each entry below is proven excluded from the dist archive by a corresponding
`.gitattributes` `export-ignore` rule (verified 2026-07-06); it ships in-repo only.
The shared porting-sdk audit pipeline reads/regenerates each in place at the repo
root, so it must stay tracked.

- CHECKLIST.md — audit contract; export-ignore in .gitattributes (orchestrator, 2026-07-06)
- DOC_AUDIT_IGNORE.md — audit contract; export-ignore in .gitattributes (orchestrator, 2026-07-06)
- PROGRESS.md — porting-process progress file; export-ignore in .gitattributes (orchestrator, 2026-07-06)
- REST_COVERAGE_GAPS.md — audit contract; export-ignore in .gitattributes (orchestrator, 2026-07-06)
- PORT_ADDITIONS.md — audit contract; export-ignore in .gitattributes (orchestrator, 2026-07-06)
- PORT_EXAMPLE_OMISSIONS.md — audit contract; export-ignore in .gitattributes (orchestrator, 2026-07-06)
- PORT_OMISSIONS.md — audit contract; export-ignore in .gitattributes (orchestrator, 2026-07-06)
- PORT_SIGNATURE_OMISSIONS.md — audit contract; export-ignore in .gitattributes (orchestrator, 2026-07-06)
- PORT_TEST_OMISSIONS.md — audit contract; export-ignore in .gitattributes (orchestrator, 2026-07-06)
- audit_coverage.json — audit contract; export-ignore in .gitattributes (orchestrator, 2026-07-06)
- audit_coverage_baseline.json — audit contract; export-ignore in .gitattributes (orchestrator, 2026-07-06)
- port_signatures.json — audit contract; export-ignore in .gitattributes (orchestrator, 2026-07-06)
- port_signatures.baseline.json — porting release-floor snapshot read by scripts/run-ci.sh SEMVER-DIFF and porting-sdk semver_diff.py; audit-pipeline artifact, not shipped library code (orchestrator, 2026-07-14)
- port_surface.json — audit contract; export-ignore in .gitattributes (orchestrator, 2026-07-06)
- examples/RelayAuditHarness.php — in-repo audit tooling; export-ignore in .gitattributes (orchestrator, 2026-07-06)
- examples/RestAuditHarness.php — in-repo audit tooling; export-ignore in .gitattributes (orchestrator, 2026-07-06)
- examples/SkillsAuditHarness.php — in-repo audit tooling; export-ignore in .gitattributes (orchestrator, 2026-07-06)
- scripts/emit_corpus.php — in-repo EMISSION-DUMP tool; export-ignore in .gitattributes (orchestrator, 2026-07-06)
- scripts/emit_skills.php — in-repo SKILL-DUMP tool; export-ignore in .gitattributes (orchestrator, 2026-07-06)
