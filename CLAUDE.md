# Instructions de développement Eagle’s Eye Optics Medical

Le projet est défini dans `specifications/README.md`. Le socle de toute l’application comprend MCD, MLD et SQL complet dans `database/`. Les fichiers 22–25 et `specifications/developpement/backlog.json` définissent maintenant l’exécution détaillée. Le développement applicatif démarre lorsque l’utilisateur donne la consigne d’implémentation, par exemple le prompt 16.

## Autorité

Lire `specifications/00-sources-et-arbitrages.md` et `15-decisions-et-questions.md`. Les trois références sont la proposition commerciale finale, le cahier des charges optique et le modèle de dossier patient. L’historique Gemini est contexte, pas une source de décisions techniques. Les originaux ne doivent pas être modifiés. Le répertoire `.spec-work/` contient les outils et extractions temporaires de préparation ; il est hors du corpus normatif et de l’archive à fournir.

## Contraintes acquises

- Hébergement web simple sans VPS. API PHP/Laravel et MySQL ; frontend Vue 3/TypeScript/Tailwind construit en PWA statique, sans serveur Node permanent.
- Enregistrement patients et facturation **hors-ligne dès L0** : stockage IndexedDB chiffré, PDF locaux, numéros pré-alloués, droits de facture et quotas stock, reprise idempotente. Lire le fichier 19 avant de concevoir la caisse.
- Application privée en français, épurée et responsive sur sous-domaine du site existant ; XAF et Africa/Douala proposés d’après les sources.
- Identité durable du patient, visites et consultations distinctes, historique jamais écrasé.
- Pièces financières immuables, reçu pour chaque encaissement, paiements/avoirs/remboursements distincts, autorisations gérante et audit.
- Première livraison ciblée sous deux semaines ; CRM/Google/Meta/atelier complet/showroom dans les lots suivants, sans retirer leur conception globale.

## Lire avant coder

01 périmètre, 02 fonctions, 03 finance/stock, 04 UX, 05 architecture, 06 MCD, 07 données, 08 API, 09 sécurité, 11 impressions, 13 planning, 14 recette, 19 hors-ligne, 20 MLD, 21 invariants, 22 plan détaillé, 23 contrats d’ingénierie, 24 tâches et 25 gates ; `database/README.md`, SQL complet et rapport MySQL. Lire 10 avant intégrations. Le fichier 17 lie chaque exigence à sa source et son test.

Tous les domaines/tables sont définis maintenant : la priorité L0 appartient au planning, pas à la portée du modèle. Créer les migrations Laravel à partir du catalogue/SQL complet en conservant noms, PK/FK, CHECK, colonnes générées, vues et guards utiles. Ne pas remplacer 117 tables intégrales par un modèle partiel centré patients/factures, ni considérer L1–L3 comme des structures à découvrir après l’urgence.

La définition canonique est `database/tools/model_definition.py`; MLD/dictionnaire/DDL/catalogue sont générés ensemble. Maintenir l’alignement de ces artefacts et des migrations. Le SQL est testé sur MySQL 8.4.9 ; l’application, chiffrement/PWA, rôles et fournisseurs restent à valider. Tests SQL seulement sur base isolée, jamais en production.

## Façon de travailler

Suivre le graphe de `specifications/developpement/backlog.json` et les fiches 24, dans l’ordre 22. Initialiser le journal d’exécution ; prendre la première tâche prête et référencer les preuves avant DONE. Le backlog est un plan, tous ses statuts initiaux sont NOT_STARTED. Ne pas régénérer le plan pour effacer un suivi commencé.

Obtenir d’abord G0 (base entière), puis G1 (tranche réellement hors-ligne, persistante après fermeture/reprise, avec patient/facture/deux paiements/PDF et ACK rejouable). Développer le reste de L0 par tranches jusqu’à G4 ; G1 n’est pas la recette L0. Réestimer le délai à J2/J4 sur les preuves obtenues. Des écrans CRUD sans règles de paiement ou une PWA qui affiche seulement des pages en cache ne satisfont pas L0.

Appliquer les choix 23 : adapter User à login/password_hash/ULID ; séparer tables techniques Laravel ; conserver les guards et enfants avant finalisation ; ne jamais UPDATE une facture émise pour changer son solde/version. ledger_version est une projection distincte. Un journal appareil commun, des compartiments par droits et la publication pull transactionnelle sont requis. I00 construit le service de réservation avant l’émission. Route directe, sync et job appellent les mêmes actions ; PDF et notifications suivent le commit.

Utiliser une caisse offline principale au départ. Les autres postes ne modifient pas librement le même solde. Client et serveur passent les mêmes vecteurs de calcul. Les mutations locales et centrales sont atomiques ; aucune commande pendante n’est effacée avant ACK. Ne jamais renuméroter un reçu à sa synchronisation.

Pour les questions sans réponse, utiliser les hypothèses du fichier 15 pour avancer sur données fictives. Ne pas inventer taxes, prix, identité légale, diagnostic, consentement ou compatibilité d’hébergement. Un choix routine autorisé ne nécessite pas une nouvelle demande de confirmation. Documenter les vrais blocages avant production et les écarts de planning, en conservant D05/D06.

Vérifier permissions serveur et locales, données cliniques, montants, concurrence, quota/numéros, offline après redémarrage, restauration et PDF. Tests pertinents au fichier 14. MySQL réel pour verrous/concurrence, Vitest pour règles TS et Playwright pour coupure/reprise. Les intégrations utilisent des doubles de test sans message réel aux patients pendant développement.

Livrer code/migrations/build documentés, compte de démonstration fictif sans mot de passe universel en production, instructions d’installation sur hébergement simple, journal/tâches/preuves/gates et couverture EE/AT. Ne pas déclarer en ligne ce qui est seulement sauvegardé localement, ou livré un module qui reste simulé. Les scripts/tests du fichier 25 sont à créer et exécuter ; le PASS de cohérence du plan n’est pas un PASS applicatif.
