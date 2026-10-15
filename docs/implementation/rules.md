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
| Décimal | Entier d'unités à échelle fixe (`FixedDecimal`) : `DECIMAL(12,3)` quantités, `(7,4)` taux 0–100, `(6,2)` mesures optiques signées. Virgule ou point, signe « + », « - » ou « − » (U+2212). Trop de décimales ou de chiffres : refus, jamais d'arrondi ni de troncature. Forme canonique = celle que MySQL rend (« 1.500 », « -0.75 », « 0.00 ») |
| Vide et zéro | `null` (non saisi, non mesuré) est distinct de « 0.000 » ou « 0.00 » (une valeur) ; aucune règle ne remplace l'un par l'autre |
| Date civile | `YYYY-MM-DD`, 1000-01-01 à 9999-12-31 (type DATE). Saisie ISO ou « JJ/MM/AAAA ». Mois et années au calendrier civil, jour ramené au dernier jour du mois d'arrivée (31 janvier + 1 mois = 28 ou 29 février ; 29 février + 1 an = 28 février), comme `DATE_ADD` de MySQL |
| Jour d'un instant | Calculé dans le fuseau du cabinet (Africa/Douala, UTC+1 sans heure d'été) : 23 h 30 UTC le 31 décembre est déjà le 1er janvier. Jamais de minuit UTC implicite. PHP `DateTimeZone`, TypeScript `Intl` |
| Instant | `2026-10-14T09:30:00.000Z` exactement (UTC, millisecondes, suffixe Z), entier de millisecondes depuis 1970 |
| Âge | Années révolues ; un 29 février compte le 1er mars d'une année non bissextile |
| Compteur | Chaîne de chiffres sans zéro initial, 0 à 18 446 744 073 709 551 615 (BIGINT UNSIGNED), calculée en `BigInt` et `brick/math` |
| Texte | Blancs de bord retirés selon une liste FERMÉE (espace, tabulation, retours, U+00A0, U+202F) ; Unicode d'origine conservé (aucune normalisation à l'enregistrement) ; vide → `null` ; longueur en caractères |
| Clé de recherche | Valeur séparée (`patient_records.name_search`) : minuscules, NFD, sans marques combinantes, séparateurs, ponctuation, symboles et contrôles réduits à une espace. « N'Guessan-Kouamé » → « n guessan kouame » ; « İstanbul » → « istanbul » ; sigma final grec conservé |
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

Un refus est un `RuleViolation`, de même nom en PHP et en TypeScript, portant le code d'API du fichier 08, le champ, l'identifiant stable de la règle et des détails sans donnée personnelle ; `toBusinessError()` le traduit en réponse 422 (saisie) ou 409 (état).

## Vecteurs communs

Format : `contracts/schemas/rule-vectors.json`. Chaque cas : `id`, `operation`, `input`, puis `expected` (résultat exact) **ou** `error` (`code`, `field`, `rule`, `details` facultatifs), et `covers` (AT, EE, IT illustrés). Neuf fichiers, 242 cas : `text`, `money`, `decimal`, `dates`, `counter`, `invoice`, `ledger`, `stock`, `follow-up`.

Les attendus sont écrits à la main puis **recoupés par une troisième implémentation indépendante** (Python `Decimal` en `ROUND_HALF_UP`, `datetime`, `calendar`) avant écriture : un vecteur faux n'est pas écrit. Les caractères invisibles (U+00A0, U+202F, U+2009, U+0301) sont écrits en échappement `\uXXXX` pour rester lisibles.

Les adaptateurs `tests/Support/Rules/RuleVectorOperations.php` et `tests/frontend/domain/vectorOperations.ts` traduisent une opération en appel de règle, sans calcul propre ; ils exposent les mêmes 36 opérations.

## Ajouter ou modifier une règle

