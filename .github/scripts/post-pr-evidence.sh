#!/usr/bin/env bash
# Publish pre-sanitized, bounded workflow evidence without guessing PR targets.
set -euo pipefail

if [[ $# -lt 1 || $# -gt 2 || ! -f "$1" ]]; then
  echo "::error::Evidence file is missing or invocation is invalid" >&2
  exit 2
fi
EVIDENCE=$1
# The evidence stream this call owns. Two steps in one job must never share a
# comment, or the later one would overwrite the earlier one's evidence.
STREAM_FAMILY=${2:-}
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

# Publication itself lives in ONE shared transport so every workflow family -
# including the inline publishers in ci.yml and the browser workflows - resolves
# the PR comment ceiling the same way. Each evidence stream owns exactly one
# marker comment: several steps in one job must not overwrite each other, so the
# family defaults to the evidence file's own name, and can be named explicitly
# with EVIDENCE_FAMILY.
COMMENTER="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)/pr-evidence-comment.sh"
EVIDENCE_FAMILY=${EVIDENCE_FAMILY:-${STREAM_FAMILY:-$(basename "$EVIDENCE" .md)}}

if [[ -n "$PR_NUMBER" ]]; then
  OUTCOME_FILE=$(mktemp)
  trap 'rm -f "$OUTCOME_FILE"' EXIT
  if "$COMMENTER" --family "$EVIDENCE_FAMILY" --pr "$PR_NUMBER" --repo "$REPOSITORY" \
       --body-file "$EVIDENCE" --outcome-file "$OUTCOME_FILE"; then
    if [[ "$(cat "$OUTCOME_FILE" 2>/dev/null)" == summary ]]; then
      # GitHub's comment ceiling: the run summary is the durable copy, and the
      # transport has already announced it as a warning.
      append_to_summary
      echo "::notice::PR #$PR_NUMBER is at GitHub's comment ceiling; evidence for '$EVIDENCE_FAMILY' was added to the run summary"
    fi
    exit 0
  fi
  # A real publication failure: keep the evidence in the run summary and fail.
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
