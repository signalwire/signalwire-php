# SNIPPET_RUN allowlist

Documented snippets that legitimately cannot run to a zero exit against the
shared mock, listed as `- path:line — reason (approver, date)`. The preferred
suppression is an inline `<!-- snippet: no-run reason -->` marker; this ledger is
for the cases where an inline marker isn't possible (e.g. an include-synced
block, whose body must stay byte-identical to its fixture and so can't carry an
extra comment). The SNIPPET-RUN gate skips these; anything NOT here must run.

- README.md:159 — quickstart-rest include: constructs a RestClient from
  $_ENV then issues four LIVE REST calls (create/play/search) against
  SIGNALWIRE_SPACE. Under SNIPPET-RUN the injected space is the non-loopback
  `example.signalwire.com`, so the calls target the real platform, not the mock
  (the loopback-http path only applies to a 127.0.0.1/localhost host). The
  example itself (examples/quickstart_rest.php) DOES run under EXAMPLES-RUN,
  which points SIGNALWIRE_SPACE at the loopback mock. Include-synced, so it can't
  carry an inline no-run marker. (lane-php, 2026-07-19)
