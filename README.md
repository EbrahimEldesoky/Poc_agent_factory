# Agent Factory — POC

One page to create AI agents by talking to **AgentFather**, run each one in its own
isolated [OpenClaw](https://docs.openclaw.ai) container, reach it on Telegram, and turn it on/off.

## Run it

Requirements: Docker (with Compose). Nothing else on the host.

```bash
cp .env.example .env      # fill BROKER_TOKEN, SESSION_SECRET, POSTGRES_PASSWORD, OWNER_PASSWORD, and a provider key
docker compose up -d --build
open http://localhost:3000   # sign in with OWNER_EMAIL / OWNER_PASSWORD from .env
```

### Smoke test and checks

Run daemon-free checks locally:

```bash
find api broker tests -name '*.php' -print0 | xargs -0 -n1 php -l
php tests/run.php
cd web && npm ci && npx tsc -p tsconfig.json --noEmit && cd ..
docker compose config -q
```

Smoke test with Docker Compose (requires a populated `.env` with non-default secrets):

```bash
docker compose up -d --build
docker compose ps          # wait until db, broker, api, web, and egress report healthy
curl -fsS http://localhost:${WEB_PORT:-3000}/login >/dev/null
curl -fsS http://localhost:${WEB_PORT:-3000}/api/health
```

Note on lockfiles: `web/package-lock.json` pins the TypeScript and `qrcode-generator` dependencies. `composer.lock` is absent because the PHP services use zero third-party Composer packages (only standard PHP 8.3 extensions and `pdo_pgsql`).

The first agent start pulls the pinned OpenClaw image (~1 GB) if it is not already present:
`docker pull ghcr.io/openclaw/openclaw:2026.11.2`.

For each agent you need a Telegram bot: talk to **@BotFather** on Telegram, `/newbot`, copy the token,
and paste it in the token field of the AgentFather summary.

`WEB_PORT=8080 docker compose up -d` changes the port.

## Demo flow

1. Sign in → empty list.
2. **Create agent** → AgentFather asks its questions one at a time, with clickable choices or free text:
   main job, company, agent name, what it must know, tone, language, prohibited tasks, what to do
   when it can't help (+ contact), **runtime** (OpenClaw or Hermes Agent), **model** (Claude, Gemini 3.8 Flash,
   or Groq GPT OSS 120B), channel, and **who can talk to it** (anyone with the QR code, or
   only listed Telegram user IDs).
3. Summary (with **Change** to edit one answer) → create a bot with @BotFather (a QR code opens it on
   your phone) → paste the token → **Confirm**. The panel shows the deploy steps; the row goes
   Starting → Active.
4. **Scan the QR code** shown when the agent is Active (or the **QR** button on its row): it opens the
   bot's chat in Telegram (`https://t.me/<bot>`). Tap Start and chat → it answers following its rules;
   ask for a prohibited task → it refuses.
5. Switch **Off** → container stopped, row Inactive, the bot stays silent. Switch **On** → answers again.
6. **Delete** another agent → its container, network, volume and token are removed.
7. **Log** on any row shows the agent's event log (real platform events).

## Architecture

```
Browser ──► web (nginx + TypeScript page) ──► api (PHP + AgentFather) ──► Postgres ◄── worker (PHP job queue)
                                                                         worker: create / start / stop / delete
                                              ▼
                     secrets volume ◄── broker (only service with the Docker socket)
                                              │ Docker API
                                              ▼
           af-agent-<id> (OpenClaw 2026.11.2, own volume, own internal network, 1 vCPU / 2 GB)
                                              │ HTTPS_PROXY
                                              ▼
                     af-egress (tinyproxy allowlist: api.anthropic.com, generativelanguage.googleapis.com, api.groq.com, api.telegram.org) ──► Internet
```

| Service | Role | Docker socket | Secrets |
|---|---|---|---|
| `web` | nginx serving the page + sign-in (TypeScript, compiled by `tsc` in the image); talks only to `/api` | no | no |
| `api` | PHP 8.3: auth, AgentFather questionnaire, config validation, enqueues jobs | no | no |
| `worker` | PHP CLI loop: runs jobs (Postgres `FOR UPDATE SKIP LOCKED`), reconciles states, records events | no | no |
| `db` | Postgres: `users`, `agents`, `agentfather_sessions`, `jobs`, `events`, `login_attempts` | no | no |
| `broker` | PHP 8.3: validates the agent manifest, drives the Docker Engine API over the unix socket, secrets store | **yes** | model key + bot tokens |
| `egress` | Forward proxy, default deny, provider API hosts and Telegram allowed | no | no |

### How a create works

