# PORT_OMISSIONS.md

Per Phase 13 of the porting checklist, every Python-reference symbol
that the PHP port deliberately does NOT implement is recorded here
with a one-line rationale. The audit tool that validates this is
`/usr/local/home/devuser/src/porting-sdk/scripts/diff_port_surface.py`,
gated on the existence of `port_surface.json` (which PHP does not yet
ship — see SUBAGENT_PLAYBOOK § Per-port-architecture-notes).

Format: `<fully.qualified.symbol>: <one-sentence rationale>`

## Omitted symbols

- `signalwire.skills.native_vector_search.skill.NativeVectorSearchSkill._search_local`:
  PHP doesn't ship a local vector-search backend. The skill supports
  network/remote mode only; `remote_url` is mandatory. Local SQLite
  and pgvector backends require Python's `signalwire.search`
  (sentence-transformers, NLTK/spaCy stopword stacks, sqlite-vss /
  pgvector drivers) — none of which has a maintained PHP port. The
  network-mode path is fully implemented and is verified by
  `audit_skills_dispatch.py`.

- `signalwire.skills.claude_skills.skill.ClaudeSkillsSkill._execute_shell_injection`:
  PHP's port implements SKILL.md discovery, frontmatter parsing,
  section enumeration, and `$ARGUMENTS` substitution — but does NOT
  evaluate `!`command`` shell-injection patterns even when
  `allow_shell_injection=true` is passed. Python defaults this off as
  well; turning it on means subprocess() of arbitrary attacker-
  controlled bytes from a SKILL.md, which we judged worth shipping
  off-by-default in Python but not worth shipping at all in PHP given
  that nothing in the PHP example suite uses it. The flag is accepted
  for cross-port API compatibility; the patterns pass through verbatim.

- `signalwire.skills.web_search.skill.GoogleSearchScraper._scrape_html`
  / `extract_reddit_content` / `_calculate_content_quality`:
  Python's web_search skill does Google CSE → per-result HTML scrape
  → quality scoring. The PHP port implements the CSE call faithfully
  and surfaces titles+snippets+links from the search response, but
  does NOT walk each hit and pull its full body (which in Python uses
  BeautifulSoup, lxml, and a per-domain quality table). The
  per-result scrape is layered on top of the upstream call that the
  audit verifies; the SDK's transport correctness and parse semantics
  are intact. A future PR can wire spider's strip_tags pipeline into
  the web_search hits without breaking the surface.
