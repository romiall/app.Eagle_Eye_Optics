# Mise en service progressive des modules (M01)

Décision du demandeur (2026-10-22) : un module terminé n'est visible de la cliente que lorsque le
demandeur l'ouvre. M01 n'est pas une tâche du backlog (qui n'est ni modifié ni régénéré) : c'est un
interrupteur de déploiement, consigné dans `progress.md` (« Écarts de planning »).

## Principe

| Niveau | Rôle | Où |
| --- | --- | --- |
| `delivered` (code) | L'écran existe et ses tests passent | `resources/frontend/app/navigation.ts` |
| `EEO_ENABLED_MODULES` (déploiement) | L'exploitant l'ouvre aux utilisateurs | `.env` du serveur, `config/eeo.php` |
| Permissions | Le compte a le droit de s'en servir | rôles et `permissions` (F05) |

Un module n'apparaît (menu, actions d'accueil, route) que s'il est livré, ouvert **et** autorisé. La
liste appartient au déploiement : la gérante ne peut ni la lire pour la modifier ni la changer
depuis les paramètres du cabinet.

## Codes

`today`, `patients`, `consultations`, `agenda`, `sales`, `stock`, `workshop`, `communication`,
`reports`, `settings` : les identifiants des modules de la navigation (fichier 04), dans cet ordre.
`app/Support/Modules.php` en est la liste côté serveur ; un test compare les deux listes. Une valeur
inconnue est ignorée : une faute de frappe laisse un module caché, jamais ouvert.

```
EEO_ENABLED_MODULES=patients,consultations
```

Vide (valeur par défaut) : aucun module métier n'est exposé. Les routes d'accès existantes
(connexion, comptes, journal, paramètres du cabinet) ne dépendent pas de cette liste.

## Serveur

- `GET /api/v1/auth/me` rend `data.clinic.enabled_modules` (liste ordonnée des modules ouverts).
- Middleware `module:<code>` (`RequireEnabledModule`) sur les routes d'un module : tant qu'il est
  fermé, la route répond **404 `RESOURCE_NOT_FOUND`**, comme si elle n'existait pas. À poser sur
  chaque route de module (par exemple `Route::middleware('module:patients')`).
- Reste à faire quand le chemin de synchronisation existera (O05) : refuser de la même façon les
  commandes d'un module fermé reçues d'un poste.

## Interface

- `session.enabledModules` (lu dans `/auth/me`) alimente `visibleModules` et `visibleHomeActions`.
- Une route déclare `meta.module` : fermée, la page répond « page introuvable » (la même que
  l'URL inconnue). Sans session connectée, aucun module n'est ouvert.
- Une action d'accueil dépend du module de son champ `module` (à défaut, de son identifiant).

## Procédure d'ouverture d'un module

1. La tâche qui livre l'écran passe `delivered` à `true`, ajoute sa route avec `meta.module` et ses
   routes serveur avec `module:<code>`, et ses tests.
2. Le demandeur ajoute le code à `EEO_ENABLED_MODULES` sur le serveur (ou, en développement, dans
   `.env`) et recharge la configuration (`php artisan config:clear` si elle est en cache).
3. Retour arrière : retirer le code ; aucune donnée n'est touchée.

## Tests

`tests/Unit/Support/ModulesTest.php`, `tests/Feature/Http/ModuleGateTest.php`,
`tests/frontend/shell/navigation.test.ts` (filtrage et cohérence avec `Modules.php`),
`tests/frontend/shell/module-gate.test.ts` (menu, actions d'accueil, route introuvable).

## État de la phase 1 (codé, vérification de fin de phase à faire)

| Code | Écrans livrés (`delivered: true`) | Serveur |
| --- | --- | --- |
| `today` | File du jour, arrivée | `module:today` |
| `patients` | Patients, fiche, activité | `module:patients` |
| `consultations` | Liste des examens (`/consultations`), consultation, révisions, prescriptions | `module:consultations` (dont `GET /consultations`) |
| `sales` | Ventes et caisse, factures, catalogue, taxes, aperçu des documents | `module:sales` |
| `stock` | Stock (disponibilités, stock initial) — entrée de menu non livrée (I01) | `module:stock` |
| `settings` | Paramètres (personnel, cabinet et documents) | aucune route de module : les routes de comptes et du cabinet (F05) restent ouvertes à la gérante même module fermé ; seul l'écran dépend de `settings` |

L'accueil affiche, sous les actions du dossier patient (fichier 04), des **raccourcis de travail** par activité
(`WORK_SHORTCUTS` de `navigation.ts` : accueil des patients, examens, caisse et ventes, administration), soumis aux
mêmes règles (livré, ouvert, autorisé). `eeo:preflight` signale un code inconnu dans `EEO_ENABLED_MODULES`, le module
`settings` fermé alors que d'autres sont ouverts, une caisse ou une échelle d'acuité manquante pour un module ouvert,
et un contrôle du paiement de l'examen incohérent (bloquant).

Ordre d'ouverture conseillé et prérequis : `deploiement.md`, étape 6.