1. AgentFather runs a fixed questionnaire (`api/src/agentfather.php`); every answer is checked against
   the allowed choices and lengths. The answers are turned into a configuration that the API validates
   against a fixed schema (`validate_config()`: exact fields, lengths, allowed values).
2. On **Confirm**, the bot token goes API → broker `/secrets` (never stored in Postgres, never sent to the
   model). The broker checks it with Telegram `getMe`, refuses a token already used by another agent,
   and returns a `secret_ref` + the bot's `@username`.
3. The API inserts the agent (`starting`) and a `create` job. The worker process runs the steps and records an
   event for each: `validate_config` → `create_container` → `start_and_health_check`.
4. The broker validates the manifest again, removes anything left by a previous attempt (so a retry can
   never make a second container), creates an internal network + volume + container, writes
   `openclaw.json` and `workspace/AGENTS.md`, starts it and waits for `/healthz`.
5. Any failure → rollback (container, network, volume removed), agent `Failed` with the reason.
   Switching a Failed agent On re-runs `create`.

## Security rules → how they are met

| Rule | Implementation |
|---|---|
| Only the broker talks to Docker | Socket mounted only in `broker`. Agents get no socket (verified). |
| One container per agent, limits, non-root, read-only | `af-agent-<id>`, uid 1000, `read_only`, tmpfs `/tmp`, `cap_drop: ALL`, `no-new-privileges`, 1 vCPU, 2 GB, 512 pids, own volume |
| Default deny | `tools: { profile: "minimal", deny: ["*"] }`. Egress: internal network + allowlist proxy restricted to supported model API hosts and Telegram |
| Permissions outside the model | No tools at all, so the agent cannot *do* anything beyond chatting. Prohibited tasks are also written as "Red Lines" in `AGENTS.md`. |
| Secrets never appear | Token only in the broker's secrets volume (0600) and the agent's env. API redacts token/key patterns from chat; validation errors never echo input; access logs off. |
| Pinned versions | OpenClaw and Hermes images pinned by tag + digest; base images pinned by exact version tag; npm deps pinned in `web/package-lock.json`. |
| Activity from real events | `events` table written by the worker; `last_message_at` comes from the newest session-transcript write inside the agent volume (read by the broker). |
| Safe failure / no duplicate | Broker `create` removes leftovers first; Docker names are unique; one in-flight job per agent enforced by a unique index. |
| One channel identity per agent | One bot token per agent; reuse refused by hash. |
| Off means off | Container stopped → Telegram long polling stops. |

## Shortcuts vs. the full spec

