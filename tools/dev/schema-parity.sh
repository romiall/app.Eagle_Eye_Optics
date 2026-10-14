#!/usr/bin/env bash
# Preuve d'équivalence F04 : base créée par import du SQL livré contre base créée par les migrations.
#
# Toutes les bases utilisées sont des bases de test jetables « eeo_*_test » (garde de nom ci-dessous) sur le
# MySQL de développement ou de CI. Jamais de base de production : le script refuse tout autre nom.
#
#   1. Import client mysql de database/eagle_eye_optics_complet.sql dans eeo_parity_sql_test.
#   2. php artisan migrate sur eeo_parity_mig_test (base neuve).
#   3. Photographies normalisées des deux bases (eeo:schema:snapshot) et comparaison stricte,
#      puis eagle:verify-schema contre database/baseline/metadata.json.
#   4. Tests SQL existants (metadata + 41 assertions smoke) sur la base MIGRÉE.
#   5. Adoption : base importée équivalente acceptée ; trois bases divergentes refusées sans écriture.
#   6. Double exécution : migrate sur une base déjà importée refusé ; second migrate sans effet.
#   7. Retour arrière refusé en configuration production, schéma inchangé.
# Résultat : docs/evidence/schema-parity.json (ou --output=chemin). Code de sortie non nul au moindre échec.
#
# Options : --write-baseline  régénère database/baseline/metadata.json depuis l'import SQL (après une
#                             modification volontaire de la baseline) ; --keep  conserve les bases jetables.
# Variables : EEO_TEST_DB_HOST/PORT/USERNAME/PASSWORD (mêmes défauts que tests/bootstrap.php),
#             EEO_MYSQL_CONTAINER (client mysql du conteneur si aucun client local), EEO_EVIDENCE_DATE.
set -uo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
cd "$ROOT"

HOST="${EEO_TEST_DB_HOST:-127.0.0.1}"
PORT="${EEO_TEST_DB_PORT:-3306}"
USER_NAME="${EEO_TEST_DB_USERNAME:-eeo_test}"
PASSWORD="${EEO_TEST_DB_PASSWORD:-eeo_test_password}"
CONTAINER="${EEO_MYSQL_CONTAINER:-eeo-mysql}"
OUTPUT="docs/evidence/schema-parity.json"
WRITE_BASELINE=0
KEEP=0
for arg in "$@"; do
  case "$arg" in
    --write-baseline) WRITE_BASELINE=1 ;;
    --keep) KEEP=1 ;;
    --output=*) OUTPUT="${arg#--output=}" ;;
    *) echo "Option inconnue : $arg" >&2; exit 2 ;;
  esac
done

WORK="$(mktemp -d "${TMPDIR:-/tmp}/eeo-parity.XXXXXX")"
LOG="$WORK/parity.log"
log() { printf '[schema-parity] %s\n' "$*" | tee -a "$LOG"; }
die() { printf '[schema-parity] ÉCHEC : %s\n' "$*" | tee -a "$LOG" >&2; exit 1; }

# Client mysql : local si présent, sinon celui du conteneur de développement. Mot de passe par MYSQL_PWD.
if command -v mysql >/dev/null 2>&1; then
  MYSQL=(env MYSQL_PWD="$PASSWORD" mysql --protocol=TCP -h"$HOST" -P"$PORT" -u"$USER_NAME")
  MYSQL_CLIENT="client mysql local ($(mysql --version | head -n1))"
else
  MYSQL=(docker exec -i -e MYSQL_PWD="$PASSWORD" "$CONTAINER" mysql -h127.0.0.1 -u"$USER_NAME")
  MYSQL_CLIENT="client mysql du conteneur $CONTAINER"
fi

guard_name() { [[ "$1" =~ ^eeo_[a-z0-9_]+_test$ ]] || die "nom de base refusé : $1 (seules les bases eeo_*_test jetables sont admises)"; }

recreate_db() {
  guard_name "$1"
  "${MYSQL[@]}" -e "DROP DATABASE IF EXISTS \`$1\`; CREATE DATABASE \`$1\` CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci;" 2>>"$LOG" \
    || die "création de la base $1 impossible"
}

drop_db() { guard_name "$1"; "${MYSQL[@]}" -e "DROP DATABASE IF EXISTS \`$1\`;" 2>>"$LOG"; }

