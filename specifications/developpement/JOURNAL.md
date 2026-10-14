# Journal d’exécution à initialiser par Claude

Ce fichier est un modèle de suivi. Aucun ticket applicatif n’est commencé dans le dossier de spécifications. Copier ou tenir ce journal dans docs/implementation/progress.md et mettre à jour les statuts du backlog de travail sans déclarer les tâches DONE avant preuve.

## État courant

- Build/commit : à renseigner.
- Lot et gate visé : L0, G1.
- Tâche courante : F01.
- Dernière preuve acquise : validation SQL existante, pas une recette applicative.
- Tâches DONE : aucune.
- Blocages externes : Q01–Q04/Q14 à vérifier pour production ; développement fictif possible.
- Prochaine action concrète : inventaire local et initialisation applicative selon 22/23.

## Compte rendu d’une tâche

| Champ | Valeur à renseigner |
| --- | --- |
| Tâche / statut | ID, NOT_STARTED → IN_PROGRESS → IN_REVIEW → DONE, ou BLOCKED |
| Objectif | Critère de sortie concret |
| Fichiers modifiés | Chemins et raison |
| Contrats / tables concernés | Commandes, événements, version, données |
| Tests réellement exécutés | Commande, environnement, date, résultat et preuve |
| Défauts ouverts | Impact, correction prévue et gate bloqué |
| Décision nouvelle | Motif/fait constaté/impact ; référence du registre 15 |
| État local avant interruption | Code non validé, tests restant à exécuter, aucune donnée sensible |
| Reprise | Première action à exécuter, sans recommencer le plan |

## Preuve d’un gate

Build et environnement exacts ; tâches prérequises DONE ; liste de scénarios ; attendu/observé ; preuves ; défauts ; décisions externes nécessaires. Une absence de réponse du client, un délai écoulé ou une simulation ne vaut pas recette, configuration réelle ni autorisation de mise en production.

## Gestion des changements

Ne pas régénérer le backlog canonique pour effacer un suivi en cours. Conserver la définition initiale et les statuts/preuves de travail séparés, ou reporter les évolutions explicitement. Après reprise de contexte, lire ce journal, le backlog de travail et les modifications non validées, puis poursuivre la tâche courante.
