# Identité, droits et session du personnel (F05)

Ce document décrit ce qui est implémenté et testé pour l'accès du personnel. Les exigences viennent des fichiers 09 (sécurité) et 23 (identité et droits). Les écrans viendront avec F09. Le coffre local et les grants hors-ligne relèvent de O01 et O02.

## Comptes, profils et permissions

| Élément | Règle appliquée |
| --- | --- |
| Compte | Table `users` du catalogue : id ULID, `login` (minuscules, `[a-z0-9._-]`, unique par cabinet), `display_name`, `password_hash` (bcrypt), `active`, `version`. Un compte n'est jamais supprimé : il est désactivé. Pas de `remember_token` ni de « rester connecté ». |
| Affectation de masse | Seuls `display_name` et `email` sont affectables en masse. `clinic_id`, `password_hash`, `active` et `version` ne sont écrits que par les actions du domaine Accès (test `ConfigurationContractTest`). |
| Profils | Les quatre profils des sources (`MANAGER`, `RECEPTION`, `CLINICIAN`, `CASHIER`), dans `user_roles`. Une révocation est datée et la ligne conservée ; une ré-attribution réactive la ligne, et l'historique complet reste dans l'audit. |
| Permissions | Les 49 codes seedés (`permissions`, `role_permissions`) font autorité. Aucun paquet de rôles ni référentiel parallèle. |
| Permissions effectives | Règle pure `App\Domain\Access\PermissionResolver` : compte désactivé = aucune permission ; profils attribués et non révoqués ; dérogations nominatives non expirées (`user_permissions`), où un refus l'emporte sur le profil et une autorisation s'ajoute. Recalculées à chaque requête : désactivation, révocation et dérogation s'appliquent immédiatement. |
| Contrôle | Chaque code est une capacité Gate (`can:users.manage`, `$user->can('payment.collect')`). Les Policies de ressource (patients, pièces, PDF) s'appuieront sur ces codes et sur le filtre de cabinet. |
| Cabinet | Décidé côté serveur : `EEO_CLINIC_ID`, sinon l'unique cabinet de la base, sinon refus 503 `CLINIC_NOT_CONFIGURED`. Le navigateur ne choisit jamais un `clinic_id`. Toutes les requêtes filtrent le cabinet du compte connecté : un identifiant d'un autre cabinet répond 404. |

## Session SPA (Sanctum)

