# Proposition_Commerciale_Finale_Eagle_Eye_Optics_Medical.pdf

## PAGE 1
C O N S E I L  E N  I N G É N I E R I E  D I G I T A L E 
PROPOSITION COMMERCIALE 
& TECHNIQUE DÉTAILLÉE  
 
 
RÉFÉRENCE DU DOSSIER  PEGH-STARTUP-2026-EEOM-0012 
DATE D'ÉMISSION  1er septembre 2026 
PROJET  Automatisation commerciale, intelligence artificielle de booking, application métier 
d'optique & showroom web 
BÉNÉFICIAIRE  EAGLE'S EYE OPTICS MEDICAL 
À L'ATTENTION DE  Madame la Directrice Générale 
LOCALISATION  Rue Ancien Cinéma Étoile, Marché Congo, Douala – Cameroun 
DURÉE D'EXÉCUTION  2 mois — déploiement opérationnel dès le mois 1 
  
P E G H- S T A R T U P  ·   2 0 2 6 

## PAGE 2
EAGLE'S EYE OPTICS MEDICAL    ·   Proposition commerciale & technique   ·   PEGH-STARTUP 
 
 Page 2 / 8 
01   RÉSUMÉ EXÉCUTIF & DIAGNOSTIC DU CABINET 
 
1.1 — DIAGNOSTIC OPÉRATIONNEL DU CABINET  
EAGLE'S EYE OPTICS MEDICAL est un acteur de santé visuelle et de dermocosmétique en plein développement à Douala. 
L'activité repose sur un triptyque à forte valeur ajoutée : 
1  L'examen clinique de réfraction et le dépistage des troubles visuels.  
2  Le conseil, la taille, le montage et la vente d'équipements optiques et solaires.  
3  Le conseil et la distribution de produits dermocosmétiques spécialisés.  
 
L'observation directe du fonctionnement du cabinet et l'analyse de son écosystème commercial mettent en évidence 
quatre goulets d'étranglement majeurs : 
▪ Perte de conversion sur les canaux digitaux — les campagnes publicitaires sur Meta (Facebook/Instagram) drainent 
des prospects vers WhatsApp et Messenger ; en l'absence de réponse immédiate et qualifiée 24h/ 24, une proportion 
significative de prospects abandonne ou s'oriente vers la concurrence.  
▪ Taux de rendez -vous non honorés (No -Show) — les rendez-vous pris par téléphone ou messages simples ne font 
l'objet d'aucun rappel automatique programmé, encombrant in utilement les plages horaires d'examen de vue. 
▪ Lourdeur administrative & double saisie — l'établissement manuel des devis, factures, fiches de réfraction et reçus 
d'acomptes monopolise un temps médical précieux qui devrait être consacré à l'examen clinique et à la vente de verres 
à forte marge. 
▪ Absence d'un outil métier unifié — les informations sont fragmentées entre carnets papier, tableurs ou messages 
épars, rendant complexe le suivi des encaissements d'acomptes, la relance des soldes impayés et la traça bilité des 
commandes de verres chez les laboratoires verriers. 
 
SITUATION ACTUELLE → SITUATION CIBLE  
SITUATION ACTUELLE SITUATION CIBLE 
✕  Réponses manuelles lentes sur WhatsApp ✓  Agent IA actif 24/7 (FAQ & booking) 
✕  Prise de rendez-vous sur calepin ou agenda papier ✓  Synchronisation Google Calendar en temps réel 
✕  Taux élevé de « No-Show » aux examens ✓  Rappels automatiques WhatsApp (J-1 / H-2) 
✕  Saisie manuelle longue des factures ✓  Facturation assistée en quelques clics 
✕  Suivi approximatif des acomptes et de la caisse ✓  Caisse automatisée & suivi des soldes 
✕  Site web externe non finalisé ✓  Portail showroom eagleeyeoptic.com connecté 
02   ARCHITECTURE TECHNIQUE GLOBALE DU SYSTÈME 
 
