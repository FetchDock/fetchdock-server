#!/usr/bin/env bash
# Redeploys a Portainer (BE) Git stack at a Git ref with a new DOCKER_TAG, then waits until the app reports
# the expected version on /.well-known/browser-extension.
#
# Required env: PORTAINER_URL, PORTAINER_API_KEY, PORTAINER_STACK_ID, PORTAINER_ENDPOINT_ID,
#               GIT_REF (e.g. refs/tags/0.7.2), DOCKER_TAG, APP_URL, EXPECTED_VERSION
# Optional env: SMOKE_TIMEOUT (seconds, default 900), DEPLOY_ID (default: current UTC timestamp)
set -euo pipefail

: "${PORTAINER_URL:?}" "${PORTAINER_API_KEY:?}" "${PORTAINER_STACK_ID:?}" "${PORTAINER_ENDPOINT_ID:?}"
: "${GIT_REF:?}" "${DOCKER_TAG:?}" "${APP_URL:?}" "${EXPECTED_VERSION:?}"

api() {
    curl --silent --show-error --fail-with-body --max-time "${CURL_MAX_TIME:-60}" \
        -H "X-API-Key: ${PORTAINER_API_KEY}" -H 'Content-Type: application/json' "$@"
}

stack=$(api "${PORTAINER_URL%/}/api/stacks/${PORTAINER_STACK_ID}")
echo "Stack: $(jq -r '.Name' <<<"$stack") (git ref before: $(jq -r '.GitConfig.ReferenceName // "?"' <<<"$stack"))"

if [ "$(jq -r '.GitConfig.URL // empty' <<<"$stack")" = "" ]; then
    echo "::error::Stack ${PORTAINER_STACK_ID} is not a Git stack. See deploy/README.md." >&2
    exit 1
fi

# The redeploy replaces the stack's environment, so keep every existing variable and only set DOCKER_TAG and
# DEPLOY_ID. DEPLOY_ID changes on every deploy, so one-shot services that use it (staging's db-refresh) rerun.
deploy_id="${DEPLOY_ID:-$(date -u +%Y%m%dT%H%M%SZ)}"
payload=$(jq -c --arg ref "$GIT_REF" --arg tag "$DOCKER_TAG" --arg id "$deploy_id" '{
    repositoryReferenceName: $ref,
    repositoryAuthentication: false,
    prune: true,
    pullImage: true,
    env: ([(.Env // [])[] | select(.name != "DOCKER_TAG" and .name != "DEPLOY_ID")]
        + [{name: "DOCKER_TAG", value: $tag}, {name: "DEPLOY_ID", value: $id}])
}' <<<"$stack")

echo "Redeploying at ${GIT_REF} with DOCKER_TAG=${DOCKER_TAG} (DEPLOY_ID=${deploy_id})"
CURL_MAX_TIME=1800 api -X PUT --data "$payload" \
    "${PORTAINER_URL%/}/api/stacks/${PORTAINER_STACK_ID}/git/redeploy?endpointId=${PORTAINER_ENDPOINT_ID}" >/dev/null

echo "Waiting for ${APP_URL} to report version ${EXPECTED_VERSION}"
deadline=$(( $(date +%s) + ${SMOKE_TIMEOUT:-900} ))
version=""
while [ "$(date +%s)" -lt "$deadline" ]; do
    version=$(curl --silent --max-time 10 "${APP_URL%/}/.well-known/browser-extension" | jq -r '.version // empty' 2>/dev/null || true)
    if [ "$version" = "$EXPECTED_VERSION" ]; then
        echo "Deployed: ${APP_URL} reports ${version}"
        exit 0
    fi
    sleep 10
done
echo "::error::${APP_URL} reports version '${version:-none}' instead of '${EXPECTED_VERSION}' after ${SMOKE_TIMEOUT:-900}s" >&2
exit 1
