# Automatisations et intégrations

## Autorité et dépendances

Application métier : patients, clinique, factures, règlements, stock et agenda interne. Kommo : prospects, conversations et reprise humaine, conformément à PC. Google Calendar : représentation externe des RDV et indisponibilités du cabinet. Site : catalogue public et entrée de réservation. Toute intégration utilise les mêmes commandes métier, sans mise à jour directe de la base.

L0 ne dépend d’aucun envoi externe. Les interfaces fournisseurs et événements sont préparés, les adaptateurs réels activés en L2 après test des comptes. Vérifier l’offre Kommo et ses capacités de webhooks/API ; le budget de la proposition ne prouve pas l’accès à une offre précise.

## Automatismes L0

| Déclencheur | Action |
| --- | --- |
| Création patient | Numéro unique, date automatique, contrôle de doublons |
| Date naissance/consultation | Âge affiché à la bonne date |
| Sélection produit | Description/prix/unité proposés depuis catalogue validé |
| Émission facture | Calcul total, numéro, snapshot, réservation et audit |
| Paiement confirmé | Nouveau reçu, solde recalculé, tableau impayés actualisé |
| Prescription et consultation finalisées | Version conservée, PDF proposé |
| Contrôle recommandé | Date calculée, tâche interne et liste quotidienne |
| Création/modification RDV | Tâches internes selon politique ; annulation des anciennes tâches |
| Livraison | Sortie réelle de stock et consommation des réserves |
| Fin de journée | Sauvegarde et contrôle de succès ; clôture de caisse humaine |

Le scheduler serveur s’exécute chaque minute si disponible. En mode hors-ligne, la PWA prépare tâches/calculs internes ; aucun message externe ni sync Google avant ACK central de la source. Une tâche peut porter un canal souhaité WhatsApp sans prétendre à un envoi L0. Distinguer « à contacter », « notification interne » et « transmission confirmée ».

## Contrôles cliniques et rappels

Une recommandation = date souhaitée de contrôle ; un rappel = moment où contacter ; un RDV = créneau confirmé. Le professionnel choisit le délai et, pour collyre, précise si 10–14 jours sont comptés depuis consultation/début de traitement ou fin de traitement. En l’absence de précision, le contrôle après collyre doit être confirmé manuellement, jamais calculé sur une durée supposée.

Politique routine : 3/6 mois, 1/2 ans ou personnalisée. Politique collyre : 10/12/14 jours ou personnalisée. DP : rappel 1/3/7 jours ou un mois avant. Un suivi sans créneau produit une tâche pour proposer le contrôle, pas une confirmation de RDV inventée.

Pour un RDV confirmé, politique PC : J-1 à 18h00 locale avec demande de confirmation, puis H-2 avec localisation et consignes. Si réservation après une échéance, ne pas expédier rétrospectivement un rappel déjà inutile ; conserver le motif d’omission. Des heures calmes peuvent décaler un message sans changer le RDV. Les coordonnées GPS réelles restent à fournir.

Clé de tâche : cabinet + source + version de programmation + politique + échéance + canal. Replanifier invalide les tâches de l’ancienne version ; vérifier état/version de source juste avant envoi. Regrouper les demandes superposées si même patient/date sans dévoiler de données, tout en conservant les liens vers les sources.

Une tentative humaine possède date, auteur, résultat contacté/RDV pris/sans réponse/refus/report. Un refus annule les relances correspondantes. Un changement de téléphone ne transfère pas automatiquement les consentements d’un ancien numéro au nouveau.

## WhatsApp et SMS L2

