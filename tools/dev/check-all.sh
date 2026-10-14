#!/usr/bin/env bash
# Enchaîne tous les contrôles locaux : Pint, analyse statique PHP (Larastan), PHPUnit, TypeScript, ESLint,
# Prettier, Vitest et build.
#
#   tools/dev/check-all.sh              contrôles locaux (les tests MySQL sont IGNORÉS si MySQL est injoignable)
#   tools/dev/check-all.sh --strict     MySQL obligatoire : un MySQL injoignable fait échouer (comme la CI)
#   tools/dev/check-all.sh --with-e2e   ajoute les tests Playwright (nécessite Chromium, voir testing.md)
#
# Prérequis : composer install et npm ci déjà exécutés ; base de test préparée par tools/dev/mysql-up.sh.
set -euo pipefail

cd "$(dirname "${BASH_SOURCE[0]}")/../.."

STRICT=0
WITH_E2E=0
for argument in "$@"; do
  case "$argument" in
    --strict) STRICT=1 ;;
    --with-e2e) WITH_E2E=1 ;;
    -h|--help) sed -n '2,8p' "${BASH_SOURCE[0]}" | sed 's/^# \{0,1\}//'; exit 0 ;;
    *) echo "Option inconnue : ${argument}" >&2; exit 2 ;;
  esac
done

[[ -d vendor ]] || { echo "vendor/ absent : lancer « composer install »." >&2; exit 2; }
[[ -d node_modules ]] || { echo "node_modules/ absent : lancer « npm ci »." >&2; exit 2; }

if [[ "$STRICT" == "1" ]]; then
  export EEO_REQUIRE_MYSQL=1
fi

step() { printf '\n==> %s\n' "$*"; }

step "Joignabilité de la base de test isolée"
if php -r '
  $host = getenv("EEO_TEST_DB_HOST") ?: "127.0.0.1";
  $port = getenv("EEO_TEST_DB_PORT") ?: "3306";
  $db   = getenv("EEO_TEST_DB_DATABASE") ?: "eeo_test";
  $user = getenv("EEO_TEST_DB_USERNAME") ?: "eeo_test";
  $pass = getenv("EEO_TEST_DB_PASSWORD") ?: "eeo_test_password";
  try { new PDO("mysql:host=$host;port=$port;dbname=$db", $user, $pass, [PDO::ATTR_TIMEOUT => 3]); exit(0); }
  catch (Throwable $e) { fwrite(STDERR, $e->getMessage()."\n"); exit(1); }
'; then
  echo "MySQL de test joignable."
elif [[ "$STRICT" == "1" ]]; then
  echo "MySQL de test injoignable : lancer tools/dev/mysql-up.sh (mode --strict : échec)." >&2
  exit 1
else
  echo "ATTENTION : MySQL de test injoignable. Les tests MySQL seront IGNORÉS (lancer tools/dev/mysql-up.sh)." >&2
fi

step "Style et analyse statique PHP (Pint, puis PHPStan + Larastan)"
composer run lint

step "PHPUnit (Unit, Feature, Concurrency, Contracts)"
composer run test

step "Contrats : fichiers générés à jour (Python et types TypeScript)"
for generator in build_enums build_commands build_events build_resources build_openapi; do
  python3 "contracts/tools/${generator}.py" --check
done
node contracts/tools/generate-types.mjs --check

step "TypeScript strict (vue-tsc)"
npm run typecheck

step "ESLint"
npm run lint

step "Prettier"
npm run format:check

step "Vitest"
npm test

step "Build (Vite + PWA)"
npm run build

if [[ "$WITH_E2E" == "1" ]]; then
  step "E2E Playwright (Chromium)"
  npx playwright test
fi

printf '\nTous les contrôles demandés sont passés.\n'
