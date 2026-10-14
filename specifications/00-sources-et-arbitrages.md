# Sources et arbitrages

## Documents de référence

| Code | Document | Rôle |
| --- | --- | --- |
| PC | `Proposition_Commerciale_Finale_Eagle_Eye_Optics_Medical.docx`, avec son PDF de 8 pages | Périmètre commercial et écosystème validés selon le demandeur |
| CDC | `cahier_des_charges_optique.docx` | Exigences opérationnelles, contrôle financier, stock, utilisateurs et communication |
| DP | `Modele_dossier_patient_Eagles_Eye_Optics_Medical.docx` | Définition détaillée du dossier patient, des champs et de l’interface clinique |
| U | Demande et précisions du 14 octobre 2026 dans ce chat | Priorité patients/facturation sous deux semaines ; sous-domaine ; hébergement web simple sans VPS ; hors-ligne dès L0 ; design épuré et responsive ; socle complet pour Claude |
| CTX | `Proposition d'automatisation pour cabinet d'optique.pdf`, 109 pages | Contexte historique uniquement, sans autorité sur les exigences |

Le DOCX et le PDF PC ont été lus. Les modules, montants et échéances structurants concordent. Ce socle utilise les sections du DOCX comme références stables ; le PDF aide à retrouver la présentation commerciale. La validation mentionnée provient de U : les zones de signature visibles dans les documents fournis ne prouvent pas à elles seules une signature effective.

Les extractions `sources/PC.docx.md`, `sources/PC.pdf.md`, `sources/CDC.docx.md` et `sources/DP.docx.md` conservent les paragraphes et tableaux. `P018` désigne le paragraphe `[018]` de l’extraction DOCX, pas une page Word. Les références aux sections restent intelligibles dans les originaux. `sources/manifest.json` fournit les empreintes SHA-256.

## Ordre d’interprétation

U fixe l’ordre de livraison. PC conserve le périmètre commercial. CDC précise les règles transversales. DP précise les champs cliniques et les parcours de saisie. Une précision récente complète une formulation moins détaillée, sans faire disparaître une exigence d’un autre domaine. Un conflit non tranché est inscrit au fichier 15 ; une proposition technique reste identifiable comme telle.

Le contexte Gemini contient notamment des pistes de remplacement de Kommo et des architectures alternatives. Elles ne sont pas retenues comme décisions du client. Kommo et Google Calendar demeurent dans le périmètre complet PC.

## Arbitrages explicites

| Sujet | Constat | Décision de spécification |
| --- | --- | --- |
| Planning | PC prévoit 8 semaines, métier en S3 et IA en S2 ; U demande le métier sous 2 semaines | Livrer L0 patients et caisse d’abord ; garder un horizon de 8 semaines à replanifier |
| Prix | PC autorise l’ajustement manuel des prix ; CDC le refuse à l’accueil et limite les corrections | Seule la gérante ajuste le prix ou autorise une remise ; le prix d’une facture émise est figé |
| Frais d’examen | CDC accueil doit encaisser, alors que le rôle accueil n’est pas une caissière générale | Permission spéciale `exam_fee.collect`, limitée à la prestation examen, attribuable explicitement à l’accueil |
| Livraison avec dette | CDC décrit une remise après acompte ; PC situe le paiement final à la livraison | Autoriser la livraison avec dette sous validation de la gérante ; toujours enregistrer facture, dette et sortie |
| EP et DP | PC mentionne EP OD/OG, DP et hauteur ; DP précise DP totale ou monoculaire | Unifier EP/DP comme écart pupillaire en mm ; conserver total, OD, OG et hauteur OD/OG |
| Indices des verres | DP liste 1.50, 1.56, 1.59, 1.60, 1.67, 1.74 ; PC cite aussi 1.61 | Référentiel contenant l’union des valeurs et une option Autre |
| Suivi | DP propose 3/6 mois et 1/2 ans ; CDC ajoute 10–14 jours après collyre | Proposer tous ces délais et une date personnalisée ; distinguer contrôle recommandé et RDV confirmé |
| Statut clinique | DP juxtapose des états de travail, d’orientation et de RDV | Stocker trois dimensions séparées, afficher les libellés DP sans contradictions |
| Confidentialité | PC décrit nom/téléphone/motif dans Google ; CDC demande accès médical restreint | Exposer à Google un motif administratif générique ; le détail clinique reste dans l’application |
| Site public | PC inclut un showroom ; U indique un site existant | Application privée au sous-domaine ; prévoir une évolution ou un raccordement du site public en L3 |
| Historique | Modèle et CDC interdisent pertes et suppression d’historique | Corrections versionnées ; aucune suppression physique de consultations finalisées ou de pièces émises |
| Hébergement | PC indique infrastructure existante ; U exclut le VPS | API PHP/MySQL et ressources PWA statiques sur hébergement web simple compatible ; aucun Node permanent requis |
| Hors-ligne | CDC §10 indique « à confirmer » ; U le rend indispensable à J14 | PWA avec persistance locale et synchronisation dès L0, numéros pré-alloués et caisse à propriétaire unique ; voir fichier 19 |
| Saisie automatique | Sources décrivent catalogue, calculs, numéros et génération | Automatiser ces opérations ; la saisie des montants reçus et données cliniques reste humaine |

L’OCR de factures papier, l’import automatique d’appareils médicaux, la passerelle de paiement mobile money et un portail patient public ne sont pas explicitement demandés. Ne pas les inventer comme acquis ; les montants mobile money sont enregistrés et rapprochés en L0, pas débités par l’application.

## Engagements commerciaux conservés

PC §4 : création et intégration 400 000 FCFA ; licences annuelles CRM et IA 324 000 FCFA ; total 724 000 FCFA. PC §6 : échéances de création 50/30/20 %, garantie technique 3 mois après recette finale, propriété des données, domaine et contenus après règlement intégral, confidentialité. La formation vidéo smartphone est incluse dans PC §3 module 5.

Ces chiffres sont ceux de la proposition fournie, pas des tarifs fournisseurs vérifiés aujourd’hui. Les fonctions supplémentaires détaillées par CDC et DP, la compatibilité d’hébergement et les coûts Meta/SMS peuvent nécessiter une discussion commerciale. Le présent dossier ne modifie ni prix ni conditions et ne déduit pas une propriété du code source qui n’est pas explicitement décrite.

Le jalon urgent L0 ne suffit pas à déclencher automatiquement l’échéance commerciale de 30 %, qui, dans PC, exige également un agent IA WhatsApp opérationnel en fin de mois 1. Faire confirmer le rapprochement des jalons techniques et contractuels.
