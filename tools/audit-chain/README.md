# Independent audit-chain snapshot checker (experimental)

**Not a concurrency fix or acceptance result.** This read-only tool checks a
whole consistent snapshot, including each stored `prev_hash` against the prior
row and each `row_hash` against its own recorded predecessor and canonical
fields. It continues after failures and reports IDs and error types, not
patient details. The snapshot begins at genesis; there is no arbitrary row
limit. The production `HashChain::verify` and `AuditLogger::verifyChain` are
not used. Test the exporter in a disposable environment before operational use;
its WP-CLI/database integration has not yet been exercised.

On an approved disposable WordPress test site with the CPMS plugin active,
from the repository root (adjust `--path` for that site):

```bash
set -o pipefail
wp --path=/path/to/wordpress eval-file tools/audit-chain/export.php | python3 tools/audit-chain/verify.py
```

Run `wp` with a database account permitted to select the audit table; the
exporter starts a repeatable-read consistent snapshot, pages in ID order, and
finishes the transaction without modifying data. Use an isolated site or a
quiet window: a long read transaction can retain InnoDB undo history. On a
busy site the result describes the snapshot, not rows committed afterward.
The header/end counts and max ID detect truncation or query failure; trailing
output, missing fields and malformed hashes fail closed. Exit statuses: 0 valid,
1 broken, 2 unusable/incomplete input (the shell pipeline also fails if WP-CLI
fails). If WP-CLI emits notices to stdout, the verifier rejects the stream;
fix the environment, do not strip arbitrary lines.

The stream contains clinic, patient and user identifiers, hashed IP and
session/request IDs. Pipe directly; do not place it in Git, CI logs or public
artifacts. Treat any retained output as sensitive and follow approved access,
encryption and retention policies. The final report contains broken audit IDs.
Neither this tool nor the app verifier establishes integrity of excluded
`before_json`, `after_json` and `meta_json`, nor can an unanchored chain detect
a deleted tail or a fully rewritten history. A successful check is not proof
of those properties or of correct booking, no deadlocks or acceptable latency.
Do not repair historical rows; preserve them and escalate any failure.

Local isolated checker tests: `python3 -m unittest discover -s tools/audit-chain -p 'test_*.py' -v`.