La solution proposée bâtit un écosystème cohérent, articulé autour de trois couches technologiques interconnectées par 
des interfaces de programmation sécurisées (API & webhooks), complétées par une couche vitrine digitale en 
raccordement optionnel : 
 

## PAGE 3
EAGLE'S EYE OPTICS MEDICAL    ·   Proposition commerciale & technique   ·   PEGH-STARTUP 
 
 Page 3 / 8 
COUC HE 1   ACQUISITION & COMMUNICATION — FRONT-OFFICE CONVERSATIONNEL 
▪  Publicités Meta (Facebook / Instagram Ads) 
▪  WhatsApp Business API & Messenger connectés à Kommo CRM 
▪  Agent IA : réponses FAQ, qualification médicale & réservation 
↓  Webhooks bidirectionnels 
COUCHE 2   PLANIFICATION & SYNCHRONISATION — AGENDA DU CABINET 
▪  Google Calendar Pro (08h30 – 17h00) synchronisé en temps réel 
▪  Détection automatique des créneaux libres / occupés 
▪  Déclencheur des notifications et rappels WhatsApp anti-no-show 
↓  API REST / base de données 
COUCHE 3   MÉTIER, GESTION & COMPTABILITÉ — BACK-OFFICE D'ADMINISTRATION 
▪  Fiches patients & historique de réfraction (OD / OG / dioptries) 
▪  Suivi d'atelier (commande verres → taillage → prêt) 
▪  Facturation express (autocomplétion catalogue, acomptes & soldes) 
▪  Gestion de caisse & tableaux de bord financiers 
↓  Raccordement optionnel 
COUCHE 4   VITRINE & SHOWROOM DIGITAL — EAGLEEYEOPTIC.COM 
▪  Présentation des expertises optique & dermocosmétique 
▪  Catalogue digital interactif des montures et technologies de verres 
▪  Module de réservation directe synchronisé avec le calendrier central 
 
03   DESCRIPTION DÉTAILLÉE DES MODULES FONCTIONNELS 
 
MODULE 1  Moteur conversationnel IA & prise de rendez-vous omnicanale 
Ce module transforme les canaux de messagerie du cabinet en un centre d'accueil automatisé, capable de convertir un 
prospect curieux en un patient assis dans le cabinet d'examen. 
 
1  Le visiteur contacte le cabinet via WhatsApp ou Messenger.  
2  L'agent IA Kommo répond aux questions fréquentes et oriente la demande.  
3  L'agent propose deux à trois créneaux disponibles. 
4  Le prospect confirme le créneau retenu. 
5  Le rendez-vous est enregistré simultanément dans Google Calendar Pro et dans le pipeline Kommo.  
6  Rappels automatiques : WhatsApp à J-1 (18h00), puis à H-2 avant l'examen. 
 
▪ Intégration omnicanale officielle — connexion de la ligne WhatsApp du cabinet et de la page Facebook à la plateforme 
CRM Kommo, avec réception unifiée de l'ensemble des discussions sur une interface claire, accessible sur smartphone 
et ordinateur. 

## PAGE 4
EAGLE'S EYE OPTICS MEDICAL    ·   Proposition commerciale & technique   ·   PEGH-STARTUP 
 
 Page 4 / 8 
