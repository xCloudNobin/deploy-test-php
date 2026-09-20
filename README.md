# PHP Taskboard

A meaningful **plain PHP** application for the xCloud app-compatibility suite: a
project/task board built with the PHP language's native capabilities — no
Laravel, no Composer packages. Persistence is **SQLite** through **PDO**,
requests are routed by a documented front-controller web-root setup, and
cookie-authenticated sessions back the CSRF-protected mutation API.

It is a production-process fixture, not a success-page shell: every workflow
reads and writes through parameterized PDO statements, all input is validated
server-side with meaningful error payloads, and `scripts/verify.sh` exercises
the real production process end to end.

## Feature summary

- Plain PHP front controller at `public/index.php` (the web root) served by
  PHP's production web server (`php -S ... -t public public/index.php`).
- Projects and tasks with status/priority, search (`q`), and status/priority
  filters over a JSON API consumed by a small DOM-rendered client.
- Sessions + CSRF: cookie-authenticated mutations must present the per-session
  token (`X-CSRF-Token` header or `_csrf` body field); reads are unauthenticated.
- Validated CRUD: blank/over-long/mistyped fields, invalid status/priority,
  malformed JSON, missing references and not-found resources all return
  meaningful JSON errors (400/404/405/503).
- Parameterized PDO SQL everywhere (LIKE wildcards escaped) — no string-built
  queries from user input. Output is escaped client-side by rendering via
  `textContent` only (no `innerHTML` with user data).
- Idempotent schema setup (`CREATE TABLE IF NOT EXISTS`) and repeatable seed
  data, guarded by a one-time seed flag so re-opens never duplicate rows.
- Persistence: explicit SQLite file (`DATA_DIR`/`DATABASE_PATH`); the app never
  stores permanent state in an ephemeral release directory.
- `/api/health/live` (process alive) and `/api/health/ready` (opens the
  configured SQLite path, ensures the schema and performs a real write + read;
  **503** while the database is unavailable).
- Non-sensitive release marker: `scripts/build.sh` writes `VERSION` (git SHA by
  default); the marker is served by `/api/meta` and shown in the UI footer.
- Logs go to stdout/stderr (the PHP built-in server's request log and
  `error_log()` output); no credentials are ever logged.

## Runtime and dependencies

- PHP **8.4.18** validated (PHP >= 8.2 required).
- Extensions: `pdo`, `pdo_sqlite`, `sqlite3`, `session`, `json` (and
  `mbstring` for safe length checks). `scripts/build.sh` preflights them.
- No third-party packages → no lockfile needed; `composer` is not used.

Runtime versions (this verification):

| Component | Version |
|-----------|---------|
| PHP       | 8.4.18 |
| SQLite    | bundled with PHP (`pdo_sqlite`) |
| Web server| PHP built-in server (`php -S`) |

## Quick start (development)

```bash
cp .env.example .env        # review and adjust (values exported by the launcher)
export $(grep -v '^#' .env | xargs)
php -S 127.0.0.1:8080 -t public public/index.php
```

Open http://localhost:8080 — the seeder has already created two demo projects
and a few tasks on first boot. The `.env` file is not auto-loaded by the
application; the process supervisor exports the variables, which is how
`scripts/smoke.sh` drives arbitrary database paths and ports.

## Production start

```bash
scripts/build.sh            # writes VERSION + PHP lint + runtime preflight
export PORT=8080 BIND_HOST=0.0.0.0 DATA_DIR=./data \
       DATABASE_PATH=./data/taskboard.db BUILD_MARKER=release-1
php -S "$BIND_HOST:$PORT" -t public public/index.php
```

- Binds to `BIND_HOST:PORT` (defaults **0.0.0.0:8080**). Set
  `PHP_CLI_SERVER_WORKERS` for more worker processes if desired.
- Run from the repository root so `public/` (UI assets) and `VERSION` resolve.
- Logs go to stdout/stderr; the process exits on SIGTERM/SIGINT.
- Use a process supervisor (systemd, an xCloud process manager, etc.) to keep
  it running and to mount a **persistent volume** at `DATA_DIR`/`DATABASE_PATH`.

### Web-root routing

`public/` is the document root. With PHP's built-in server, existing static
assets are served directly and everything else flows through the front
controller `public/index.php`, which:

1. routes `/api/...` prefixes to the JSON API;
2. serves JS/CSS assets from `public/`;
3. returns 404 for missing static assets;
4. renders the SPA shell for any other path, so nested client routes (e.g.
   `/project/3`) work and deep-link into the UI.

Behind PHP-FPM/FastCGI, point the web server's document root at `public/` and
configure a try-files fallback to `index.php` (nginx `location / { try_files
$uri $uri/ /index.php; }`), which preserves the same behavior.

## Sessions and CSRF

Every request may establish an HttpOnly, SameSite=Lax session. All mutating
API requests (POST/PATCH/PUT/DELETE) must carry the per-session CSRF token,
either as the `X-CSRF-Token` request header or as a `_csrf` field in the JSON
body. The SPA shell embeds the token on every page load; `/api/meta` also
exposes it so automated clients can bootstrap a session from a cookie jar.
Mutations without a valid token are rejected with **403** — cross-site forms
cannot forge changes even though the cookie admits the browser.

