#!/usr/bin/env bash
# Sauvegarde quotidienne de l'installation (X02 minimal) : base MySQL (structure, données, vues, TRIGGERS), fichiers
# produits par l'application (storage/app) et clés (.env : APP_KEY, sans laquelle des données chiffrées ne se relisent
# plus). À lancer par la tâche planifiée (cron) du serveur, jamais depuis le web.
#
#   bash tools/deploy/backup.sh [dossier de l'application]        (par défaut : ~/eeo)
#
# Réglages lus dans le .env de l'application : DB_HOST, DB_PORT, DB_DATABASE, DB_USERNAME, DB_PASSWORD,
# EEO_BACKUP_DIR (vide : ~/eeo-backups, toujours HORS de la racine web), EEO_BACKUP_KEEP_DAYS (14 par défaut) et
# EEO_BACKUP_KEY_FILE : fichier de clé de chiffrement (hors de l'application, 0600, copie gardée HORS du serveur par
# la personne responsable ; sans elle, une sauvegarde chiffrée ne se relit plus). Créé une fois :
#   openssl rand -base64 48 > ~/.eeo-backup.key && chmod 600 ~/.eeo-backup.key
# Avec la clé : base, fichiers et .env sont chiffrés (AES-256, openssl, dérivation PBKDF2) et le journal backup_runs
# reçoit l'identifiant de la clé (jamais la clé). Sans clé : la sauvegarde est faite EN CLAIR et consignée en échec
# NOT_ENCRYPTED (fichier 09 : copie chiffrée obligatoire) ; elle ne sert pas de preuve de restauration.
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
KEY_FILE="$(env_value EEO_BACKUP_KEY_FILE)"; KEY_FILE="${KEY_FILE/#\~/$HOME}"
KEY_ID=""
if [[ -n "$KEY_FILE" ]]; then
  command -v openssl >/dev/null || { echo "openssl est introuvable : chiffrement impossible." >&2; exit 2; }
  [[ -f "$KEY_FILE" && -r "$KEY_FILE" ]] || { echo "EEO_BACKUP_KEY_FILE (${KEY_FILE}) est introuvable ou illisible." >&2; exit 2; }
  case "$(cd "$(dirname "$KEY_FILE")" && pwd)" in
    "$APP"|"$APP"/*) echo "EEO_BACKUP_KEY_FILE ne doit pas être dans le dossier de l'application." >&2; exit 2 ;;
  esac
  [[ "$(wc -c < "$KEY_FILE")" -ge 32 ]] || { echo "La clé ${KEY_FILE} est trop courte (32 octets au moins)." >&2; exit 2; }
  KEY_ID="sha256:$(sha256sum "$KEY_FILE" | cut -c1-16)"
else
  echo "ATTENTION : EEO_BACKUP_KEY_FILE n'est pas renseigné, la sauvegarde sera EN CLAIR et consignée NOT_ENCRYPTED." >&2
fi
PHP_BIN="${EEO_PHP_BIN:-php}"
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

STARTED_AT="$(date -u +%Y-%m-%dT%H:%M:%SZ)"
echo "[${STARTED_AT}] Sauvegarde de ${DB_DATABASE} vers ${TARGET}"
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

# Chiffrement : chaque fichier porteur de données ou de secrets devient <fichier>.enc ; l'original en clair est retiré.
if [[ -n "$KEY_FILE" ]]; then
  for part in database.sql.gz storage-app.tar.gz env.backup; do
    [[ -f "${TARGET}/${part}" ]] || continue
    openssl enc -aes-256-cbc -pbkdf2 -iter 200000 -salt -in "${TARGET}/${part}" -out "${TARGET}/${part}.enc" -pass "file:${KEY_FILE}" \
      || { echo "Chiffrement de ${part} impossible : sauvegarde refusée." >&2; rm -rf "$TARGET"; exit 1; }
    rm -f "${TARGET}/${part}"
  done
fi

(cd "$TARGET" && sha256sum ./* > SHA256SUMS)
chmod 600 "${TARGET}"/*
COMPLETED_AT="$(date -u +%Y-%m-%dT%H:%M:%SZ)"

# Journal backup_runs (une ligne par partie). Un journal non écrit n'annule pas la sauvegarde : il est signalé.
record_part() {
  local scope="$1" file="$2" sha size
  [[ -f "${TARGET}/${file}" ]] || return 0
  sha="$(sha256sum "${TARGET}/${file}" | cut -d' ' -f1)"
  size="$(wc -c < "${TARGET}/${file}" | tr -d ' ')"
  (cd "$APP" && "$PHP_BIN" artisan eeo:backup:record --scope="$scope" --status=SUCCEEDED \
    --started-at="$STARTED_AT" --completed-at="$COMPLETED_AT" --destination="$TARGET" \
    --sha256="$sha" --size="$size" ${KEY_ID:+--key-id="$KEY_ID"}) \
    || echo "ATTENTION : journal backup_runs non écrit pour ${file}." >&2
}
SUFFIX=""; [[ -n "$KEY_FILE" ]] && SUFFIX=".enc"
record_part SERVER_DATABASE "database.sql.gz${SUFFIX}"
record_part SERVER_FILES "storage-app.tar.gz${SUFFIX}"

# Rétention : les dossiers de sauvegarde plus anciens que KEEP_DAYS jours (jamais d'autres fichiers).
find "$BACKUP_DIR" -maxdepth 1 -type d -name 'eeo-backup-*' -mtime +"$KEEP_DAYS" -exec rm -rf {} +

SIZE="$(du -sh "$TARGET" | cut -f1)"
echo "[$(date -u +%Y-%m-%dT%H:%M:%SZ)] Sauvegarde terminée : ${TARGET} (${SIZE}). Pensez à la copie hors serveur."
