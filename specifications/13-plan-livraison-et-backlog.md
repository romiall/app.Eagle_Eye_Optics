# Plan de livraison et backlog

Ce fichier présente les regroupements et échéances. Le [plan détaillé 22](22-plan-developpement-detaille.md), les [contrats 23](23-contrats-ingenierie.md), les [fiches 24](24-taches-developpement.md) et les [gates 25](25-validation-et-bascule.md) fixent maintenant l’exécution. Le [backlog JSON](developpement/backlog.json) porte le graphe des dépendances ; les tickets ci-dessous restent les regroupements de livraison et leur correspondance est fournie en 24.

## Cadence proposée

Le modèle conceptuel/relationnel et le SQL sont définis pour toute l’application. Les tickets suivants ordonnent le développement des comportements/écrans, sans limiter les domaines du schéma. Les migrations suivent immédiatement le modèle intégral de référence.

Objectif L0 : 14 jours calendaires après démarrage effectif, environ 10 jours ouvrés selon calendrier convenu. Si lancement le 14 octobre 2026, cible indicative le 28 octobre 2026. Une date ferme dépend des accès, disponibilités et décisions du fichier 15. Le plan est une estimation de travail, à recalibrer à J2 sur une tranche réellement terminée.

Le socle vise un développeur expérimenté assisté par Claude et des validations rapides. Le hors-ligne confirmé exige une tranche G1 visée dès J2 et ajoute une difficulté réelle ; J14 reste une cible à recalibrer à J2/J4 sur les preuves et tâches restantes. Ne pas le reporter silencieusement. Les huit semaines PC restent l’horizon global, avec ordre des modules et jalons commerciaux à ajuster. G1 démontre un parcours, G3 reçoit tout L0, G4 autorise sa bascule réelle ; ces étapes ne sont pas interchangeables.

## Découpage de L0

| Jour de travail | Livrable vérifiable | Dépendances |
| --- | --- | --- |
| J1 | Audit hébergement web simple, projet Laravel/API + Vue/PWA, CI/DB, droits et coffre IndexedDB | Accès et données de départ demandés |
| J2 | Prototype patient hors-ligne après redémarrage, plage numéros, commande/ACK rejouable, schéma local | Enrôlement et règles de sync testés |
| J3 | Catalogue, facture brouillon/émission et numéros, snapshots | Prix/taxes/mentions validés pour production |
| J4 | Paiements/reçus PDF locaux, soldes, propriétaire unique et reprise idempotente | Règles 03 et 19, imprimante |
| J5 | Quotas de stock, réserve/remise, gérante, avoir/remboursement et export local | Stock réel, numéros/droits pré-alloués |
| J6 | Parcours clinique complet : motif, antécédents, acuités et réfraction | Échelles et convention de saisie |
| J7 | Prescription/médicaments/orientation, historique et correction versionnée | Modèles d’impression et professionnels |
| J8 | Agenda/suivi interne, statistiques tableaux, caisse et audit | Horaires, durée RDV, rappels |
| J9 | Recette réseau coupé/reprise/deux postes/stock, mobile/PDF, restaurations serveur et locale | Disponibilité gérante/utilisateurs |
| J10 | Corrections de recette, import contrôlé, formation et bascule | Zéro défaut bloquant et accès production |

À J4, une démonstration patients → facture → acompte → reçu → paiement du solde doit déjà fonctionner sur données fictives. À J7, démontrer un dossier clinique conforme aux champs DP. Ce sont des jalons internes : aucune mise en production incomplète n’est annoncée comme recette de L0.

Si l’évaluation J2 montre que tous les critères L0 ne tiendront pas, réduire les raffinements de présentation, les graphiques et les imports historiques non urgents, puis proposer une date révisée ou une bascule progressive explicite. Ne pas enlever les droits, reçus, règles de paiement, champs requis par DP, sauvegardes ou tests critiques sans faire apparaître la modification de périmètre.

## Backlog L0

