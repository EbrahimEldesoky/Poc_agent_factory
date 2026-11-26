#!/usr/bin/env bash
# Writes a throwaway env file for an isolated test stack. Every secret is random and
# single-use; the model key is a syntactically valid fake (never verified for .env keys).
set -euo pipefail
out="${1:?usage: gen-env.sh <output-file>}"
rand() { openssl rand -hex "$1"; }
umask 077
cat > "$out" <<ENV
BROKER_TOKEN=$(rand 32)
SESSION_SECRET=$(rand 32)
POSTGRES_PASSWORD=$(rand 24)
OWNER_EMAIL=ci-owner@example.test
OWNER_PASSWORD=Ci-$(rand 12)
ANTHROPIC_API_KEY=sk-ant-api03-ci$(rand 24)
WEB_PORT=${WEB_PORT:-3999}
EGRESS_NAME=${EGRESS_NAME:-af-egress-ci}
ENV
echo "wrote $out"
