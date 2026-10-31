# Independent audit-chain snapshot checker (experimental)

**Not a concurrency fix or acceptance result.** This read-only tool checks a
whole consistent snapshot, including each stored `prev_hash` against the prior
row and each `row_hash` against its own recorded predecessor and canonical
fields. It continues after failures and reports IDs and error types, not
patient details. The snapshot begins at genesis; there is no arbitrary row
limit. The production `HashChain::verify` and `AuditLogger::verifyChain` are
not used. The exporter was exercised on isolated MySQL 8.4.13 and MariaDB
11.4.13 sites in run 41217916386; that small synthetic run does not establish
operational safety on a production database.

On an approved disposable WordPress test site with the CPMS plugin active,
from the repository root (adjust `--path` for that site):

```bash
set -o pipefail
wp --path=/path/to/wordpress eval-file clinic-practice-management/tests/audit-chain/export.php | python3 clinic-practice-management/tests/audit-chain/verify.py --suffix 10
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

The optional `--suffix N` also checks the final N rows against the actual
preceding row when one exists (otherwise genesis). Its `anchor` field states
which case was verified; this does not authenticate a rewritten predecessor.

Local isolated checker tests: `python3 -m unittest discover -s clinic-practice-management/tests/audit-chain -p 'test_*.py' -v`.

Preserved from investigation PR #289, head `a813285d5824b5731125ba2120e8e626f0176e31`, without modifying that branch. Its location here keeps the production-only/companion-isolation boundary intact.
