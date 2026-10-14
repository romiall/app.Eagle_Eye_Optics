# Plan de validation, preuves et bascule

Version 3.0, 14 octobre 2026. Les tests ci-dessous sont à implémenter/exécuter pendant le développement. Les 41 assertions SQL déjà passées dans database/VALIDATION.md ne sont pas une preuve d’authentification, de PWA, de cryptographie, de synchronisation ou d’impression applicative.

## Organisation de la validation

| Niveau | Objet | Outil et environnement | Moment |
| --- | --- | --- | --- |
| Contrat | DTO/schémas, erreurs, versions, fixtures | OpenAPI/JSON Schema, PHP et TS | Avant et à chaque évolution de commande |
| Règle pure | Montants, quantités, dates, mesures/nulls | PHPUnit + Vitest, mêmes vecteurs JSON | F08 puis chaque règle modifiée |
| Action centrale | Permissions, atomicité, ledger, guards | PHPUnit sur MySQL 8.4 réel | Chaque tranche métier |
| Concurrence | Writer, plages, stock, créneaux, idempotence et pull | Deux connexions/processus MySQL avec barrières contrôlées | F07/O03 puis G2 |
| Moteur local | Chiffrement, CAS, clés/compartiments, reprise | Vitest + navigateur réel ; Dexie réelle pour preuve finale | O01/O04 puis chaque commande offline |
| Parcours | Écran → commit → PDF → restart → ACK | Playwright avec profils persistants et API réelle | G1 puis régressions ciblées |
| Usage/document | Rôles, appareils, clavier, PDF et imprimante | Recette humaine + captures sur build identifié | D01/D02/U01 |
| Exploitation | Installation, cron, backup et recovery | Hébergement cible et restauration isolée | X03/H01/G3/G4 |
| Fournisseur | OAuth/webhook/consentement/message/handoff | Doubles d’abord ; comptes et contacts contrôlés autorisés ensuite | L2/G6 |

Chaque action a un cas admissible, un refus pertinent et une preuve de ses invariants. Ne pas créer des tests qui affirment uniquement qu’un composant existe ou reproduisent une formule sans cas de bord. Le test doit détecter un risque concret de perte, duplication, incohérence ou divulgation.

## Scripts attendus de F03

Ces commandes sont des contrats de scripts **à créer**, pas des commandes applicatives déjà présentes dans ce dossier. F03 les configure et documente leur comportement/exit code ; l’agent ne rapporte pas leur succès avant exécution réelle.

| Commande future | Résultat requis |
| --- | --- |
| `composer run lint` | Format/analyse statique PHP selon configuration verrouillée |
| `composer run test:unit` | Règles pures PHP et vecteurs communs |
| `composer run test:contracts` | Fixtures requêtes/réponses/enveloppes et refus de type/version |
| `composer run test:mysql` | Actions centrales, rôles, contraintes et diagnostics sur base test |
| `composer run test:concurrency` | Interleavings reproductibles, pas seulement deux promesses sans contrôle |
| `php artisan eagle:verify-schema` | Équivalence des 117 tables métier + inventaire technique séparé |
| `npm run typecheck` | TypeScript strict sans erreur |
| `npm run test:domain` | Vecteurs TS identiques aux vecteurs PHP |
| `npm run test:e2e` | Build PWA et API de test, profils persistants, réseau/pannes contrôlés |
| `npm run build` | Assets/PWA/fonts/templates complets sans secret |

La CI vérifie les contrôles pertinents à chaque tranche ; build/typecheck/contrats après changements structurels. Avant un gate, exécuter tous les contrôles qui le conditionnent sur le même build. Après correction isolée, reproduire d’abord le défaut puis la régression concernée ; éviter des répétitions générales sans raison. Le build final G3 reçoit néanmoins la recette L0 complète.

Les suites MySQL utilisent une base distincte et des credentials test. Les tests en transaction peuvent rollback leurs fixtures. Pour concurrence à plusieurs connexions, préparer un dataset committé dans une base entièrement jetable ; les fixtures immuables ne se nettoient pas par DELETE contournant les guards. Une procédure de remise à zéro vérifie explicitement environnement/noms/absence de production avant de recréer cette base. SQLite ne remplace pas les preuves InnoDB/guards/CHECK.

## Scénarios complémentaires d’ingénierie

