# Coquille responsive et composants réutilisés (F09)

Tâche F09 du backlog (L0, prérequis F02 ; regroupement L0-14). Sorties attendues : coquille
accessible et agréable, **aucun menu de module futur présenté comme livré**, composants utilisables
dans les écrans patient et caisse. Validation : contrastes, focus, clavier et largeurs d'écran
(fichier 04, AT28, AT29, EE044).

## Fichiers

| Domaine | Fichiers |
| --- | --- |
| Palette et règles de base | `resources/frontend/styles/tokens.css`, `resources/frontend/styles/base.css`, importés par `resources/frontend/app/styles.css` |
| Coquille | `resources/frontend/app/App.vue`, `router.ts`, `bootstrap.ts`, `navigation.ts`, `session.ts`, `api.ts`, `network.ts`, `unsavedChanges.ts` |
| Écrans de la coquille | `resources/frontend/pages/{LoginPage,HomePage,ForbiddenPage,NotFoundPage}.vue` |
| Composants | `resources/frontend/components/ui/AppButton.vue`, `form/{FieldShell,TextField,DecimalField,MoneyField,DateField,SelectField,ComboboxField}.vue`, `form/{classes,format}.ts`, `feedback/{ErrorSummary,ConfirmDialog,SaveStatus,StateMessage,NetworkStatus}.vue`, `clinical/OpticalTable.vue`, `finance/AmountSummary.vue` |
| Tests | `tests/frontend/bootstrap.test.ts`, `tests/frontend/shell/*.test.ts` (serveur simulé `server.ts`), `tests/frontend/components/*.test.ts`, `tests/e2e/shell.spec.ts`, `tests/e2e/support/accounts.ts` |

## Navigation : seulement ce qui est livré et autorisé

`navigation.ts` déclare les dix modules du fichier 04 (Aujourd'hui, Patients, Consultations, Agenda,
Ventes et caisse, Stock, Atelier, Communication, Rapports, Paramètres) et les six actions d'accueil
du modèle de dossier (Nouveau patient, Rechercher, Rendez-vous, Consultations du jour, Statistiques,
Paramètres). Chaque entrée porte :

- `permissions` : codes du référentiel seedé, l'un suffit. Le serveur revérifie chaque action ;
- `deliveredBy` : la tâche du backlog qui livre l'écran ;
- `delivered` : `false` pour toutes les entrées dans cette version.