1. Écrire la règle des deux côtés, avec les mêmes identifiants de règle et de champ.
2. Ajouter ses cas acceptés **et refusés** dans `contracts/vectors/`, attendus calculés indépendamment.
3. Si le résultat d'un calcul existant change : nouvelle version de règles dans `RulesVersion`, `rulesVersion.ts` et `compatibility.json`, l'ancienne restant reprise.
4. `composer run test:unit`, `npm run test:domain` et `tests/Feature/Rules` (MySQL) doivent passer.

## Preuves

| Test | Prouve |
| --- | --- |
| `tests/Unit/Rules/RuleVectorsTest.php` | Les 242 cas en PHP (résultat exact ou même refus) ; chaque fichier conforme au schéma, version de règles = constante = `compatibility.json`, identifiants uniques |
| `tests/frontend/domain/rule-vectors.test.ts` (`npm run test:domain`) | Les mêmes 242 cas en TypeScript, même verdict (IT03) ; même contrôle de format avec Ajv strict |
| `tests/Feature/Rules/MysqlRuleParityTest.php` | MySQL 8.4 réel : arrondi de ligne = `ROUND(q × p, 0)` et CHECK de `invoice_lines` satisfaits, dates = `DATE_ADD`, décimaux canoniques = forme rendue par la colonne |
| `tests/Unit/Rules/SqlBoundsTest.php` | Bornes des règles = bornes du schéma livré (28 montants à 10^12, taux 0–100 en `DECIMAL(7,4)`, quantités `DECIMAL(12,3)`, mesures `DECIMAL(6,2)`) |
| `tests/Unit/Rules/NoFloatArithmeticTest.php`, `tests/frontend/domain/no-float.test.ts` | Aucun flottant dans les règles : ni conversion, ni littéral décimal, ni division `/`, ni `round`, `floor`, `ceil` ou `toFixed` |

## Décisions et limites

| Sujet | Décision |
| --- | --- |
| Taxe | Aucun taux fourni (fichier 07 : régime validé par la gérante). Méthode **proposée** à valider avec Q03 : taxe comprise dans le prix TTC, ventilée par ligne sur le net après remise, arrondie au FCFA demi supérieur. Les vecteurs emploient des taux fictifs (10 %, 12,5 %, 100 %) signalés comme tels. Une autre méthode exigée par le comptable prendra une nouvelle version de règles |
| Saisie des montants | Stricte (groupes de trois exacts) pour détecter une faute de frappe (« 80 00 ») au lieu de l'interpréter |
| Clé de recherche | Calculée par `mb_strtolower` + `Normalizer` (extension intl, exigée par l'audit d'hébergement, point 3) côté PHP, `toLowerCase` + `normalize` côté navigateur. Les vecteurs couvrent les écarts connus entre moteurs (I pointé, sigma final) ; un caractère d'une version Unicode plus récente que celle du serveur ou du navigateur peut différer : à surveiller par les vecteurs si de nouveaux cas apparaissent |
| Fuseau | Africa/Douala par défaut ; un autre fuseau suppose une base de fuseaux à jour dans PHP et dans le navigateur (aucun calcul ne s'appuie sur `CONVERT_TZ` de MySQL, dont les tables manquent souvent chez les hébergeurs) |
| Lot expirant le jour même | Délivrable ce jour-là (la date d'expiration est le dernier jour d'usage) ; à confirmer par le cabinet avec les collyres réels |
| Indice de verre `DECIMAL(4,2)`, acuité normalisée `DECIMAL(8,4)` | Même `FixedDecimal`, règles de mesure livrées avec C02 (échelles et bornes cliniques : Q06) |
| Couverture | Règles des scénarios AT08 (montants recalculés depuis les seuls snapshots de ligne), AT10, AT12, AT14, AT22, AT26, AT40 et des exigences EE021, EE022, EE033, EE045 au niveau du calcul. La recette de ces scénarios (pièces, reçus, PDF, synchronisation) appartient à B03, B04, B07, B08, I01, S02, X01 et G1–G3 |
