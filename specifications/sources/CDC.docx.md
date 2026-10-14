# cahier_des_charges_optique.docx

[001] Cahier des charges

[002] Automatisation du cabinet d'optique

[003] Document à remettre au prestataire / ingénieur informatique en charge du développement

[004] 1. Contexte et objectif

[005] Je suis promotrice d'un cabinet d'optique où sont réalisés à la fois des examens de vue et la commercialisation d'équipements optiques (montures, verres, lentilles, collyres). Je souhaite un système unique (logiciel et/ou application) qui automatise l'ensemble du parcours, depuis l'accueil du patient jusqu'au suivi après-vente, afin de :

[006] supprimer les registres papier ;

[007] garantir qu'aucune sortie de marchandise ou d'argent n'échappe à mon contrôle ;

[008] suivre ma comptabilité en temps réel, sans attendre un rapport mensuel ;

[009] suivre mon stock de montures et de collyres avec précision ;

[010] relancer automatiquement mes patients pour leurs rendez-vous ;

[011] communiquer et promouvoir mes offres sur WhatsApp sans risquer un blocage du compte ;

[012] répondre automatiquement (via une IA) aux messages des patients/prospects sur mes réseaux, jusqu'à ce que je reprenne la conversation pour conclure la vente.

[013] Le prestataire doit me proposer une solution qui couvre TOUT le parcours ci-dessous de façon connectée (un seul système, ou plusieurs outils qui communiquent entre eux automatiquement) — pas des outils isolés qu'il faudrait ressaisir à la main d'un module à l'autre.

[014] 2. Utilisateurs et droits d'accès (obligatoire)

[015] Le système doit gérer des comptes utilisateurs distincts, avec un journal (historique) de qui a fait quoi et quand. Chaque action (vente, sortie de stock, modification de prix, annulation) doit être enregistrée avec le nom de l'utilisateur et l'heure — sans possibilité de suppression de l'historique.

[TABLE 1]
R01: Rôle || Accès autorisés || Accès interdits
R02: Gérante / promotrice (moi) || Accès total : ventes, stock, comptabilité, patients, rapports, paramètres || —
R03: Accueil / réceptionniste || Créer une fiche patient, prendre un rendez-vous, consulter l'agenda || Modifier les prix, supprimer des ventes, accéder à la comptabilité globale
R04: Examinateur(trice) / opticien(ne) || Saisir les résultats d'examen dans le dossier patient || Modifier les ventes ou le stock
R05: Caissière / vendeuse || Enregistrer une vente, encaisser un paiement/acompte, consulter le stock disponible || Supprimer une vente déjà enregistrée, modifier le stock manuellement sans motif, voir les rapports financiers globaux

[016] 3. Module Accueil — début du parcours patient

[017] Dès l'arrivée du patient, l'accueil doit pouvoir :

[018] rechercher un patient existant par nom/téléphone, ou créer une nouvelle fiche en quelques secondes (nom, téléphone, date de naissance) ;

[019] choisir le motif de la visite : examen de vue, achat direct (sans examen), retrait de commande, contrôle de suivi ;

[020] enregistrer et encaisser les frais d'examen de vue (avec reçu automatique) — l'examen ne doit pouvoir démarrer que si les frais sont enregistrés comme payés (ou une dérogation explicite de la gérante) ;

[021] orienter le patient : vers l'examinateur (si examen) ou directement vers la vente (si le patient vient uniquement acheter) ;

[022] visualiser l'agenda du jour et les rendez-vous à venir.

[023] 4. Module Dossier patient / Examen de vue

[024] Remplace entièrement le registre papier. Chaque passage crée une nouvelle entrée horodatée dans l'historique du patient, avec :

[025] date de la visite ;

[026] nom et coordonnées du patient ;

[027] plaintes visuelles (motif de consultation, en texte libre) ;

[028] acuité visuelle de loin et de près, avec et sans correction (champ séparé pour chaque œil) ;

[029] correction optique : OD (œil droit), OG (œil gauche), ADD (addition) ;

[030] collyres prescrits (nom, posologie, durée du traitement) ;

[031] champ "prochain contrôle recommandé" (ex. dans 10 jours / 1 an / 2 ans) — ce champ doit alimenter automatiquement le module de relance (section 7).

[032] possibilité de consulter en un clic tout l'historique des visites précédentes du patient, avec comparaison de l'évolution de la vue dans le temps.

[033] 5. Module Vente & Encaissement (point le plus critique)

[034] Le problème que je veux résoudre en priorité : un patient verse un acompte, repart avec le produit, revient payer le solde des semaines ou des mois plus tard — et si la caissière ne fait pas de reçu, je n'en ai jamais connaissance. Je veux que ceci devienne techniquement impossible.

[035] Règles obligatoires

[036] Toute remise d'un article au patient (monture, verre, lentille, collyre) doit être bloquée tant qu'une vente n'a pas été créée dans le système pour cet article — pas de sortie "informelle".

[037] La vente enregistre : nom du patient, article(s), prix total, montant versé, reste à payer, mode de paiement, date.

[038] Si un reste à payer existe, le dossier passe automatiquement en statut "Paiement en attente" et apparaît sur un tableau de bord des impayés, visible par moi à tout moment (pas seulement en fin de mois).

