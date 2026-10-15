# Règles pures et vecteurs communs (F08)

F08 fixe les calculs que le serveur PHP et la PWA TypeScript doivent faire **à l'identique** : montants, quantités, taux, mesures optiques, dates civiles, instants, compteurs, textes, lignes de facture, soldes, stock et dates de contrôle. Les mêmes fichiers JSON (`contracts/vectors/`) sont rejoués par PHPUnit et par Vitest, et MySQL sert de troisième moteur pour les arrondis, les dates et les décimaux. Contrat : fichier 23, « Valeurs et sérialisation » ; fichier 03 ; fiche 24, F08 ; IT03 et IT15 (fichier 25).

Version des règles : `2026-10-14.1` (`App\Domain\Shared\RulesVersion`, `resources/frontend/domain/rulesVersion.ts`, `contracts/compatibility.json`). Toute règle modifiée prend une nouvelle version, ajoutée sans retirer l'ancienne tant qu'un poste détient des pièces calculées avec elle.

## Où sont les règles

| Domaine | PHP | TypeScript |
| --- | --- | --- |
| Refus commun | `app/Domain/Shared/RuleViolation.php` | `resources/frontend/domain/errors.ts` |
| Texte, recherche, téléphone | `app/Domain/Shared/ValueObjects/Text.php` | `values/text.ts` |
| Montants XAF | `ValueObjects/Money.php` | `values/money.ts` |
| Décimaux exacts, quantités, taux, mesures | `ValueObjects/{FixedDecimal,Quantity,Rate,Diopter}.php` | `values/decimal.ts` |
| Dates civiles et instants | `ValueObjects/{CivilDate,Instant}.php` | `values/{civilDate,instant}.ts` |
| Compteurs BIGINT UNSIGNED | `ValueObjects/Counter.php` | `values/counter.ts` |
| Lignes, total, taxe comprise | `app/Domain/Billing/Rules/{LineAmounts,IncludedTax}.php` | `billing/lineAmounts.ts` |
| Solde d'une facture émise | `Billing/Rules/LedgerBalance.php` | `billing/ledger.ts` |
| Reprise de créance papier | `Billing/Rules/LegacyBalance.php` | `billing/legacy.ts` |
| Disponibilités et quotas | `app/Domain/Inventory/Rules/StockAvailability.php` | `inventory/stock.ts` |
| Lots, péremption, FEFO | `Inventory/Rules/LotRules.php` | `inventory/lots.ts` |
| Dates de contrôle et de rappel | `app/Domain/Scheduling/Rules/FollowUpDates.php` | `scheduling/followUp.ts` |

Les chemins TypeScript sont relatifs à `resources/frontend/domain/`. Les règles sont des fonctions pures : aucune base, aucun réseau, aucune horloge implicite. Les actions métier (B02–B08, I00–I02, S02…) les appellent sous leurs verrous ; elles ne recalculent rien elles-mêmes.

## Conventions

