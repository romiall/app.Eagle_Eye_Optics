# Déploiement sur l'hébergement (Hostinger, accès SSH)

Procédure pour mettre en ligne une version de l'application sur un hébergement web simple (sans VPS) auquel on accède
par **SSH**. Elle a été **répétée de bout en bout sur ce poste** à partir de l'archive seule : extraction dans un
dossier neuf, base MySQL 8.4 vierge, diagnostic, migrations (40 s), création du cabinet et de la gérante,
vérification du schéma, démarrage, contrôle de fumée (connexion, recherche de patients, file du jour). Elle n'a
**pas encore été exécutée sur le serveur Hostinger** : l'offre exacte (version de PHP, base MySQL ou MariaDB, droits,
racine web) n'est pas connue. C'est le rôle de l'étape 1.

> Hostinger propose selon l'offre MySQL ou MariaDB. Le projet est validé sur **MySQL 8.4** (88 triggers, contraintes
> CHECK, colonnes générées). Si le diagnostic annonce MariaDB ou refuse les triggers, **ne pas installer** : voir
> « Si le diagnostic bloque ».

## Vue d'ensemble

| Étape | Où | Durée indicative |
| --- | --- | --- |
| 1. Diagnostic de l'hébergement | Serveur (SSH) | 5 minutes |
| 2. Construire l'archive | Poste de développement | 5 minutes |
| 3. Envoyer et installer l'archive | Serveur (SSH) | 10 minutes |
| 4. Configurer, installer la base, créer la gérante | Serveur (SSH) | 10 minutes |
| 5. Ouvrir le site, contrôle de fumée | Poste | 5 minutes |
| 6. Mise en service progressive des modules | Serveur (SSH) | 1 minute par module |

## 1. Diagnostic de l'hébergement (avant tout)

1. Dans le panneau Hostinger : créer le sous-domaine (par exemple `app.eagleeyeoptic.com`), activer **HTTPS**,
   choisir **PHP 8.3 ou 8.4**, créer une **base MySQL** et son utilisateur (noter nom, utilisateur, mot de passe, hôte).
2. Envoyer un petit paquet de test, ou l'archive complète (étape 3), puis sur le serveur :

```bash
cd ~/eeo            # dossier de l'application (voir l'étape 3)
php -v
php artisan eeo:preflight
```

Attention : la version de PHP **en ligne de commande (SSH)** peut différer de celle du site web choisie dans le
panneau. `php -v` doit afficher 8.3 ou plus ; sinon, utiliser le binaire de la bonne version indiqué par l'hébergeur
(par exemple `php8.3`) pour **toutes** les commandes `artisan` de cette procédure, et corriger la version du site.

`eeo:preflight` vérifie PHP, les extensions, les réglages, les droits d'écriture et surtout les **droits MySQL réels** :
il crée puis supprime des objets temporaires (table avec CHECK, clé étrangère, colonne générée, vue, **trigger**) sans
toucher aux tables de l'application. Sortie : `OK`, `ALERTE` (à lire, ne bloque pas) ou `BLOQUANT` ; code de sortie
1 s'il y a un point bloquant. `php artisan eeo:preflight --json` donne le rapport à conserver (il répond à la grille
`hosting-audit.md`).

### Si le diagnostic bloque

| Message | Cause probable | Action |
| --- | --- | --- |
| `Serveur de base … MariaDB n'est pas déclarée compatible` | L'offre fournit MariaDB | Demander à Hostinger une base **MySQL 8** (autre offre ou base distante MySQL 8.4), ou décider d'une recette complète sur MariaDB (travail à planifier) |
| `Droit TRIGGER … log_bin_trust_function_creators` | Journal binaire actif sans SUPER | Demander l'activation de `log_bin_trust_function_creators=1` ou le droit SUPER pour l'installation |
| `Droit TRIGGER` autre | Droit `TRIGGER` non accordé à l'utilisateur de la base | Accorder `ALL PRIVILEGES` sur la base à l'utilisateur de l'application |
| `Contraintes CHECK appliquées` | Serveur qui ignore les CHECK (MySQL avant 8.0.16) | Changer de serveur de base |
| `PHP … 8.3 au minimum` | Version de PHP trop basse | Choisir PHP 8.3 ou 8.4 dans le panneau |
| `Extensions PHP requises … manquantes` | Extension désactivée | L'activer dans le panneau (au minimum `intl`, `pdo_mysql`, `mbstring`, `xml`, `openssl`) |
| `Racine web … contient` | La racine du site n'est pas `public/` | Voir l'étape 3 |

