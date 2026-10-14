# Démarrer le développement dans Claude

## Dossier à fournir

Ouvrir le dossier contenant `CLAUDE.md`, `specifications/` et `database/`. Les originaux sont utiles pour vérification ; les extractions `sources/` sont consultables sans Word. L’archive inclut MCD, MLD, SQL complet, référentiels, vues, protections et preuves de validation MySQL. Gemini n’est pas une source normative.

Le code applicatif peut être créé à la racine à côté de `specifications/`, en conservant ces documents. Les fichiers de conception restent versionnés avec les changements de code. N’envoyer aucun dossier patient réel à un fournisseur d’IA pour les tests.

Le [plan détaillé 22](22-plan-developpement-detaille.md), les [contrats 23](23-contrats-ingenierie.md), les [fiches 24](24-taches-developpement.md) et les [gates 25](25-validation-et-bascule.md) prescrivent l’exécution. Le [backlog JSON](developpement/backlog.json) donne les dépendances et le [journal](developpement/JOURNAL.md) conserve l’état entre sessions. L’agent commence à F01 et suit les tâches prêtes ; il ne recommence pas une phase générale de conception.

## Prompt prêt à copier

```text
Développe l’application métier privée Eagle’s Eye Optics Medical à partir de CLAUDE.md, specifications et database. Lis d’abord README, 00, 01, 03, 05, 08, 09, 15, 19, 21 et 22–25. Consulte 06/07/20 et les sources au niveau des agrégats travaillés ; lis database/README.md et la preuve SQL avant F04. Les trois sources normatives sont PC, CDC et DP ; Gemini n’a pas autorité.

Applique le plan 22 et les contrats d’ingénierie 23. Utilise specifications/developpement/backlog.json et les fiches 24 : première tâche F01, puis première tâche NOT_STARTED dont les prérequis sont DONE. Initialiser docs/implementation/progress.md depuis le modèle JOURNAL et tenir statuts, chemins, tests et preuves. Le plan est livré avec tous les statuts NOT_STARTED : aucun PASS de cohérence du plan ne vaut succès applicatif. Ne régénère pas le backlog pour effacer un suivi. Reprends la tâche en cours après interruption de contexte.

Le socle de toute l’application est déjà défini : MCD/MLD/dictionnaire/SQL complets, 117 tables tous domaines. Le chantier urgent vient en premier dans le planning, sans réduire le modèle. Traduis le schéma intégral en migrations Laravel fidèles et non destructrices ; active les fonctions/adaptateurs selon les lots. Respecte FK composites, colonnes générées, CHECK, snapshots/guards, vues et invariants transactionnels 21. Ne crée pas un deuxième modèle simplifié des mêmes données pour l’urgence.

Implémente le lot L0 complet, en commençant par une tranche patients → facture → acompte/reçu → paiement du solde/reçu qui fonctionne réellement sans Internet après fermeture/reprise du navigateur, puis se synchronise sans doublon. L0 comprend aussi les champs du dossier clinique DP, prescriptions imprimables, droits, historique, agenda/suivi internes, stock/remise minimal, caisse et sauvegardes. Le lot urgent est ciblé sous deux semaines ; évalue sa faisabilité sur le prototype et signale les écarts sans retirer le hors-ligne.

Valide G0 (schéma intégral) puis G1 (tranche offline réelle), G2 (finance/stock/reprise), G3 (recette L0 entière) et G4 (bascule sur hébergement réel). G1 ne remplace pas G3/G4. Construis le service de réservation I00 avant IssueInvoice. Réestime le calendrier à J2/J4 à partir du travail observé, en préservant le périmètre L0 requis. Les tâches L1Dxx/L2Dxx/L3Dxx sont aussi définies, avec gates G5–G7 ; leur activation suit le planning.

Contraintes confirmées : hébergement web simple, aucun VPS ; interface épurée, agréable, française et responsive ; enregistrement et facturation hors-ligne dès la première livraison. Architecture retenue : Laravel/PHP API + MySQL et Vue 3/TypeScript/Tailwind en PWA statique avec Dexie/IndexedDB chiffré et PDF client. Aucun Node permanent. Audit de l’offre PHP avant déploiement. Une caisse financière hors-ligne principale L0, plages de numéros, propriétaires de factures, quotas de stock et protocole de reprise selon fichier 19.

Développe par tranches vérifiables avec migrations non destructrices et tests pertinents. Applique les mêmes vecteurs métier PHP/TypeScript ; montants en entiers FCFA ; client et serveur revalident paiements, autorisations, stock et numéros. Pas de suppression de facture/reçu émis, pas de réécriture d’historique, pas de dernier écrit gagnant pour soldes ou clinique, pas de renumérotation après sync. Préserve toute pièce locale en conflit et toute commande non acquittée.

Respecte les points 23 : User login/password_hash/ULID et session SPA ; tables techniques framework séparées ; enfants écrits avant finalisation ; ledger_version dérivé et aucun UPDATE de facture émise ; un journal par appareil avec compartiments par droits ; curseur pull sans perte de commit tardif ; résolution de conflit conservant l’original et permettant reprise ; PDF et effets externes après commit. Routes directes, sync et jobs utilisent les mêmes actions. Ne déplace pas les calculs ou l’autorisation dans les composants.

Conserve les décisions et questions explicites du fichier 15. Avance avec données fictives si les prix, taxes, mentions, appareils ou accès manquent ; ne les invente pas. Ne remplace pas Kommo/Google ni l’hébergement simple par un VPS. Ne déclenche aucun message réel aux patients ou campagne depuis les tests.

Crée les scripts de validation du fichier 25, puis vérifie les 39 scénarios L0 AT01–AT29 et AT36–AT45 et les IT pertinents, y compris PDF/impression, accès par rôle, paiement concurrent, réseau coupé, fermeture/reprise, synchronisation interrompue, ancienne créance papier et restauration locale/serveur. Fournis une démonstration utilisable, le code, la configuration à compléter, la procédure de déploiement sur hébergement simple et un bilan clair des tests/exigences/gates réellement acquis. Mets à jour documentation et backlog de travail si une décision change. L1/L2/L3 restent conçus et planifiés sans prétendre les avoir livrés avec L0 ; le socle entier est conservé pour poursuivre jusqu’à G7 selon la consigne du responsable.
```

## Premier résultat à exiger

Avant multiplication des écrans : preuve sur deux contextes de navigateur qu’une caisse préparée crée et imprime une pièce réseau coupé, la retrouve après fermeture et la synchronise avec le même numéro après timeout/retry. Cette preuve détermine la fiabilité du socle. Ensuite appliquer la checklist de recette complète ; un exemple de page jolie ne suffit pas à valider la caisse.

## Remise attendue

Application de démonstration fonctionnelle, schémas/migrations et référentiels, tests et captures de recette, compilation PWA, `.env.example` sans secret, instructions d’hébergement simple, documents PDF validés visuellement, guide profils/synchronisation/restauration et liste des décisions restantes avant production. Chaque exigence de la matrice a un statut livré/testé/à faire/écart, sans résultat inventé.