| Valeur | Règle |
| --- | --- |
| Montant | Entier FCFA, 0 à 10^12 (les 28 CHECK `BETWEEN 0 AND 1000000000000` du schéma), signé ±10^12 pour un solde. PHP `int` et `brick/math` pour les produits ; TypeScript `BigInt` pour sommes et produits, nombres JSON entiers sûrs en entrée et en sortie. Refus au-delà, jamais de troncature |
| Saisie d'un montant | Chiffres seuls (« 80000 ») ou groupes de trois séparés par une espace, une espace insécable ou une espace fine (« 80 000 »). Ni virgule, ni point (« 80.000 » serait ambigu), ni signe, ni unité. Vide → `null`, jamais 0 |
| Affichage | « 80 000 FCFA » : espace fine insécable U+202F entre groupes, espace insécable avant FCFA, « - » pour un négatif |
| Décimal | Entier d'unités à échelle fixe (`FixedDecimal`) : `DECIMAL(12,3)` quantités, `(7,4)` taux 0–100, `(6,2)` mesures optiques signées. Virgule ou point, signe « + », « - » ou « − » (U+2212). Trop de décimales ou de chiffres : refus, jamais d'arrondi ni de troncature. Forme canonique = celle que MySQL rend (« 1.500 », « -0.75 », « 0.00 »). Une valeur de confiance (relue en base, `Quantity::of`) est bornée comme sa colonne : au plus 999 999 999,999 en valeur absolue et 18 chiffres au plus, sinon refus |
| Vide et zéro | `null` (non saisi, non mesuré) est distinct de « 0.000 » ou « 0.00 » (une valeur) ; aucune règle ne remplace l'un par l'autre |
| Date civile | `YYYY-MM-DD`, 1000-01-01 à 9999-12-31 (type DATE). Saisie ISO ou « JJ/MM/AAAA ». Mois et années au calendrier civil, jour ramené au dernier jour du mois d'arrivée (31 janvier + 1 mois = 28 ou 29 février ; 29 février + 1 an = 28 février), comme `DATE_ADD` de MySQL |
| Jour d'un instant | Calculé dans le fuseau du cabinet (Africa/Douala, UTC+1 sans heure d'été) : 23 h 30 UTC le 31 décembre est déjà le 1er janvier. Jamais de minuit UTC implicite. PHP `DateTimeZone`, TypeScript `Intl` |
| Instant | `2026-10-14T09:30:00.000Z` exactement (UTC, millisecondes, suffixe Z, ni saut de ligne final ni décalage), entier de millisecondes depuis 1970, **jamais antérieur à 1970** (`instant.out_of_range`) : aucun événement de l'application ne le précède, et c'est avant 1919 que les bases de fuseaux de PHP (timezonedb) et des navigateurs (ICU) divergent pour Africa/Douala |
| Âge | Années révolues ; un 29 février compte le 1er mars d'une année non bissextile |
| Compteur | Chaîne de chiffres sans zéro initial, 0 à 18 446 744 073 709 551 615 (BIGINT UNSIGNED), calculée en `BigInt` et `brick/math` |
| Texte | Blancs de bord retirés selon une liste FERMÉE (espace, tabulation, retours, U+00A0, U+202F) ; Unicode d'origine conservé (aucune normalisation à l'enregistrement) ; vide → `null` ; longueur en caractères |
| Clé de recherche | Valeur séparée (`patient_records.name_search`) : minuscules, NFD, sans marques combinantes ni caractères de format invisibles (U+00AD, U+200B, U+FEFF), séparateurs, ponctuation, symboles et contrôles réduits à une espace. « N'Guessan-Kouamé » → « n guessan kouame » ; « İstanbul » → « istanbul » ; sigma final grec conservé |
| Téléphone normalisé | Chiffres, précédés de « + » si la saisie commence par « + » ou « 00 » ; 3 à 31 chiffres ; aucun indicatif déduit (un numéro national et le même avec indicatif restent retrouvables par les chiffres) |

## Calculs

```text
brut_ligne    = arrondi_FCFA(quantité × prix_TTC), demi supérieur      (= ck_invoice_lines_04, ROUND de MySQL)
net_ligne     = brut_ligne - remise, 0 ≤ remise ≤ brut                  (= ck_invoice_lines_03)
taxe_comprise = arrondi(net × t / (100 + t)), demi supérieur, si un taux validé existe ; sinon null
total         = somme des nets, ≤ 10^12
solde_signé   = total - avoirs - paiements confirmés + contre-écritures + remboursements   (= vw_invoice_balances)
reste_dû      = max(0, solde) ; crédit_à_rembourser = max(0, -solde) ; avoir possible = total - avoirs
ledger_version = 1 + nombre de paiements, contre-écritures, avoirs et remboursements
central       = physique - réservations centrales - quotas de postes non consommés   (= vw_stock_position_balances)
local         = quota alloué - consommé - restitué - réservations locales
```

