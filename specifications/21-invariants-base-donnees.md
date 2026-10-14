# Invariants de la base et responsabilités de l’application

Le SQL définit l’application entière. Il garantit types, liens, unicités et protections de pièces installées ; il ne constitue pas le code complet des commandes métier, des droits ou de la synchronisation. Ce fichier indique exactement ce que Claude doit contrôler dans ses transactions et tests, sans attribuer aux CHECK MySQL des capacités qu’ils n’ont pas.

## Autorité et génération

[MCD](06-modele-conceptuel.md), [modèle relationnel](20-modele-relationnel.md), [dictionnaire](07-dictionnaire-donnees.md), [catalogue JSON](../database/schema_catalog.json) et [SQL](../database/01_schema.sql) décrivent le même modèle entier. Les scripts ne filtrent aucune table selon L0/L1/L2/L3. Le planning 13 fixe uniquement l’ordre de développement/activation. La structure serveur MySQL n’est pas un schéma IndexedDB à copier sans chiffrement : la PWA conserve des projections autorisées et commandes du même domaine.

## Garanties structurelles présentes

| Domaine | Garantie du SQL |
| --- | --- |
| Relations | FK indexées avec cabinet ; liens vers une autre clinique refusés ; suppressions en cascade absentes |
| Identité | Numéro de dossier unique par cabinet, téléphone non unique, absence de naissance/contact justifiée |
| Clinique | Acuités contexte/distance/correction/œil distinctes ; stages réfraction distincts ; codes des sélections normalisés |
| Prescriptions | Origine interne avec consultation ou externe avec provenance ; même patient ; séries/versions distinctes |
| Finance | Quantités/montants exacts, calcul de ligne TTC vérifié, reçu unique par paiement ; références note/ligne/facture cohérentes |
| Atelier | Même patient entre ligne facturée et prescription de fabrication ; spécifications OD/OG figées pour labo |
| Stock | Même article entre ligne/réserve/position ; quota de même position/lot ; livraison liée à la bonne réserve/ligne/lot |
| Mouvements | Article source de réception/retour et ligne/position/lot remis cohérents ; signe/origine/motif requis |
| Effets d’une commande | Plusieurs mouvements possibles pour une commande, rang stable et unique `(clinic_id,idempotency_id,movement_position)` |
| Activité exclusive | Une session ouverte par caisse/caissière ; un propriétaire financier actif ou en fermeture par facture |
| Consentement | Flux d’événements ordonné par sujet, destination exacte, canal et finalité, même si sujet patient/prospect est nullable |
| Historique | Triggers du script 04 refusent modifications/suppressions de snapshots et pièces finalisés ; les enfants de pièces émises sont protégés |
| Grants | Champs signés immuables ; révocation conservée, nouvelle autorisation au lieu de réécriture |

L’installation du script 04 requiert le droit TRIGGER. Sans lui, ne pas prétendre bénéficier de ces gardes SQL : choisir une offre web simple qui le permet ou documenter un mécanisme équivalent de privilèges/contrôles avant production. Aucun VPS n’est imposé.

## Contrôles transactionnels obligatoires

| Commande | Vérifications avant commit | Preuve à fournir |
| --- | --- | --- |
| Créer/modifier patient | Nom réel, date non future, normalisation téléphone, éventuel doublon sans fusion automatique | AT03/04/36 ; deux téléphones partagés acceptés |
| Démarrer examen | Frais de cette visite réellement confirmés ou dérogation gérante exacte ; preuve locale/serveur | AT05/06 ; un achat de monture ne débloque pas l’examen |
| Finaliser/corriger clinique | Aucun connu exclusif, Autre expliqué, valeurs non mesurées conservées, auteur/motif, snapshot complet dans la même transaction | AT18–21/42 ; révisions antérieures intactes |
| Valider prescription | Saisie explicite, axe avec cylindre non nul, DP/tolérance justifiée, capacités professionnelles réelles et source externe identifiée | AT20/21 ; aucune prescription IA ni examen fictif |
| Émettre facture/avoir | Lignes non vides, total égal somme, régime/mentions validés, droits prix, snapshot et numéro admissible | AT07–09/14/37 ; diagnostic I01/I05 |
| Encaisser | Facture émise, montant ≤solde actuel verrouillé, droit de poste/epoch, actualité de la version et idempotence | AT10–12/38/39 ; paiement et reçu atomiques |
| Contre-écrire | Original confirmé, cumul ≤original, erreur comptable documentée, gérante/hash exact | AT13 ; original conservé, diagnostic I04 |
| Avoir/rembourser | Cumul valeur/quantité ≤ligne originale, crédit à rembourser avant opération, approbation consommable une fois, session applicable | AT14 ; zéro remboursement au-delà du crédit |
| Réserver/remettre | Catalogue de la ligne, état stock, quantité non remise, lot vendable non expiré au jour local, réserve/quota non doublement soustraits | AT15–17/40 ; stock non négatif |
| Rentrer un retour | Quantité ≤remise non déjà retournée, même article/lot, inspection et destination justifiée, lien financier éventuel indépendant | AT17 ; avoir seul n’augmente pas le stock |
| Réception/inventaire | Fournisseur/origine/coût connus ou inconnus explicitement, emplacement/lot correspondants, comptage figé, écart validé | AT30 ; un mouvement par effet stable |
| Atelier | Prescription/version et caractéristiques cohérentes, labo, contrôle qualité, remise réellement confirmée avant Livré | AT31 ; notification Prêt idempotente par révision |
| Agenda/public booking | Sujet identique, service/ressource/créneau exacts, durée/horaires, non-chevauchement sous verrou et disponibilité externe récente | AT23/32/33 ; aucune confirmation offline supposée |
| Relance/message | Destinataire du suivi/RDV/facture/atelier exact, numéro vérifié, dernier consentement accordé même canal/finalité, template/version/compte appropriés | AT34/35 ; révocation honorée juste avant envoi |
| Campagne/publication | Cible figée validée et consentement revérifié ; média/droits/publication approuvés, aucune donnée médicale/stock privé exposée | AT34/35 ; ancien numéro non repris sans consentement |
| Préparer poste offline | Plages sans chevauchement sous verrou de séquence, quotas exacts, sole writer et versions/grants signés | AT36–43 ; I15/I16 détectent anomalies |
| Synchroniser | Signature/schéma/hash/contexte d’autorisation, séquence, parent connu, calculs et allocation ; ACK atomique, conflits intacts | AT38/42/45 ; aucun dernier écrit gagnant |
| Transférer/reprendre poste | Dernières commandes récupérées, droit fermé avant nouveau epoch, numéros passés conservés et nouvelles plages sur matériel remplacé | AT39/44 ; aucun clone actif de plages |