- **Session par cookie du seul sous-domaine**, jamais de jeton permanent dans la PWA. `SANCTUM_STATEFUL_DOMAINS` doit contenir exactement l'origine de l'application (par exemple `app.eagleeyeoptic.com`). Sans session, la connexion est refusée explicitement (400 `SESSION_REQUIRED`) au lieu de « réussir » sans effet.
- **Cookie** `Secure`, `HttpOnly` et `SameSite=Lax`. Durée d'inactivité de 30 minutes (`SESSION_LIFETIME`). Sessions en base (`fw_sessions`, user_id ULID).
- **CSRF** : la SPA appelle `GET /api/sanctum/csrf-cookie`, puis envoie `X-XSRF-TOKEN` sur chaque écriture. Laravel 13 accepte aussi une écriture marquée `Sec-Fetch-Site: same-origin` (Fetch Metadata, en-tête qu'une page ne peut pas forger). Une écriture intersite, ou sans Fetch Metadata et sans jeton, reçoit 419 `SESSION_CONTEXT_EXPIRED` (preuve e2e). Une origine tierce n'obtient jamais de session.
- **Connexion** : recherche dans le seul cabinet configuré. La réponse est identique pour un identifiant inconnu, un mot de passe faux ou un compte désactivé (même coût de hash). Au-delà de 5 échecs par minute pour un même identifiant et une même adresse, 429 `RATE_LIMITED` ; s'y ajoutent 20 connexions par minute par adresse. La session est régénérée à la connexion.
- **Déconnexion** : la session serveur est invalidée et le jeton CSRF renouvelé. Le verrouillage du coffre local sans perte des pièces relève de O01.
- **Statut relu à chaque requête** (`EnsureUserIsActive`) : un compte désactivé perd sa session même si son cookie existe encore. La désactivation et la réinitialisation par la gérante suppriment aussi toutes les lignes `fw_sessions` du compte. Un changement de mot de passe ferme les autres sessions, ce que renforce `AuthenticateSession` de Sanctum.
- **Réauthentification** (`POST /api/v1/auth/reauthenticate`) : elle est valable 15 minutes (`EEO_REAUTHENTICATION_TTL`) pour la gestion des comptes, les paramètres du cabinet et, plus tard, les approbations et exports globaux. Sans elle, 423 `REAUTHENTICATION_REQUIRED`. Une nouvelle connexion l'annule.

## Routes de F05 (`/api/v1`)

| Route | Accès | Notes |
| --- | --- | --- |
| `POST auth/login` | Public, session SPA | Corps `{login, password}` ; réponse : compte, profils, permissions, cabinet |
| `POST auth/logout`, `GET auth/me` | Connecté | `me` est toujours `no-store` |
| `POST auth/reauthenticate` | Connecté | 5 essais par minute |
| `POST auth/password` | Connecté | `{current_password, password, password_confirmation}` ; version incrémentée |
| `GET clinic/settings` | Connecté | Lecture des paramètres administratifs |
| `PATCH clinic/settings` | `users.manage` + réauthentification | Le fuseau, la monnaie et `production_documents_enabled` sont interdits par l'API (422) |
| `GET staff`, `GET staff/{id}` | `users.manage` | Paginé (50) ; filtré par cabinet |
| `POST staff` | `users.manage` + réauthentification | `{login, display_name, email?, password, roles[]}` |
| `PATCH staff/{id}` | `users.manage` + réauthentification | `{expected_version, display_name?, email?, active?, roles?, reason?}` ; motif obligatoire pour désactiver ou retirer un profil |
| `POST staff/{id}/permissions` | `users.manage` + réauthentification | `{permission_code, effect: ALLOW/DENY/CLEAR, reason, expires_at?}` |
| `POST staff/{id}/password` | `users.manage` + réauthentification | Réinitialisation par la gérante : `{password, password_confirmation, reason}` ; ferme les sessions du compte |

Garde-fous métier :
- le cabinet garde toujours une gérante active (409 `LAST_MANAGER_REQUIRED`) ;
- une gérante ne se désactive pas elle-même et ne s'applique pas de dérogation (409 `SELF_DEACTIVATION_FORBIDDEN`, `SELF_OVERRIDE_FORBIDDEN`) ;
- elle ne réinitialise pas son propre mot de passe par cette route (409 `SELF_RESET_FORBIDDEN`) ;
- une version obsolète est refusée (409 `VERSION_CONFLICT`).

L'administration des comptes est sérialisée par le verrou de la ligne du cabinet, premier verrou de l'ordre du contrat 23.

## Enveloppe d'erreur (fichier 08)

```json
{ "error": { "code": "VALIDATION_FAILED", "message": "…", "fields": { "login": ["…"] } }, "correlation_id": "01J…" }
```

Les codes en usage sont : `AUTH_REQUIRED` 401, `SESSION_CONTEXT_EXPIRED` 419, `ACTION_FORBIDDEN` 403, `RESOURCE_NOT_FOUND` 404, `VERSION_CONFLICT` 409, `VALIDATION_FAILED` 422, `RATE_LIMITED` 429, `INTERNAL_ERROR` 500, auxquels s'ajoutent les codes propres à F05 ci-dessus. L'identifiant de corrélation est repris de `X-Correlation-Id` s'il est sûr, sinon généré. Il figure dans la réponse, dans l'en-tête, dans les journaux et dans l'audit. Les codes ajoutés par F05 (`REAUTHENTICATION_REQUIRED`, `SESSION_REQUIRED`, `CLINIC_NOT_CONFIGURED`, `LAST_MANAGER_REQUIRED`, `SELF_*`) sont à reporter dans les contrats OpenAPI de F06.

## Mots de passe

- Au moins 12 caractères, avec lettres et chiffres, au plus 72 octets (limite de bcrypt), et différent de l'identifiant.
- Aucune vérification par un service externe, donc aucun appel réseau.
- **Aucun mot de passe universel ni par défaut** dans l'application : test `test_aucun_mot_de_passe_universel_n_ouvre_un_compte`.
- La réinitialisation est faite par la gérante et le mot de passe transmis en personne. Il n'y a pas de réinitialisation par e-mail, faute de fournisseur actif avant L2/L3 ; la table `fw_password_reset_tokens` n'est donc pas créée.
- Le catalogue n'a pas de colonne pour forcer un changement à la première connexion : la procédure est organisationnelle, et le compte change son mot de passe par `POST auth/password`.

## Mise en service et démonstration

- `php artisan eeo:setup-clinic` crée le cabinet, puis la première gérante, puis son profil MANAGER (`assigned_by` = ce compte désormais existant).
  - Il ne s'exécute qu'une fois (`CLINIC_ALREADY_SET_UP` ensuite).
  - Le mot de passe est saisi deux fois de façon masquée, ou lu sur l'entrée standard (`--password-stdin`), jamais en argument.
  - Sur un hébergement sans SSH, la procédure locale sécurisée est à définir avec H01 (contrat 23).
- `php artisan eeo:demo:accounts [--output=fichier] [--reset-passwords]` crée quatre comptes fictifs (`demo.gerante`, `demo.accueil`, `demo.professionnel`, `demo.caisse`).
  - Le cabinet de démonstration est marqué « DONNÉES FICTIVES ».
  - Les mots de passe sont aléatoires, affichés une seule fois ou écrits en mode 0600.
  - **La commande est refusée en production.** Les e2e l'utilisent via `tests/e2e/prepare-env.mjs`.

## Audit

Actions journalisées dans `audit_events`, append-only :
- `auth.login`, `auth.login_failed`, `auth.login_refused_inactive` ;
- `auth.logout`, `auth.reauthenticated`, `auth.reauthentication_failed`, `auth.password_changed` ;
- `staff.created`, `staff.updated`, `staff.deactivated`, `staff.permission_override`, `staff.password_reset` ;
- `clinic.setup`, `clinic.settings_updated`.

Chaque événement porte l'auteur, le nom à la date, l'instant UTC, la cible, le motif, la corrélation, un diff nettoyé et le SHA-256 du contenu. Les mots de passe et hashes sont refusés dans un diff ; l'identifiant tenté lors d'un échec n'est conservé que haché. La chaîne `previous_hash` (facultative, fichier 09) n'est pas tenue : décision à prendre au chemin central F07, sous le verrou du cabinet.

## Preuves

- `tests/Unit/Access/PermissionResolverTest.php` : 7 cas.
- `tests/Feature/Access/AuthenticationTest.php` : connexion, échecs identiques, 429, autre cabinet, cabinet non configuré, 401, déconnexion, réauthentification et son expiration, mot de passe, absence de mot de passe universel, connexion sans session.
- `tests/Feature/Access/StaffManagementTest.php` : quatre profils et leurs permissions, refus des non-gérantes, création, validations, version, désactivation qui coupe les sessions, dernière gérante, profils et dérogations immédiats et expirés, réinitialisation, isolement du cabinet, paramètres.
- `tests/Feature/Access/SetupAndDemoCommandsTest.php` : mise en service unique, mots de passe faibles ou différents, comptes de démonstration aléatoires, refus en production.
- `tests/e2e/auth-session.spec.ts`, sur l'application construite avec MySQL :
  - session réelle avec CSRF, `me` `no-store` et cookie HttpOnly ;
  - 419 sans Fetch Metadata ni jeton, et en intersite ;
  - origine tierce sans session ;
  - 403 pour la caisse, 200 pour la gérante ;
  - `/api` authentifiée jamais servie hors ligne par le service worker.

## Reste à faire hors F05 (selon le graphe)

| Sujet | Tâche |
| --- | --- |
| Clés d'idempotence et chemin central des mutations de comptes | F07 (CommandBus) |
| Verrouillage du coffre local à la déconnexion ou à la désactivation, droits locaux bornés | O01, O02 |
| Refus des PDF au mauvais rôle | D01 et D02 (Policies de documents) |
| Écrans, garde de navigation et avertissement d'inactivité | F09 et F10 |
| MFA de la gérante (colonnes `mfa_*` présentes, recommandé par le fichier 09) | Non implémenté : décision à prendre avant tout accès d'administration distant |
| `trustProxies` (adresse IP réelle pour la limitation et l'audit derrière un proxy) | Avec l'audit de l'hébergeur (Q01) |
