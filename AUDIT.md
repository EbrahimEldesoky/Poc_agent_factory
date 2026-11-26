# Security and Reliability Audit (Phase 0)

This audit covers `README.md`, `docker-compose.yml`, all `Dockerfile`s, all PHP files in `api/` and `broker/`, and all TypeScript/web files in `web/`.

## Findings

### AUD-01 (Critical): Empty `BROKER_TOKEN` allows unauthenticated access to the broker
- **Location**: `broker/index.php:85`
- **Evidence**:
  ```php
  if (!hash_equals((string)getenv('BROKER_TOKEN'), $_SERVER['HTTP_X_BROKER_TOKEN'] ?? '')) {
  ```
  If `BROKER_TOKEN` is unset or empty, `(string)getenv('BROKER_TOKEN')` evaluates to `''`. An unauthenticated request without `X-Broker-Token` passes `hash_equals('', '')`.
- **Proposed Fix**: Fail fast at broker startup if `BROKER_TOKEN` is missing, empty, or shorter than 16 characters (or matches a placeholder), and explicitly reject empty tokens in the auth check.
- **Verification Test**: Unit/contract test verifying broker startup/auth rejects missing, empty, or weak `BROKER_TOKEN` and returns HTTP 401 when `X-Broker-Token` is missing or invalid.

### AUD-02 (Critical): Missing or empty `SESSION_SECRET` allows session cookie forgery
- **Location**: `api/public/index.php:49-62`
- **Evidence**:
  ```php
  $mac = hash_hmac('sha256', $payload, getenv('SESSION_SECRET'));
  ```
  If `SESSION_SECRET` is unset or empty, `getenv('SESSION_SECRET')` returns `false` (cast to `''`), allowing an attacker to forge valid HMAC signatures for any user ID.
- **Proposed Fix**: Fail fast on API startup if `SESSION_SECRET` is missing, empty, weak/placeholder, or under 16 characters.
- **Verification Test**: Unit test verifying config validation rejects missing/empty/weak `SESSION_SECRET` before serving requests.

### AUD-03 (Critical): Weak default credentials and missing startup fail-fast validation
- **Location**: `docker-compose.yml:8,41,46,58,62`, `.env.example:8-12`, `api/src/db.php:87-90`, `api/src/jobs.php:12,14`
- **Evidence**:
  - `OWNER_PASSWORD` defaults to `changeme` in `docker-compose.yml:46,62`, `.env.example:12`, and `api/src/db.php:90`.
  - `POSTGRES_PASSWORD` defaults to `agentfactory` in `docker-compose.yml:8,41,58`.
  - `BROKER_TOKEN` and `SESSION_SECRET` are not validated at startup in `api`, `worker`, or `broker`.
- **Proposed Fix**:
  - Update `.env.example` with safe placeholder values and document required variables (`BROKER_TOKEN`, `SESSION_SECRET`, `OWNER_PASSWORD`, `POSTGRES_PASSWORD`).
  - Add a shared startup config validator in `api/src/` (used by API and worker) and in `broker/index.php` that fails fast with a clear, secret-free error message if required secrets are missing, empty, or set to weak defaults (`changeme`, `secret`, `replace-me`, etc.).
- **Verification Test**: Unit tests for startup config validation with missing, empty, and default/weak secrets.

### AUD-04 (Critical): Non-atomic agent confirmation flow can orphan tokens or agents and race on duplicate requests
- **Location**: `api/public/index.php:210-239`
- **Evidence**:
  - `get_session()` reads `agentfather_sessions` without `FOR UPDATE` or a transaction (`api/public/index.php:211`). Two concurrent `POST /confirm` requests can both observe `status === 'open'`.
  - `broker('POST', '/secrets', ...)` runs at line 224 before any DB write.
  - `INSERT INTO agents` (line 230), `UPDATE agentfather_sessions` (line 234), `event()` (line 236), and `enqueue()` (line 237) run without a DB transaction.
  - `enqueue($a, 'create')` return value is unchecked at line 237. If `INSERT`, `UPDATE`, or `enqueue` fails, the Telegram token remains orphaned in the broker's `/secrets` directory (blocking future retries with 409) or an agent remains in `starting` without a job.
