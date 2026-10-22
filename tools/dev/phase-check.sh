#!/usr/bin/env bash
# Vérification transversale de fin de phase : exécute TOUTES les étapes, même quand l'une échoue, garde
# le journal complet de chacune et termine par un tableau récapitulatif. À la différence de
# check-all.sh (qui s'arrête au premier échec), elle sert à constater l'état d'une phase codée d'un bloc
# avant de corriger (consigne du demandeur : vérifier par phase, pas fichier par fichier).
#
#   tools/dev/phase-check.sh                 toutes les étapes sauf Playwright
#   tools/dev/phase-check.sh --with-e2e      ajoute Playwright (build + application réelle, voir testing.md)
#   tools/dev/phase-check.sh --only a,b      seulement certaines étapes (noms du tableau final)
#
# MySQL de test obligatoire (comme la CI) : EEO_TEST_DB_PORT, EEO_TEST_DB_DATABASE (défaut eeo_test).
# Journaux : storage/framework/testing/phase-check/<horodatage>/<étape>.log (ignorés par git).
set -uo pipefail

cd "$(dirname "${BASH_SOURCE[0]}")/../.."

WITH_E2E=0
ONLY=""
while [[ $# -gt 0 ]]; do
  case "$1" in
    --with-e2e) WITH_E2E=1 ;;
    --only) shift; ONLY="${1:-}" ;;
    -h|--help) sed -n '2,13p' "${BASH_SOURCE[0]}" | sed 's/^# \{0,1\}//'; exit 0 ;;
    *) echo "Option inconnue : $1" >&2; exit 2 ;;
  esac
  shift
done

[[ -d vendor ]] || { echo "vendor/ absent : lancer « composer install »." >&2; exit 2; }
[[ -d node_modules ]] || { echo "node_modules/ absent : lancer « npm ci »." >&2; exit 2; }

export EEO_REQUIRE_MYSQL=1
PYTHON=python3
command -v python3 >/dev/null 2>&1 && python3 -c 'import sys' >/dev/null 2>&1 || PYTHON=python

OUT="storage/framework/testing/phase-check/$(date +%Y%m%d-%H%M%S)"
mkdir -p "$OUT"

declare -a NAMES=() STATUSES=() DURATIONS=()

wanted() {
  [[ -z "$ONLY" ]] && return 0
  [[ ",${ONLY}," == *",$1,"* ]]
}

# run <nom> <commande…> : exécute, journalise, n'interrompt jamais la suite.
run() {
  local name="$1"
  shift
  wanted "$name" || return 0
  printf '\n==> %s\n' "$name"
  local start=$SECONDS
  if "$@" >"$OUT/$name.log" 2>&1; then
    STATUSES+=("OK")
  else
    STATUSES+=("ÉCHEC")
    tail -n 25 "$OUT/$name.log"
  fi
  NAMES+=("$name")
  DURATIONS+=("$((SECONDS - start)) s")
}

contracts_check() {
  local failed=0
  for generator in build_enums build_commands build_events build_resources build_openapi build_vectors; do
    "$PYTHON" "contracts/tools/${generator}.py" --check || failed=1
  done
  node contracts/tools/generate-types.mjs --check || failed=1
  return "$failed"
}

mysql_reachable() {
  php -r '
    $host = getenv("EEO_TEST_DB_HOST") ?: "127.0.0.1";
    $port = getenv("EEO_TEST_DB_PORT") ?: "3306";
    $db   = getenv("EEO_TEST_DB_DATABASE") ?: "eeo_test";
    $user = getenv("EEO_TEST_DB_USERNAME") ?: "eeo_test";
    $pass = getenv("EEO_TEST_DB_PASSWORD") ?: "eeo_test_password";
    try { new PDO("mysql:host=$host;port=$port;dbname=$db", $user, $pass, [PDO::ATTR_TIMEOUT => 3]); echo "MySQL joignable : $db\n"; }
    catch (Throwable $e) { fwrite(STDERR, $e->getMessage()."\n"); exit(1); }
  '
}

run mysql mysql_reachable
run contrats contracts_check
run pint vendor/bin/pint --test
run phpstan vendor/bin/phpstan analyse --memory-limit=1G --no-progress
run phpunit php vendor/bin/phpunit --no-progress
run vue-tsc npm run typecheck
run eslint npm run lint
run prettier npm run format:check
# Une seule file : le poste local manque de mémoire quand Vitest ouvre un environnement jsdom par cœur.
run vitest npx vitest run --no-file-parallelism
run build npm run build
if [[ "$WITH_E2E" == "1" ]]; then
  run playwright npx playwright test --reporter=line
fi

printf '\n%-12s %-7s %s\n' "Étape" "État" "Durée"
failed=0
for index in "${!NAMES[@]}"; do
  printf '%-12s %-7s %s\n' "${NAMES[$index]}" "${STATUSES[$index]}" "${DURATIONS[$index]}"
  [[ "${STATUSES[$index]}" == "OK" ]] || failed=1
done
printf '\nJournaux : %s\n' "$OUT"
exit "$failed"
