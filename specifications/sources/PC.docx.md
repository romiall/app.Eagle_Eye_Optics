# Proposition_Commerciale_Finale_Eagle_Eye_Optics_Medical.docx

[TABLE 1]
R01: CONSEIL EN INGÉNIERIE DIGITALE / PROPOSITION COMMERCIALE / & TECHNIQUE DÉTAILLÉE / 

[TABLE 2]
R01: RÉFÉRENCE DU DOSSIER || PEGH-STARTUP-2026-EEOM-0012
R02: DATE D'ÉMISSION || 1er septembre 2026
R03: PROJET || Automatisation commerciale, intelligence artificielle de booking, application métier d'optique & showroom web
R04: BÉNÉFICIAIRE || EAGLE'S EYE OPTICS MEDICAL
R05: À L'ATTENTION DE || Madame la Directrice Générale
R06: LOCALISATION || Rue Ancien Cinéma Étoile, Marché Congo, Douala – Cameroun
R07: DURÉE D'EXÉCUTION || 2 mois — déploiement opérationnel dès le mois 1

[001] PEGH-STARTUP  ·  2026

[TABLE 3]
R01: 01   RÉSUMÉ EXÉCUTIF & DIAGNOSTIC DU CABINET

[002] 1.1 — DIAGNOSTIC OPÉRATIONNEL DU CABINET

[003] EAGLE'S EYE OPTICS MEDICAL est un acteur de santé visuelle et de dermocosmétique en plein développement à Douala. L'activité repose sur un triptyque à forte valeur ajoutée :

[004] 1  L'examen clinique de réfraction et le dépistage des troubles visuels.

[005] 2  Le conseil, la taille, le montage et la vente d'équipements optiques et solaires.

[006] 3  Le conseil et la distribution de produits dermocosmétiques spécialisés.

[007] L'observation directe du fonctionnement du cabinet et l'analyse de son écosystème commercial mettent en évidence quatre goulets d'étranglement majeurs :

[008] Perte de conversion sur les canaux digitaux — les campagnes publicitaires sur Meta (Facebook/Instagram) drainent des prospects vers WhatsApp et Messenger ; en l'absence de réponse immédiate et qualifiée 24h/24, une proportion significative de prospects abandonne ou s'oriente vers la concurrence.

[009] Taux de rendez-vous non honorés (No-Show) — les rendez-vous pris par téléphone ou messages simples ne font l'objet d'aucun rappel automatique programmé, encombrant inutilement les plages horaires d'examen de vue.

[010] Lourdeur administrative & double saisie — l'établissement manuel des devis, factures, fiches de réfraction et reçus d'acomptes monopolise un temps médical précieux qui devrait être consacré à l'examen clinique et à la vente de verres à forte marge.

[011] Absence d'un outil métier unifié — les informations sont fragmentées entre carnets papier, tableurs ou messages épars, rendant complexe le suivi des encaissements d'acomptes, la relance des soldes impayés et la traçabilité des commandes de verres chez les laboratoires verriers.

[012] SITUATION ACTUELLE → SITUATION CIBLE

[TABLE 4]
R01: SITUATION ACTUELLE || SITUATION CIBLE
R02: ✕  Réponses manuelles lentes sur WhatsApp || ✓  Agent IA actif 24/7 (FAQ & booking)
R03: ✕  Prise de rendez-vous sur calepin ou agenda papier || ✓  Synchronisation Google Calendar en temps réel
R04: ✕  Taux élevé de « No-Show » aux examens || ✓  Rappels automatiques WhatsApp (J-1 / H-2)
R05: ✕  Saisie manuelle longue des factures || ✓  Facturation assistée en quelques clics
R06: ✕  Suivi approximatif des acomptes et de la caisse || ✓  Caisse automatisée & suivi des soldes
R07: ✕  Site web externe non finalisé || ✓  Portail showroom eagleeyeoptic.com connecté
R08: 02   ARCHITECTURE TECHNIQUE GLOBALE DU SYSTÈME

[013] La solution proposée bâtit un écosystème cohérent, articulé autour de trois couches technologiques interconnectées par des interfaces de programmation sécurisées (API & webhooks), complétées par une couche vitrine digitale en raccordement optionnel :

[TABLE 5]
R01: COUCHE 1   ACQUISITION & COMMUNICATION — FRONT-OFFICE CONVERSATIONNEL / ▪  Publicités Meta (Facebook / Instagram Ads) / ▪  WhatsApp Business API & Messenger connectés à Kommo CRM / ▪  Agent IA : réponses FAQ, qualification médicale & réservation