Les sommes et contraintes d’intervalle portent sur plusieurs lignes ; MySQL CHECK ne peut pas effectuer ces sous-requêtes. Les FK permettent des relations sûres mais ne calculent pas un solde et ne vérifient pas une signature. Les contrôles sont dans les actions de domaine et dans le moteur local, avec les mêmes vecteurs de référence et des tests de concurrence.

Le catalogue/tarif/taxe préparé pour un grant hors-ligne est conservé côté serveur comme snapshot privé immuable (fichier `stored_files` identifié par son hash, ou contenu figé du payload signé). Le grant lie explicitement cette version et son hash ; le moteur recalcule les pièces locales sur cette version autorisée, sans remplacer leurs prix par le tarif courant. Conserver le snapshot tant que des grants/pièces le référencent ; une simple chaîne `catalog_version` sans contenu vérifiable ne suffit pas.

## Vues de gestion

`vw_invoice_balances` agrège séparément paiements, contre-écritures, avoirs et remboursements : aucune multiplication des montants par une jointure de plusieurs lignes. Solde signé = facture −avoir −paiements +contre-écritures +remboursements. Dette et crédit à rembourser affichés séparément.

`vw_revenue_events` exclut reprises papier et leurs avoirs du CA courant. `vw_collection_events` date chaque encaissement/correction/remboursement au fait correspondant. Les bornes jour/semaine/mois sont converties du fuseau du cabinet vers UTC en PHP ; les vues ne dépendent pas d’une table MySQL de fuseaux disponible sur un mutualisé.

`vw_stock_position_balances` et `vw_stock_lot_balances` recalculent physique, réservations centrales et quotas locaux. Une réserve sous quota est déjà incluse dans le quota, sans deuxième soustraction. Les projections stock_positions/stock_lots sont mises à jour dans la même transaction et rapprochées après commit.

`vw_cash_session_totals` conserve le total de clôture figé et montre à part les contre-écritures ultérieures. Corriger une ancienne erreur comptable ne réécrit pas une session close et ne prétend pas à une sortie d’espèces. Les sommes recomputées et les snapshots de clôture ont des objectifs différents ; toute correction post-clôture est expliquée.

`vw_current_communication_consents` retourne le dernier événement d’un flux, qui peut être REVOKED. Le code doit exiger GRANTED pour le destinataire exact avant l’envoi. `vw_device_sync_health` voit seulement les commandes déjà reçues ; il ne peut compter celles restant sur un poste non connecté.

Les vues couvrent plusieurs cabinets et ne constituent pas une politique d’accès au personnel. L’API filtre le cabinet, le rôle et la ressource ; un compte SQL d’application unique ne remplace pas les comptes individuels du logiciel.

## Diagnostics et validation

[05_integrity_checks.sql](../database/05_integrity_checks.sql) exécute des diagnostics en lecture seule après les transactions. Chaque résultat devrait être vide. Un diagnostic ne prévient pas l’erreur à lui seul : l’action doit la refuser avant commit. Les champs polymorphes audit/outbox/external_links/registre de numéros sont validés contre une liste de types admis et une ressource du même cabinet par leur adaptateur.

L’import MySQL et les tests de contraintes sont documentés dans le rapport de validation. Ils prouvent l’exécutabilité du schéma et les invariants testés ; ils ne prouvent pas la réalisation de l’application PWA, des messages fournisseurs, de la sécurité complète ou de toutes les transactions concurrentes. Ces preuves restent à obtenir dans la recette applicative 14.
