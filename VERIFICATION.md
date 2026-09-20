# Verification record

Fixture: **deploy-test-php** (plain PHP + PDO/SQLite + sessions/CSRF,
PHP built-in web server).

## Command

```bash
bash scripts/verify.sh
```

## Environment

| Component | Version |
|-----------|---------|
| PHP       | 8.4.18 |
| SQLite    | bundled with PHP (`pdo_sqlite` via `sqlite3`) |
| Web server| PHP built-in server (`php -S`, web root `public/`) |
| Dependencies | none (no Composer packages, no lockfile required) |

## Steps and outcome (exit 0 = all passed)

1. `scripts/build.sh` — writes the `VERSION` release marker, preflights the
   runtime (PHP >= 8.2 + `pdo`, `pdo_sqlite`, `sqlite3`, `session`, `json`,
   `mbstring`) and lints every PHP file: **passed**.
2. Unit/integration suite — `php tests/run_tests.php` against real
   PDO/SQLite: **71 checks, 0 failed** (CRUD, search/filter, LIKE-wildcard
   escaping, validation negatives, CSRF enforcement 403, schema/seed
   idempotency, restart-style persistence, cascade delete, database-unavailable
   readiness 503).
3. Production smoke — `scripts/smoke.sh` against the real process
   `php -S 127.0.0.1:<port> -t public public/index.php`:
   **57 checks, 0 failed**, including:
   - session + CSRF bootstrap with a cookie jar; mutations without the token
     are rejected with 403;
   - liveness/readiness, release marker, static UI + assets, nested UI route,
     unknown static/API 404, unhandled method 405;
   - project/task CRUD over HTTP with CSRF, read-back, search and
     status/priority/project filters;
   - negative/validation cases (400) and not-found (404);
   - sqlite file written to disk;
   - persistence survivor survives a signal-based stop → restart on the
     **same** SQLite path with stable counts;
   - database-unavailable readiness: `503` with `status: "unavailable"`
     while liveness stays `200`, the API degrades to 503 and the static UI
     keeps serving; readiness recovers once the database path is valid again.

## Notes

- `VERSION` resolves to the git short SHA once committed; the run above used
  the short SHA of the then-current checkout (`87c113e`). A deployment that
  re-runs `scripts/build.sh` regenerates it from the deployed commit.
- No live platform/deployment qualification was performed; this is local
  production verification only.