[014] ↓  Webhooks bidirectionnels

[TABLE 6]
R01: COUCHE 2   PLANIFICATION & SYNCHRONISATION — AGENDA DU CABINET / ▪  Google Calendar Pro (08h30 – 17h00) synchronisé en temps réel / ▪  Détection automatique des créneaux libres / occupés / ▪  Déclencheur des notifications et rappels WhatsApp anti-no-show

[015] ↓  API REST / base de données

[TABLE 7]
R01: COUCHE 3   MÉTIER, GESTION & COMPTABILITÉ — BACK-OFFICE D'ADMINISTRATION / ▪  Fiches patients & historique de réfraction (OD / OG / dioptries) / ▪  Suivi d'atelier (commande verres → taillage → prêt) / ▪  Facturation express (autocomplétion catalogue, acomptes & soldes) / ▪  Gestion de caisse & tableaux de bord financiers

[016] ↓  Raccordement optionnel

[TABLE 8]
R01: COUCHE 4   VITRINE & SHOWROOM DIGITAL — EAGLEEYEOPTIC.COM / ▪  Présentation des expertises optique & dermocosmétique / ▪  Catalogue digital interactif des montures et technologies de verres / ▪  Module de réservation directe synchronisé avec le calendrier central

[TABLE 9]
R01: 03   DESCRIPTION DÉTAILLÉE DES MODULES FONCTIONNELS

[017] MODULE 1  Moteur conversationnel IA & prise de rendez-vous omnicanale

[018] Ce module transforme les canaux de messagerie du cabinet en un centre d'accueil automatisé, capable de convertir un prospect curieux en un patient assis dans le cabinet d'examen.

[019] 1  Le visiteur contacte le cabinet via WhatsApp ou Messenger.

[020] 2  L'agent IA Kommo répond aux questions fréquentes et oriente la demande.

[021] 3  L'agent propose deux à trois créneaux disponibles.

[022] 4  Le prospect confirme le créneau retenu.

[023] 5  Le rendez-vous est enregistré simultanément dans Google Calendar Pro et dans le pipeline Kommo.

[024] 6  Rappels automatiques : WhatsApp à J-1 (18h00), puis à H-2 avant l'examen.

[025] Intégration omnicanale officielle — connexion de la ligne WhatsApp du cabinet et de la page Facebook à la plateforme CRM Kommo, avec réception unifiée de l'ensemble des discussions sur une interface claire, accessible sur smartphone et ordinateur.

[026] Agent IA entraîné au métier de l'optique — traitement autonome des questions fréquentes (localisation, horaires dès 08h30, tarifs indicatifs des examens, prise en charge des assurances, description des traitements de verres Blue-Cut, antireflet, photochromiques, progressifs) et qualification préliminaire du besoin (première consultation, renouvellement de monture, fatigue visuelle sur écran, contrôle d'ordonnance).

[027] Négociation et réservation autonome d'horaires — l'agent IA interroge l'agenda Google Calendar en direct, formule une proposition de créneaux et valide le choix du prospect par confirmation textuelle.

[028] Synchronisation bidirectionnelle Google Calendar — tout rendez-vous validé s'inscrit immédiatement dans l'agenda avec nom, téléphone et motif ; toute plage bloquée manuellement par la praticienne est automatiquement ignorée par l'agent IA, évitant les doubles réservations.

[029] Dispositif anti-no-show automatisé — rappel à J-1 (18h00) demandant la confirmation, puis rappel à H-2 avec localisation GPS et consignes pratiques pour fluidifier l'arrivée au cabinet.

[030] MODULE 2  Application métier back-office spécialisée optique

[031] Conçue pour remplacer la paperasse et les classeurs physiques, cette application web d'administration constitue le cœur opérationnel du cabinet.

[032] CYCLE DE TRAITEMENT DANS L'APPLICATION

[033] 1   Création du dossier patient & saisie réfraction

[034] –  Saisie des valeurs : Sphère, Cylindre, Axe, Addition, EP (OD / OG)

[035] –  Conservation de l'historique des prescriptions antérieures

[036] 2   Sélection équipement & commande atelier

[037] –  Choix de la monture (marque, modèle, référence interne)

[038] –  Choix du verre (indice 1.56 / 1.61 / 1.67, traitement Blue-Cut / HMC)

[039] –  Génération du bon de commande pour le laboratoire verrier

[040] 3   Facturation express & gestion des acomptes

[041] –  Autocomplétion intelligente des lignes de facture en deux lettres

[042] –  Encaissement de l'acompte (50 % ou 70 %) → édition du reçu officiel

