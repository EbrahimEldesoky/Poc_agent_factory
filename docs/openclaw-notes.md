# OpenClaw notes (build step 1)

Checked on 2026-11-24 against the official docs (docs.openclaw.ai) and the docs shipped
inside the official image (`/app/docs`). Every point below was also verified by running the image.

## Version

- Image: `ghcr.io/openclaw/openclaw:2026.11.2`, pinned by digest
  `sha256:55294524dac01370288fb4beb16714b60f75cec0e96e07e662054cf29900965a`.
- Why this one: first pinned to 2026.5.22. Moved to 2026.11.2 (the version the owner already runs
  successfully with a Claude subscription token) after 2026.5.22 + `ANTHROPIC_OAUTH_TOKEN` was rejected
  by Anthropic with "Third-party apps now draw from extra usage, not plan limits".
- 2026.11.2 needs a writable `~/.cache/openclaw-<uid>` (private, mode 0700). With a read-only root
  filesystem the broker mounts a tmpfs at `/home/node/.cache` (uid 1000, mode 0700).
- Model credentials: `ANTHROPIC_OAUTH_TOKEN` (Claude subscription, from `claude setup-token`) or
  `ANTHROPIC_API_KEY`; OpenClaw lists both env vars for the `anthropic` provider, OAuth first.

## Runtime facts (from `docker image inspect` + running it)

| Fact | Value |
|---|---|
| User | `node` (uid 1000, gid 1000) |
| Entrypoint / cmd | `tini -s --` / `node openclaw.mjs gateway` |
| State dir | `/home/node/.openclaw` (config, sessions, workspace) |
| Config file | `/home/node/.openclaw/openclaw.json` (JSON5); override with `OPENCLAW_CONFIG_PATH` |
| Gateway port | 18789; `/healthz` liveness, also `/readyz` |
| Image healthcheck | `fetch('http://127.0.0.1:18789/healthz')` every 3 min (we override to 3 s) |
| Config check | `node openclaw.mjs config validate` ("Unknown keys … cause the Gateway to refuse to start") |
| Runs read-only | Yes, with `--read-only --tmpfs /tmp`, `--cap-drop ALL`, no-new-privileges |

## Configuration used per agent

```json5
{
  gateway: { mode: "local", bind: "loopback", auth: { mode: "token", token: "${OPENCLAW_GATEWAY_TOKEN}" } },
  agents: { defaults: { model: { primary: "anthropic/claude-sonnet-4-6" },
                        workspace: "/home/node/.openclaw/workspace", skipBootstrap: true } },
  tools: { profile: "minimal", deny: ["*"] },
  channels: { telegram: { enabled: true, dmPolicy: "open", allowFrom: ["*"] } },
}
```

- `gateway.mode: "local"` is required, or the gateway refuses to start.
- `bind: "loopback"`: nothing outside the container talks to the gateway. Health is checked from inside the container.
- `${VAR}` substitution is supported in any config string.
- Model key: the Anthropic provider reads `ANTHROPIC_API_KEY` from the environment (docs/providers/anthropic.md).
  `anthropic/claude-sonnet-4-6` is in this version's catalog (auto-enabled on start).
- Instructions: OpenClaw has no system-prompt key for the main agent. It injects workspace
  bootstrap files (`AGENTS.md`, `SOUL.md`, …) into context. We set `skipBootstrap: true` and the
  broker writes our own `workspace/AGENTS.md` (instructions + a "Red Lines" section, which OpenClaw
  re-injects after compaction by default).
- Tools: `tools.allow` / `tools.deny` are global, deny wins, `*` wildcards supported. `profile: "minimal"`
  + `deny: ["*"]` = no tools at all (the POC form offers no tools, so default deny means none).

## Telegram

- Token: `channels.telegram.botToken`, `tokenFile`, or env `TELEGRAM_BOT_TOKEN` (default account only).
  We use the env var so the token never lands in a file in the agent volume.
- `dmPolicy`: `pairing` (default), `allowlist`, `open` (requires `allowFrom: ["*"]`), `disabled`.
  The POC uses `open` so "anyone allowed" = anyone; an allowlist of Telegram user ids is the next step.
- Transport: long polling by default (webhook optional). So stopping the container stops polling:
  "off means off" without any webhook cleanup.
- A bad token shows `getMe returned 401` in the logs and the channel retries; the gateway stays healthy.
  That is why the broker verifies the token with `getMe` before storing it.
- Proxy: "Telegram also honors process proxy env for Bot API transport (HTTP_PROXY, HTTPS_PROXY …)".
  We also set `NODE_USE_ENV_PROXY=1` so Node's `fetch` (model calls) uses the proxy.

## WhatsApp

OpenClaw docs (channels/whatsapp.md): "production-ready via WhatsApp Web (Baileys). The gateway owns
the linked session(s); there is no separate Twilio WhatsApp channel." Login is QR-only.

So OpenClaw does **not** support the WhatsApp Business Cloud API the brief asks for. Options:
1. Link a phone number per agent through a QR code (WhatsApp Web). Needs a UI step to show the QR
   from `openclaw channels login`, and the egress allowlist must add WhatsApp hosts.
2. Put a small Cloud API bridge in front (webhook → OpenClaw HTTP API). More moving parts.

The POC ships with Telegram only (brief, step 7) and rejects WhatsApp at Confirm with a clear message.