- **Proposed Fix**:
  - Wrap confirmation in a DB transaction (`beginTransaction()` / `commit()` / `rollBack()`).
  - Lock the session row with `SELECT ... FROM agentfather_sessions WHERE id=? AND tenant_id=? AND user_id=? FOR UPDATE` and verify `status === 'open'`.
  - Call `broker('POST', '/secrets', ...)` only after validation passes; if any subsequent DB step or `enqueue()` fails (including `enqueue()` returning `null`), roll back the DB transaction and issue a compensating `broker('DELETE', "/secrets/{$sec['secret_ref']}")` call.
- **Verification Test**: Integration/contract tests for: (a) duplicate confirmation returning 409, (b) broker `/secrets` failure leaving no agent/session change, and (c) DB or `enqueue()` failure after `/secrets` triggering compensating secret deletion and rolling back the agent/session.

### AUD-05 (High): Race condition and non-atomic writes in Telegram token storage
- **Location**: `broker/index.php:325-351`
- **Evidence**:
  - `glob(SECRETS_DIR . '/sec_*')` at line 332 runs without any file lock; concurrent `POST /secrets` requests with the same token can both pass the duplicate check and write two separate secret files.
  - `file_put_contents(SECRETS_DIR . "/$ref", ...)` at line 347 writes directly to the final path rather than using temp-file + `chmod(0600)` + `rename()`.
  - No `DELETE /secrets/{ref}` route exists for API compensating cleanup.
- **Proposed Fix**:
  - Acquire an exclusive `flock()` on `SECRETS_DIR . '/.secrets.lock'` around the duplicate-digest check and secret creation.
  - Write to a temporary file in `SECRETS_DIR`, `chmod($tmp, 0600)`, and atomically `rename()` to `SECRETS_DIR . "/$ref"`.
  - Add authenticated `DELETE /secrets/(sec_[a-f0-9]{32})` endpoint on the broker.
- **Verification Test**: Unit/contract tests verifying duplicate token rejection, `0600` file permissions, and `DELETE /secrets/{ref}` cleanup.

### AUD-06 (High): `enqueue()` swallows all `PDOException`s as HTTP 409
- **Location**: `api/src/jobs.php:35-46`
- **Evidence**:
  ```php
  } catch (PDOException) { // unique index one_active_job_per_agent
      return null;
  }
  ```
  Any database error (connection loss, constraint violation on another column, syntax/permission error) is caught and treated as `null`, causing callers to return HTTP 409 "A change is already in progress for this agent".
- **Proposed Fix**: Check `$e->getCode() === '23505'` and verify the error references `one_active_job_per_agent` before returning `null`. Log other `PDOException`s safely (without secrets) and rethrow so the API returns HTTP 500.
- **Verification Test**: Unit test with fake/mocked PDO throwing `23505` on `one_active_job_per_agent` (returns `null`) vs `23505` on another constraint or `08006` connection error (rethrows).

### AUD-07 (High): Broker `remove_all()` ignores Docker errors and reports success when resources remain
- **Location**: `broker/index.php:303-310`, `broker/index.php:438-449`
- **Evidence**:
  `remove_all()` calls `docker('DELETE', ...)`, `docker('POST', "/networks/$n/disconnect", ...)`, `docker('DELETE', "/networks/$n")`, and `docker('DELETE', "/volumes/$v?force=true")` without checking any HTTP status codes. `DELETE /agents/{id}` always returns `200 {"deleted": true}` even if Docker failed to remove the container, network, or volume.
- **Proposed Fix**:
  - Check HTTP status codes in `remove_all()`: treat `200`/`204`/`404` as success (idempotent), and `409` on network disconnect when the egress container is not connected (after checking or when message indicates not connected), while throwing `RuntimeException` on unexpected `>= 400` errors.
  - Verify resources are gone (or fail clearly) before returning `deleted: true`.
- **Verification Test**: Contract test with a mocked Docker transport returning HTTP 500 on container/volume/network delete, verifying `DELETE /agents/{id}` returns an error instead of `{"deleted": true}`.

### AUD-08 (High): Worker restart permanently fails interrupted jobs and marks agents as `failed`
- **Location**: `api/src/worker.php:8-11`
- **Evidence**:
  ```php
  foreach (q("SELECT * FROM jobs WHERE state IN ('running','rolling_back')") as $j) {
      q("UPDATE jobs SET state='failed', error='Interrupted by a platform restart' WHERE id=?", $j['id']);
      set_agent($j['agent_id'], ['state' => 'failed', 'enabled' => false, 'state_reason' => 'Interrupted by a platform restart']);
  }
  ```
  Restarting the worker container while a job is running permanently fails the job and disables the agent.