[043] –  Enregistrement du mode de règlement (espèces / mobile money)

[044] 4   Suivi fabrication & clôture de facture

[045] –  Suivi des étapes : commandé → reçu → taillé → prêt

[046] –  Notification WhatsApp automatique de mise à disposition

[047] –  Règlement du reliquat à la livraison → facture soldée

[048] Dossier patient & fiche de réfraction complète — interface ergonomique standardisée pour l'œil droit et l'œil gauche (Sphère, Cylindre, Axe, Addition/Presbytie, écart pupillaire, hauteur de montage), avec archivage numérique sécurisé et consultation instantanée de l'historique visuel du patient.

[049] Module atelier & traçabilité des commandes verriers — suivi du cycle de vie de chaque équipement, de la commande transmise au verrier jusqu'à la livraison et à l'ajustement, avec déclenchement automatique d'un message WhatsApp dès que l'équipement passe au statut « prêt pour retrait ».

[TABLE 10]
R01: Commande transmise   →   Verres réceptionnés   →   Taillage & montage   →   Contrôle qualité   →   Livré & ajusté

[050] Facturation intelligente assistée par autocomplétion — base de données pré-remplie de l'ensemble des prestations, verres et montures ; la frappe de deux ou trois caractères (ex. « PROG » ou « BLUE ») affiche immédiatement les références correspondantes avec leurs prix, ajustables manuellement sans ressaisie.

[051] Gestion avancée des acomptes & rapprochement de caisse — gestion du versement initial et calcul automatique du solde restant dû, génération instantanée de reçus et factures acquittées au format PDF professionnel, suivi ventilé des paiements (espèces, MTN MoMo / Orange Money, virement) et tableau de bord du chiffre d'affaires journalier, hebdomadaire et mensuel.

[052] MODULE 3  Déploiement & Sécurisation sur l'Infrastructure Existante (annuel)

[053] PEGH-STARTUP capitalisera directement sur les actifs déjà acquis par le cabinet :

[054] Reprise et configuration de la zone DNS du nom de domaine existant eagleeyeoptic.com.

[055] Déploiement de l'application et de la base de données sur l'hébergement existant du client.

[056] Configuration des adresses emails professionnelles et des certificats de sécurité SSL/HTTPS. »

[057] Messagerie professionnelle sécurisée — création et configuration d'adresses emails personnalisées (direction@eagleeyeoptic.com, contact@eagleeyeoptic.com).

[058] Sécurité & confidentialité des données médicales — certificats de chiffrement SSL/TLS (HTTPS) et sauvegardes automatiques quotidiennes de la base de données patients et de la comptabilité.

[059] MODULE 4  Portail web vitrine & showroom digital (front-office)

[060] Module d'exposition commerciale déployé sous eagleeyeoptic.com.

[TABLE 11]
R01: SECTION DU SITE || CONTENU
R02: Entête || Navigation fluide, coordonnées du Marché Congo, accès direct à la réservation
R03: Hero banner || Positionnement santé visuelle, précision clinique & dermocosmétique — boutons « Prendre RDV en ligne » et « WhatsApp direct »
R04: Pôles d'expertise || Examens de réfraction, dépistage, lentilles de contact, traitements innovants de verres, espace dermocosmétique
R05: Showroom digital || Galerie de montures optiques et solaires (filtres par style/marque) avec bouton « Essayer ce modèle en cabinet »
R06: Module de booking || Choix de la prestation et de la date/heure disponible, enregistrement direct dans l'agenda Google + notification WhatsApp
R07: Pied de page || Horaires (08h30–17h00), plan Google Maps, réseaux sociaux Meta

[TABLE 12]
R01: POURQUOI CETTE OPTION EST DÉCISIVE FACE À UN SITE WEB TRADITIONNEL / Un site vitrine ordinaire réalisé par une agence classique reste une simple « affiche en ligne ». Notre solution est un portail transactionnel connecté : chaque rendez-vous réservé sur le site s'inscrit immédiatement dans le même calendrier que les réservations WhatsApp et met à jour les disponibilités de l'agent IA en temps réel.

[061] Showroom produits dynamique — exposition élégante des montures optiques, des solaires et des soins dermocosmétiques pour inciter les visiteurs web à se déplacer au cabinet de Douala.

[062] Accessibilité mobile & widget WhatsApp flottant — conception 100 % adaptée aux smartphones (utilisés par plus de 90 % des internautes au Cameroun) avec bouton de bascule directe vers l'agent conversationnel WhatsApp.

[063] MODULE 5  Formation pratique & autonomie vidéo

