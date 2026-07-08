# meta-consistent allowlist (php)

- floor:php — The port's real PHP floor is **8.2**: `composer.json` requires
  `php >=8.2`, `README.md` states "Requires PHP 8.2+", and the CI matrix
  (`.github/workflows/test.yml`) tests 8.2/8.3/8.4. The floor was deliberately
  bumped 8.1→8.2 (commit history on `composer.json`). The lone dissenting source
  is `PORT_PHILOSOPHY_PHP.md`, which still says `php >=8.1` — but that doc lives
  in the porting-sdk repo and cannot be edited from this port repo. The two
  in-repo sources this gate can act on (manifest + README) already agree at 8.2;
  the mismatch is entirely the stale porting-sdk philosophy doc. Reported to the
  orchestrator to update PORT_PHILOSOPHY_PHP.md 8.1→8.2. (orchestrator, 2026-07-06)