- **Proposed Fix**:
  - Add `lease_expires_at timestamptz` to `jobs` in `api/src/db.php`.
  - Set `lease_expires_at = now() + interval '180 seconds'` when claiming a job and refresh it at each step in `run_job()`.
  - On worker startup (and periodically in the worker loop), recover stale/interrupted `running` jobs by resetting them to `state='queued', current_step=NULL, lease_expires_at=NULL` without changing agent status to `failed`. Handle interrupted `rolling_back` jobs safely by completing cleanup/marking the failed job without losing state.
- **Verification Test**: Unit test verifying interrupted `running` jobs are re-queued and agent state is preserved, and stale leased jobs are recovered.

### AUD-09 (High): Missing CSRF protection on cookie-authenticated state-changing requests
- **Location**: `api/public/index.php:126-245`, `web/src/app.ts:129-142`
- **Evidence**: Authentication relies on the `af_session` cookie, and all `POST`/`DELETE` endpoints accept requests without any CSRF token or custom header verification.
- **Proposed Fix**:
  - Issue a session-bound CSRF token (`hash_hmac('sha256', 'csrf:' . $uid . ':' . $exp, SESSION_SECRET)`) returned by `/api/login` and `/api/me`.
  - Require `X-CSRF-Token` matching the session's CSRF token on all authenticated non-GET/HEAD/OPTIONS requests; return HTTP 403 on mismatch.
  - Update `web/src/app.ts` to read `csrf_token` from `/api/me` and send `X-CSRF-Token` on state-changing requests.
- **Verification Test**: API test verifying `POST`/`DELETE` without or with invalid `X-CSRF-Token` returns 403, and succeeds with valid token.

### AUD-10 (High): No login rate limiting or lockout
- **Location**: `api/public/index.php:126-133`
- **Evidence**: `POST /api/login` performs `password_verify()` with no attempt tracking, throttling, or lockout.
- **Proposed Fix**: Add a `login_attempts` table in `db.php` keyed by `(email, ip)`. After 5 failed attempts within 15 minutes, lock out for 5 minutes with HTTP 429; reset on successful login.
- **Verification Test**: Unit/API test verifying 5 failed login attempts trigger HTTP 429 on the 6th attempt.

### AUD-11 (Medium): Cookie `Secure` flag missing on HTTPS and incomplete cookie clearing on logout
- **Location**: `api/public/index.php:130-137`
- **Evidence**:
  - Login sets `httponly` and `samesite => 'Strict'`, but never sets `secure => true` when served over HTTPS.
  - Logout calls `setcookie(COOKIE, '', ['expires' => 1, 'path' => '/'])` without `httponly`, `samesite`, or `secure`.
- **Proposed Fix**: Add `is_https_request()` (checking `COOKIE_SECURE` env, `HTTPS`, `SERVER_PORT`, and `HTTP_X_FORWARDED_PROTO` when `TRUST_PROXY` is enabled) and a shared `cookie_options(int $expires)` helper used by both login and logout.
- **Verification Test**: Unit test verifying `cookie_options()` sets `httponly`, `samesite=Strict`, and `secure` based on HTTPS/env, and that logout uses identical attributes.

### AUD-12 (Medium): Missing explicit CORS / allowed-origin policy
- **Location**: `api/public/index.php`
- **Evidence**: No `Origin` validation or `CORS_ALLOWED_ORIGINS` handling exists.
- **Proposed Fix**: Support `CORS_ALLOWED_ORIGINS` (empty = same-origin only). Reject disallowed cross-origin `Origin` headers on state-changing requests with 403, and emit `Access-Control-Allow-Origin` + `Vary: Origin` + `Access-Control-Allow-Credentials: true` only for explicitly allowed origins.
- **Verification Test**: Unit test verifying allowed vs disallowed `Origin` handling.

