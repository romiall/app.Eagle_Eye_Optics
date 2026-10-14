# Périmètre et livraisons

## Socle intégral défini dès maintenant

Le socle décrit **l'application entière** issue de la proposition commerciale finale, du cahier des charges optique et du modèle de dossier patient. Le modèle conceptuel, le modèle relationnel, le dictionnaire et le schéma SQL couvrent immédiatement tous les domaines ci-dessous. Il n'existe pas un modèle de données provisoire limité à l'urgence qui devrait être remplacé pour les autres modules.

Les lots L0 à L3 désignent uniquement **l'ordre de réalisation, de vérification et d'activation**. Une fonctionnalité prévue en L1, L2 ou L3 est déjà conçue, mais cette conception n'affirme ni qu'elle est codée ni qu'elle est opérationnelle. Les comptes externes, modèles officiels et paramètres métier réels doivent encore être fournis et validés avant leur activation. Les migrations applicatives suivent ce modèle complet et restent non destructives.

| Domaine couvert par le socle complet | Données et comportements à conserver | Première réalisation prévue |
| --- | --- | --- |
| Organisation et accès | Cabinet, comptes individuels, rôles/permissions, paramètres, accès par domaine, audit et approbations | L0 |
| Patients et clinique | Identité durable, visites, consultation DP entière, antécédents, acuités, réfraction, prescriptions optiques et médicaments, orientation, versions et historique | L0 |
| Agenda et suivi | Ressources, prestations, RDV, indisponibilités, recommandations de contrôle, politiques de rappel, tâches et tentatives de contact | Agenda/suivi interne L0 ; connexion Google L2 |
| Catalogue et facturation | Prestations/articles, unités/prix/taxes, factures et lignes figées, acomptes/soldes, reçus, contre-écritures, avoirs, remboursements, documents et numérotation | L0 |
| Stock et achats | Emplacements, positions, lots/péremptions, réservations, livraisons et retours, mouvements, fournisseurs, achats/réceptions et inventaires | Sécurité et remise L0 ; gestion fournisseurs/inventaires L1 |
| Atelier | Équipement facturé, version de prescription, laboratoire, commande verrier, montage, contrôle qualité, reprises et livraison | L1 |
| Caisse et gestion | Sessions, mouvements autorisés, dépenses, rapprochements, créances historiques, exports et indicateurs | Caisse simple/reprise nécessaire L0 ; gestion détaillée L1 |
| Communication et CRM | Prospects distincts des patients, conversations administratives, reprise humaine, consentements, contenus/templates, campagnes, transmissions et supervision | Modèle/contrats maintenant ; activation Kommo/Meta/SMS et assistant L2 |
| Intégrations | Comptes et correspondances externes, Google Calendar, Kommo et canaux officiels, inbox de webhooks, outbox et réconciliation | Contrats et fiabilité L0 ; adaptateurs connectés L2 |
| Site public | Catalogue publiable, médias/contenus validés, showroom et réservation reliée au même agenda métier | L3 |
| Hors-ligne | Postes enrôlés, autorisations signées, plages de numéros, propriétaire des écritures par facture, quotas de stock, commandes, synchronisation, conflits et sauvegardes locales | L0, obligatoire |
| Exploitation et accompagnement | Sous-domaine, HTTPS, hébergement simple sans VPS, sauvegardes/restaurations, exports privés, guides, formation métier et formation vidéo smartphone | Exploitation/formation métier L0 ; accompagnement complet L3 |

Les tableaux de bord exploitent les écritures et historiques des domaines ci-dessus ; ils n'introduisent pas un second registre de vérité. Les emails professionnels et la formation vidéo sont des livrables de configuration/accompagnement ; ils n'exigent pas un service de messagerie ou un logiciel de montage créé dans l'application.

## Résultat attendu

Remplacer les registres patient et la facturation manuelle par une application unique. Un opérateur doit retrouver un patient, ajouter une consultation, produire une prescription ou une facture et recevoir un paiement sans ressaisir l’identité entre les modules. La gérante voit les créances et les encaissements, ainsi que l’auteur des opérations.

Cabinet initial unique à Douala ; interface française ; monnaie XAF affichée FCFA ; fuseau métier `Africa/Douala`. Paramètres d’ouverture initiaux 08h30–17h00 issus de PC, jours ouvrés et durée des créneaux à confirmer. Les instants sont enregistrés en UTC puis affichés dans le fuseau du cabinet.

## L0 Première mise en service sous 14 jours calendaires

L0 doit être utilisable même si Kommo, Meta et Google ne sont pas encore configurés.

