#!/usr/bin/env bash
# Démarre (ou réutilise) le conteneur Docker MySQL 8.4.9 de développement et prépare la base isolée
# des tests : base « eeo_test », utilisateur « eeo_test » limité aux bases de test jetables.
#
# Aucun mot de passe n'est écrit dans le dépôt. Variables lues dans l'environnement :
#   EEO_MYSQL_ROOT_PASSWORD  mot de passe root du conteneur. Défaut JETABLE et explicite :
#                            « eeo-dev-root-jetable », valable seulement pour ce conteneur local lié à
#                            127.0.0.1 ; le remplacer dès que la machine est partagée.
#   EEO_TEST_DB_DATABASE     base de test (défaut eeo_test ; doit se terminer par _test)
#   EEO_TEST_DB_USERNAME     utilisateur de test (défaut eeo_test)
#   EEO_TEST_DB_PASSWORD     mot de passe fictif de test (défaut eeo_test_password)
#   EEO_MYSQL_CONTAINER      nom du conteneur (défaut eeo-mysql)
#   EEO_MYSQL_PORT           port local lié à 127.0.0.1 (défaut 3306)
#   EEO_MYSQL_VERSION        version de l'image (défaut 8.4.9, version validée du schéma)
#   EEO_START_DOCKERD=1      tente de lancer « dockerd » si le démon est arrêté (conteneur cloud, root)
#
# Image : mysql:<version>, avec repli sur le miroir mirror.gcr.io/library/mysql:<version> lorsque Docker
# Hub refuse le téléchargement (limite de débit). Une image déjà présente est réutilisée.
set -euo pipefail

CONTAINER="${EEO_MYSQL_CONTAINER:-eeo-mysql}"
VERSION="${EEO_MYSQL_VERSION:-8.4.9}"
PORT="${EEO_MYSQL_PORT:-3306}"
ROOT_PASSWORD="${EEO_MYSQL_ROOT_PASSWORD:-eeo-dev-root-jetable}"
TEST_DB="${EEO_TEST_DB_DATABASE:-eeo_test}"
TEST_USER="${EEO_TEST_DB_USERNAME:-eeo_test}"
TEST_PASSWORD="${EEO_TEST_DB_PASSWORD:-eeo_test_password}"
IMAGES=("mysql:${VERSION}" "mirror.gcr.io/library/mysql:${VERSION}")

log() { printf '[mysql-up] %s\n' "$*"; }
fail() { printf '[mysql-up] ERREUR : %s\n' "$*" >&2; exit 1; }

# Les identifiants sont insérés dans du SQL : on n'accepte qu'un jeu de caractères sûr.
[[ "$TEST_DB" =~ ^[a-z0-9][a-z0-9_]*_test$ ]] || fail "EEO_TEST_DB_DATABASE doit se terminer par _test (reçu : ${TEST_DB})."
[[ "$TEST_USER" =~ ^[a-z0-9_]+$ ]] || fail "EEO_TEST_DB_USERNAME ne doit contenir que [a-z0-9_]."
[[ "$TEST_PASSWORD" =~ ^[A-Za-z0-9_.-]+$ ]] || fail "EEO_TEST_DB_PASSWORD ne doit contenir que [A-Za-z0-9_.-]."
[[ "$ROOT_PASSWORD" =~ ^[A-Za-z0-9_.-]+$ ]] || fail "EEO_MYSQL_ROOT_PASSWORD ne doit contenir que [A-Za-z0-9_.-]."
[[ "$PORT" =~ ^[0-9]+$ ]] || fail "EEO_MYSQL_PORT doit être numérique."

command -v docker >/dev/null 2>&1 || fail "docker est introuvable."

if ! docker info >/dev/null 2>&1; then
  if [[ "${EEO_START_DOCKERD:-0}" == "1" ]] && command -v dockerd >/dev/null 2>&1; then
    DOCKERD_LOG="${TMPDIR:-/tmp}/eeo-dockerd.log"
    log "Démon Docker arrêté : lancement de dockerd (journal : ${DOCKERD_LOG})."
    (dockerd >"$DOCKERD_LOG" 2>&1 &)
    for _ in $(seq 1 60); do
      docker info >/dev/null 2>&1 && break
      sleep 1
    done
  fi
  docker info >/dev/null 2>&1 || fail "le démon Docker est arrêté. Le démarrer (ou EEO_START_DOCKERD=1 en root) puis relancer."
fi

mysql_root() {
  # Le mot de passe passe par l'environnement du processus (MYSQL_PWD), jamais par la ligne de commande.
  docker exec -i -e MYSQL_PWD="$ROOT_PASSWORD" "$CONTAINER" mysql -uroot -h127.0.0.1 "$@"
}