### AUD-13 (Medium): Missing defense-in-depth `tenant_id` scoping on secondary queries
- **Location**: `api/public/index.php:87,151,166,168,205,234,235,243`
- **Evidence**: While `get_agent()` and `get_session()` filter by `tenant_id`, subsequent queries (`events`, `agents` updates/selects in `switch`/`confirm`, `agentfather_sessions` updates in `messages`/`confirm`/`cancel`, and `active_job()`) omit `AND tenant_id=?`.
- **Proposed Fix**: Scope all API queries touching `agents`, `agentfather_sessions`, `jobs`, and `events` by `tenant_id` (and `user_id` for sessions).
- **Verification Test**: Negative test verifying accessing/mutating a session or agent belonging to another tenant returns 404.

### AUD-14 (Medium): Broker `/health` endpoint leaks internal image configuration
- **Location**: `broker/index.php:320-323`
- **Evidence**:
  ```php
  out(['ok' => true, 'image' => getenv('OPENCLAW_IMAGE')]);
  ```
  `/health` is unauthenticated and returns the `OPENCLAW_IMAGE` string and digest.
- **Proposed Fix**: Return only `['ok' => true]` from `GET /health` after verifying the Docker socket `_ping`.
- **Verification Test**: Contract test verifying `GET /health` returns `{"ok": true}` without `image` or environment details.

### AUD-15 (Medium): Unpinned npm deps in `web/Dockerfile`, missing PHP extension checks, and missing Compose healthchecks
- **Location**: `web/Dockerfile:3`, `api/Dockerfile`, `broker/Dockerfile`, `docker-compose.yml:19-74`
- **Evidence**:
  - `web/Dockerfile:3` installs `typescript@5.6.3` and `qrcode-generator@1.4.4` without a `package.json` or `package-lock.json`.
  - `api/Dockerfile` and `broker/Dockerfile` do not explicitly verify that `curl`, `mbstring`, `json`, and `pdo_pgsql` (API) are loaded.
  - `docker-compose.yml` has no healthchecks for `broker`, `api`, `web`, or `egress`, and `api` depends on `broker: { condition: service_started }` rather than `service_healthy`.
- **Proposed Fix**:
  - Add `web/package.json` and `web/package-lock.json` and use `npm ci` in `web/Dockerfile`.
  - Add build-time extension verification (`php -r` checking required extensions) in `api/Dockerfile` and `broker/Dockerfile`.
  - Add healthchecks to `broker`, `api`, `web`, and `egress` in `docker-compose.yml` and use `condition: service_healthy` for `depends_on` where appropriate.
- **Verification Test**: `docker compose config`, image builds, and `tsc -p web/tsconfig.json --noEmit`.

### AUD-16 (Low): Broker exception logging could expose sensitive strings
- **Location**: `broker/index.php:44`
- **Evidence**: `logline('error: ' . get_class($e) . ': ' . $e->getMessage());` logs raw exception messages.
- **Proposed Fix**: Redact token/key patterns (Telegram tokens, `sk-*`, `AIza*`, `gsk_*`) before writing to stderr in `logline()`.
- **Verification Test**: Unit test verifying `redact()` strips Telegram bot tokens and API keys from log strings.

### AUD-17 (Low): `README.md` discrepancies vs actual implementation
- **Location**: `README.md:29,54,91,126,130-131`
- **Evidence**:
  1. `README.md:126` claims `Telegram access is dmPolicy: "open" ... An allowlist of Telegram user ids is the obvious next field`, but `api/src/agentfather.php:109-119` and `broker/index.php:176-178,265` already implement `allowlist` / `TELEGRAM_ALLOWED_USERS`.
  2. `README.md:54` lists only `api.anthropic.com, api.telegram.org` in the egress diagram, whereas `egress/allowlist` also allows `generativelanguage.googleapis.com` and `api.groq.com`.
  3. `README.md:29` omits the specific Claude 5.5 / 4.6 models offered in `api/src/agentfather.php:20-27`.
  4. `README.md:130-131` describes the old worker crash behavior (marking jobs failed) rather than resumable leased recovery.
  5. `README.md:91` claims all base images are pinned by digest, whereas Dockerfiles and `postgres` use exact version tags (`OPENCLAW_IMAGE` and `HERMES_IMAGE` use tag+digest).
- **Proposed Fix**: Update `README.md` to accurately reflect the current implementation, setup requirements, absence of `composer.lock` (zero PHP dependencies), and smoke test instructions.
