# Socle du projet Eagle’s Eye Optics Medical

Version 3.0 du 14 octobre 2026. Le socle définit l’application entière, son modèle conceptuel, son modèle relationnel et sa base SQL MySQL complète. Il comprend maintenant un plan d’exécution détaillé, des contrats d’ingénierie et un backlog avec dépendances. Le planning donne la priorité à une première livraison utile sous deux semaines. Il est destiné au prestataire, à la gérante pour la recette et à Claude pour le développement.

La première réalisation vise patients, clinique et facturation hors-ligne, droits, traçabilité et sauvegardes. Atelier, stocks/achats/inventaires complets, CRM/IA/campagnes, site/showroom et intégrations sont aussi modélisés immédiatement. Seule leur réalisation et activation suit les lots du planning.

## Lecture recommandée

| Fichier | Utilité |
| --- | --- |
| [00 Sources et arbitrages](00-sources-et-arbitrages.md) | Autorité des documents, divergences et interprétations |
| [01 Périmètre et livraisons](01-perimetre-et-livraisons.md) | Ce qui doit être utilisable à J14 et le projet complet |
| [02 Fonctionnement métier](02-specifications-fonctionnelles.md) | Patients, parcours clinique et gestion du cabinet |
| [03 Règles financières et stock](03-regles-finance-stock.md) | Factures, encaissements, corrections et marchandises |
| [04 Interface et ergonomie](04-interface-et-ergonomie.md) | Écrans, navigation, formulaires et comportement mobile |
| [05 Architecture](05-architecture-technique.md) | Stack proposée, modules et transactions |
| [06 Modèle conceptuel](06-modele-conceptuel.md) | Entités, relations et états du système |
| [07 Dictionnaire des données](07-dictionnaire-donnees.md) | Champs, types, contraintes et référentiels |
| [08 Contrats applicatifs](08-contrats-applicatifs.md) | Commandes métier, endpoints et erreurs |
| [09 Sécurité](09-securite-et-confidentialite.md) | Permissions, journal, accès aux données et sauvegarde |
| [10 Automatisations](10-automatisations-et-integrations.md) | Suivi interne, Google, Kommo, WhatsApp et IA |
| [11 Documents imprimés](11-documents-et-impression.md) | Facture, reçu, prescription, dossier et commande |
| [12 Exploitation](12-deploiement-et-exploitation.md) | Sous-domaine, hébergement, déploiement et restauration |
| [13 Plan et backlog](13-plan-livraison-et-backlog.md) | Ordre de développement et dépendances |
| [14 Recette](14-recette-et-scenarios.md) | Tests métier et conditions de mise en service |
| [15 Décisions ouvertes](15-decisions-et-questions.md) | Hypothèses explicites et informations à obtenir |
| [16 Démarrer dans Claude](16-demarrer-dans-Claude.md) | Instructions et prompt prêt à copier |
| [17 Traçabilité](17-matrice-tracabilite.md) | Correspondance exigences, sources, lots et recette |
| [18 Références techniques](18-references-techniques.md) | Documentation officielle consultée |
| [19 Hors-ligne et synchronisation](19-hors-ligne-et-synchronisation.md) | Facturation locale, numéros, conflits et reprise |
| [20 Modèle relationnel](20-modele-relationnel.md) | Toutes tables, PK, FK et clés uniques de l’application |
| [21 Invariants de base](21-invariants-base-donnees.md) | Garanties SQL et transactions applicatives obligatoires |
| [22 Plan de développement détaillé](22-plan-developpement-detaille.md) | Ordre d’exécution, chemin critique, cadence et définition de DONE |
| [23 Contrats d’ingénierie](23-contrats-ingenierie.md) | Choix précis : migrations, auth, ledger, transactions, coffre et sync |
| [24 Tâches exécutables](24-taches-developpement.md) | Travaux/fichiers/dépendances/tests et responsabilité des 117 tables |
| [25 Validation et bascule](25-validation-et-bascule.md) | Scripts à créer, tests critiques, gates et ouverture réelle |
| [Backlog JSON](developpement/backlog.json) | Graphe machine des tâches et couverture EE/AT |
| [Journal d’exécution](developpement/JOURNAL.md) | Modèle de suivi et reprise de contexte pour Claude |
| [Vérification du plan](developpement/validation-plan.json) | Couverture et graphe contrôlés ; aucun test applicatif annoncé exécuté |
| [Base SQL complète](../database/README.md) | Import MySQL, référentiels, vues, historique et tests |

## Livrables des données

[MCD complet](06-modele-conceptuel.md), [MLD complet](20-modele-relationnel.md), [dictionnaire](07-dictionnaire-donnees.md) et [SQL en un fichier](../database/eagle_eye_optics_complet.sql) couvrent les 117 tables et les 14 domaines du système. [Le rapport MySQL](../database/VALIDATION.md) donne les preuves de validation du schéma ; les scénarios applicatifs 14 restent à exécuter après développement.

Les [instructions Claude à la racine](../CLAUDE.md) pointent vers ce dossier. Les extractions textuelles des trois documents se trouvent dans [sources](sources/README.md), avec des repères de paragraphes pour retrouver chaque besoin. Les originaux restent inchangés.

## Décisions structurantes

Application web en français, privée et responsive, sur un sous-domaine proposé `app.eagleeyeoptic.com`. Un patient possède plusieurs visites et consultations ; une vente peut exister sans examen. Factures, paiements, reçus et livraisons sont des objets distincts. Une consultation terminée et un document financier émis conservent leur historique.

Architecture proposée : API Laravel/PHP et MySQL sur hébergement web simple, interface Vue 3/TypeScript responsive installable en PWA, stockage local IndexedDB chiffré et synchronisation. Le demandeur a confirmé le hors-ligne dès L0 et exclu le VPS. Les patients, consultations de travail, factures et reçus peuvent être enregistrés localement et imprimés sur les postes préparés. Le fichier 19 définit les limites et garanties opérationnelles.

## Comment utiliser ce dossier

1. Lire les fichiers 00, 01 et 15 pour connaître les limites et hypothèses.
2. Fournir à Claude le dossier complet et `CLAUDE.md`, puis copier le prompt du fichier 16.
3. Suivre le plan 22, les contrats 23 et les tâches 24/backlog JSON ; 13 donne les échéances de livraison. Tenir le journal de travail.
4. Exécuter la recette 14 avec la gérante, sur des données fictives puis sur le catalogue et les modèles approuvés.
5. Valider les gates 25 ; conserver les lots L1 à L3 dans le backlog, sans les présenter comme livrés à J14.

Le dossier contient des spécifications et une base SQL exécutable ; l’application n’est pas encore développée. J14 reste un objectif de planning soumis aux accès, données de départ, prototype et recette. Aucun prix ni condition commerciale n’est modifié.