| Règle | Refus |
| --- | --- |
| Encaissement | Montant > 0 et ≤ reste dû, sinon `PAYMENT_EXCEEDS_BALANCE` avec `amount_due` (AT12 : le second règlement de 40 000 sur 50 000 propose 10 000) |
| Avoir | ≤ part non encore créditée (`credit_note.exceeds_creditable`) |
| Remboursement | ≤ crédit à rembourser (`refund.exceeds_credit`) |
| Contre-écriture | ≤ montant du paiement non encore contre-passé (`reversal.exceeds_payment`) |
| Reprise papier | Reste dû = total papier - déjà payé, > 0 ; chiffre d'affaires de la pièce reprise = 0 (AT26) |
| Réservation, remise | Quantité > 0 et ≤ disponible, sinon `STOCK_UNAVAILABLE` avec `available` (0 si le disponible est négatif) |
| Remise synchronisée sous quota | Sort du physique ET consomme le quota dans le même calcul (AT40, IT15 : physique 5, quota 3, remise 1 → physique 4, quota 2, central 2) |
| Lot | Quarantaine → `STOCK_UNAVAILABLE` ; expiré avant la date locale de remise → `LOT_EXPIRED` ; un lot qui expire le jour même reste délivrable ; FEFO puis lots sans date |
| Contrôle recommandé | Départ choisi par le professionnel + délai ≥ 1 jour, mois ou année ; rappel = date recommandée moins 0 ou plus jours ou mois. Aucune durée de traitement supposée (AT22) |

Un refus est un `RuleViolation`, de même nom en PHP et en TypeScript, portant le code d'API du fichier 08, le champ, l'identifiant stable de la règle et des détails sans donnée personnelle . `toBusinessError()` le traduit comme le fichier 08 : `VALIDATION_FAILED` et `LOT_EXPIRED` en 422, `PAYMENT_EXCEEDS_BALANCE` et `STOCK_UNAVAILABLE` en 409, avec les valeurs actualisées à proposer dans `error.details` (reste dû, quantité disponible ; champ ajouté au contrat `schemas/error.json`). Côté TypeScript, un nombre non entier (NaN, infini, 1,5) donné comme délai ou composante de date est refusé (`number.integer_required`, `date.invalid`), jamais transformé en date.

## Vecteurs communs

Format : `contracts/schemas/rule-vectors.json`. Chaque cas : `id`, `operation`, `input`, puis `expected` (résultat exact) **ou** `error` (`code`, `field`, `rule`, `details` facultatifs), et `covers` (AT, EE, IT illustrés). Neuf fichiers, 262 cas : `text`, `money`, `decimal`, `dates`, `counter`, `invoice`, `ledger`, `stock`, `follow-up`.

Les attendus sont écrits à la main dans `contracts/tools/build_vectors.py`, puis **recoupés par une troisième implémentation indépendante** (Python `Decimal` en `ROUND_HALF_UP`, `datetime`, `calendar`) avant écriture : un attendu faux fait échouer le script, rien n'est écrit. `build_vectors.py --check` (CI et `check-all.sh`) échoue si un fichier est périmé ; les fichiers ne se modifient jamais à la main et sont exclus de Prettier. Les caractères invisibles (U+00A0, U+202F, U+2009, U+0301, U+00AD, U+200B, U+FEFF) sont écrits en échappement `\uXXXX` pour rester lisibles.

Les adaptateurs `tests/Support/Rules/RuleVectorOperations.php` et `tests/frontend/domain/vectorOperations.ts` traduisent une opération en appel de règle, sans calcul propre ; ils exposent les mêmes 36 opérations.

## Ajouter ou modifier une règle

1. Écrire la règle des deux côtés, avec les mêmes identifiants de règle et de champ.
2. Ajouter ses cas acceptés **et refusés** dans `contracts/vectors/`, attendus calculés indépendamment.
3. Si le résultat d'un calcul existant change : nouvelle version de règles dans `RulesVersion`, `rulesVersion.ts` et `compatibility.json`, l'ancienne restant reprise.
4. `composer run test:unit`, `npm run test:domain` et `tests/Feature/Rules` (MySQL) doivent passer.

## Preuves