| Domaine | Contenu obligatoire L0 |
| --- | --- |
| Accès | Comptes individuels, quatre rôles métier, politiques serveur, journal consultable par la gérante |
| Patients | Identité complète DP, numéro automatique, recherche nom/téléphone/dossier, doublons signalés |
| Visites | Examen, achat direct, retrait, suivi ; file du jour ; visite administrative sans obligation d’examen |
| Consultation | Tous les champs du modèle DP §§4–10, OD/OG/OU, antécédents, prescriptions, orientation ; sauvegarde par étape |
| Historique | Consultations distinctes, dossier et prescription en PDF, correction versionnée après finalisation |
| Facturation | Catalogue réel de départ, autocomplétion, brouillon, facture numérotée, impression A4, export PDF |
| Paiements | Acomptes libres, soldes, modes espèces/MoMo/Orange/virement/autre, reçu pour chaque encaissement |
| Contrôle | Examen payé ou dérogation ; impayés ; annulation/avoir/remboursement et remise soumis aux droits définis |
| Marchandises | Stock initial validé, réservation et sortie à la remise, aucune sortie applicative sans pièce ou motif autorisé |
| Agenda et suivi | Agenda interne simple, contrôle calculé, rappels internes et liste des patients à contacter |
| Tableau de bord | Patients/consultations/RDV du jour ; statistiques DP sous forme de tableaux ; CA, encaissements et impayés réservés à la gérante |
| Hors-ligne | Patients/consultations de travail, factures/paiements/reçus/PDF locaux, synchronisation et conflits conservés |
| Exploitation | Hébergement web simple sans VPS, HTTPS, sauvegardes serveur et local, restauration testée, prise en main de l’équipe |

Les médicaments sont prescrits dans L0 avec tous les champs DP. Si des collyres sont vendus dans L0, lot et péremption sont saisis, les lots expirés bloqués et les quantités tracées. Le suivi fournisseur et les inventaires élaborés arrivent en L1.

Pour tenir le délai, employer des formulaires structurés, des listes et des tableaux sobres. Une comparaison clinique en tableau suffit en L0 ; graphiques et analyses avancées sont ultérieurs. Les tests critiques, les permissions, la sauvegarde et l’immutabilité des pièces restent obligatoires.

## L1 Gestion complète du cabinet cible semaines 3 et 4

Réalisation des modules déjà définis dans le socle : atelier complet avec bon laboratoire, contrôle qualité et livraison ; achats/réceptions fournisseurs, inventaires et valorisation du stock ; alertes seuil et péremption ; rapprochement de caisse, dépenses et sorties d'argent autorisées ; export de gestion ; statistiques détaillées et comparaison visuelle de l'évolution. Reprise des créances historiques si elle n'a pas été nécessaire à la bascule L0.

## L2 Communication et rendez-vous connectés cible semaines 4 à 6

Kommo, canaux officiels WhatsApp/Messenger/Facebook/Instagram selon disponibilité des comptes, assistant commercial avec reprise humaine, agenda Google bidirectionnel, réservation omnicanale, rappels anti-no-show, suivi clinique par messages autorisés et notifications équipement prêt. Campagnes ciblées avec autorisation du destinataire et suivi de contact.

L’envoi réel dépend de l’ouverture des comptes, des droits, de l’offre Kommo et des templates Meta. Les contrats d’intégration sont définis maintenant ; les appels externes sont simulés dans les environnements de développement jusqu’à leur configuration.

## L3 Vitrine et accompagnement cible semaines 6 à 8

Showroom connecté au catalogue public autorisé, filtres montures, réservation depuis le site, coordonnées et localisation, contenu dermocosmétique, widget WhatsApp. Paramétrage des emails professionnels prévus par PC. Formation vidéo smartphone, guides utilisateurs, recette complète et démarrage de la garantie selon les conditions commerciales.

## Fonctionnement hors-ligne obligatoire dès L0

Le demandeur l’a confirmé. La PWA préparée en ligne fonctionne ensuite sans Internet pour enregistrer des patients, des consultations de travail, des factures, acomptes et soldes, et produire/imprimer leurs PDF. Les opérations sont confirmées durablement dans la base locale, puis synchronisées au retour du réseau. Elles portent un état clair « Enregistré sur ce poste — à synchroniser ».

Le poste doit avoir été enrôlé, l’application téléchargée, les droits et plages de numéros préparés, et les factures anciennes nécessaires chargées. Par défaut L0 utilise un poste principal de caisse pour les écritures financières hors-ligne ; les autres postes peuvent saisir les patients/consultations autorisés. Une ancienne facture détenue par un autre poste ne peut pas être encaissée hors-ligne sans transfert préalable de son droit d’écriture.

Numérotation et stock sont des ressources pré-allouées ; aucun stock disponible ou solde ne dépend d’un simple cache. Les documents sont réellement émis localement dans les bornes de l’autorisation préparée, et ne sont pas renumérotés à la synchronisation. Les modèles fiscaux et règles d’usage de cette série doivent être validés avant production. Voir fichier 19, obligatoire au même titre que les règles financières.

Le hors-ligne augmente le travail et les tests nécessaires ; le délai J14 demeure un objectif à mesurer à J2. Ne pas retirer cette exigence pour préserver une estimation initiale. Sans Internet, les messages externes et l’agenda Google attendent ; ils ne sont pas annoncés comme transmis.

## Hors du périmètre acquis

Pas de diagnostic médical par IA, de comptabilité fiscale certifiée ou de télétransmission assurance présumées. Pas de nouvelle boutique de paiement en ligne, d’OCR ni de connexion aux appareils sans demande complémentaire. Le système enregistre une prescription humaine et fournit des outils de gestion ; il ne calcule pas une ordonnance à partir de symptômes.

La possibilité de bloquer une sortie dans le logiciel ne rend pas physiquement impossible un retrait de rayon. Le contrôle repose également sur les procédures de remise, les inventaires et la clôture de caisse. Aucune spécification ne doit promettre l’absence de vol, de blocage Meta ou de perte de données en toutes circonstances.
