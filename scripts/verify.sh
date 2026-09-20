#!/usr/bin/env bash
# Full verification for the plain-PHP taskboard fixture:
#
#   1. scripts/build.sh -> release marker (VERSION) + PHP lint + preflight
#      runtime extensions
#   2. dependency-free unit/integration suite -> php tests/run_tests.php
#   3. scripts/smoke.sh -> real production server (php -S -> public/index.php):
#      CRUD, invalid input, search/filter, restart persistence,
#      database-unavailable readiness + recovery
#
# Usage:
#   scripts/verify.sh
#
# Exit codes: 0 = all checks passed, nonzero = a check failed. The first
# failing step aborts with its own nonzero code.
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
PHP="${PHP:-$(command -v php)}"
[ -x "$PHP" ] || { echo "php executable not found" >&2; exit 1; }

step() { printf '\n=== %s ===\n' "$*"; }

step "build release marker + lint all PHP sources"
"$ROOT/scripts/build.sh"

step "unit/integration suite (php tests/run_tests.php)"
(cd "$ROOT" && "$PHP" tests/run_tests.php)

step "production smoke: real process + CRUD + negatives + persistence + DB outage/recovery"
"$ROOT/scripts/smoke.sh"

step "verification complete (all steps passed)"
printf '%s\n' "php: $("$PHP" -r 'echo PHP_VERSION;')"
printf '%s\n' "release marker: $(cat "$ROOT/VERSION")"