## Health and readiness

| Endpoint | Meaning |
|----------|---------|
| `GET /api/health/live`  | Process is alive (always 200 while serving; DB not touched). |
| `GET /api/health/ready` | Opens the configured SQLite path, ensures the schema, performs a real write + read; **503** with `status: "unavailable"` when the database is unavailable. |

`/api/health/ready` is a genuine dependency probe (fresh connection, real
write), not a static marker. `scripts/smoke.sh` proves it: it starts the
process against a database path that cannot be created (parent is a regular
file), observes readiness drop to 503 while liveness stays 200 and the static
UI still serves, and then repairs the path and verifies readiness recovers.

## Environment variables

See `.env.example` for the full commented list.

| Variable | Required | Default | Purpose |
|----------|----------|---------|---------|
| `PORT` | no | `8080` | bind port |
| `BIND_HOST` | no | `0.0.0.0` | bind address |
| `DATA_DIR` | no | `<repo>/data` | base data directory |
| `DATABASE_PATH` | no | `<DATA_DIR>/taskboard.db` | **persistent SQLite path** |
| `BUILD_MARKER` | no | git SHA | release marker in `/api/meta` and the UI footer |
| `BASE_PATH` | no | (empty) | URL prefix when served under a sub-path |
| `PHP_CLI_SERVER_WORKERS` | no | (server default) | PHP built-in server worker count |

No credentials or secrets are committed or required.

## Persistence

Data lives in the SQLite file at `DATABASE_PATH`, which defaults under
`DATA_DIR` (gitignored). For redeploys that reuse or replace the release
directory, mount a persistent volume at `DATA_DIR`/`DATABASE_PATH` so the file
survives. `scripts/smoke.sh` proves it: it creates a "PERSIST" survivor task
over HTTP, stops the production process, restarts it on the **same database
path**, and verifies the record is still served with stable counts.

Because the app opens a fresh PDO connection per request, a database that
becomes unavailable is immediately reported by readiness (**503**) and the
data routes (**503**), and readiness recovers as soon as the path is valid
again. A process whose database file is deleted must be restarted to reclaim
its prior in-memory data, consistent with SQLite semantics.

## Schema

Created idempotently (`CREATE TABLE IF NOT EXISTS`) by `src/db.php`:

- `project` — id, name, description, status (`active|archived`), timestamps.
- `task` — id, `project_id` FK (`ON DELETE CASCADE`), title, description,
  status (`todo|in_progress|done`), priority (`low|medium|high`), timestamps.
- `seed_flag` — marks the one-time seed as applied.
- `heartbeat` — backing table for the readiness write probe.

Seeding is repeatable: the second and subsequent opens never add rows (the
test suite asserts seed idempotency).

## API

| Method | Path | Purpose |
|--------|------|---------|
| GET | `/api/health/live` | liveness |
| GET | `/api/health/ready` | readiness (DB probe) |
| GET | `/api/meta` | release marker + runtime versions + CSRF token |
| GET | `/api/csrf` | per-session CSRF token |
| GET/POST | `/api/projects` | list / create projects |
| GET/PATCH/DELETE | `/api/projects/:id` | read / update / delete a project |
| GET/POST | `/api/tasks` | list (filters `q`, `status`, `priority`, `project_id`) / create tasks |
| GET/PATCH/DELETE | `/api/tasks/:id` | read / update / delete a task |

Mutating methods require the session CSRF token. The UI at `/` (and nested
paths like `/project/<id>`) consumes the same JSON API.

## Automated verification

```bash
scripts/verify.sh
```

Runs, in order:

1. `scripts/build.sh` — writes the `VERSION` marker, preflights the runtime
   (PHP version + required extensions) and lints every PHP file.
2. `tests/run_tests.php` — dependency-free suite against **real PDO/SQLite**:
   CRUD, search/filter, LIKE-wildcard escaping, validation negatives
   (blank/over-long/mistyped fields, invalid status/priority, malformed JSON,
   missing project, empty PATCH, 404s), CSRF enforcement (403), schema/seed
   idempotency, restart-style persistence, cascade delete, and readiness that
   drops to 503 when the database path is unusable.
3. `scripts/smoke.sh` — the real production process (`php -S -t public
   public/index.php`): session/CSRF bootstrap, liveness/readiness, CRUD over
   HTTP, search/status/priority filters, release marker, negative cases,
   stop → restart persistence, database-unavailable readiness (503) while
   liveness stays 200, and recovery once the database is available again.

Exit 0 only when every check passes.

## Repository layout

```
public/         index.php (front controller / web root), app.js, style.css
src/            config.php (env), session.php (CSRF), db.php (schema/seed/probe),
                api.php (routing + validated CRUD), ui.php (SPA shell)
tests/          run_tests.php (dependency-free unit/integration suite)
scripts/        build.sh (marker + lint + preflight), smoke.sh (production check),
                verify.sh (full verification)
.env.example    documented, safe configuration template
VERSION         non-sensitive release marker written by scripts/build.sh
```

## License

MIT — see [LICENSE](LICENSE). This fixture is part of the MIT-licensed
[xCloud app-compatibility suite](https://github.com/xCloudNobin/app-compatibility).