[064] Afin de maximiser l'impact des campagnes publicitaires Facebook/Instagram et d'alimenter les réseaux du cabinet en contenu attractif :

[065] Session de formation pratique au montage vidéo sur smartphone — prise en main des outils d'édition mobile pour réaliser des vidéos promotionnelles percutantes de 30 à 60 secondes : mise en scène des montures, présentation des examens de vue, explications visuelles des bienfaits des verres Blue-Cut ou progressifs, et structuration des vidéos pour maximiser les clics vers WhatsApp (accroche, démonstration, appel à l'action).

[TABLE 13]
R01: 04   ANALYSE ÉCONOMIQUE & GRILLE TARIFAIRE DÉTAILLÉE

[066] L'investissement se structure en deux volets distincts : les frais uniques de conception, de développement et d'intégration, et les abonnements annuels garantissant le fonctionnement opérationnel des serveurs, des licences CRM et de l'intelligence artificielle sur 12 mois.

[067] 4.1 — FRAIS DE CONCEPTION, DÉVELOPPEMENT & INTÉGRATION (PAIEMENT UNIQUE)

[TABLE 14]
R01: RÉF. || PRESTATION || LIVRABLES CLÉS || INVESTISSEMENT
R02: DEV-01 || Application métier back-office (administration) || •  Module dossiers réfraction (OD/OG, dioptries) / •  Suivi d'atelier & fabrication verres / •  Facturation assistée avec autocomplétion / •  Gestion des acomptes, soldes et caisse journalière || 200 000 FCFA
R03: INF- || Déploiement sur Domaine || Configuration DNS domaine eagleeyeoptic.com, SSL & emails || Infrastructure existante (0 FCFA)
R04: AUT-01 || Intégration & automatisations IA / CRM || •  Connexion WhatsApp Business API & Messenger / •  Entraînement de l'agent IA (FAQ, qualification, booking) / •  Synchronisation bidirectionnelle Google Calendar / •  Automatisation des rappels anti-no-show (J-1 / H-2) || 100 000 FCFA
R05: WEB-01 || Portail web vitrine & showroom interactif || •  Site médical responsive sous eagleeyeoptic.com / •  Showroom digital des montures et verres / •  Module de réservation connecté au calendrier / •  Bouton flottant de discussion WhatsApp direct || 100 000 FCFA
R06: FORM-01 || Formation autonomie vidéo publicitaire || •  Formation pratique au montage de vidéos promotionnelles / •  Scripts et gabarits de présentation produits pour Meta || Offert
R07: TOTAL — INVESTISSEMENT CRÉATION & INTÉGRATION GLOBALE (MODULES 1 À 5) || 400 000 FCFA

[068] 4.2 — LICENCES OPÉRATIONNELLES & INFRASTRUCTURE MOTEUR IA (COUVERTURE 12 MOIS)

[069] Pour garantir la disponibilité du système 24h/24 sans coupure de service au cours de l'année, les licences et crédits de flux sont souscrits pour une durée de 12 mois :

[TABLE 15]
R01: RÉF. || SERVICE || USAGE TECHNIQUE || TARIF / MOIS || TOTAL / AN
R02: LIC-01 || Licence CRM opérationnelle || Gestion centralisée des flux WhatsApp/Messenger, boîte de réception mobile praticienne, passerelles Meta. || 20 000 || 240 000
R03: API-01 || Moteur IA & passerelles de synchronisation || Crédits de requêtes pour l'agent conversationnel IA, connecteurs Google Calendar et webhooks serveurs. || 7 000 || 84 000
R04: TOTAL — ABONNEMENTS ANNUELS LICENCES & IA (12 MOIS) || 27 000 || 324 000

[070] 4.3 — RÉCAPITULATIF GÉNÉRAL DE L'OFFRE CLÉ EN MAIN

[TABLE 16]
R01: 1. Frais de conception, développement & intégrations (pack global) || 400 000 FCFA
R02: 2. Abonnements annuels — licences CRM & moteur IA (12 mois complets) || 324 000 FCFA
R03: TOTAL DE L'INVESTISSEMENT GLOBAL CLÉ EN MAIN || 724 000 FCFA

[071] 4.4 — ANALYSE DU RETOUR SUR INVESTISSEMENT (ROI)

[072] Pour un cabinet d'optique où le prix moyen d'un équipement complet (monture + verres traités) se situe couramment entre 45 000 et 120 000 FCFA, l'amortissement du système est particulièrement rapide :