import_sql() {
  guard_name "$1"
  "${MYSQL[@]}" "$1" < database/eagle_eye_optics_complet.sql 2>>"$LOG" || die "import du SQL livré dans $1 en échec"
}

APP_KEY_VALUE="base64:$(head -c 32 /dev/urandom | base64)"
artisan() { # base, environnement, arguments…
  local db="$1" env="$2"; shift 2
  guard_name "$db"
  env APP_ENV="$env" APP_KEY="$APP_KEY_VALUE" APP_DEBUG=false DB_CONNECTION=mysql DB_HOST="$HOST" DB_PORT="$PORT" \
    DB_DATABASE="$db" DB_USERNAME="$USER_NAME" DB_PASSWORD="$PASSWORD" LOG_CHANNEL=stderr \
    php artisan "$@" --no-interaction
}

count_baseline_rows() { # nombre de migrations de baseline enregistrées
  "${MYSQL[@]}" -N -B "$1" -e "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='migrations'" 2>>"$LOG" | grep -qx 1 \
    || { echo 0; return; }
  "${MYSQL[@]}" -N -B "$1" -e "SELECT COUNT(*) FROM migrations WHERE migration LIKE '2026\_10\_14\_00000%\_eeo\_baseline\_%'" 2>>"$LOG"
}

SQL_DB=eeo_parity_sql_test
MIG_DB=eeo_parity_mig_test
OK_DB=eeo_adopt_ok_test
BAD_DBS=(eeo_adopt_bad_trigger_test eeo_adopt_bad_check_test eeo_adopt_bad_reference_test)
BAD_SQL=(
  "DROP TRIGGER tr_payments_update_guard"
  "ALTER TABLE invoices DROP CHECK ck_invoices_01"
  "UPDATE permissions SET label='Modifié hors baseline' WHERE code='invoice.issue'"
)

log "Client : $MYSQL_CLIENT ; journal : $LOG"
MYSQL_VERSION="$("${MYSQL[@]}" -N -B -e 'SELECT VERSION()' 2>>"$LOG")" || die "MySQL injoignable (lancer tools/dev/mysql-up.sh)"
log "MySQL $MYSQL_VERSION"

log "Contrôle du générateur : les instructions reproduisent les scripts SQL validés."
python3 -I database/tools/build_migrations.py --check >>"$LOG" 2>&1 || die "database/baseline/statements.json périmé ou divergent (voir $LOG)"

log "1. Import du SQL livré dans $SQL_DB."
recreate_db "$SQL_DB"
import_sql "$SQL_DB"
artisan "$SQL_DB" testing eeo:schema:snapshot --source="Import client mysql de database/eagle_eye_optics_complet.sql sur MySQL $MYSQL_VERSION" \
  --output="$WORK/snapshot-sql.json" >>"$LOG" 2>&1 || die "photographie de $SQL_DB impossible"

log "2. Migrations sur la base neuve $MIG_DB."
recreate_db "$MIG_DB"
artisan "$MIG_DB" testing migrate --force >"$WORK/migrate-1.txt" 2>&1 || { cat "$WORK/migrate-1.txt" >>"$LOG"; die "php artisan migrate en échec sur base neuve"; }
artisan "$MIG_DB" testing migrate --force >"$WORK/migrate-2.txt" 2>&1 || die "second php artisan migrate en échec"
artisan "$MIG_DB" testing eeo:schema:snapshot --source="php artisan migrate sur base neuve" \
  --output="$WORK/snapshot-mig.json" >>"$LOG" 2>&1 || die "photographie de $MIG_DB impossible"

if [[ "$WRITE_BASELINE" == "1" ]]; then
  log "Régénération demandée de database/baseline/metadata.json depuis l'import SQL."
  cp "$WORK/snapshot-sql.json" database/baseline/metadata.json
fi

log "3. Comparaison stricte des métadonnées."
artisan "$MIG_DB" testing eagle:verify-schema --expected="$WORK/snapshot-sql.json" --json="$WORK/verify-mig-vs-sql.json" >>"$LOG" 2>&1
VERIFY_MIG_VS_SQL=$?
artisan "$MIG_DB" testing eagle:verify-schema --json="$WORK/verify-mig.json" >>"$LOG" 2>&1
VERIFY_MIG=$?
artisan "$SQL_DB" testing eagle:verify-schema --json="$WORK/verify-sql.json" >>"$LOG" 2>&1
VERIFY_SQL=$?