| ID | Défaut que le test doit détecter | Preuve attendue |
| --- | --- | --- |
| IT01 | Migration perd CHECK/FK/colonne générée/trigger | Comparaison métadonnées et refus d’adoption sur divergence |
| IT02 | Laravel cherche password ou session.user_id numérique | Login/ULID réel, session/reset/désactivation et quatre profils |
| IT03 | Prix calculés différemment en PHP/TS | Tous vecteurs acceptés/refusés donnent mêmes résultats exacts |
| IT04 | Paiement tente UPDATE invoices émise | Aucun UPDATE du parent final ; ledger_version dérivé progresse |
| IT05 | Parent finalisé avant écriture des enfants | Emission/prescription/avoir/remise rollback ou ordre correct sous guards |
| IT06 | Publication auto-incrémentée lue avant commit précédent | Deux transactions entrelacées, pull puis commit tardif ; aucune entrée perdue |
| IT07 | JSON Unicode/signature incompatibles | JCS/hash/JWS PHP–navigateur croisés ; payload altéré, mauvaise clé/alg refusés |
| IT08 | Web Crypto laisse expirer transaction Dexie | Chiffrement hors transaction + CAS ; aucune écriture partielle |
| IT09 | Deux profils créent deux heads pour même poste | Un head commun, compartiments protégés et séquence monotone |
| IT10 | Timeout ACK crée seconde émission | Une occurrence par command_id, retour du résultat durable, numéro inchangé |
| IT11 | Conflit N bloque le journal indéfiniment | Résolution autorisée, original intact, N finalisé puis N+1 revalidé |
| IT12 | REJECTED est compté comme paiement accepté | Projection centrale exclut le fait rejeté et UI conserve son litige |
| IT13 | Catalogue récent remplace prix autorisé offline | Grant lie snapshot/hash ancien, calcul sur snapshot admis, PDF identique |
| IT14 | Expiration libère plage/quota/propriété | Nouvelle opération bloquée ; ressources conservées jusqu’à reprise/release |
| IT15 | Une réserve quota est soustraite deux fois | Vecteur stock5/quota3/remise1 donne physique4/quota2/disponible2 |
| IT16 | PDF FAILED relance encaissement | Reprise du même generated_document et zéro nouveau paiement |
| IT17 | Source PDF non ACK exposée en public | Upload attend source et Policy, fichiers hors public, chemins serveur |
| IT18 | Compte/clés perdus rendent export local inutilisable | Recovery neuf déchiffre tous compartiments nécessaires sans cloner futurs droits |
| IT19 | SW update ou migration locale purge pending | Anciennes commandes/templates reprennent après interruption et upgrade |
| IT20 | Queue/outbox divulgue clinique ou annule vente | Payload minimal ; paiement confirmé même si fournisseur indisponible |
| IT21 | Nouveau consentement à un autre numéro autorise ancien numéro | Vérification dernier flux exact sujet/destination/canal/finalité avant send |
| IT22 | Tests app se trompent de base ou destinataire | Garde environnement négative, credentials distincts, fournisseurs doubles |

Ces IT complètent les 45 AT du fichier 14 ; ils ne renumérotent pas la recette métier ni la traçabilité source. Les preuves IT/AT apparaissent dans les rapports de tâches/gates. Les checks de cohérence du plan générés maintenant sont distincts de ces tests futurs.

## Preuve G1 et G2 pas à pas

1. Configurer cabinet/profils fictifs, catalogue de recette et caisse. Préparer appareil, catalogue signé, numéros, grant et ressources stock. Relever IDs/plages/versions/head et état central initial.
2. Couper réellement les accès réseau utilisés par SPA/API ; les fontes et templates doivent déjà être locaux. Créer nouveau patient, facture 80000, paiement30000/reçu puis paiement50000/reçu. Vérifier solde0 et deux numéros distincts.
3. Fermer le navigateur entier, relancer avec le même répertoire de profil persistant et déverrouiller. Retrouver pièces et PDFs. Un simple reload ou un contexte en mémoire n’est pas cette preuve.
4. Ouvrir un deuxième onglet ; injecter collision de numéro/paiement. Puis simuler panne avant commit et après commit. Vérifier tout ou rien et une occurrence par intention.
5. Rétablir réseau, injecter timeout après commit serveur avant ACK ; replay plusieurs fois. Comparer IDs/numéros/hashes des sources et compter toutes les pièces/écritures en base.
6. Ouvrir une autre caisse et tenter écrire la même facture. Exiger refus propriétaire. Tester allocation/plage/derniers articles concurrents et release avant reprise.
7. Produire volontairement un conflit de version/dépendance dans un dataset fictif ; préserver original, résoudre sous rôle autorisé et reprendre la suite du journal, sans accepter un fait financier fictif.
8. Exporter local chiffré, simuler perte, restaurer sur nouveau device ; rapprocher les anciens faits, réserver de nouvelles ressources futures et traiter la réapparition de l’ancien appareil.

G1 couvre les étapes de base patient/facture/PDF/reprise ; G2 couvre tous les risques financiers/concurrence/recovery. Enregistrer versions navigateur/OS/build/règles/templates, modes persistance, commandes et montants avant/après. Ajouter imprimante réelle lors de D01/U01/G4.

## Critères des gates