[TABLE 17]
R01: Coût mensuel global lissé (création + abonnements sur 1 an) : 724 000 FCFA / 12 mois ≈ 60 300 FCFA / mois / Gain commercial généré par l'agent IA & l'anti-no-show : 1 à 2 paires de lunettes supplémentaires vendues par mois suffisent à rentabiliser 100 % de l'investissement mensuel. / Gain de temps administratif pour la praticienne : environ 45 heures de travail administratif économisées par mois, entièrement réinjectées dans les consultations et la vente au cabinet.
R02: 05   PLANNING DE DÉPLOIEMENT OPÉRATIONNEL (2 MOIS)

[073] Le déploiement est structuré sur huit semaines avec une mise en service progressive, afin que le cabinet récolte les premiers bénéfices dès la fin du premier mois.

[TABLE 18]
R01: MOIS 1 — INFRASTRUCTURE, BACK-OFFICE MÉTIER & AUTOMATISATION IA
R02: Semaine 1 || Audit des accès existants, configuration DNS du domaine eagleeyeoptic.com et paramétrage des emails professionnels
R03: Semaine 2 || Configuration du CRM, de WhatsApp Business API et de l'agent IA.
R04: Semaine 3 || Développement du back-office (dossiers de réfraction & caisse).
R05: Semaine 4 || Synchronisation Google Calendar, tests anti-no-show & mise en service.
R06: MOIS 2 — EXPANSION VITRINE WEB, FORMATION & OPTIMISATION GLOBALE
R07: Semaine 5 || Intégration du portail web showroom eagleeyeoptic.com.
R08: Semaine 6 || Raccordement du widget de booking web avec l'agenda central.
R09: Semaine 7 || Session de formation pratique au montage vidéo promotionnel.
R10: Semaine 8 || Tests d'intégration de bout en bout, ajustements & recette.
R11: 06   CONDITIONS COMMERCIALES & ENGAGEMENT

[074] 6.1 — MODALITÉS ET ÉCHÉANCIER DE PAIEMENT

[075] Frais de conception, développement & intégration — 400 000 FCFA, réglés en trois échéances :

[TABLE 19]
R01: PART || MONTANT || ÉCHÉANCE & CONDITIONS
R02: 50 % || 200 000 FCFA || À la signature du devis (acompte de lancement) — permet l'allocation immédiate des ressources techniques et la configuration de l'infrastructure.
R03: 30 % || 120 000 FCFA || À la livraison intermédiaire, fin du mois 1 — conditionné par la livraison validée de l'application back-office et de l'agent IA WhatsApp opérationnel.
R04: 20 % || 80 000 FCFA || À la livraison finale, fin du mois 2 — à la mise en ligne du site vitrine et à l'issue de la formation vidéo.

[TABLE 20]
R01: Abonnements annuels licences & IA — 324 000 FCFA : réglés intégralement au démarrage du projet, pour l'achat et la sécurisation des licences logicielles, et des quotas d'API sur la période contractuelle de 12 mois.

[076] Modes de règlement acceptés — virement bancaire ou chèque, espèce, ainsi que mobile money professionnel sécurisé (Orange Money / MTN Mobile Money).

[077] 6.2 — ENGAGEMENTS & GARANTIE DE SERVICE

[078] Garantie de bon fonctionnement — une période de garantie technique de 3 mois couvre toute correction d'anomalie ou ajustement sans frais supplémentaires après la recette finale.

[079] Propriété intellectuelle — à l'issue du règlement intégral, EAGLE'S EYE OPTICS MEDICAL est l'unique propriétaire de sa base de données patients, de son nom de domaine et de l'ensemble de ses contenus.

[080] Confidentialité médicale — le prestataire s'engage au respect absolu du secret professionnel et à la non-divulgation des données médicales et comptables manipulées au cours de la mission.

[TABLE 21]
R01: 07   BON POUR ACCORD & SIGNATURE CONTRACTUELLE

[081] La signature du présent document vaut validation formelle du cahier des charges, de la grille tarifaire et du calendrier d'exécution.

[082] Fait à Douala, le 6 Septembre  en deux (2) exemplaires originaux.

[TABLE 22]
R01: POUR LE PRESTATAIRE / (PEGH-STARTUP) / PEGHIEMBOUO ROMIAL : /  /  || POUR LE CLIENT / EAGLE'S EYE OPTICS MEDICAL — Madame la Directrice Générale / Nom : /  / Signature précédée de la mention manuscrite « Bon pour accord et validation du devis » : /  / 

[word/header1.xml]
EAGLE'S EYE OPTICS MEDICAL   ·   Proposition commerciale & technique   ·   PEGH-STARTUP

[word/footer1.xml]

Page 2 / 2