WhatsApp via API officielle Meta ou connexion officielle fournie par Kommo, un seul système propriétaire de chaque envoi. Pas d’automatisation par WhatsApp Web ou session QR non officielle. Hors fenêtre de service de 24 h, employer les templates approuvés selon la politique actuelle ; vérifier leur validité avant activation et évolution. Source : [politique officielle WhatsApp](https://business.whatsapp.com/policy).

Consentement traçable par canal et finalité ; retrait effectif ; segmentation sans pathologie révélée ; numéros partagés traités prudemment. Messages administratifs neutres : invitation à contacter le cabinet ou rappel de RDV, sans diagnostic, médicament ou dette détaillée. La relance de solde nécessite identifier le destinataire avant révélation du montant. Pas de facture clinique ou dossier joint automatiquement.

L’API officielle et le consentement réduisent le risque de restrictions, sans garantir qu’aucun compte ne sera bloqué. Campagnes soumises à limites, qualité de compte, opt-out et approbation de contenu. Les promotions de médicaments ou données de santé exigent vérification préalable des règles du fournisseur et du contexte applicable ; ne pas les activer par défaut.

SMS via adaptateur fournisseur choisi ultérieurement ; coût, pays et autorisations à confirmer. Une tâche non délivrée peut être affectée à un appel humain ; pas de bascule payante vers un second canal sans politique validée.

Tentatives : timeout/rate limit → nouvelle tentative bornée avec backoff/jitter et `Retry-After`. Réponse définitive invalide → échec visible. Timeout après soumission peut signifier « état inconnu » : réconcilier avec identifiant fournisseur quand possible avant de renvoyer. Ne pas promettre une déduplication externe que le fournisseur ne supporte pas.

## Google Calendar L2

OAuth avec périmètre minimal, tokens chiffrés, calendrier dédié à droits limités. Stocker correspondance RDV ↔ événement Google et versions/etag. Ne pas publier les antécédents, mesures ou conclusions dans les titres/descriptions. Le détail de motif clinique prévu largement par PC est remplacé par une catégorie administrative pour préserver la confidentialité de CDC.

Disponibilité = horaires cabinet − RDV actifs − blocs internes − indisponibilités Google. Un créneau proposé n’est pas garanti tant qu’il n’est pas confirmé sous verrou serveur. Relecture des indisponibilités récentes avant confirmation externe ; intervalle demi-ouvert `[début, fin)` pour permettre un RDV exactement après le précédent.

Créer/déplacer/annuler un RDV interne produit un événement d’outbox. Réserver en interne est atomique ; Google n’est pas dans la transaction DB. En cas d’échec, afficher synchronisation en attente et réessayer. Les créneaux proposés au bot sont suspendus ou transférés à un humain si le cache Google est trop ancien.

Synchronisation incrémentale avec `syncToken`, pagination complète avant mise à jour du token. Token invalide/410 : refaire une synchronisation complète des ressources externes, sans supprimer les patients/RDV internes. Notifications push = signal pour resynchroniser, pas contenu intégral ni preuve de changement ; renouveler les canaux à expiration. Voir [synchronisation Google](https://developers.google.com/workspace/calendar/api/guides/sync) et [notifications Google](https://developers.google.com/workspace/calendar/api/guides/push).

Événement Google non lié : importer comme indisponibilité. Événement lié modifié extérieurement : vérifier conflit/version, mettre à jour les informations administratives autorisées, journaliser et reprogrammer les rappels. Une suppression externe propose/traite l’annulation selon règle validée, sans effacer l’historique. Une collision créée simultanément depuis Google et le système produit une alerte gérante ; aucune architecture répartie ne garantit une exclusion atomique entre deux systèmes indépendants.

## Kommo et assistant L2

Conserver Kommo comme prévu par PC. Connecter le numéro WhatsApp et les pages réelles avec accès autorisé et offre vérifiée. Pipeline proposé : Nouveau contact → Besoin qualifié → Créneau proposé → RDV confirmé → Reprise humaine → Vente suivie/clos. Les identifiants contact/prospect restent distincts des dossiers patients ; liaison explicite au moment de l’enregistrement ou rapprochement confirmé.

OAuth/API documentés. Webhooks persistés rapidement puis traités en arrière-plan ; récupérer les données de l’entité depuis l’API pour confirmer le compte/la source quand aucune signature n’est fournie pour ce type de hook. Ne pas supposer que la signature du callback d’installation existe aussi sur tous les événements CRM. Réconciliation périodique nécessaire pour les événements perdus ; références [Kommo OAuth](https://developers.kommo.com/docs/oauth-20) et [webhooks](https://developers.kommo.com/docs/webhooks-general).

L’assistant répond sur horaires, localisation, services, tarifs publics validés, disponibilités et technologies de verres. Qualifier type de besoin et budget sans établir de diagnostic. Questions d’assurance : seulement informations validées ; ne pas inventer une prise en charge. Les textes sont gérés par la gérante et versionnés.

Transfert obligatoire si demande explicite, vente proche de conclusion, incertitude, prix dérogatoire, insatisfaction ou question médicale nécessitant le professionnel. Résumé administratif pour l’humain, reprise par membre autorisé ; état `HUMAN_TAKEOVER` bloque les réponses automatiques jusqu’à reprise explicite. Pour une situation décrite comme urgente, orienter vers une prise en charge humaine sans prescrire.

Un message utilisateur peut contenir des instructions malveillantes : le bot ne change ni ses permissions ni ses règles métier. Les outils possibles sont ceux du fichier 08 ; réponse du modèle validée par code avant réservation. Le moteur IA exact, ses coûts et le mode Kommo natif ou service connecté sont une décision L2 à prendre après vérification de licence ; ne pas remplacer le CRM par une nouvelle application sans accord explicite.

## Atelier et site

Passage Atelier prêt : une notification par commande/version Prêt, mention neutre « Votre équipement est disponible au cabinet ». Retour en fabrication ne renvoie rien ; nouveau passage Prêt après une reprise peut nécessiter une nouvelle notification identifiée et approuvée.

Site L3 : service de disponibilité public, demande de réservation, showroom des seules références publiées. Le stock détaillé et les prix d’achat ne sont pas publics. Deux entrées web/WhatsApp confirmant le même créneau utilisent la même action de réservation et les mêmes verrous. Publicités Meta conduisent au contact/booking existants ; aucun moteur d’achat publicitaire maison n’est ajouté.

## Supervision

Gérante/prestataire : liste des tâches en échec, canal, type administratif, dernière erreur filtrée, essais, prochaine tentative et action Reprendre/Annuler. Indicateurs : retard scheduler, taux de livraison, délai sync et expiration tokens/canaux. Un échec fournisseur ne fait pas disparaître une consultation ou un paiement déjà validé.