- **Two runtimes instead of one.** The brief kept Hermes Agent out of the POC; the owner asked for the
  choice. Both images are pinned by digest: OpenClaw `2026.11.2`, Hermes Agent `v0.23.0` (the versions
  the owner already runs with a Claude subscription). Hermes runs `hermes gateway run --no-supervise`
  directly as uid 10000 (skipping the image's root s6 init so capabilities can be dropped), with every
  toolset disabled for Telegram (`platform_toolsets.telegram: []` + `agent.disabled_toolsets`), its
  persona in `/opt/data/SOUL.md`, and health read from `/opt/data/gateway_state.json`
  (`platforms.telegram.state == "connected"`).
- **Model credentials**: configure the selected provider key in `.env`: `ANTHROPIC_API_KEY` or
  `ANTHROPIC_OAUTH_TOKEN` for Claude, `GEMINI_API_KEY` for Gemini, or `GROQ_API_KEY` for Groq.
  The broker passes only that provider's credential to an agent. OpenClaw 2026.5.22 was rejected by Anthropic with a subscription token ("third-party
  apps draw from extra usage"); 2026.11.2 is accepted.
- **Stack**: PHP 8.3 backend (API, worker, broker) and a TypeScript front, at the owner's request,
  instead of the brief's FastAPI + Next.js. No framework: one router file per service, PDO for
  Postgres, curl for the Anthropic API and for the Docker socket. The front is one page of strict
  TypeScript compiled to plain JS and served by nginx; no React/Next.js for a single page.
- **PHP built-in web server** (`php -S` with `PHP_CLI_SERVER_WORKERS`) serves the API and the broker.
  Fine for a POC; production would use php-fpm behind nginx.
- **WhatsApp not shipped.** OpenClaw only supports WhatsApp *Web* (QR linking), not the WhatsApp Business
  Cloud API. WhatsApp is accepted in the chat but rejected at Confirm. See `docs/openclaw-notes.md`
  for the two ways forward.
- **AgentFather** is a guided questionnaire in the API (clickable choices + free text), not an LLM on
  Hermes Agent. The owner asked for explicit questions with choices; it is also deterministic and
  cannot be talked into an invalid config. The model key is therefore only held by the broker.
- **Telegram onboarding**: Telegram has no API to create bots, so the owner creates one with
  @BotFather (QR code provided) and pastes the token. People reach the agent by scanning its QR code.
- **Secrets store** is a Docker volume owned by the broker, not a vault. Tokens are passed to agent
  containers as env vars, so `docker inspect` on the host shows them (host admins only).
- **Telegram access** supports both `dmPolicy: "open"` (anyone with the link/QR) and `dmPolicy: "allowlist"` (only listed numeric Telegram user IDs, enforced via `allowFrom` / `TELEGRAM_ALLOWED_USERS`).
- **Prohibited tasks** for a tools-less chat agent are enforced by the instructions; the hard guarantee
  is that the agent has no tools and no network beyond the model and Telegram.
- **Job queue** is a single worker process with leased jobs (`lease_expires_at`). Interrupted `running` jobs are automatically re-queued on worker startup or lease expiry without failing the agent.
- **Auth**: one seeded owner, HMAC-signed cookie (`HttpOnly`, `SameSite=Strict`, `Secure` on HTTPS), `password_hash()` (bcrypt), CSRF token (`X-CSRF-Token`), and DB-backed login rate limiting. No sign-up, no roles.
- **Egress proxy** filters by hostname (CONNECT), not by IP.

## Layout

```
docker-compose.yml   .env.example
api/      PHP: public/index.php (routes, auth), src/agentfather.php, src/jobs.php (queue, steps, reconciler),
          src/worker.php (worker loop), src/db.php (schema, helpers)
broker/   index.php: Deployment Broker (Docker Engine API over /var/run/docker.sock)
egress/   tinyproxy allowlist
web/      src/app.ts, src/login.ts (TypeScript) + public/{index.html, login.html, styles.css} + nginx.conf
docs/openclaw-notes.md
```

## CI

| Workflow | Trigger | What it does |
|---|---|---|
| `ci.yml` | push / PR to `main` | **Checks**: PHP lint, unit + contract tests (`tests/run.php`), strict TypeScript, shellcheck, compose validation. **Security**: gitleaks (full history), hadolint, Trivy config + dependency scan, `npm audit`. **Build**: the 4 images with layer cache, each scanned by Trivy. **Integration**: boots the real stack with throwaway secrets and runs `tests/integration/smoke.sh` (auth, CSRF/CORS, model providers, AgentFather, confirm through the broker to Telegram, Docker-socket isolation, egress allowlist, no secret in logs or DB). |
| `e2e.yml` | nightly + manual | Full agent lifecycle with a real bot and model key (`tests/e2e/agent-lifecycle.sh`): create → Active → hardening checks → Off → On → Delete → nothing left. Needs secrets `E2E_TELEGRAM_BOT_TOKEN` (a bot used only by CI) and `E2E_ANTHROPIC_API_KEY`; skipped otherwise. |

**Run the checks locally**

```bash
php tests/run.php                                   # unit + contract (or: docker run --rm -v "$PWD":/app -w /app php:8.3-cli php tests/run.php)
tests/integration/gen-env.sh /tmp/ci.env            # throwaway secrets, port 3999, egress "af-egress-ci"
docker compose -p afci --env-file /tmp/ci.env up -d --build --wait
tests/integration/smoke.sh afci /tmp/ci.env
docker compose -p afci --env-file /tmp/ci.env down -v
```

The test stack is isolated (own project, port and egress), so it can run next to a live stack.

**Rebuilds keep agents online**: recreating the `egress` container (e.g. `docker compose up --build`) used
to cut running agents off their network. The broker now re-attaches egress to every agent network on its
healthcheck (≤ 30 s).

**CD** (`.github/workflows/cd.yml`): after CI passes on a push to `main`, GitHub uploads the code to the
server over SSH and runs `deploy/remote-deploy.sh`, which rebuilds the stack with `docker-compose.prod.yml`
(Caddy adds automatic HTTPS on ports 80/443). The first run creates `.env` with fresh secrets on the server;
they never leave it. The job is skipped while the `DEPLOY_*` repository secrets are missing.

Manual deploy from a machine with SSH access:
```bash
git archive HEAD | ssh user@host 'mkdir -p ~/agent-factory && tar -xf - -C ~/agent-factory'
ssh user@host 'cd ~/agent-factory && SITE_ADDRESS=1-2-3-4.sslip.io sh deploy/remote-deploy.sh'
```

Reviewed exceptions: `.gitleaksignore` (two fake values in test fixtures) and `.trivyignore.yaml`
(the broker runs as root because it must open the Docker socket).
