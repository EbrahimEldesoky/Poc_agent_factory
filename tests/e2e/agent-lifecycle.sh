#!/usr/bin/env bash
# shellcheck disable=SC2016,SC2034  # checks are strings eval'd later; they read variables set afterwards
# End-to-end agent lifecycle against a live stack, with a REAL Telegram bot and model key:
#   create (AgentFather -> Confirm) -> Active -> hardening checks -> Off -> On -> Delete -> nothing left.
#
#   E2E_TELEGRAM_BOT_TOKEN=... tests/e2e/agent-lifecycle.sh <compose-project> <env-file> [openclaw|hermes]
#
# Use a bot dedicated to CI: Telegram allows only one poller per bot, so a bot used by a
# real agent would conflict. The model key comes from the env file (ANTHROPIC_*).
set -uo pipefail

PROJECT="${1:?compose project}"; ENV_FILE="${2:?env file}"; RUNTIME="${3:-openclaw}"
: "${E2E_TELEGRAM_BOT_TOKEN:?set E2E_TELEGRAM_BOT_TOKEN to a bot dedicated to CI}"
set -a
# shellcheck disable=SC1090
source "$ENV_FILE"
set +a
BASE="http://localhost:${WEB_PORT:-3999}"
COMPOSE=(docker compose -p "$PROJECT" --env-file "$ENV_FILE")
JAR="$(mktemp)"; BODY="$(mktemp)"; trap 'rm -f "$JAR" "$BODY"' EXIT

pass=0; fail=0
ok()  { pass=$((pass + 1)); echo "  PASS  $1"; }
bad() { fail=$((fail + 1)); echo "  FAIL  $1"; }
check() { if eval "$2"; then ok "$1"; else bad "$1"; fi; }
json() { python3 -c "import json,sys; d=json.load(open('$BODY')); print($1)"; }
req() {
  local m="$1" p="$2" data="${3:-}"
  local args=(-s -o "$BODY" -w '%{http_code}' -b "$JAR" -c "$JAR" -X "$m" -H 'content-type: application/json')
  [ -n "${CSRF:-}" ] && args+=(-H "X-CSRF-Token: $CSRF")
  [ -n "$data" ] && args+=(--data "$data")
  code=$(curl "${args[@]}" "$BASE$p")
}
state() { req GET "/api/agents/$AID"; json "d['state']"; }
wait_state() { # wait_state <target> <seconds>
  local s=""; for _ in $(seq 1 "$2"); do s=$(state); [ "$s" = "$1" ] || [ "$s" = failed ] && break; sleep 1; done; echo "$s"
}

echo "== Sign in and create the agent ($RUNTIME)"
req POST /api/login "$(python3 -c 'import json,os;print(json.dumps({"email":os.environ["OWNER_EMAIL"],"password":os.environ["OWNER_PASSWORD"]}))')"
CSRF=$(json "d['csrf_token']")
req POST /api/agentfather/sessions ''; SID=$(json "d['id']")
answer() { req POST "/api/agentfather/sessions/$SID/messages" "$1"; }
RUNTIME_CHOICE=$([ "$RUNTIME" = hermes ] && echo "Hermes Agent" || echo "OpenClaw (recommended)")
answer '{"choices":["Customer support"]}'; answer '{"text":"CI Company"}'; answer '{"choices":["Nova"]}'
answer '{"text":"We test the platform."}'; answer '{"choices":["Short and to the point"]}'; answer '{"choices":["English only"]}'
answer '{"choices":["Promise refunds or discounts"]}'; answer "{\"choices\":[\"Simply say it doesn't know\"]}"
answer "{\"choices\":[\"$RUNTIME_CHOICE\"]}"; answer '{"choices":["Claude Haiku 5.5 (fastest)"]}'
answer '{"choices":["Telegram"]}'; answer '{"choices":["Anyone with the QR code or link"]}'
check "AgentFather produced a valid configuration" '[ "$(json "d[\"complete\"]")" = True ]'
req POST "/api/agentfather/sessions/$SID/confirm" "$(python3 -c 'import json,os;print(json.dumps({"telegram_token":os.environ["E2E_TELEGRAM_BOT_TOKEN"]}))')"
check "Confirm accepted" '[ "$code" = 200 ]'
AID=$(json "d['id']")
check "agent becomes Active (container healthy)" '[ "$(wait_state active 240)" = active ]'

echo "== Container hardening"
C="af-agent-$AID"
f() { docker inspect "$C" --format "$1"; }
check "runs as a non-root user" '[ "$(f "{{.Config.User}}" | cut -d: -f1)" != 0 ] && [ -n "$(f "{{.Config.User}}")" ]'
check "read-only root filesystem" '[ "$(f "{{.HostConfig.ReadonlyRootfs}}")" = true ]'
check "all capabilities dropped, not privileged" '[ "$(f "{{.HostConfig.CapDrop}}")" = "[ALL]" ] && [ "$(f "{{.HostConfig.Privileged}}")" = false ]'
check "1 CPU / 2 GB limits" '[ "$(f "{{.HostConfig.NanoCpus}}")" = 1000000000 ] && [ "$(f "{{.HostConfig.Memory}}")" = 2147483648 ]'
check "no Docker socket inside the agent" '! f "{{range .Mounts}}{{.Source}} {{end}}" | grep -q docker.sock'
NET="af-net-$AID"
check "own internal network (no direct internet)" '[ "$(docker network inspect "$NET" --format "{{.Internal}}")" = true ]'
check "network holds only the agent and egress" '[ "$(docker network inspect "$NET" --format "{{len .Containers}}")" = 2 ]'
check "bot token never stored in the database" '! "${COMPOSE[@]}" exec -T db pg_dump -U agentfactory agentfactory | grep -qF -- "$E2E_TELEGRAM_BOT_TOKEN"'
check "bot token never in platform logs" '! "${COMPOSE[@]}" logs 2>&1 | grep -qF -- "$E2E_TELEGRAM_BOT_TOKEN"'

echo "== Off / On"
req POST "/api/agents/$AID/switch" '{"enabled":false}'
check "Off -> Inactive" '[ "$(wait_state inactive 60)" = inactive ]'
check "Off -> container stopped (no polling, no answers)" '[ "$(f "{{.State.Running}}")" = false ]'
req POST "/api/agents/$AID/switch" '{"enabled":true}'
check "On -> Active again" '[ "$(wait_state active 240)" = active ]'
check "On -> exactly one container for the agent" '[ "$(docker ps -aq --filter "label=af.agent_id=$AID" | wc -l | tr -d " ")" = 1 ]'

echo "== Delete"
req DELETE "/api/agents/$AID"
for _ in $(seq 1 60); do req GET "/api/agents/$AID"; [ "$code" = 404 ] && break; sleep 1; done
check "agent gone from the list" '[ "$code" = 404 ]'
check "container, network and volume removed" '[ -z "$(docker ps -aq --filter "label=af.agent_id=$AID")" ] && [ -z "$(docker network ls -q --filter "label=af.agent_id=$AID")" ] && [ -z "$(docker volume ls -q --filter "label=af.agent_id=$AID")" ]'
check "bot token removed from the broker store" '[ "$("${COMPOSE[@]}" exec -T broker sh -c "ls /secrets | grep -c ^sec_ || true")" = 0 ]'

echo
echo "E2E ($RUNTIME): $pass passed, $fail failed"
[ "$fail" -eq 0 ]
