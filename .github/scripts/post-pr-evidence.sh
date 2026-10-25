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

if [[ -n "$PR_NUMBER" ]]; then
  if ! gh pr comment "$PR_NUMBER" --repo "$REPOSITORY" --body-file "$EVIDENCE"; then
    # Preserve a run-visible copy, but fail the step so required PR publication
    # failures cannot be mistaken for successful evidence publication.
    append_to_summary
    echo "::error::Required evidence comment to PR #$PR_NUMBER failed" >&2
    exit 1
  fi
else
  # Main pushes, schedules, main dispatches, and ambiguous/unmatched dispatches
  # retain evidence in the run summary; never broaden token permissions to post
  # commit comments or guess at an unrelated PR.
  append_to_summary
  echo "::notice::No uniquely resolved PR target; evidence was added to the run summary"
fi