[039] Quand le patient revient régler le solde, la caissière doit rechercher le dossier existant (pas en créer un nouveau) et enregistrer le paiement complémentaire, avec reçu.

[040] Un reçu (numéroté, non modifiable après émission) est généré à chaque encaissement, partiel ou total.

[041] Je dois pouvoir consulter à tout moment : chiffre d'affaires du jour/semaine/mois, total des impayés, liste des ventes par caissière.

[042] Toute annulation ou modification d'une vente déjà enregistrée doit nécessiter mon autorisation (code ou validation à distance) et rester visible dans l'historique (jamais supprimée).

[043] 6. Module Stock (montures, verres, collyres)

[044] Objectif : que je sache à tout moment ce qui est censé être en rayon, et détecter immédiatement un écart (vol, vente non enregistrée, erreur).

[045] Chaque article (monture, verre, collyre) a une fiche avec référence/code unique, quantité en stock, prix d'achat et prix de vente.

[046] La seule façon de faire sortir un article du stock est via une vente enregistrée dans le système (section 5) ou un mouvement de stock justifié (casse, retour fournisseur, etc.), avec motif obligatoire et validation par moi.

[047] Alerte automatique quand le stock d'un article passe sous un seuil minimum (réapprovisionnement) ou approche la date de péremption (collyres).

[048] Rapport comparant le stock théorique (calculé par le système) et le stock physique réel (comptage), pour identifier tout écart lors des inventaires.

[049] Historique des mouvements de stock par article, consultable à tout moment.

[050] 7. Module Relances & Rendez-vous

[051] Basé sur le champ "prochain contrôle recommandé" saisi lors de l'examen (section 4) :

[052] Relance automatique 10 à 14 jours après un traitement par collyre, pour vérifier l'évolution.

[053] Relance automatique 1 à 2 ans après un examen de routine, pour proposer un nouveau contrôle.

[054] Chaque jour, une liste des patients à contacter ce jour-là doit être générée automatiquement (par SMS, WhatsApp ou appel selon ce que je choisis).

[055] Suivi de la relance : contacté / rendez-vous pris / sans réponse — pour éviter d'oublier ou de relancer deux fois la même personne.

[056] 8. Communication WhatsApp, réseaux sociaux et IA

[057] Envoi de messages et promotions

[058] L'envoi de messages et de visuels promotionnels en masse doit passer par l'API officielle WhatsApp Business (et non un outil non-officiel), pour éviter tout blocage de mon compte.

[059] Possibilité de créer des listes de diffusion ciblées (ex. patients n'étant pas venus depuis plus d'un an, patients ayant un solde impayé, etc.).

[060] Assistant IA (chatbot)

[061] Un assistant IA doit pouvoir répondre automatiquement aux messages reçus sur WhatsApp et mes autres réseaux (Facebook/Instagram) : horaires, tarifs, disponibilités, informations générales.

[062] L'IA doit qualifier l'intérêt du client (ex. type de produit recherché, budget) et faire progresser la conversation vers la vente.

[063] Dès que la vente est proche d'être conclue (ou sur demande explicite du client), l'IA doit transférer la conversation à moi ou à un membre de mon équipe, avec un résumé de l'échange.

[064] Je dois pouvoir revoir/modifier les réponses type de l'IA à tout moment (pas de contenu figé).

[065] 9. Rapports et tableau de bord

[066] Une page d'accueil (tableau de bord), consultable depuis mon téléphone, doit m'afficher en un coup d'œil :

[067] chiffre d'affaires du jour / de la semaine / du mois ;

[068] total des paiements en attente (impayés) ;

[069] valeur du stock actuel et alertes de réapprovisionnement ;

[070] nombre de rendez-vous et relances du jour ;

[071] ventes par vendeuse/caissière, pour comparer et détecter une anomalie.

[072] 10. Contraintes techniques

[073] Accessible sur téléphone (Android/iPhone) et sur ordinateur/tablette à l'accueil et à la caisse.

[074] Fonctionnement possible même en cas de coupure internet temporaire, avec synchronisation automatique au retour de la connexion (à confirmer avec le prestataire selon la solution retenue).

[075] Interface en français.

[076] Sauvegarde automatique des données (aucune perte de données patient ou de vente en cas de panne).

[077] Respect de la confidentialité des données médicales des patients (accès restreint selon les rôles définis en section 2).

[078] 11. Questions à poser au prestataire avant de signer

[079] Le blocage "pas de sortie d'article sans vente enregistrée" (section 5) est-il possible techniquement dans votre solution ? Comment ?

[080] Le système fonctionne-t-il hors-ligne en cas de coupure internet ou électricité ?

[081] L'envoi WhatsApp passe-t-il par l'API officielle Meta/WhatsApp Business ?

[082] Quel est le coût : mise en place, abonnement mensuel, coût par utilisateur supplémentaire ?

[083] Qui héberge mes données et puis-je les récupérer si je change de prestataire un jour ?

[084] Combien de temps pour la mise en place et la formation de mon équipe ?

[085] Un support est-il disponible en cas de panne ou de question, et sous quel délai ?

[086] Ce document reflète mes besoins fonctionnels ; c'est au prestataire de proposer la solution technique (logiciel existant adapté, ou développement sur mesure) qui les couvre.
