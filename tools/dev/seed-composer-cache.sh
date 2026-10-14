#!/usr/bin/env bash
# Pré-remplit le cache de Composer avec les archives « dist-only » de GitHub qu'un poste restreint refuse
# (HTTP 403 sur https://api.github.com/repos/<propriétaire>/<dépôt>/zipball/<commit>).
#
# Paquet concerné : phpstan/phpstan (analyse statique, voir testing.md). composer.lock ne lui connaît
# qu'une archive « dist » et aucun « source » git : quand l'API GitHub est refusée, « composer install »
# ne peut pas retomber sur un clone et échoue avec « Could not authenticate against github.com ».
#
# Le script reconstruit l'archive depuis le dépôt git public, au commit EXACT du verrou (échec si le
# commit récupéré diffère), puis la dépose dans le cache de Composer sous la clé que Composer calcule
# lui-même (sha1 de l'URL dist, Composer 2.x). « composer install » la consomme ensuite sans appeler
# l'API. Le contenu est celui de « git archive », identique au zipball de GitHub ; aucun code récupéré
# n'est exécuté.
#
#   tools/dev/seed-composer-cache.sh && composer install
#
# Inutile sur un poste ou une CI ordinaire : la CI GitHub accède à l'API sans restriction.
set -euo pipefail

cd "$(dirname "${BASH_SOURCE[0]}")/../.."

for command in composer git php; do
  command -v "$command" >/dev/null 2>&1 || { echo "ERREUR : « ${command} » est introuvable." >&2; exit 2; }
done
[[ -f composer.lock ]] || { echo "ERREUR : composer.lock est introuvable." >&2; exit 2; }

CACHE_DIR="$(composer config cache-files-dir)"
WORK_DIR="$(mktemp -d "${TMPDIR:-/tmp}/eeo-composer-seed.XXXXXX")"
trap 'rm -rf "$WORK_DIR"' EXIT

# Paquets dont le verrou n'a pas de « source » et dont l'archive vient de l'API GitHub :
# nom, URL dist, commit. Un paquet par ligne, champs séparés par une tabulation.
PACKAGES="$(php -r '
  $lock = json_decode(file_get_contents("composer.lock"), true, 512, JSON_THROW_ON_ERROR);
  foreach (array_merge($lock["packages"], $lock["packages-dev"] ?? []) as $package) {
    $dist = $package["dist"] ?? null;
    if (isset($package["source"]) || ! is_array($dist) || ($dist["type"] ?? "") !== "zip") continue;
    if (preg_match("#^https://api\.github\.com/repos/[^/]+/[^/]+/zipball/[0-9a-f]{40}$#", $dist["url"] ?? "") !== 1) continue;
    echo $package["name"], "\t", $dist["url"], "\t", $dist["reference"], "\n";
  }
')"

if [[ -z "$PACKAGES" ]]; then
  echo "Aucun paquet dist-only à préparer."
  exit 0
fi

while IFS=$'\t' read -r NAME URL REFERENCE; do
  KEY="$(php -r 'echo sha1($argv[1]);' "$URL")"
  TARGET="${CACHE_DIR}/${NAME}/${KEY}.zip"

  if [[ -s "$TARGET" ]]; then
    echo "${NAME} : déjà présent dans le cache de Composer."
    continue
  fi

  REPOSITORY="$(sed -E 's#^https://api\.github\.com/repos/([^/]+/[^/]+)/zipball/.*#https://github.com/\1.git#' <<<"$URL")"
  CLONE="${WORK_DIR}/${NAME//\//-}"

  echo "${NAME} : récupération du commit ${REFERENCE:0:12} depuis ${REPOSITORY}."
  git init -q "$CLONE"
  git -C "$CLONE" remote add origin "$REPOSITORY"
  git -C "$CLONE" fetch -q --depth 1 origin "$REFERENCE"

  FETCHED="$(git -C "$CLONE" rev-parse FETCH_HEAD)"
  if [[ "$FETCHED" != "$REFERENCE" ]]; then
    echo "ERREUR : ${NAME} : commit récupéré ${FETCHED}, attendu ${REFERENCE} (verrou). Cache non modifié." >&2
    exit 1
  fi

  mkdir -p "$(dirname "$TARGET")"
  ARCHIVE="${WORK_DIR}/${NAME//\//-}.zip"
  git -C "$CLONE" archive --format=zip --prefix="${NAME//\//-}-${REFERENCE:0:7}/" -o "$ARCHIVE" FETCH_HEAD
  mv "$ARCHIVE" "$TARGET"
  echo "${NAME} : archive déposée dans ${TARGET}."
done <<<"$PACKAGES"

echo "Terminé. Lancer maintenant : composer install"
