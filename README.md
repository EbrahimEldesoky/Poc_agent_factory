# Agent Factory — POC

One page to create AI agents by talking to **AgentFather**, run each one in its own
isolated [OpenClaw](https://docs.openclaw.ai) container, reach it on Telegram, and turn it on/off.

## Run it

Requirements: Docker (with Compose). Nothing else on the host.

```bash
cp .env.example .env      # fill ANTHROPIC_API_KEY, BROKER_TOKEN, SESSION_SECRET
docker compose up -d --build
open http://localhost:3000   # sign in: owner@acme.test / changeme (from .env)
```

The first agent start pulls the pinned OpenClaw image (~1 GB) if it is not already present:
`docker pull ghcr.io/openclaw/openclaw:2026.11.2`.

For each agent you need a Telegram bot: talk to **@BotFather** on Telegram, `/newbot`, copy the token,
and paste it in the token field of the AgentFather summary.

`WEB_PORT=8080 docker compose up -d` changes the port.

## Demo flow

1. Sign in → empty list.
2. **Create agent** → AgentFather asks its questions one at a time, with clickable choices or free text:
   main job, company, agent name, what it must know, tone, language, prohibited tasks, what to do
   when it can't help (+ contact), **runtime** (OpenClaw or Hermes Agent), **model** (Claude Sonnet 5.5,
   Opus 5.5, Haiku 5.5, Sonnet 4.6), channel, and **who can talk to it** (anyone with the QR code, or
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
                     af-egress (tinyproxy allowlist: api.anthropic.com, api.telegram.org) ──► Internet
```

| Service | Role | Docker socket | Secrets |
|---|---|---|---|
| `web` | nginx serving the page + sign-in (TypeScript, compiled by `tsc` in the image); talks only to `/api` | no | no |
| `api` | PHP 8.3: auth, AgentFather questionnaire, config validation, enqueues jobs | no | no |
| `worker` | PHP CLI loop: runs jobs (Postgres `FOR UPDATE SKIP LOCKED`), reconciles states, records events | no | no |
| `db` | Postgres: `users`, `agents`, `agentfather_sessions`, `jobs`, `events` | no | no |
| `broker` | PHP 8.3: validates the agent manifest, drives the Docker Engine API over the unix socket, secrets store | **yes** | model key + bot tokens |
| `egress` | Forward proxy, default deny, two hosts allowed | no | no |

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
| Default deny | `tools: { profile: "minimal", deny: ["*"] }`. Egress: internal network + allowlist proxy (Google is blocked, verified) |
| Permissions outside the model | No tools at all, so the agent cannot *do* anything beyond chatting. Prohibited tasks are also written as "Red Lines" in `AGENTS.md`. |
| Secrets never appear | Token only in the broker's secrets volume (0600) and the agent's env. API redacts token/key patterns from chat; validation errors never echo input; access logs off. |
| Pinned versions | OpenClaw tag + digest; all base images pinned. |
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
- **Model credentials**: a Claude subscription token (`ANTHROPIC_OAUTH_TOKEN`, from `claude setup-token`)
  or an API key. OpenClaw 2026.5.22 was rejected by Anthropic with a subscription token ("third-party
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
- **Telegram access** is `dmPolicy: "open"`: anyone who finds the bot can chat. An allowlist of
  Telegram user ids is the obvious next field for AgentFather to ask.
- **Prohibited tasks** for a tools-less chat agent are enforced by the instructions; the hard guarantee
  is that the agent has no tools and no network beyond the model and Telegram.
- **Job queue** is a single worker process. Jobs left `running` by a worker crash are marked failed at
  startup; the owner retries with the switch (which re-runs `create` and removes any leftover container).
- **Auth**: one seeded owner, HMAC-signed cookie, `password_hash()` (bcrypt). No sign-up, no roles.
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