Une entrée n'apparaît que si elle est livrée **et** autorisée. Sans aucune entrée visible, aucune
navigation n'est affichée et l'accueil le dit (« Aucun module métier livré pour l'instant »). Si des
écrans sont livrés mais qu'aucun n'est ouvert au compte, l'accueil dit « Aucune action ouverte à ce
compte » : il ne parle pas de livraison.

**Livrer un écran**, c'est passer son entrée à `delivered: true` dans la même modification que la
route, l'écran et ses tests, puis mettre à jour `navigation.test.ts`. Ce test vérifie que :

- chaque `deliveredBy` est une tâche de `backlog.json` ;
- chaque permission existe dans `database/02_reference_data.sql` ;
- l'ordre suit le fichier 04 ;
- aucune entrée n'est livrée aujourd'hui.

Les chemins des entrées non livrées sont indicatifs : la tâche qui livre l'écran fixe le sien.

Sur ordinateur (≥ 768 px), la barre latérale liste l'accueil puis les modules. Sur téléphone, la barre
du bas montre les raccourcis Aujourd'hui, Patients et Agenda, puis « Plus » pour les autres modules.
« Plus » est une liste dépliante native (`details`/`summary`, clavier et lecteurs d'écran natifs).
Elle se referme à l'ouverture d'un écran, ou avec Échap qui rend le focus à « Plus » ; sa liste est
bornée en hauteur et défile (téléphone en paysage, zoom 200 %). Une fois connecté, le titre de
l'en-tête est un lien vers l'accueil, sur toutes les largeurs. Tant qu'aucun écran n'est livré, la
navigation n'existe que dans les tests jsdom (catalogue fictif) : sa vérification dans un vrai
navigateur arrive avec le premier écran livré (P01, P02).

## Coquille

- **En-tête** : « Eagle’s Eye Optics Medical – Gestion des patients ». Le texte est écrit en casse
  normale et affiché en capitales par `uppercase`, pour qu'un lecteur d'écran le lise comme des mots.
  L'en-tête montre aussi l'état réseau, le compte connecté et « Se déconnecter ». La recherche rapide
  arrive avec la recherche de patients (P02) et l'état de synchronisation avec le journal du poste
  (F10, O04, O05) : ils ne sont pas simulés avant d'exister.
- **État réseau** (`NetworkStatus`) : « Hors ligne » quand le navigateur n'a plus de réseau. «
  Serveur injoignable » quand l'application n'a pas répondu : aucune réponse, ou une page d'erreur
  HTML de l'hébergeur ; une réponse JSON de l'application, même une erreur, prouve qu'elle répond.
  Sinon « En ligne ». La joignabilité est vérifiée par requête (`/up`, jamais par
  `navigator.onLine` seul) : toutes les 30 secondes tant que le serveur ne répond pas, toutes les 60
  secondes sinon, et au retour du réseau. Chaque état a un libellé et un symbole, et il est annoncé
  poliment. « En ligne » ne dit rien des pièces locales.
- **Session** (`session.ts`, session Sanctum du fichier 23) : `unknown`, `anonymous`,
  `authenticated` ou `unverified`. `unverified` veut dire que la session n'a pas pu être vérifiée :
  le serveur est muet ou répond par une erreur (5xx, maintenance). Ce n'est jamais pris pour une
  déconnexion. L'accueil dit lequel des deux cas se produit (« Serveur injoignable » ou « Session
  non vérifiée »). La coquille relit la session au retour du réseau, puis toutes les 30 secondes.
- **Garde du routeur** : sans session, la connexion s'ouvre avec `suite` = le chemin demandé, sans
  ses paramètres de requête (aucun nom, téléphone ni filtre dans l'URL). Ce chemin peut contenir un
  identifiant opaque (ULID d'une facture), jamais une donnée personnelle ; après la connexion, seul
  un chemin interne est suivi. Une page protégée par des permissions ne
  s'ouvre que si la session est vérifiée : sinon l'accueil explique l'état. Une page dont le compte
  n'a pas la permission mène à « Accès refusé ». Pour un compte connecté, une adresse inconnue mène
  à « Page introuvable » ; sans session, à la connexion. Aucune URL métier non livrée n'ouvre
  d'écran vide.
- **Déconnexion** : l'écran en cours est d'abord quitté ; si sa garde de saisie est refusée, rien
  n'est fermé. Puis la connexion ne s'affiche qu'une fois la session fermée par le serveur. Sans
  réponse, ou sur une erreur, le compte reste affiché avec le message « Déconnexion impossible […]
  La session reste ouverte sur ce poste ». Sur un poste partagé, la personne suivante ne doit pas
  hériter d'une session que l'écran prétendrait fermée. Une session déjà expirée (401) est
  considérée fermée ; un jeton CSRF périmé (419) est renouvelé une fois.
- **Connexion** : vrais libellés, autocomplétion `username` et `current-password`, champs vides
  signalés sans appel au serveur. Un refus du serveur (422 sur `login`) s'affiche sous le champ et
  dans le résumé focalisé, et le mot de passe est à ressaisir. « Trop de tentatives » (429) et «
  Serveur injoignable » sont dits tels quels. Après la connexion, seul un chemin interne est suivi,
  jamais `//autre-origine`. Le déverrouillage hors-ligne d'un poste préparé appartient à O01 et O02.
- **Accessibilité de la coquille** : lien « Aller au contenu » en premier arrêt du clavier. Après
  chaque navigation, sauf au premier affichage, le focus va au contenu principal pour que le lecteur
  d'écran annonce le nouvel écran. L'onglet prend le titre de l'écran (« Accueil – Eagle’s Eye
  Optics Medical »). Le défilement remonte en haut de l'écran. Le focus clavier est toujours visible
  (contour de 3 px doublé de blanc) et aucun utilitaire ne peut le retirer.

## Composants pour les écrans patient et caisse

| Composant | Usage et garanties |
| --- | --- |
| `AppButton` | Libellé qui dit la conséquence ; cible ≥ 44 px ; pendant la commande : désactivé, `aria-busy`, libellé « … en cours » (l'idempotence reste la vraie protection) ; désactivé lisible (teintes pleines, jamais d'opacité) |
| `FieldShell` | Vrai `label`, aide et erreur reliées par `aria-describedby`, `aria-invalid`, « (obligatoire) » écrit, erreur avec symbole et texte ; identifiant imposable (cible du résumé d'erreurs) |
| `TextField`, `SelectField` | Saisie conservée telle quelle ; liste native avec option vide explicite (null) |
| `DecimalField` | Mesures et quantités exactes par les règles F08 (virgule ou point) ; trop de décimales refusé, jamais arrondi ; vide = null, jamais 0 ; lu à la sortie du champ **et à Entrée** (avant l'envoi du formulaire) ; `commit()` exposé ; pavé décimal pour une valeur positive, clavier texte pour une dioptrie (le pavé décimal de l'iPhone n'a pas de « - ») |
| `MoneyField` | FCFA entiers (« 80 000 »), décimales et séparateurs ambigus refusés ; unité annoncée aux lecteurs d'écran ; affichage relu sans dérive |
| `DateField` | JJ/MM/AAAA, ISO ou 8 chiffres JJMMAAAA (pavé numérique de l'iPhone, sans « / ») ; date inexistante refusée ; jamais de date inventée |
| Saisie refusée (`DecimalField`, `MoneyField`, `DateField`) | Message sous le champ, saisie conservée pour correction, et valeur du parent remise à null : l'ancienne valeur valide ne part jamais à la place de ce que l'écran affiche ; le formulaire bloque l'envoi sur l'événement `invalid` ou le retour de `commit()` |
| `ComboboxField` | Choix avec recherche (catalogue, dossier) : motif combobox ARIA 1.2, recherche à partir de 2 caractères, ↓/↑ puis Entrée, Échap ferme ; rien n'est choisi à la place de l'utilisateur ; les résultats d'une saisie précédente disparaissent dès la nouvelle saisie et toute réponse obsolète est ignorée ; l'option active est ramenée dans la partie visible de la liste ; « Recherche indisponible » distinct de « Aucun résultat » ; résultats partiels avec avertissement (`{ options, notice }` : dossiers préparés sur ce poste quand la recherche serveur est indisponible). La source des résultats est fournie par l'écran (F10) |
| `ErrorSummary` | Résumé focalisé quand de nouvelles erreurs apparaissent ; chaque erreur mène à son champ ; `focus()` exposé pour un nouvel échec identique |
| `ConfirmDialog` | `<dialog>` modal natif (reste de la page inerte) ; focus initial sur l'action, ou sur « Annuler » pour une action risquée ; focus rendu au déclencheur ; Échap annule par le parent, sauf pendant la commande ; pendant la commande, focus gardé dans la boîte et libellé propre (`pendingLabel`) ; fermeture imposée par le navigateur signalée au parent, ou boîte rouverte pendant la commande |
| `SaveStatus` | « Enregistré sur ce poste à 10h42 » (heure du cabinet), « Synchronisé avec le serveur », « Échec de l'enregistrement : vos saisies restent affichées » ; il affiche l'état fourni, ne le déduit pas |
| `StateMessage` | États du fichier 04 : chargement, vide, succès, erreur, conflit, droits insuffisants, connexion indisponible ; rôle `alert` pour erreur et conflit ; symbole et titre, jamais la seule couleur |
| `OpticalTable` | OD toujours avant OG, OU seulement sur demande (acuité) ; libellé complet par champ (mesure, unité, œil) ; grille sur grand écran, blocs OD puis OG sur téléphone ; aucune recopie d'un œil à l'autre ; cellule dioptrie par défaut, autre nature (axe, acuité, hauteur) par la fente `cellule-<clé>` dont les règles appartiennent à C02/C04 |
| `AmountSummary` | Total, Versé, Reste (reste mis en évidence) en FCFA entiers ; aucun solde calculé par le composant (projection ou serveur) ; colonnes à la taille de leur contenu qui passent à la ligne, jamais de chevauchement ni de coupe (mise en page vérifiée par l'e2e de l'écran qui l'emploiera, B02–B04) |
| `useUnsavedChangesGuard` | Quitter un écran avec une saisie non enregistrée demande confirmation : navigation interne, même écran avec un autre paramètre (`/dossier/1` → `/dossier/2`), fermeture de l'onglet ; rien n'est conservé ailleurs |

Un composant ne lit ni n'écrit aucune table locale et n'appelle aucune API métier lui-même : les
écrans passent par les services de lecture et le répartiteur de mutations (F10). Les gardes
statiques interdisent `localStorage` et `sessionStorage` et toute ressource distante (fonte, style,
script, appel).

## Palette et contrastes

Teintes du fichier 04 reprises exactement : fond `#F6F8FB`, surface blanche, texte `#172B3A`, action
principale `#155E75`, accent `#167D65`. Les teintes d'état en dérivent. Le manifeste PWA et la
balise `theme-color` utilisent la même action principale. `tests/frontend/shell/palette.test.ts`
calcule le contraste WCAG 2.2 de chaque association à partir de `tokens.css`. Une teinte ajoutée
sans association vérifiée fait échouer le test. Extrait :

| Association | Usage | Contraste |
| --- | --- | --- |
| `ink` sur `surface` | texte courant | 14,55:1 |
| `ink` sur `canvas` | texte sur le fond | 13,68:1 |
| `muted` sur `surface` | aide, texte secondaire | 7,01:1 |
| `primary` sur `surface` | liens, boutons secondaires | 7,27:1 |
| `primary` sur `primary-soft` | navigation active | 6,32:1 |
| `primary-contrast` sur `primary` | bouton principal | 7,27:1 |
| `primary-contrast` sur `danger` | action risquée | 6,57:1 |
| `accent` sur `surface` | « En ligne », « Synchronisé » | 5,05:1 |
| `warning` sur `surface` | « Hors ligne » | 5,93:1 |
| `danger` sur `danger-soft` | titre du résumé d'erreurs | 6,05:1 |
| `disabled-ink` sur `disabled` | bouton ou champ désactivé | 5,74:1 |
| `field` sur `canvas` | bordure de champ (≥ 3:1) | 4,14:1 |
| `primary` sur `canvas` | contour de focus (≥ 3:1) | 6,83:1 |

Le texte désactivé est tenu à 4,5:1 bien que WCAG l'exempte. Le texte courant fait 16 px et suit le
réglage du navigateur. La police est celle du système, sans aucune fonte distante.

## Largeurs d'écran, zoom et clavier (AT28)

`tests/e2e/shell.spec.ts` sert l'application construite, avec la vraie API et la base de test
isolée. Les comptes de démonstration sont fictifs. Le test vérifie :

- connexion par le formulaire, accueil sans module futur, déconnexion ;
- refus d'identifiants : erreur sous le champ, résumé focalisé, mot de passe vidé ;
- premier arrêt du clavier « Aller au contenu », contour visible de 3 px, contenu focalisé, ordre de
  tabulation sans piège ;
- 360 px, 683 px (1366 px à 200 % de zoom), tablette 768 px et ordinateur 1366 × 768 : aucun
  défilement horizontal, contrôles d'au moins 44 px, en-tête dans la largeur, texte de 16 px ;
- « Hors ligne » puis « En ligne » quand le réseau est coupé puis rétabli ;
- une URL métier non livrée (`/patients`) mène à « Page introuvable ».

## Hors de F09

- Tests sur téléphones Android et iPhone réels, impression A4 en noir et blanc, mesures d'usage
  (création d'identité en moins d'une minute, etc.) : U01 et G3. Ce sont des objectifs de recette,
  pas des résultats acquis ici.
- AT29 (parcours patient → facture → reçu sans ressaisie) se vérifie quand les écrans existent (P01,
  P02, B02 à B04, D01). F09 fournit les composants et la coquille de ce parcours.
- Recherche rapide de l'en-tête (P02) ; état de synchronisation des pièces (F10, O04, O05) ;
  déverrouillage hors-ligne d'un profil préparé (O01, O02) ; règles des cellules axe et acuité (C02).

## Revue contradictoire (2026-10-15) et traitement

Revue indépendante et adverse des commits `1c39c52`, `0354212` et `5ff7363`, en lecture seule, avec
sondes et mutations sur une copie : **pass=false**, 4 majeurs et 14 mineurs. Elle a aussi relevé ce
qui tient : aucun module futur présenté comme livré, aucune redirection ouverte, déconnexion
honnête, relecture de session sans fuite, champs exacts, OD avant OG, contrastes exacts et correction
F05 sans effet de bord. Tous les constats sont traités ci-dessous, chacun avec son test.

| Constat | Gravité | Traitement |
| --- | --- | --- |
| Page d'erreur HTML d'un hébergeur (503, pare-feu, portail captif) : `SyntaxError`, session « inconnue » sans relecture, connexion sans message | Majeur | Réponse non JSON = `ApiError` `HTTP_ERROR`, serveur compté injoignable ; tests au démarrage (relecture rétablie) et à la connexion |
| Garde de saisie contournée par un changement de paramètre de route | Majeur | `onBeforeRouteUpdate` avec la même condition ; test `/dossier/1` → `/dossier/2` |
| Date impossible à saisir au pavé numérique de l'iPhone | Majeur | 8 chiffres JJMMAAAA mis en forme dans le champ (règle F08 inchangée, date ISO envoyée) ; test |
| Option active hors de la partie visible de la liste | Majeur | `scrollIntoView({ block: 'nearest' })` à chaque changement ; test |
| Résultats périmés choisissables pendant une recherche | Mineur | Résultats vidés dès la nouvelle saisie ; test |
| Résumé d'erreurs non réannoncé à l'identique | Mineur | La connexion refocalise le résumé à chaque échec ; test |
| État réseau figé (retour du serveur non vu, liaison perdue affichée « En ligne ») | Mineur | Sonde `/up` périodique (30 s / 60 s) et au retour du réseau ; deux tests |
| Session fermée avant la garde de saisie | Mineur | L'écran est quitté d'abord, la déconnexion s'arrête si la saisie est gardée ; test |
| Trois associations de couleurs employées mais non vérifiées | Mineur | Ajoutées au test de contraste ; commentaire de `tokens.css` corrigé |
| Menu « Plus » rogné en paysage ou à 200 % ; navigation jamais vue dans un vrai navigateur | Mineur | Liste bornée en hauteur et défilante ; e2e de la navigation avec le premier écran livré (P01, P02) |
| Grands montants qui débordent entre 640 et 767 px | Mineur | Colonnes à la taille du contenu qui passent à la ligne |
| Saisie refusée : le parent gardait l'ancienne valeur valide | Mineur | Valeur du parent remise à null, saisie conservée ; test |
| Pas de résultats locaux avec « Recherche serveur indisponible » | Mineur | Résultat `{ options, notice }` ; test |
| `ConfirmDialog` : focus hors de la boîte pendant la commande, libellé générique | Mineur | Focus gardé dans la boîte, `pendingLabel` ; tests (dont la réouverture) |
| Accueil : module livré sans action d'accueil annoncé « aucun module livré » | Mineur | Modules pris en compte, renvoi au menu ; test |
| Gardes statiques contournables | Mineur | Commentaires retirés avant analyse, accès indirects, URL sans protocole, `import()`, `sendBeacon`, `WebSocket` |
| Affirmations inexactes de ce document | Mineur | Corrigées (`suite`, focus de « Plus », lien de l'en-tête, réponses obsolètes) |
| `opcache.enable_cli` sans effet pour `php -S`, préchauffage sans délai | Mineur | Option retirée (le serveur intégré suit `opcache.enable`), délai de 30 s par requête de préchauffage |

Les cinq mutations qui survivaient (réponse obsolète, focus au premier affichage, paramètres de
requête dans `suite`, saisie refusée écrasée, boîte non rouverte), plus deux nouvelles (garde sans
`onBeforeRouteUpdate`, réponse HTML non protégée), font maintenant échouer au moins un test.