if docker ps -a --format '{{.Names}}' | grep -qx "$CONTAINER"; then
  if docker ps --format '{{.Names}}' | grep -qx "$CONTAINER"; then
    log "Conteneur ${CONTAINER} déjà démarré : réutilisation."
  else
    log "Conteneur ${CONTAINER} arrêté : démarrage."
    docker start "$CONTAINER" >/dev/null
  fi
else
  IMAGE=""
  for candidate in "${IMAGES[@]}"; do
    if docker image inspect "$candidate" >/dev/null 2>&1; then IMAGE="$candidate"; break; fi
  done
  if [[ -z "$IMAGE" ]]; then
    for candidate in "${IMAGES[@]}"; do
      log "Téléchargement de ${candidate}."
      if docker pull "$candidate" >/dev/null 2>&1; then IMAGE="$candidate"; break; fi
      log "Échec pour ${candidate}."
    done
  fi
  [[ -n "$IMAGE" ]] || fail "aucune image MySQL ${VERSION} disponible (Docker Hub et miroir mirror.gcr.io en échec)."

  log "Création du conteneur ${CONTAINER} (${IMAGE}) sur 127.0.0.1:${PORT}."
  export MYSQL_ROOT_PASSWORD="$ROOT_PASSWORD"
  # Pas de volume : conteneur jetable. Réglages MySQL 8.4 par défaut (mode strict, InnoDB, utf8mb4),
  # sauf log_bin_trust_function_creators=1 : sans lui, un utilisateur sans SUPER ne peut pas créer les
  # triggers de protection de l'historique alors que le journal binaire est actif.
  docker run -d --name "$CONTAINER" -p "127.0.0.1:${PORT}:3306" -e MYSQL_ROOT_PASSWORD "$IMAGE" \
    --log-bin-trust-function-creators=1 >/dev/null
  unset MYSQL_ROOT_PASSWORD
fi

log "Attente de MySQL (connexion TCP interne)."
ready=0
for _ in $(seq 1 90); do
  if output="$(mysql_root -e 'SELECT 1' 2>&1)"; then ready=1; break; fi
  if [[ "$output" == *"Access denied"* ]]; then
    fail "mot de passe root refusé : voir EEO_MYSQL_ROOT_PASSWORD (le conteneur existant a été créé avec un autre mot de passe)."
  fi
  sleep 1
done
[[ "$ready" == "1" ]] || fail "MySQL ne répond pas après 90 secondes (mot de passe root incorrect ? voir EEO_MYSQL_ROOT_PASSWORD)."

log "Préparation de la base isolée ${TEST_DB} et de l'utilisateur ${TEST_USER}."
mysql_root <<SQL
SET GLOBAL log_bin_trust_function_creators = 1;
CREATE DATABASE IF NOT EXISTS \`${TEST_DB}\` CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci;
CREATE USER IF NOT EXISTS '${TEST_USER}'@'%' IDENTIFIED BY '${TEST_PASSWORD}';
ALTER USER '${TEST_USER}'@'%' IDENTIFIED BY '${TEST_PASSWORD}';
GRANT ALL PRIVILEGES ON \`${TEST_DB}\`.* TO '${TEST_USER}'@'%';
-- Bases jetables créées par les tests eux-mêmes (ex. eeo_reset_probe_test).
GRANT ALL PRIVILEGES ON \`eeo\_%\_test\`.* TO '${TEST_USER}'@'%';
SQL

VERSION_FOUND="$(docker exec -i -e MYSQL_PWD="$TEST_PASSWORD" "$CONTAINER" mysql -u"$TEST_USER" -h127.0.0.1 "$TEST_DB" -N -B -e 'SELECT VERSION()')"
log "Connexion de test vérifiée : MySQL ${VERSION_FOUND}, base ${TEST_DB}."

BOUND="$(docker port "$CONTAINER" 3306/tcp 2>/dev/null | head -n1 || true)"
if [[ -n "$BOUND" && "$BOUND" != 127.0.0.1:* ]]; then
  log "ATTENTION : le port est publié sur ${BOUND} (pas seulement sur 127.0.0.1). Recréer le conteneur sur une machine partagée."
fi

cat <<INFO

Prêt. Les tests lisent ces variables (valeurs par défaut identiques) :
  export EEO_TEST_DB_HOST=127.0.0.1 EEO_TEST_DB_PORT=${PORT}
  export EEO_TEST_DB_DATABASE=${TEST_DB} EEO_TEST_DB_USERNAME=${TEST_USER}
  export EEO_TEST_DB_PASSWORD=<mot de passe fictif de test>
Lancer ensuite : tools/dev/check-all.sh --strict
INFO