log "4. Tests SQL existants sur la base migrée."
"${MYSQL[@]}" -N -B "$MIG_DB" < database/tests/mysql_metadata_tests.sql >"$WORK/sql-metadata.txt" 2>>"$LOG"
SQL_METADATA_EXIT=$?
"${MYSQL[@]}" -N -B "$MIG_DB" < database/tests/mysql_smoke_tests.sql >"$WORK/sql-smoke.txt" 2>>"$LOG"
SQL_SMOKE_EXIT=$?

log "5. Adoption d'une base importée équivalente ($OK_DB)."
recreate_db "$OK_DB"
import_sql "$OK_DB"
artisan "$OK_DB" production eeo:schema:adopt --force >"$WORK/adopt-ok.txt" 2>&1
ADOPT_OK=$?
ADOPT_OK_ROWS="$(count_baseline_rows "$OK_DB")"
artisan "$OK_DB" production eeo:schema:adopt --force >"$WORK/adopt-ok-again.txt" 2>&1
ADOPT_AGAIN=$?
artisan "$OK_DB" testing migrate --force >"$WORK/adopt-ok-migrate.txt" 2>&1
ADOPT_MIGRATE=$?
artisan "$OK_DB" testing eagle:verify-schema --json="$WORK/verify-adopted.json" >>"$LOG" 2>&1
ADOPT_VERIFY=$?

BAD_RESULTS=()
for i in "${!BAD_DBS[@]}"; do
  db="${BAD_DBS[$i]}"
  log "5.$((i + 1)) Adoption refusée attendue : ${BAD_SQL[$i]} ($db)."
  recreate_db "$db"
  import_sql "$db"
  "${MYSQL[@]}" "$db" -e "${BAD_SQL[$i]}" 2>>"$LOG" || die "impossible d'introduire la divergence dans $db"
  artisan "$db" production eeo:schema:adopt --force >"$WORK/adopt-bad-$i.txt" 2>&1
  code=$?
  rows="$(count_baseline_rows "$db")"
  BAD_RESULTS+=("$code|$rows|${BAD_SQL[$i]}|$WORK/adopt-bad-$i.txt")
done

log "6. Double exécution : migrate sur la base déjà importée $SQL_DB."
artisan "$SQL_DB" testing migrate --force >"$WORK/double-migrate.txt" 2>&1
DOUBLE_EXIT=$?
DOUBLE_ROWS="$(count_baseline_rows "$SQL_DB")"
artisan "$SQL_DB" testing eagle:verify-schema >>"$LOG" 2>&1
DOUBLE_VERIFY=$?

log "7. Retour arrière en configuration production sur $MIG_DB."
artisan "$MIG_DB" production migrate:rollback --force >"$WORK/rollback-production.txt" 2>&1
ROLLBACK_EXIT=$?
artisan "$MIG_DB" testing eagle:verify-schema --expected="$WORK/snapshot-sql.json" >>"$LOG" 2>&1
ROLLBACK_VERIFY=$?

export WORK OUTPUT MYSQL_VERSION MYSQL_CLIENT VERIFY_MIG_VS_SQL VERIFY_MIG VERIFY_SQL SQL_METADATA_EXIT SQL_SMOKE_EXIT \
  ADOPT_OK ADOPT_OK_ROWS ADOPT_AGAIN ADOPT_MIGRATE ADOPT_VERIFY DOUBLE_EXIT DOUBLE_ROWS DOUBLE_VERIFY ROLLBACK_EXIT ROLLBACK_VERIFY
BAD_JOINED="$(printf '%s\n' "${BAD_RESULTS[@]}")"
export BAD_JOINED
python3 -I tools/dev/schema_parity_report.py "$ROOT"
REPORT_EXIT=$?

if [[ "$KEEP" != "1" ]]; then
  for db in "$SQL_DB" "$MIG_DB" "$OK_DB" "${BAD_DBS[@]}"; do drop_db "$db"; done
fi

if [[ "$REPORT_EXIT" != "0" ]]; then
  die "au moins un contrôle d'équivalence a échoué : voir $OUTPUT et $LOG"
fi
log "Équivalence prouvée : $OUTPUT"