Les alertes (`expose_php`, OPcache, taille d'envoi) se règlent dans le panneau (PHP, Options) ou un fichier
`.user.ini`.

## 2. Construire l'archive (poste de développement)

La livraison ne contient ni code de test, ni spécifications, ni dépendances de développement, ni `.env`.

```bash
git status                          # l'arbre de travail doit être propre (tout validé)
bash tools/deploy/build-release.sh  # npm run build + composer install --no-dev ; 5 minutes sur ce poste
```

Résultat dans `release/` : `eeo-AAAAMMJJTHHMMZ-<commit>.tar.gz` et son empreinte `.sha256`. Elle contient `vendor/`
(donc Composer n'est pas exigé sur le serveur) et `public/build/`.

## 3. Envoyer et installer l'archive (SSH)

Depuis le poste (adapter l'utilisateur, l'hôte et le port donnés par Hostinger) :

```bash
scp -P <port> release/eeo-*.tar.gz release/eeo-*.sha256 <utilisateur>@<hôte>:~/
```

Sur le serveur :

```bash
cd ~
sha256sum -c eeo-*.sha256                       # doit afficher « OK »
mkdir -p ~/eeo-releases/<nom-de-l-archive>
tar -xzf eeo-*.tar.gz -C ~/eeo-releases/<nom-de-l-archive>
ln -sfn ~/eeo-releases/<nom-de-l-archive> ~/eeo   # « eeo » pointe vers la version en service
```

**Racine web.** Le site doit être servi depuis `~/eeo/public` et **rien d'autre** : ni `.env`, ni `vendor/`, ni
`storage/` ne doivent être accessibles par HTTP. Sur Hostinger :

- si le panneau permet de choisir le **dossier racine du sous-domaine**, le pointer sur `~/eeo/public` ;
- sinon, remplacer le dossier du sous-domaine (`public_html` du sous-domaine) par un **lien symbolique** vers
  `~/eeo/public` (`ln -sfn ~/eeo/public <dossier du sous-domaine>`), si l'offre l'autorise ;
- à défaut : demander au support la procédure pour un projet Laravel. **Ne jamais** copier `public/` à côté du code en
  laissant le reste dans la racine web.

Droits d'écriture pour le serveur web :

```bash
cd ~/eeo
chmod -R u+rwX storage bootstrap/cache
```

## 4. Configurer, installer la base, créer la gérante

```bash
cd ~/eeo
cp .env.example .env
nano .env      # ou vi : renseigner les valeurs ci-dessous
php artisan key:generate --force
```

Valeurs à renseigner dans `.env` (aucune valeur d'exemple ne doit rester) :

| Variable | Valeur |
| --- | --- |
| `APP_ENV` | `production` |
| `APP_DEBUG` | `false` |
| `APP_URL` | `https://app.eagleeyeoptic.com` (le vrai sous-domaine, en HTTPS) |
| `SANCTUM_STATEFUL_DOMAINS` | exactement l'hôte : `app.eagleeyeoptic.com` |
| `SESSION_SECURE_COOKIE` | `true` |
| `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD` | ceux du panneau Hostinger |
| `PRODUCTION_DOCUMENTS_ENABLED` | `false` (documents marqués TEST tant que mentions légales, logo et fiscalité ne sont pas validés) |
| `EEO_ENABLED_MODULES` | vide au départ ; voir l'étape 6 |

Puis :

```bash
chmod 600 .env
php artisan eeo:preflight                 # doit conclure « Aucun point bloquant »
php artisan migrate --force               # crée les tables, les vues et les triggers (≈ 40 s à 3 minutes)
php artisan eagle:verify-schema           # « Schéma équivalent à la baseline : aucun écart »
php artisan eeo:setup-clinic --clinic-name "EAGLE'S EYE OPTICS MEDICAL" --manager-name "<Nom de la gérante>" --login <identifiant>
                                          # demande le mot de passe (12 caractères minimum) ; aucun mot de passe par défaut
php artisan config:cache && php artisan route:cache && php artisan view:cache
```

`eeo:setup-clinic` se lance **une seule fois**. Le mot de passe de la gérante est choisi à ce moment et conservé
hors du serveur. Le cabinet est ensuite identifié par la base (si plusieurs cabinets existaient un jour, renseigner
`EEO_CLINIC_ID`).

## 5. Ouvrir le site et contrôle de fumée

Depuis le poste (jamais depuis un site tiers) :

```bash
EEO_SMOKE_PASSWORD='<mot de passe de la gérante>' bash tools/deploy/smoke.sh https://app.eagleeyeoptic.com <identifiant>
```

Le contrôle est **en lecture seule**. Il vérifie : `/up`, l'API (404 JSON), les fichiers du build, le service worker,
que `.env`, `.git`, `composer.json`, `artisan` et les journaux **ne sont pas servis**, la connexion réelle, la lecture
du compte, la recherche de patients et la file du jour (ou « module fermé »). Il doit se terminer par
« Contrôle de fumée réussi. ». Puis ouvrir `https://app.eagleeyeoptic.com` dans un navigateur : écran de connexion,
connexion avec la gérante, accueil.

## 6. Mise en service progressive des modules

Un module livré reste invisible (écran **et** API) tant qu'il n'est pas ouvert. Pour ouvrir les premières fonctions :

```bash
cd ~/eeo
nano .env             # EEO_ENABLED_MODULES=patients,today
php artisan config:cache
```

| Code | Fonction ouverte | Disponible dans cette version |
| --- | --- | --- |
| `patients` | Fiches patient, recherche | Oui (en ligne) |
| `today` | Arrivée du patient, file du jour | Oui (en ligne) |
| `consultations`, `sales`, `stock`, `agenda`, `reports`, `settings`, `workshop`, `communication` | — | Pas encore : les ouvrir n'affiche rien |

Retour en arrière : retirer le code de la liste et relancer `php artisan config:cache`. Aucune donnée n'est touchée.

## Mise à jour vers une nouvelle version

```bash
# Poste : construire la nouvelle archive (étape 2) et l'envoyer (étape 3).
# Serveur :
cd ~ && mkdir -p ~/eeo-releases/<nouvelle-archive> && tar -xzf eeo-<nouvelle>.tar.gz -C ~/eeo-releases/<nouvelle-archive>
cp ~/eeo/.env ~/eeo-releases/<nouvelle-archive>/.env
cd ~/eeo-releases/<nouvelle-archive> && chmod -R u+rwX storage bootstrap/cache
php artisan eeo:preflight
php artisan down                         # facultatif : maintenance de quelques minutes
php artisan migrate --force && php artisan eagle:verify-schema
php artisan config:cache && php artisan route:cache && php artisan view:cache
ln -sfn ~/eeo-releases/<nouvelle-archive> ~/eeo
php artisan up
```

Retour à la version précédente : `ln -sfn ~/eeo-releases/<ancienne-archive> ~/eeo`. Les migrations ne sont pas
destructives ; ne jamais restaurer une ancienne base sans sauvegarde récente (voir ci-dessous).

## Sauvegardes (obligatoire avant la mise en service)

- Activer et vérifier les **sauvegardes automatiques** de l'hébergeur (base et fichiers), et conserver au moins une
  copie hors du serveur.
- Avant chaque mise à jour : `mysqldump --single-transaction --routines --triggers -u <utilisateur> -p <base> > sauvegarde-AAAAMMJJ.sql`
  (les triggers font partie de la protection de l'historique).
- Tester la restauration sur une base jetable avant la mise en service : une sauvegarde jamais restaurée n'est pas
  une sauvegarde (X03).

## Limites de cette version

- **Rien ne fonctionne hors ligne** : le mode hors ligne (coffre local, numéros pré-alloués, synchronisation) arrive
  ensuite. Une coupure d'Internet interrompt le travail.
- Pas de consultation, de prescription, de facture ni de paiement : les modules correspondants ne sont pas ouverts.
- Le **contrôle « examen payé ou dérogation » n'existe pas** : l'examen n'est pas bloqué faute de règlement. L'écran le
  dit (« Paiement de l'examen non contrôlé »).
- Les icônes de l'application sont provisoires ; le logo, les mentions légales et le régime fiscal ne sont pas
  fournis (documents marqués TEST).
- Aucune tâche planifiée (cron) n'est encore nécessaire.
- Sécurité : HTTPS obligatoire, mot de passe de la gérante fort et conservé hors serveur, `.env` en `chmod 600`,
  racine web = `public/` seul.
