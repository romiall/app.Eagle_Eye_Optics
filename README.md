# Eagle’s Eye Optics Medical — socle de l’application

Ce dépôt contient la conception complète de l’application métier, le plan de développement pour Claude et le code applicatif en cours de réalisation (Laravel 13 et PWA Vue 3). L’état réel des tâches, des preuves et des gates est tenu dans [le journal d’exécution](docs/implementation/progress.md) ; aucune fonction n’y est annoncée livrée sans preuve. La priorité de livraison porte sur les patients, la facturation, les reçus et l’impression réellement hors ligne, sur un hébergement web classique sans VPS.

## Démarrer

1. Lire [CLAUDE.md](CLAUDE.md) et l’[index des spécifications](specifications/README.md).
2. Suivre le [plan détaillé](specifications/22-plan-developpement-detaille.md), les [contrats d’ingénierie](specifications/23-contrats-ingenierie.md) et les [79 tâches](specifications/24-taches-developpement.md).
3. Utiliser le [prompt Claude](specifications/16-demarrer-dans-Claude.md) et tenir le [journal de travail](specifications/developpement/JOURNAL.md).
4. Appliquer les [critères de validation et de bascule](specifications/25-validation-et-bascule.md). Les statuts initiaux du backlog sont NOT_STARTED.

## Modèle et base

Le [MCD](specifications/06-modele-conceptuel.md), le [MLD](specifications/20-modele-relationnel.md), le [dictionnaire](specifications/07-dictionnaire-donnees.md) et le [SQL complet](database/eagle_eye_optics_complet.sql) couvrent 117 tables et 14 domaines. Consulter [le guide d’import](database/README.md) avant toute installation ; le script vise une base vide et ne remplace pas les migrations applicatives futures.

Le schéma a été importé sur MySQL 8.4.9 ; 41 assertions SQL ont passé. [Le rapport](database/VALIDATION.md) indique les preuves et leurs limites. Les tests applicatifs, PWA et intégrations restent à exécuter pendant le codage.

## Vérifier le plan localement

```text
python specifications/developpement/verifier_plan.py
```

Cette vérification contrôle le graphe, la couverture des 46 exigences/45 scénarios et la conservation du SQL validé. [Le rapport du plan](specifications/developpement/validation-plan.json) distingue cette vérification documentaire de la recette applicative.

La définition des tâches est dans [construire_backlog.py](specifications/developpement/construire_backlog.py). Sa régénération remet les statuts initiaux : conserver le suivi d’exécution séparément avant de l’utiliser.

## Références

Les trois documents de référence sont disponibles en [extractions textuelles et empreintes](specifications/sources/README.md). Les originaux sont conservés dans le dossier de travail du prestataire. L’historique Gemini sert seulement de contexte. Les arbitrages et informations nécessaires avant production restent explicites dans les fichiers 00 et 15.

L’[archive complète](Socle_Eagle_Eye_Optics_2026-10-14.zip) permet de remettre le socle à un agent de développement. Aucun prix ni jalon commercial n’est modifié par le planning technique.
