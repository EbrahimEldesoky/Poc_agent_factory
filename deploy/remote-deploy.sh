#!/bin/sh
# Runs on the server, from the project directory. Creates .env with fresh secrets on the first run,
# then builds and (re)starts the stack and waits until the site answers.
set -eu
cd "$(dirname "$0")/.."

COMPOSE="docker compose -f docker-compose.yml -f docker-compose.prod.yml"

if [ ! -f .env ]; then
  : "${SITE_ADDRESS:?SITE_ADDRESS is required on the first deploy}"
  umask 077
  {
    echo "BROKER_TOKEN=$(openssl rand -hex 32)"
    echo "SESSION_SECRET=$(openssl rand -hex 32)"
    echo "POSTGRES_PASSWORD=$(openssl rand -hex 16)"
    echo "OWNER_EMAIL=${OWNER_EMAIL:-owner@agentfactory.test}"
    echo "OWNER_PASSWORD=$(openssl rand -hex 12)"
    echo "SITE_ADDRESS=$SITE_ADDRESS"
    echo "ANTHROPIC_API_KEY="
    echo "ANTHROPIC_OAUTH_TOKEN="
    echo "GEMINI_API_KEY="
    echo "GROQ_API_KEY="
  } > .env
  echo "Created .env with fresh secrets"
fi

$COMPOSE up -d --build --remove-orphans

i=0
until $COMPOSE exec -T web wget -qO- http://127.0.0.1:8080/login >/dev/null 2>&1; do
  i=$((i + 1))
  if [ "$i" -ge 60 ]; then
    $COMPOSE ps
    $COMPOSE logs --tail 50
    echo "Stack did not become healthy" >&2
    exit 1
  fi
  sleep 2
done

docker image prune -f >/dev/null
$COMPOSE ps
echo "Deploy OK"