| Test | Prouve |
| --- | --- |
| `tests/Unit/Rules/RuleVectorsTest.php` | Les 262 cas en PHP (résultat exact ou même refus) ; chaque fichier conforme au schéma, version de règles = constante = `compatibility.json`, identifiants uniques |
| `tests/frontend/domain/rule-vectors.test.ts` (`npm run test:domain`) | Les mêmes 262 cas en TypeScript, même verdict (IT03) ; même contrôle de format avec Ajv strict |
| `tests/Feature/Rules/MysqlRuleParityTest.php` | MySQL 8.4 réel sur les vecteurs acceptés : arrondi de ligne = `ROUND(q × p, 0)` et contraintes ck_invoice_lines_03 et _04 vraies, dates au jour, au mois et à l'année = `DATE_ADD`, décimaux canoniques = forme rendue par la colonne |
| `tests/Unit/Rules/ValueBoundsTest.php`, `tests/frontend/domain/value-guards.test.ts` | Ce que les vecteurs n'expriment pas : plafond des quantités calculées, valeurs de confiance bornées, saut de ligne final refusé, aucun instant avant 1970 ; en TypeScript, délais et composantes de date non entiers refusés, ordre FEFO identique à `strcmp` |
| `tests/Feature/Http/RuleViolationResponseTest.php` | Refus de règle traduit en réponse du fichier 08, conforme à `schemas/error.json` : 409 avec `amount_due` ou `available`, 422 pour `LOT_EXPIRED` et une saisie invalide |
| `tests/Unit/Rules/SqlBoundsTest.php` | Bornes des règles = bornes du schéma livré (28 montants à 10^12, taux 0–100 en `DECIMAL(7,4)`, quantités `DECIMAL(12,3)`, mesures `DECIMAL(6,2)`) |
| `tests/Unit/Rules/NoFloatArithmeticTest.php`, `tests/frontend/domain/no-float.test.ts` | Aucun flottant dans les règles. PHP (jetons) : ni conversion, ni littéral décimal, ni division `/` ou `/=`, ni `round`, `floor`, `ceil`. TypeScript (arbre syntaxique du compilateur, indifférent aux regex et aux commentaires) : ni division `/` ou `/=` hors de `values/integer.ts` (division entière exacte des dates, arrondi demi supérieur des BigInt), ni littéral décimal, ni `parseFloat`, `toFixed`, `Math.round`… ; la garde se contrôle elle-même sur le mutant d'arrondi flottant relevé par la revue |

## Décisions et limites

