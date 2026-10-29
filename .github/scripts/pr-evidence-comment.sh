#!/usr/bin/env bash
# Publish one workflow family's evidence to its pull request.
#
# WHY THIS EXISTS
#   GitHub caps the comments on a single issue/PR at 2,500. PR #282 reached that
#   cap, so every `gh pr comment` in every workflow began failing - some steps
#   turned red because of it, others reported it as "permissions?", and all of
#   them silently stopped publishing. None of that was a product defect, and
#   none of it was honest.
#
# WHAT IT DOES
#   * ONE automation-owned comment per family, found by an explicit marker and
#     updated IN PLACE, so a long-lived PR never grows a comment per run.
#   * Created once, only when the family has no comment yet.
#   * At GitHub's ceiling the condition is announced explicitly and the step
#     does NOT fail: the durable copy of the evidence is the run summary and the
#     uploaded artifacts, and the caller is told so.
#   * Every other publication failure stays a hard failure. Nothing is deleted
#     and no failure is ever converted into a pass.
#
# USAGE
#   pr-evidence-comment.sh --family NAME --body-file FILE [--pr N] [--repo O/R]
#
# EXIT
#   0 published, or the ceiling (announced); 1 any other failure.

set -uo pipefail

FAMILY=''
BODY=''
OUTCOME=''
PR_NUMBER=${CPMS_PR_NUMBER:-${PR_NUMBER:-}}
REPOSITORY=${GITHUB_REPOSITORY:-}

while [ $# -gt 0 ]; do
  case "$1" in
    --family) FAMILY=${2:-}; shift 2 ;;
    --body-file) BODY=${2:-}; shift 2 ;;
    --pr) PR_NUMBER=${2:-}; shift 2 ;;
    --repo) REPOSITORY=${2:-}; shift 2 ;;
    --outcome-file) OUTCOME=${2:-}; shift 2 ;;
    *) echo "::error::pr-evidence-comment.sh: unknown argument $1" >&2; exit 1 ;;
  esac
done

COMMENT_CEILING=2500

# Machine-readable outcome for the caller that wraps this transport
# (post-pr-evidence.sh): `comment` when a comment was written, `summary` when
# the ceiling forced the run summary to be the only copy, `failed` otherwise.
report() {
  [ -n "$OUTCOME" ] || return 0
  printf '%s\n' "$1" > "$OUTCOME" 2>/dev/null || true
}

if [ -z "$FAMILY" ] || [ -z "$BODY" ] || [ ! -f "$BODY" ]; then
  echo "::error::pr-evidence-comment.sh: --family and an existing --body-file are required" >&2
  exit 1
fi
if [ -z "$REPOSITORY" ]; then
  echo "::error::pr-evidence-comment.sh: no repository (set GITHUB_REPOSITORY or --repo)" >&2
  exit 1
fi
if [ -z "$PR_NUMBER" ]; then
  echo "::warning title=EVIDENCE_COMMENT_SKIPPED::No pull request for $FAMILY; evidence stays in this run's summary and artifacts." >&2
  exit 0
fi

MARKER="<!-- cpms:evidence-sticky:${FAMILY} -->"
SERVER=${GITHUB_SERVER_URL:-https://github.com}

BODY_FILE=$(mktemp)
ERR_FILE=$(mktemp)
trap 'rm -f "$BODY_FILE" "$ERR_FILE"' EXIT
{
  printf '%s\n' "$MARKER"
  printf '**%s evidence** - run [%s](%s/%s/actions/runs/%s), head `%s` (%s).\n\n' \
    "$FAMILY" "$FAMILY" "$SERVER" "$REPOSITORY" "${GITHUB_RUN_ID:-0}" \
    "${GITHUB_SHA:-unknown}" "$(date -u +%Y-%m-%dT%H:%M:%SZ)"
  cat "$BODY"
  printf '\n_This comment is owned by automation and updated in place; it is never appended to._\n'
} > "$BODY_FILE"

# 1. The family's own sticky comment, if it has one. Paginated, because the
#    ceiling is reached precisely on PRs with thousands of comments.
STICKY_ID=''
if ! STICKY_ID=$(gh api --paginate \
      "repos/$REPOSITORY/issues/$PR_NUMBER/comments?per_page=100" \
      --jq ".[] | select(.body | startswith(\"$MARKER\")) | .id" 2>"$ERR_FILE" | head -n 1); then
  STICKY_ID=''
  echo "::warning title=EVIDENCE_COMMENT_LOOKUP::Could not list PR #$PR_NUMBER comments for $FAMILY; assuming none yet. $(head -c 300 "$ERR_FILE")" >&2
fi

if [ -n "$STICKY_ID" ]; then
  if gh api --method PATCH "repos/$REPOSITORY/issues/comments/$STICKY_ID" \
       -F body=@"$BODY_FILE" >/dev/null 2>"$ERR_FILE"; then
    report comment
    echo "PR_EVIDENCE_PUBLICATION=$FAMILY#comment:$STICKY_ID (updated in place)"
    exit 0
  fi
  echo "::warning title=EVIDENCE_COMMENT_UPDATE::Could not update $FAMILY comment #$STICKY_ID. $(head -c 300 "$ERR_FILE")" >&2
fi

# 2. First run for this family: create the sticky exactly once.
if gh api --method POST "repos/$REPOSITORY/issues/$PR_NUMBER/comments" \
     -F body=@"$BODY_FILE" >/dev/null 2>"$ERR_FILE"; then
  report comment
  echo "PR_EVIDENCE_PUBLICATION=$FAMILY#comment (created)"
  exit 0
fi
CREATE_ERR=$(head -c 300 "$ERR_FILE" 2>/dev/null)

# 3. Classify honestly by asking GitHub how full the PR is, never by matching an
#    API message. A full PR is a platform limit, not a failed test.
COUNT=$(gh api "repos/$REPOSITORY/issues/$PR_NUMBER" --jq '.comments' 2>/dev/null || true)
if [ -n "${GITHUB_STEP_SUMMARY:-}" ]; then
  {
    printf '\n> PR comment not written for `%s` (see the warning on this step). The evidence above is the durable copy.\n' "$FAMILY"
  } >> "$GITHUB_STEP_SUMMARY" 2>/dev/null || true
fi
if [[ "${COUNT}" =~ ^[0-9]+$ ]] && [ "$COUNT" -ge "$COMMENT_CEILING" ]; then
  report summary
  echo "::warning title=EVIDENCE_COMMENT_CEILING::PR #$PR_NUMBER has $COUNT comments (GitHub ceiling $COMMENT_CEILING), so $FAMILY evidence could not be added as a comment. It is published in this run's summary and artifacts instead." >&2
  echo "PR_EVIDENCE_PUBLICATION=$FAMILY#run-summary (comment ceiling $COUNT)"
  exit 0
fi

report failed
echo "::error title=EVIDENCE_COMMENT_FAILED::Could not publish $FAMILY evidence to PR #$PR_NUMBER. ${CREATE_ERR}" >&2
exit 1
