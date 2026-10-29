#!/usr/bin/env bash
# Publish pre-sanitized, bounded workflow evidence without guessing PR targets.
set -euo pipefail

if [[ $# -ne 1 || ! -f "$1" ]]; then
  echo "::error::Evidence file is missing or invocation is invalid" >&2
  exit 2
fi
EVIDENCE=$1
EVENT=${GITHUB_EVENT_NAME:?GITHUB_EVENT_NAME is required}
REPOSITORY=${GITHUB_REPOSITORY:?GITHUB_REPOSITORY is required}
REF_NAME=${GITHUB_REF_NAME:?GITHUB_REF_NAME is required}
PR_NUMBER=

case "$EVENT" in
  pull_request)
    # github.event.pull_request.number is injected directly by the workflow.
    PR_NUMBER=${CPMS_PR_NUMBER:-}
    if [[ ! "$PR_NUMBER" =~ ^[1-9][0-9]*$ ]]; then
      echo "::error::pull_request event is missing a valid event PR number; refusing to guess" >&2
      exit 1
    fi
    ;;
  workflow_dispatch)
    # Manual runs on main have no PR. On a source branch, accept only one open,
    # same-repository PR targeting main whose returned fields match exactly.
    if [[ "$REF_NAME" != main ]]; then
      OWNER=${GITHUB_REPOSITORY_OWNER:?GITHUB_REPOSITORY_OWNER is required}
      CANDIDATES=$(gh pr list \
        --repo "$REPOSITORY" \
        --head "$REF_NAME" \
        --base main \
        --state open \
        --json number,headRefName,baseRefName,headRepositoryOwner)
      MATCHES=$(jq -c \
        --arg branch "$REF_NAME" \
        --arg owner "$OWNER" \
        '[.[] | select(.headRefName == $branch and .baseRefName == "main" and .headRepositoryOwner.login == $owner)]' \
        <<< "$CANDIDATES")
      MATCH_COUNT=$(jq 'length' <<< "$MATCHES")
      if [[ "$MATCH_COUNT" == 1 ]]; then
        PR_NUMBER=$(jq -r '.[0].number | tostring' <<< "$MATCHES")
      else
        echo "::notice::Manual dispatch found $MATCH_COUNT exact same-repository open PRs for '$REF_NAME' targeting main; no PR comment will be attempted"
      fi
    fi
    ;;
  push|schedule)
    if [[ "$REF_NAME" != main ]]; then
      echo "::error::$EVENT is only supported on main; refusing to infer a PR target" >&2
      exit 1
    fi
    ;;
  *)
    echo "::error::Unsupported evidence publication event '$EVENT'; refusing to infer a PR target" >&2
    exit 1
    ;;
esac

if [[ -n "$PR_NUMBER" && ! "$PR_NUMBER" =~ ^[1-9][0-9]*$ ]]; then
  echo "::error::Resolved PR target is invalid; refusing to publish" >&2
  exit 1
fi

append_to_summary() {
  if [[ -z "${GITHUB_STEP_SUMMARY:-}" ]]; then
    echo "::error::No PR target exists and GITHUB_STEP_SUMMARY is unavailable; evidence was not published" >&2
    return 1
  fi
  cat "$EVIDENCE" >> "$GITHUB_STEP_SUMMARY"
}

# GitHub caps the number of comments on one issue/PR (2,500). A publisher that
# always creates a comment therefore stops working *permanently* on a long-lived
# pull request - which is a silent loss of evidence exactly where the most
# evidence exists. The body carries a marker so the automation can recognise its
# own comment, and publication EDITS that comment in place instead of appending.
# History is not deleted: every run's full evidence is also in its own, immutable
# run summary and its uploaded artifacts.
STICKY_MARKER='<!-- cpms:evidence-sticky -->'

if [[ -n "$PR_NUMBER" ]]; then
  STICKY_BODY=$(mktemp)
  trap 'rm -f "$STICKY_BODY"' EXIT
  {
    printf '%s\n' "$STICKY_MARKER"
    printf '**Rolling evidence** - workflow `%s`, run [%s](%s/%s/actions/runs/%s), head `%s` (%s).\n\n' \
      "${GITHUB_WORKFLOW:-workflow}" \
      "${GITHUB_RUN_ID:-local}" "${GITHUB_SERVER_URL:-https://github.com}" "$REPOSITORY" "${GITHUB_RUN_ID:-0}" \
      "${GITHUB_SHA:-unknown}" "$(date -u +%Y-%m-%dT%H:%M:%SZ)"
    cat "$EVIDENCE"
    printf '\n_This comment is updated in place; older evidence remains in each run summary._\n'
  } > "$STICKY_BODY"

  EDIT_ERR=$(mktemp)
  CREATE_ERR=$(mktemp)
  trap 'rm -f "$STICKY_BODY" "$EDIT_ERR" "$CREATE_ERR"' EXIT
  # 1. Preferred: replace the automation's own last comment (no count growth).
  if gh pr comment "$PR_NUMBER" --repo "$REPOSITORY" --edit-last --body-file "$STICKY_BODY" >"$EDIT_ERR" 2>&1; then
    echo "PR_EVIDENCE_PUBLICATION=pr-comment (updated in place)"
    exit 0
  fi
  # 2. Nothing editable yet (first run by this token): create the sticky once.
  if gh pr comment "$PR_NUMBER" --repo "$REPOSITORY" --body-file "$STICKY_BODY" >"$CREATE_ERR" 2>&1; then
    cat "$CREATE_ERR"
    echo "PR_EVIDENCE_PUBLICATION=pr-comment (sticky created)"
    exit 0
  fi
  # Both transports are already captured; show what GitHub actually said.
  cat "$EDIT_ERR" "$CREATE_ERR" >&2
  # 3. Classify honestly. A comment-count ceiling is NOT a product or evidence
  #    defect: the canonical copy is the run summary, which is durable, and the
  #    condition is annotated so it can never be mistaken for silent success.
  if grep -qiE 'comment (limit|count)|too many comments|secondary rate limit' "$EDIT_ERR" "$CREATE_ERR" 2>/dev/null; then
    append_to_summary
    echo "::warning title=EVIDENCE_COMMENT_CEILING::PR #$PR_NUMBER has reached GitHub's comment ceiling; evidence was published to this run's summary and to the run artifacts only." >&2
    echo "PR_EVIDENCE_PUBLICATION=run-summary (comment ceiling)" >&2
    exit 0
  fi
  # Any other publication failure is a real failure and must not be swallowed.
  append_to_summary
  echo "::error::Required evidence comment to PR #$PR_NUMBER failed" >&2
  exit 1
else
  # Main pushes, schedules, main dispatches, and ambiguous/unmatched dispatches
  # retain evidence in the run summary; never broaden token permissions to post
  # commit comments or guess at an unrelated PR.
  append_to_summary
  echo "::notice::No uniquely resolved PR target; evidence was added to the run summary"
fi