| Sujet | Décision |
| --- | --- |
| Taxe | Aucun taux fourni (fichier 07 : régime validé par la gérante). Méthode **proposée** à valider avec Q03 : taxe comprise dans le prix TTC, ventilée par ligne sur le net après remise, arrondie au FCFA demi supérieur. Les vecteurs emploient des taux fictifs (10 %, 12,5 %, 100 %) signalés comme tels. Une autre méthode exigée par le comptable prendra une nouvelle version de règles |
| Saisie des montants | Stricte (groupes de trois exacts) pour détecter une faute de frappe (« 80 00 ») au lieu de l'interpréter |
| Clé de recherche | Calculée par `mb_strtolower`, `Normalizer` (extension intl, exigée par l'audit d'hébergement, point 3) et les classes Unicode de PCRE2 côté PHP, par `toLowerCase`, `normalize` et les classes Unicode de V8 côté navigateur. Les tables Unicode diffèrent selon les moteurs (revue F08 : PCRE2 10.44 en Unicode 15.0, V8 de Node 24 en Unicode 17.0) : 966 points de code récents, absents des noms usuels, n'ont pas la même clé. Conséquence assumée : `name_search` est **toujours recalculé par le serveur** à partir des noms reçus, jamais repris du poste ; la clé du navigateur ne sert qu'à chercher dans les données locales déchiffrées. Les vecteurs couvrent les écarts connus (I pointé, sigma final, caractères de format) |
| Fuseau | Africa/Douala par défaut ; un autre fuseau suppose une base de fuseaux à jour dans PHP et dans le navigateur (aucun calcul ne s'appuie sur `CONVERT_TZ` de MySQL, dont les tables manquent souvent chez les hébergeurs). Les instants commencent en 1970, après la dernière divergence connue des deux bases pour Douala (1919) |
| Brut de ligne | Borné à 10^12 comme tout montant affiché, même si le net après remise le serait (2 × 10^12 moins 10^12 de remise satisferait les CHECK) : la ligne est refusée plutôt que d'afficher un brut hors bornes |
| Ordre FEFO | Identifiants comparés par points de code des deux côtés (ordre des octets UTF-8 de `strcmp`) ; en pratique des ULID ASCII |
| Version des règles | `2026-10-14.1` est la première version publiée avec F08 : les corrections de sa revue précèdent toute pièce réelle ou de démonstration calculée avec elle, donc sans nouvelle version. Après F08 DONE, tout changement de résultat prendra une nouvelle version |
| Lot expirant le jour même | Délivrable ce jour-là (la date d'expiration est le dernier jour d'usage) ; à confirmer par le cabinet avec les collyres réels |
| Indice de verre `DECIMAL(4,2)`, acuité normalisée `DECIMAL(8,4)` | Même `FixedDecimal`, règles de mesure livrées avec C02 (échelles et bornes cliniques : Q06) |
| Couverture | Règles des scénarios AT08 (montants recalculés depuis les seuls snapshots de ligne), AT10, AT12, AT14, AT22, AT26, AT40 et des exigences EE021, EE022, EE033, EE045 au niveau du calcul. La recette de ces scénarios (pièces, reçus, PDF, synchronisation) appartient à B03, B04, B07, B08, I01, S02, X01 et G1–G3 |

## Revue contradictoire et traitement (2026-10-15)

Un vérificateur indépendant et adverse a rejoué les règles en différentiel entre PHP 8.4.21 et Node 24.15 (2 graines × 91 032 entrées, 1 112 064 points de code), contre un oracle Python et MySQL 8.4.9 : calculs justes et identiques, arrondi égal à `ROUND` sur 4 596 lignes, mais **pass=false**. Tous les constats sont traités ; chaque correction a son test.

| Constat | Gravité | Traitement | Preuve |
| --- | --- | --- | --- |
| Types TypeScript non régénérés après l'ajout du schéma des vecteurs (CI rouge) | Majeur, reproduit | Types régénérés au commit `6aeab1c`, définitions renommées `VectorCase`, `VectorRefusal` | `generate-types.mjs --check` |
| Dates TypeScript sans garde d'entier : NaN ou 1,5 jour donnaient « 0NaN-NaN-NaN » ou « 2026-10-16.5 » | Majeur, reproduit | `assertSafeInteger` sur délais, avances et composantes de date (`number.integer_required`, `date.invalid`) | `value-guards.test.ts` |
| `toBusinessError()` : `LOT_EXPIRED` en 409 au lieu de 422, valeurs actualisées perdues | Majeur, reproduit | Statuts du fichier 08 ; `error.details` ajouté au contrat et rendu par l'API (reste dû, quantité disponible) | `RuleViolationResponseTest`, fixtures `errors/error.payment-details.valid.json` et `error.nested-details.invalid.json` |
| Expressions PCRE terminées par `$` : saut de ligne final accepté en PHP, refusé en TypeScript | Mineur, reproduit | Ancrage `\z` dans toutes les expressions des valeurs | Vecteurs `instant.parse.saut-de-ligne-final`, `counter.parse.saut-de-ligne-final`, `ValueBoundsTest` |
| Bases de fuseaux divergentes pour Douala avant 1919 | Mineur, reproduit | Instants bornés à 1970 et au-delà | Vecteur `instant.parse.avant-origine` |
| Clé de recherche différente sur 966 points de code récents ; caractères de format conservés | Mineur, reproduit | Caractères de format retirés ; `name_search` toujours recalculé par le serveur, divergence documentée | Vecteurs `text.search.trait-union-conditionnel`, `espace-sans-chasse`, `indicateur-ordre-octets` |
| Quantité de confiance au-delà de `DECIMAL(12,3)` acceptée ; débordement en flottant de `fromString` | Mineur, reproduit | `Quantity::of` et `forLine` bornés ; `fromString` refuse plus de 18 chiffres | Vecteur `invoice.line.quantite-au-dela-sql`, `ValueBoundsTest`, `value-guards.test.ts` |
| Mutants survivants (siècle non bissextile, total égal au maximum, blancs de contrôle, minute 60, bornes du solde et des sommes, plafond de quantité, bornes du téléphone, U+2009, arrondi flottant TypeScript) | Mineur, reproduit | 20 vecteurs ajoutés ; gardes sans flottant renforcées (arbre syntaxique, `/=`) ; arrondi demi supérieur unique `divideRoundHalfUp` | `build_vectors.py --check`, `no-float.test.ts` |
| FEFO : `strcmp` et `<` diffèrent hors du plan de base | Mineur, reproduit | Comparaison par points de code en TypeScript | `value-guards.test.ts` |
| Affirmations de ce document fausses ou non prouvées (Instant, Décimal, refus, recoupement Python, garde TypeScript, Unicode) | — | Corrigées ci-dessus ; générateur des vecteurs versionné avec `--check` | Ce document |
