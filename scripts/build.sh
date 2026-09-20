#!/usr/bin/env bash
# Generate the non-sensitive release marker (VERSION) and lint every PHP file.
#
# Resolution order for the marker:
#   1. $BUILD_MARKER (explicit), e.g. BUILD_MARKER=v1.2.3
#   2. latest git short SHA at the checkout
#   3. current UTC date as a fallback
#
# The marker is intentionally not secret; database paths and environment
# values stay out of the repository. There is no dependency lockfile for
# plain PHP — the runtime requirements (PHP >= 8.2 with pdo_sqlite + session)
# are documented in README and preflighted here.
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
OUT="$ROOT/VERSION"
PHP="${PHP:-$(command -v php)}"
NODE="${NODE:-$(command -v node)}"

[ -x "$PHP" ] || { echo "php executable not found" >&2; exit 1; }
command -v php >/dev/null 2>&1 || { echo "php not found" >&2; exit 1; }

if [ -n "${BUILD_MARKER:-}" ]; then
  MARKER="$BUILD_MARKER"
elif sha="$(git -C "$ROOT" rev-parse --short HEAD 2>/dev/null)"; then
  MARKER="$sha"
else
  MARKER="build-$(date -u +%Y%m%d-%H%M%S)"
fi

printf '%s\n' "$MARKER" > "$OUT"
printf 'VERSION = %s\n' "$MARKER"

printf 'preflight runtime: '
"$PHP" -r '
if (PHP_VERSION_ID < 80200) { fwrite(STDERR, "PHP >= 8.2 required, got " . PHP_VERSION . "\n"); exit(1); }
foreach (["pdo", "pdo_sqlite", "sqlite3", "session", "json"] as $ext) {
  if (!extension_loaded($ext)) { fwrite(STDERR, "missing extension: $ext\n"); exit(1); }
}
printf("php %s (extensions ok)\n", PHP_VERSION);
'

printf 'linting PHP sources\n'
while IFS= read -r -d '' file; do
  "$PHP" -l "$file" >/dev/null
done < <(find "$ROOT/src" "$ROOT/public" "$ROOT/tests" -type f -name '*.php' -print0)

if [ -n "$NODE" ] && [ -x "$NODE" ]; then
  printf 'client JavaScript syntax check\n'
  "$NODE" --check "$ROOT/public/app.js"
else
  printf 'node not found; skipping client JavaScript syntax check\n'
fi

printf 'build complete\n'