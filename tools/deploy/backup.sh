#!/usr/bin/env bash
# Sauvegarde quotidienne de l'installation (X02 minimal) : base MySQL (structure, données, vues, TRIGGERS), fichiers
# produits par l'application (storage/app) et clés (.env : APP_KEY, sans laquelle des données chiffrées ne se relisent
# plus). À lancer par la tâche planifiée (cron) du serveur, jamais depuis le web.
#
#   bash tools/deploy/backup.sh [dossier de l'application]        (par défaut : ~/eeo)
#
# Réglages lus dans le .env de l'application : DB_HOST, DB_PORT, DB_DATABASE, DB_USERNAME, DB_PASSWORD,
# EEO_BACKUP_DIR (vide : ~/eeo-backups, toujours HORS de la racine web) et EEO_BACKUP_KEEP_DAYS (14 par défaut).
#
# Le mot de passe de la base n'apparaît jamais sur la ligne de commande (fichier d'options temporaire en 0600).
# Chaque sauvegarde est un dossier daté en 0700 avec ses empreintes SHA-256 ; les sauvegardes plus anciennes que
# EEO_BACKUP_KEEP_DAYS sont supprimées. Une copie HORS du serveur reste à faire (voir docs/implementation/deploiement.md)
# : une sauvegarde qui ne quitte jamais le serveur disparaît avec lui. Restauration : tools/deploy/restore-check.sh.
set -euo pipefail
umask 077

APP="${1:-$HOME/eeo}"
APP="${APP%/}"
ENV_FILE="${APP}/.env"
[[ -f "$ENV_FILE" ]] || { echo "Fichier ${ENV_FILE} introuvable : indiquez le dossier de l'application." >&2; exit 2; }
command -v mysqldump >/dev/null || { echo "mysqldump est introuvable : utilisez les sauvegardes du panneau de l'hébergeur." >&2; exit 2; }
command -v gzip >/dev/null || { echo "gzip est introuvable." >&2; exit 2; }

# Lecture d'une valeur du .env sans l'exécuter (aucune substitution de commande) ; guillemets simples ou doubles retirés.
env_value() {
  local line value
  line="$(grep -E "^$1=" "$ENV_FILE" | tail -n 1 || true)"
  value="${line#*=}"
  value="${value%$'\r'}"
  if [[ "$value" == \"*\" && "$value" == *\" ]]; then value="${value:1:${#value}-2}"; fi
  if [[ "$value" == \'*\' && "$value" == *\' ]]; then value="${value:1:${#value}-2}"; fi
  printf '%s' "$value"
}

DB_HOST="$(env_value DB_HOST)"; DB_HOST="${DB_HOST:-127.0.0.1}"
DB_PORT="$(env_value DB_PORT)"; DB_PORT="${DB_PORT:-3306}"
DB_DATABASE="$(env_value DB_DATABASE)"
DB_USERNAME="$(env_value DB_USERNAME)"
DB_PASSWORD="$(env_value DB_PASSWORD)"
BACKUP_DIR="$(env_value EEO_BACKUP_DIR)"; BACKUP_DIR="${BACKUP_DIR:-$HOME/eeo-backups}"
KEEP_DAYS="$(env_value EEO_BACKUP_KEEP_DAYS)"; KEEP_DAYS="${KEEP_DAYS:-14}"

[[ -n "$DB_DATABASE" && -n "$DB_USERNAME" ]] || { echo "DB_DATABASE et DB_USERNAME doivent être renseignés dans ${ENV_FILE}." >&2; exit 2; }
[[ "$KEEP_DAYS" =~ ^[0-9]+$ && "$KEEP_DAYS" -ge 1 ]] || { echo "EEO_BACKUP_KEEP_DAYS doit être un entier ≥ 1." >&2; exit 2; }
case "$(cd "$(dirname "$BACKUP_DIR")" 2>/dev/null && pwd)/$(basename "$BACKUP_DIR")" in
  "$APP"/public|"$APP"/public/*) echo "EEO_BACKUP_DIR ne doit jamais être sous public/ (racine web)." >&2; exit 2 ;;
esac

STAMP="$(date -u +%Y%m%dT%H%M%SZ)"
TARGET="${BACKUP_DIR}/eeo-backup-${STAMP}"
mkdir -p "$BACKUP_DIR"
chmod 700 "$BACKUP_DIR"
mkdir "$TARGET"

OPTIONS="$(mktemp)"
trap 'rm -f "$OPTIONS"' EXIT
chmod 600 "$OPTIONS"
{
  echo '[client]'
  echo "host=${DB_HOST}"
  echo "port=${DB_PORT}"
  echo "user=${DB_USERNAME}"
  # Valeur entre guillemets d'un fichier d'options MySQL : barres obliques inverses puis guillemets échappés.
  escaped="${DB_PASSWORD//\\/\\\\}"
  printf 'password="%s"\n' "${escaped//\"/\\\"}"
} > "$OPTIONS"

# Options compatibles avec un utilisateur d'hébergement mutualisé (pas de droit PROCESS : --no-tablespaces).
DUMP_OPTIONS=(--single-transaction --quick --routines --triggers --hex-blob --no-tablespaces
  --default-character-set=utf8mb4)
if mysqldump --help 2>/dev/null | grep -q -- '--set-gtid-purged'; then
  DUMP_OPTIONS+=(--set-gtid-purged=OFF)
fi

echo "[$(date -u +%Y-%m-%dT%H:%M:%SZ)] Sauvegarde de ${DB_DATABASE} vers ${TARGET}"
# Les clauses DEFINER sont retirées : une restauration par un autre utilisateur (sans SUPER) recrée vues et triggers
# au nom de l'utilisateur qui restaure.
mysqldump --defaults-extra-file="$OPTIONS" "${DUMP_OPTIONS[@]}" "$DB_DATABASE" \
  | sed -E 's/ DEFINER=`[^`]+`@`[^`]+`//g' \
  | gzip -9 > "${TARGET}/database.sql.gz"
# Un vidage interrompu ne se termine pas par la ligne « Dump completed » : il est refusé.
gzip -dc "${TARGET}/database.sql.gz" | tail -n 1 | grep -q 'Dump completed' \
  || { echo "Vidage incomplet : sauvegarde refusée." >&2; rm -rf "$TARGET"; exit 1; }

if [[ -d "${APP}/storage/app" ]]; then
  tar -czf "${TARGET}/storage-app.tar.gz" -C "${APP}/storage" app
fi
cp "$ENV_FILE" "${TARGET}/env.backup"
[[ -f "${APP}/RELEASE.json" ]] && cp "${APP}/RELEASE.json" "${TARGET}/RELEASE.json"

(cd "$TARGET" && sha256sum ./* > SHA256SUMS)
chmod 600 "${TARGET}"/*

# Rétention : les dossiers de sauvegarde plus anciens que KEEP_DAYS jours (jamais d'autres fichiers).
find "$BACKUP_DIR" -maxdepth 1 -type d -name 'eeo-backup-*' -mtime +"$KEEP_DAYS" -exec rm -rf {} +

SIZE="$(du -sh "$TARGET" | cut -f1)"
echo "[$(date -u +%Y-%m-%dT%H:%M:%SZ)] Sauvegarde terminée : ${TARGET} (${SIZE}). Pensez à la copie hors serveur."