▪ Agent IA entraîné au métier de l'optique — traitement autonome des questions fréquentes (localisation, horaires dès 
08h30, tarifs indicatifs des examens, prise en charge des assurances, description des traitements de verres Blue -Cut, 
antireflet, photochromiques, progressifs) et qualification préliminaire du besoin (première consultation, 
renouvellement de monture, fatigue visuelle sur écran, contrôle d'ordonnance). 
▪ Négociation et réservation autonome d'horaires — l'agent IA interroge l'agenda Google Calendar en direct, formule 
une proposition de créneaux et valide le choix du prospect par confirmation textuelle.  
▪ Synchronisation bidirectionnelle Google Calendar — tout rendez-vous validé s'inscrit immédiatement dans l'agenda 
avec nom, téléphone et motif ; toute plage bloquée manuellement par la praticienne est automatiquement ignorée 
par l'agent IA, évitant les doubles réservations. 
▪ Dispositif anti -no-show automatisé — rappel à J -1 (18h00) demandant la confirmation, puis rappel à H -2 avec 
localisation GPS et consignes pratiques pour fluidifier l'arrivée au cabinet.  
MODULE 2  Application métier back-office spécialisée optique 
Conçue pour remplacer la paperasse et les classeurs physiques, cette application web d'administration constitue le cœur 
opérationnel du cabinet. 
CYCLE DE TRAITEMENT DANS L'APPLICATION  
1   Création du dossier patient & saisie réfraction 
–  Saisie des valeurs : Sphère, Cylindre, Axe, Addition, EP (OD / OG) 
–  Conservation de l'historique des prescriptions antérieures 
2   Sélection équipement & commande atelier 
–  Choix de la monture (marque, modèle, référence interne) 
–  Choix du verre (indice 1.56 / 1.61 / 1.67, traitement Blue-Cut / HMC) 
–  Génération du bon de commande pour le laboratoire verrier 
3   Facturation express & gestion des acomptes 
–  Autocomplétion intelligente des lignes de facture en deux lettres 
–  Encaissement de l'acompte (50 % ou 70 %) → édition du reçu officiel 
–  Enregistrement du mode de règlement (espèces / mobile money) 
4   Suivi fabrication & clôture de facture 
–  Suivi des étapes : commandé → reçu → taillé → prêt 
–  Notification WhatsApp automatique de mise à disposition 
–  Règlement du reliquat à la livraison → facture soldée 
 
▪ Dossier patient & fiche de réfraction complète — interface ergonomique standardisée pour l'œil droit et l'œil gauche 
(Sphère, Cylindre, Axe, Addition/Presbytie, écart pupillaire, hauteur de montage), avec archivage numérique sécurisé 
et consultation instantanée de l'historique visuel du patient. 
▪ Module atelier & traçabilité des commandes verriers — suivi du cycle de vie de chaque équipement, de la commande 
transmise au verrier jusqu'à la livraison et à l'ajustement, avec déclenchement automatique d'un message WhatsApp 
dès que l'équipement passe au statut « prêt pour retrait ». 
Commande transmise   →   Verres réceptionnés   →   Taillage & montage   →   Contrôle qualité   →   Livré & ajusté 
 
▪ Facturation intelligente assistée par autocomplétion — base de données pré-remplie de l'ensemble des prestations, 
verres et montures ; la frappe de deux ou trois caractères (ex. « PROG » ou « BLUE ») affiche immédiatement les 
références correspondantes avec leurs prix, ajustables manuellement sans ressaisie.  
▪ Gestion avancée des acomptes & rapprochement de caisse — gestion du versement initial et calcul automatique du 
solde restant dû, génération instantanée de reçus et factures acquittées au format PDF professionnel, suivi ventilé des 

## PAGE 5
EAGLE'S EYE OPTICS MEDICAL    ·   Proposition commerciale & technique   ·   PEGH-STARTUP 
 
 Page 5 / 8 
paiements (espèces, MTN Mo Mo / Orange Money, virement) et tableau de bord du chiffre d'affaires journalier, 
hebdomadaire et mensuel. 
MODULE 3  Déploiement & Sécurisation sur l'Infrastructure Existante (annuel) 
▪ PEGH-STARTUP capitalisera directement sur les actifs déjà acquis par le cabinet :  
– Reprise et configuration de la zone DNS du nom de domaine existant eagleeyeoptic.com.   
– Déploiement de l'application et de la base de données sur l'hébergement existant du client. 
– Configuration des adresses emails professionnelles et des certificats de sécurité SSL/HTTPS. »  
▪ Messagerie professionnelle sécurisée — création et configuration d'adresses emails personnalisées 
(direction@eagleeyeoptic.com, contact@eagleeyeoptic.com). 
▪ Sécurité & confidentialité des données médicales — certificats de chiffrement SSL/TLS (HTTPS) et sauvegardes 
automatiques quotidiennes de la base de données patients et de la comptabilité.  
MODULE 4  Portail web vitrine & showroom digital (front-office) 
Module d'exposition commerciale déployé sous eagleeyeoptic.com.  
SECTION DU SITE CONTENU 
Entête Navigation fluide, coordonnées du Marché Congo, accès direct à la réservation 
Hero banner Positionnement santé visuelle, précision clinique & dermocosmétique — boutons « Prendre 
RDV en ligne » et « WhatsApp direct » 
Pôles d'expertise Examens de réfraction, dépistage, lentilles de contact, traitements innovants de verres, espace 
dermocosmétique 
Showroom digital Galerie de montures optiques et solaires (filtres par style/marque) avec bouton « Essayer ce 
modèle en cabinet » 
Module de booking Choix de la prestation et de la date/heure disponible, enregistrement direct dans l'agenda 
Google + notification WhatsApp 
Pied de page Horaires (08h30–17h00), plan Google Maps, réseaux sociaux Meta 
 
POURQUOI CETTE OPTION EST DÉCISIVE FACE À UN SITE WEB TRADITIONNEL  
Un site vitrine ordinaire réalisé par une agence classique reste une simple « affiche en ligne ». Notre solution est un portail 
transactionnel connecté : chaque rendez -vous réservé sur le s ite s'inscrit immédiatement dans le même calendrier que 
les réservations WhatsApp et met à jour les disponibilités de l'agent IA en temps réel. 
 
▪ Showroom produits dynamique — exposition élégante des montures optiques, des solaires et des soins 
dermocosmétiques pour inciter les visiteurs web à se déplacer au cabinet de Douala.  
▪ Accessibilité mobile & widget WhatsApp flottant — conception 100 % adaptée aux smartphones (utilisés par plus de 
90 % des internautes au Cameroun) avec bouton de bascule directe vers l'agent conversationnel WhatsApp.  
MODULE 5  Formation pratique & autonomie vidéo 
Afin de maximiser l'impact des campagnes publicitaires Facebook/Instagram et d'alimenter les ré seaux du cabinet en 
contenu attractif : 
▪ Session de formation pratique au montage vidéo sur smartphone — prise en main des outils d'édition mobile pour 
réaliser des vidéos promotionnelles percutantes de 30 à 60 secondes : mise en scène des montures, présentation des 

## PAGE 6
EAGLE'S EYE OPTICS MEDICAL    ·   Proposition commerciale & technique   ·   PEGH-STARTUP 
 
 Page 6 / 8 
examens de vue, explications visuelles des bienfaits des verres Blue -Cut ou progressifs, et st ructuration des vidéos 
pour maximiser les clics vers WhatsApp (accroche, démonstration, appel à l'action).  
04   ANALYSE ÉCONOMIQUE & GRILLE TARIFAIRE DÉTAILLÉE 
L'investissement se structure en deux volets distincts : les frais uniques de conception, de développement et d'intégration, 
et les abonnements annuels garantissant le fonctionnement opérationnel des serveurs, des licences CRM et de l'intelligence 
artificielle sur 12 mois. 
4.1 — FRAIS DE CONCEPTION, DÉVELOPPEMENT & INTÉGRATION (PAIEMENT UNIQUE)  
RÉF. PRESTATION LIVRABLES CLÉS INVESTISSEMENT 
DEV-01 Application métier back-office 
(administration) 
•  Module dossiers réfraction (OD/OG, dioptries) 
•  Suivi d'atelier & fabrication verres 
•  Facturation assistée avec autocomplétion 
•  Gestion des acomptes, soldes et caisse journalière 
200 000 FCFA 
INF- Déploiement sur Domaine Configuration DNS domaine eagleeyeoptic.com, SSL & 
emails   
Infrastructure 
existante (0 
FCFA) 
AUT-01 Intégration & automatisations IA 
/ CRM 
•  Connexion WhatsApp Business API & Messenger 
•  Entraînement de l'agent IA (FAQ, qualification, booking) 
•  Synchronisation bidirectionnelle Google Calendar 
•  Automatisation des rappels anti-no-show (J-1 / H-2) 
100 000 FCFA 
WEB-01 Portail web vitrine & showroom 
interactif 
•  Site médical responsive sous eagleeyeoptic.com 
•  Showroom digital des montures et verres 
•  Module de réservation connecté au calendrier 
•  Bouton flottant de discussion WhatsApp direct 
100 000 FCFA 
FORM-01 Formation autonomie vidéo 
publicitaire 
•  Formation pratique au montage de vidéos promotionnelles 
•  Scripts et gabarits de présentation produits pour Meta Offert 
TOTAL — INVESTISSEMENT CRÉATION & INTÉGRATION GLOBALE (MODULES 1 À 5) 400 000 FCFA 
4.2 — LICENCES OPÉRATIONNELLES & INFRASTRUCTURE MOTEUR IA (COUVERTURE 12 MOIS)  
Pour garantir la disponibilité du système 24h/24 sans coupure de service au cours de l'année, les licences et crédits de flux 
sont souscrits pour une durée de 12 mois : 
RÉF. SERVICE USAGE TECHNIQUE TARIF / 
MOIS 
TOTAL / AN 
LIC-01 Licence CRM opérationnelle 
Gestion centralisée des flux 
WhatsApp/Messenger, boîte de réception 
mobile praticienne, passerelles Meta. 
20 000 240 000 
API-01 Moteur IA & passerelles de 
synchronisation 
Crédits de requêtes pour l'agent 
conversationnel IA, connecteurs Google 
Calendar et webhooks serveurs. 
7 000 84 000 
TOTAL — ABONNEMENTS ANNUELS LICENCES & IA (12 MOIS) 27 000 324 000 
 
4.3 — RÉCAPITULATIF GÉNÉRAL DE L'OFFRE CLÉ EN MAIN  
1. Frais de conception, développement & intégrations (pack global) 400 000 FCFA 
2. Abonnements annuels — licences CRM & moteur IA (12 mois complets) 324 000 FCFA 

## PAGE 7
EAGLE'S EYE OPTICS MEDICAL    ·   Proposition commerciale & technique   ·   PEGH-STARTUP 
 
 Page 7 / 8 
TOTAL DE L'INVESTISSEMENT GLOBAL CLÉ EN MAIN 724 000 FCFA 
 
4.4 — ANALYSE DU RETOUR SUR INVESTISSEMENT (ROI)  
Pour un cabinet d'optique où le prix moyen d'un équipement complet (monture + verres traités) se situe couramment entre 
45 000 et 120 000 FCFA, l'amortissement du système est particulièrement rapide :  
Coût mensuel global lissé (création + abonnements sur 1 an) : 724 000 FCFA / 12 mois ≈ 60 300 FCFA / mois 
Gain commercial généré par l'agent IA & l'anti-no-show : 1 à 2 paires de lunettes supplémentaires vendues par mois 
suffisent à rentabiliser 100 % de l'investissement mensuel. 
Gain de temps administratif pour la praticienne : environ 45 heures de travail administratif économisées par mois, 
entièrement réinjectées dans les consultations et la vente au cabinet. 
05   PLANNING DE DÉPLOIEMENT OPÉRATIONNEL (2 MOIS) 
 
Le déploiement est structuré sur huit semaines avec une mise en service progressive, afin que le cabinet récolte les premiers 
bénéfices dès la fin du premier mois. 
 
MOIS 1 — INFRASTRUCTURE, BACK -OFFICE MÉTIER & AUTOMATISATION IA  
Semaine 1 Audit des accès existants, configuration DNS du domaine eagleeyeoptic.com et paramétrage des emails 
professionnels 
Semaine 2 Configuration du CRM, de WhatsApp Business API et de l'agent IA. 
Semaine 3 Développement du back-office (dossiers de réfraction & caisse). 
Semaine 4 Synchronisation Google Calendar, tests anti-no-show & mise en service. 
MOIS 2 — EXPANSION VITRINE WEB, FORMATION & OPTIMISATION GLOBALE  
Semaine 5 Intégration du portail web showroom eagleeyeoptic.com. 
Semaine 6 Raccordement du widget de booking web avec l'agenda central. 
Semaine 7 Session de formation pratique au montage vidéo promotionnel. 
Semaine 8 Tests d'intégration de bout en bout, ajustements & recette. 
06   CONDITIONS COMMERCIALES & ENGAGEMENT 
 
6.1 — MODALITÉS ET ÉCHÉANCIER DE PAIEMENT  
Frais de conception, développement & intégration — 400 000 FCFA, réglés en trois échéances : 
PART MONTANT ÉCHÉANCE & CONDITIONS 
50 % 200 000 FCFA À la signature du devis (acompte de lancement) — permet l'allocation immédiate 
des ressources techniques et la configuration de l'infrastructure. 
30 % 120 000 FCFA À la livraison intermédiaire, fin du mois 1 — conditionné par la livraison validée de 
l'application back-office et de l'agent IA WhatsApp opérationnel. 

## PAGE 8
EAGLE'S EYE OPTICS MEDICAL    ·   Proposition commerciale & technique   ·   PEGH-STARTUP 
 
 Page 8 / 8 
PART MONTANT ÉCHÉANCE & CONDITIONS 
20 % 80 000 FCFA À la livraison finale, fin du mois 2 — à la mise en ligne du site vitrine et à l'issue de la 
formation vidéo. 
 
Abonnements annuels licences & IA — 324 000 FCFA : réglés intégralement au démarrage du projet, pour l'achat et la 
sécurisation des licences logicielles, et des quotas d'API sur la période contractuelle de 12 mois. 
 
▪ Modes de règlement acceptés — virement bancaire ou chèque , espèce , ainsi que mobile money professionnel 
sécurisé (Orange Money / MTN Mobile Money). 
 
6.2 — ENGAGEMENTS & GARANTIE DE SERVICE  
▪ Garantie de bon fonctionnement — une période de garantie technique de 3 mois couvre toute correction d'anomalie 
ou ajustement sans frais supplémentaires après la recette finale. 
▪ Propriété intellectuelle — à l'issue du règlement intégral, EAGLE'S EYE OPTICS MEDICAL est l'unique propriétaire de 
sa base de données patients, de son nom de domaine et de l'ensemble de ses contenus. 
▪ Confidentialité médicale — le prestataire s'engage au respect absolu du secret professionnel et à la non -divulgation 
des données médicales et comptables manipulées au cours de la mission.  
 
La signature du présent document vaut validation formelle du cahier des charges, de la grille tarifaire et du calendrier 
d'exécution. 
 
Fait à Douala, le 6 Septembre  en deux (2) exemplaires originaux. 
POUR LE PRESTATAIRE 
(PEGH-STARTUP) 
PEGHIEMBOUO ROMIAL : 
  
  
POUR LE CLIENT 
EAGLE'S EYE OPTICS MEDICAL — Madame la Directrice Générale 
Nom : 
  
Signature précédée de la mention manuscrite « Bon pour accord et 
validation du devis » : 
  
  
 
07   BON POUR ACCORD & SIGNATURE CONTRACTUELLE 