| Gate | Conditions cumulatives |
| --- | --- |
| G0 | Baseline entière équivalente, migrations installables, CI/build test, `.env.example` sans secrets |
| G1 | Parcours réel sans réseau après fermeture, PDF local, ACK perdu rejoué sans duplication, preuve des IDs/soldes |
| G2 | Finance/approbations/stock/numéros/writer/concurrence/crypto/conflicts/recovery testés ; diagnostics SQL sans anomalie sur dataset |
| G3 | AT01–AT29 et AT36–AT45 passés : 39 scénarios L0 ; IT critiques acquis ; PDFs et appareils ciblés ; serveur/local restaurés ; zéro défaut bloquant |
| G4 | G3 + hébergement réel compatible, paramètres réels validés, comptes/postes prêts, utilisateurs formés, créances/stock/numéros rapprochés, décision de bascule responsable consignée |
| G5 | AT30/31, stock/atelier/rapprochement et formats convenus ; régression L0 des chemins affectés |
| G6 | AT32–35, comptes/canaux autorisés, consentements exacts, tests externes contrôlés, handoff/pause/réconciliation prouvés |
| G7 | EE001–EE046 évaluées, AT01–AT45 avec preuves/écarts explicites, site et documentation/remise/support reçus |

Un gate IN_REVIEW n’est pas DONE. Un accès hébergeur absent peut laisser le code de H01 prêt mais G4 bloqué ; ne pas l’annoncer opérationnel. Une intégration simulée est reçue comme prototype, pas comme canal réel. Les gates production ou changements externes s’appuient sur l’autorisation du responsable ; l’agent prépare la release, les preuves et la procédure avant de demander une décision nécessaire.

## Registre de preuves

Créer docs/evidence/acceptance-L0.json avec un enregistrement par AT : ID, build/commit, environnement, date, préconditions, attendu, observé, résultat PASS/FAIL/NOT_RUN/BLOCKED, chemins des preuves, défaut et correction. Registre équivalent pour IT, G5/G6/G7 et les exigences EE. Aucun résultat PASS initial dans le template.

Les preuves n’incluent que données fictives ou informations autorisées, sans passwords/tokens/clés ni PDF patient réel envoyé à une IA. Les logs techniques utilisent IDs de corrélation, codes d’erreurs et états ; les snapshots médicaux restent dans le stockage autorisé.

Défaut bloquant : perte de donnée/pièce, montant faux, doublon paiement/numéro, stock négatif injustifié, fuite de rôle/cabinet, altération historique, purge pending, reprise non fiable ou facture illisible. L’agent suspend le gate concerné et corrige. Défaut mineur visuel : documenter et planifier ; une action critique inaccessible n’est pas mineure.

## Checklist de bascule réelle

| Étape | Résultat à constater |
| --- | --- |
| 1. Candidat | Build immuable, contrats/règles/templates identifiés, G3 prouvé |
| 2. Hébergement | PHP/extensions/MySQL/InnoDB/VIEW/TRIGGER, racine public, stockage privé, HTTPS/cron et quotas testés |
| 3. Installation | Sauvegarde de l’état préalable, migrations contrôlées, référentiels ; aucune réinitialisation métier |
| 4. Cabinet | Mentions/logo/régime/tarifs/horaires/professionnels réels approuvés ; documents TEST désactivés seulement après vérification |
| 5. Reprise | Stock compté, unités/lots, créances ouvertes signées et numéros papier rapprochés, séries numériques distinctes |
| 6. Profils | Comptes nominatifs, droits/mots de passe/MFA selon politique ; accès gérante distant contrôlé |
| 7. Poste | Enrôlement, grant, clés/recovery, plages, factures/quotas préparés, espace/persistance, imprimante et autonomie matériel |
| 8. Sauvegardes | Copie serveur hors hébergement et export local ; restauration exercée et responsable désigné |
| 9. Utilisateurs | Formation accueil/professionnel/caisse/gérante ; patient→facture→reçu→sync et incident répétés |
| 10. Ouverture | Date/heure, responsable et décision de bascule ; dernière pièce papier, premier numéro numérique et limites consignés |

Vérifier l’offre réelle sans achat implicite, migration DNS ou envoi de messages patient pour tester. Une procédure de mise en production sans SSH est préparée si l’hébergement l’exige ; aucune URL « cron » publique non protégée ni installation ouverte persistante.

## Rollback et surveillance

Après nouvelle vente, restaurer une ancienne base ferait perdre des faits ; le rollback standard remet une release compatible avec le schéma et préserve la base. Si une restauration DB est nécessaire, fermer les écritures, récupérer PDFs/journaux locaux et rapprocher toute opération après sauvegarde avant réouverture. Ne pas désinstaller PWA/purger IndexedDB pour une panne serveur.

Surveiller après bascule : dernières sync/commandes en litige, capacité numéros, grants proches expiration, stock/quotas, sessions de caisse, PDFs FAILED, cron/jobs, dernier backup et espace. Préflight quotidien et export à fermeture hors-ligne ; jamais de libération automatique des ressources d’un appareil expiré/perdu. Les routines sont testées en H01 et consignées dans le guide exploitation.
