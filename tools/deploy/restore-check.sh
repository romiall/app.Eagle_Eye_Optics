#!/usr/bin/env bash
# Exercice de restauration (X03 minimal) : restaure une sauvegarde de tools/deploy/backup.sh dans une base MySQL
# JETABLE, vérifie le schéma par rapport à la baseline et compte quelques tables. La base de production n'est jamais
# touchée : le script refuse une base cible égale à DB_DATABASE du .env, ou dont le nom ne se termine pas par
# « _restore » (ou « _test »).
#
#   bash tools/deploy/restore-check.sh <dossier eeo-backup-…> <base jetable> [dossier de l'application]
#
# La base jetable est créée VIDE au préalable (panneau de l'hébergeur), accessible avec les identifiants du .env
# (ou EEO_RESTORE_DB_USERNAME / EEO_RESTORE_DB_PASSWORD dans l'environnement du shell). Le mot de passe n'apparaît
# jamais sur la ligne de commande. À faire avant la mise en service, puis régulièrement : une sauvegarde jamais
# restaurée n'est pas une sauvegarde.
set -euo pipefail
umask 077

BACKUP="${1:-}"
TARGET_DB="${2:-}"
APP="${3:-$HOME/eeo}"
APP="${APP%/}"
if [[ -z "$BACKUP" || -z "$TARGET_DB" ]]; then
  sed -n '2,12p' "${BASH_SOURCE[0]}" | sed 's/^# \{0,1\}//'
  exit 2
fi
ENV_FILE="${APP}/.env"
[[ -f "$ENV_FILE" ]] || { echo "Fichier ${ENV_FILE} introuvable." >&2; exit 2; }
[[ -f "${BACKUP}/database.sql.gz" ]] || { echo "${BACKUP}/database.sql.gz introuvable." >&2; exit 2; }
command -v mysql >/dev/null || { echo "Le client mysql est introuvable." >&2; exit 2; }

env_value() {
  local line value
  line="$(grep -E "^$1=" "$ENV_FILE" | tail -n 1 || true)"
  value="${line#*=}"
  value="${value%$'\r'}"
  if [[ "$value" == \"*\" && "$value" == *\" ]]; then value="${value:1:${#value}-2}"; fi
  if [[ "$value" == \'*\' && "$value" == *\' ]]; then value="${value:1:${#value}-2}"; fi
  printf '%s' "$value"
}

PRODUCTION_DB="$(env_value DB_DATABASE)"
if [[ "$TARGET_DB" == "$PRODUCTION_DB" ]]; then
  echo "Refus : la base cible est la base de l'application (${PRODUCTION_DB}). Utilisez une base jetable." >&2
  exit 2
fi
if [[ ! "$TARGET_DB" =~ ^[A-Za-z0-9_]+_(restore|test)$ ]]; then
  echo "Refus : le nom de la base jetable doit se terminer par _restore ou _test (reçu ${TARGET_DB})." >&2
  exit 2
fi

echo "Empreintes de la sauvegarde…"
(cd "$BACKUP" && sha256sum -c SHA256SUMS)

DB_HOST="$(env_value DB_HOST)"; DB_HOST="${DB_HOST:-127.0.0.1}"
DB_PORT="$(env_value DB_PORT)"; DB_PORT="${DB_PORT:-3306}"
DB_USERNAME="${EEO_RESTORE_DB_USERNAME:-$(env_value DB_USERNAME)}"
DB_PASSWORD="${EEO_RESTORE_DB_PASSWORD:-$(env_value DB_PASSWORD)}"

OPTIONS="$(mktemp)"
trap 'rm -f "$OPTIONS"' EXIT
chmod 600 "$OPTIONS"
{
  echo '[client]'
  echo "host=${DB_HOST}"
  echo "port=${DB_PORT}"
  echo "user=${DB_USERNAME}"
  escaped="${DB_PASSWORD//\\/\\\\}"
  printf 'password="%s"\n' "${escaped//\"/\\\"}"
} > "$OPTIONS"

existing="$(mysql --defaults-extra-file="$OPTIONS" -N -B -e "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = '${TARGET_DB}'")"
if [[ "$existing" != "0" ]]; then
  echo "Refus : la base ${TARGET_DB} n'est pas vide (${existing} tables). Videz-la ou créez-en une neuve." >&2
  exit 2
fi

echo "Restauration dans ${TARGET_DB}…"
gzip -dc "${BACKUP}/database.sql.gz" | mysql --defaults-extra-file="$OPTIONS" --default-character-set=utf8mb4 "$TARGET_DB"

echo "Vérification du schéma (baseline complète)…"
# Configuration relue sans le cache de production, base cible substituée : la base de l'application n'est pas lue.
(cd "$APP" && APP_CONFIG_CACHE="$(mktemp -u)" DB_DATABASE="$TARGET_DB" DB_USERNAME="$DB_USERNAME" DB_PASSWORD="$DB_PASSWORD" \
  php artisan eagle:verify-schema)

echo "Comptes de quelques tables restaurées :"
for table in clinics users patient_records visits consultations invoices payments receipts audit_events; do
  count="$(mysql --defaults-extra-file="$OPTIONS" -N -B -e "SELECT COUNT(*) FROM \`${TARGET_DB}\`.\`${table}\`" 2>/dev/null || echo '?')"
  printf '  %-18s %s\n' "$table" "$count"
done

echo
echo "Restauration vérifiée dans ${TARGET_DB}. Supprimez cette base jetable une fois l'exercice consigné."