| Ticket | Contenu | Dépendances | Recette |
| --- | --- | --- | --- |
| L0-01 | Structure projet, auth, politiques, cabinet, CI | Audit stack | AT01 AT02 |
| L0-02 | Patients, numéros, recherche/doublons | L0-01 | AT03 AT04 |
| L0-03 | Visites, file, examen payé/dérogation | L0-02 L0-05 | AT05 AT06 |
| L0-04 | Catalogue et édition brouillon | L0-01 | AT07 |
| L0-05 | Factures émises, taxes validées, numéros/PDF | L0-02 L0-04 | AT08 AT09 |
| L0-06 | Paiements/reçus/impayés/concurrence | L0-05 | AT10 AT11 AT12 |
| L0-07 | Approbations/avoirs/erreurs/remboursements | L0-06 | AT13 AT14 |
| L0-08 | Stock de départ, lots, réserve/remise/retour | L0-04 L0-05 L0-07 | AT15 AT16 AT17 |
| L0-09 | Consultation DP complète et saisie par étapes | L0-02 L0-03 | AT18 AT19 |
| L0-10 | Prescriptions/PDF/versions/historique | L0-09 | AT20 AT21 |
| L0-11 | Agenda, suivi/calculs/tâches internes | L0-09 | AT22 AT23 |
| L0-12 | Dashboards, stats DP/tableaux, caisse simple | L0-06 L0-09 L0-11 | AT24 AT25 |
| L0-13 | Migration dettes, export et backups | L0-05 L0-06 | AT26 AT27 |
| L0-14 | UX/mobile/impression et formation/bascule | Tous parcours | AT28 AT29 |
| L0-15 | PWA/coffre, droits/plages/propriétaire/quota, sync/recovery | L0-01, intégré dès premier ticket métier | AT36 à AT45 |

Les références entre tickets ne prescrivent pas un travail en cascade complète : une action clinique et la caisse peuvent être développées par tranches sur le même socle. La vérification finale reste séquentielle sur un parcours complet.

## Lots suivants

| Ticket | Lot | Contenu | Prérequis |
| --- | --- | --- | --- |
| L1-01 | L1 | Fournisseurs/réceptions, seuils, alertes, inventaires, valorisation | Stock L0 fiable |
| L1-02 | L1 | Atelier complet, bon labo, contrôle qualité, reprise | Vente/prescription liées |
| L1-03 | L1 | Clôture avancée, dépenses, rapprochement MoMo/virements, export comptable convenu | Écritures L0 et besoins comptable |
| L1-04 | L1 | Comparaisons visuelles, stats détaillées, fusion patient contrôlée | Historique et qualité des données |
| L2-01 | L2 | Google Calendar bidirectionnel, blocs et réconciliation | Comptes OAuth et agenda dédié |
| L2-02 | L2 | Kommo officiel et mapping prospects/patients | Licence, pages et numéro |
| L2-03 | L2 | Assistant FAQ/qualification/booking/reprise humaine | L2-01 L2-02, FAQ et prix publics validés |
| L2-04 | L2 | Templates, consentements, rappels et atelier prêt | Meta opérationnel et politiques |
| L2-05 | L2 | Campagnes ciblées/opt-out/SMS/supervision | Fournisseurs et budget confirmés |
| L3-01 | L3 | Audit et raccordement site/showroom/booking | Site et L2-01 |
| L3-02 | L3 | Emails, guide/support, formation vidéo smartphone, recette finale | Tous livrables PC concernés |
| EXT-01 | Ultérieur si demandé | Plusieurs caisses hors-ligne et protocoles de transfert étendus | L0 hors-ligne prouvé et besoin multi-caisse confirmé |

## Conditions de passage

Un ticket terminé comprend code, migration non destructive, permissions, erreurs d’interface, tests pertinents et documentation de configuration. Le fichier 24 précise les fichiers/actions attendus et 25 les preuves. Une démonstration avec données fictives ne vaut pas preuve de fonctionnement sur l’hébergement client.

L0 est livrable quand tous les scénarios L0 du fichier 14 passent, que les accès et le catalogue réel sont vérifiés, et que sauvegarde/restauration et formation ont été réalisées. Un canal externe non activé doit être identifié comme tel. L2 est reçu après messages de test autorisés, synchronisation et reprise humaine vérifiées sur les comptes réels.

L’ordre des lots ne modifie pas automatiquement les factures du prestataire : confirmer avec la gérante comment la livraison urgente s’articule avec les échéances PC.
