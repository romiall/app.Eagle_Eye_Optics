# Spécifications fonctionnelles

Les identifiants EE renvoient à la matrice du fichier 17. Toute l’application décrite ici est conçue dès maintenant. L0 est la priorité de réalisation ; L1–L3 ordonnent les réalisations suivantes, sans réduire le modèle de données complet.

## Patients et accueil EE001 EE002 EE018 EE019

La fiche patient est commune à la clinique et à la vente. Champs : nom, prénom(s), sexe féminin/masculin ou non renseigné, date de naissance, âge calculé, téléphone principal, téléphone secondaire, adresse/quartier et profession. Le numéro `EE-AAAA-NNNN` et la date de création sont automatiques ; la séquence peut dépasser quatre chiffres sans troncature.

Le nom est obligatoire. Téléphone et date de naissance sont demandés à l’accueil ; si inconnus, enregistrer la raison, sans inventer une date. Afficher « âge non renseigné » si nécessaire. Un prénom peut être absent. Pour un mineur, un contact responsable peut compléter la fiche ; extension proposée, facultative et clairement séparée de DP. L’âge est calculé à la date courante ; un âge à la consultation est calculé à sa date, jamais stocké à la place de la naissance.

Recherche à partir de deux caractères, insensible à la casse et aux accents pour les noms, et normalisée pour les téléphones. Résultats avec nom, numéro, âge disponible et téléphone. Plusieurs patients peuvent partager un téléphone ; signaler les ressemblances avant création, sans fusion automatique. Une fusion manuelle gérante, avec conservation des identifiants historiques, est une amélioration L1.

À l’arrivée, créer une visite horodatée avec motif administratif : examen de vue, achat direct, retrait de commande, contrôle de suivi. Un achat n’impose pas de créer un examen vide. La visite affichée au dossier peut contenir une consultation, une vente, une livraison ou plusieurs de ces éléments. Une nouvelle consultation crée une nouvelle entrée et ne remplace aucune précédente.

La file du jour distingue arrivé, en attente de paiement examen, prêt pour examen, en consultation, terminé et annulé. Le paiement de l’examen se fait à la caisse ou via la permission spéciale accueil. Le démarrage clinique nécessite une facture examen soldée ou une dérogation gérante enregistrée avec motif. Un contrôle gratuit nécessite cette dérogation ou une prestation explicitement paramétrée gratuite par la gérante.

## Consultation et antécédents EE003 EE004 EE005 EE013

L’en-tête affiche identité, dossier, date/heure de consultation et professionnel. Date effective modifiable avec motif si la saisie est rétrospective ; dates techniques de création et modification restent automatiques. Le droit de saisir une consultation historique ne dispense pas d’expliquer la situation du règlement de l’examen.

Motifs en sélection multiple : baisse de vision, vision floue, céphalées, fatigue visuelle, difficulté de loin, difficulté de près, renouvellement de lunettes, contrôle de routine, rougeur, larmoiement, démangeaisons, douleur, photophobie, autre. Texte libre plaintes/symptômes. Si Autre, préciser. « Porte actuellement des lunettes » oui/non/non renseigné ; si oui, durée en texte et ancienne correction disponible oui/non/non renseigné.

Antécédents, selections multiples et commentaires :

| Groupe | Valeurs initiales |
| --- | --- |
| Oculaires | Aucun connu, traumatisme, chirurgie, glaucome, cataracte, infection, lentilles, autre |
| Médicaux | Aucun connu, diabète, hypertension, allergies, traitement en cours, autre |
| Familiaux oculaires | Aucun connu, glaucome, cataracte, forte myopie, autre |

« Aucun connu » est exclusif des autres valeurs du même groupe ; absence de saisie signifie non renseigné, pas aucun connu. Les antécédents sont un état observé pendant une consultation. La précédente saisie peut être proposée en lecture pour reprise explicite ; elle ne doit pas mettre à jour rétroactivement les consultations.

État du travail : brouillon, en cours, terminé. « À revoir », « Orienté vers ophtalmologiste » et « Rendez-vous programmé » sont des badges dérivés du suivi, de l’orientation et de l’agenda. Une consultation terminée peut ainsi être orientée et avoir un RDV. Terminer fige une version ; une correction ultérieure exige motif, auteur et nouvelle version consultable.

Le professionnel peut enregistrer un dossier incomplet. À la finalisation, exiger motif ou plaintes, conclusion ou justification d’absence, et résolution des erreurs de validation. Afficher les rubriques non renseignées ; ne pas exiger des mesures non réalisées. Une prescription imprimée requiert en revanche des valeurs explicitement saisies ou la mention de l’absence de correction par œil concerné.

## Examens et réfraction EE006 EE007 EE008

Acuités : quatre combinaisons loin/près × sans/avec correction, chacune avec OD, OG, OU. Douze valeurs distinctes, toutes facultatives quand non mesurées. Sélection de l’échelle utilisée, valeurs usuelles configurables et saisie libre ; conserver la valeur originale. Une valeur « 10/10 », « 6/6 », « P2 » ou qualitative ne devient pas un nombre comparable sans échelle commune et conversion validée.

Autorefractomètre : sphère, cylindre, axe par OD et OG ; DP totale ou DP OD/OG. Réfraction objective : sphère, cylindre, axe OD et OG. Réfraction subjective : sphère, cylindre, axe, addition OD et OG. Acuité après correction : OD, OG, OU avec échelle ; ne pas présumer une distinction loin/près absente de DP pour cette dernière rubrique.

Amétropies en sélection multiple : emmétropie, myopie, hypermétropie, astigmatisme, presbytie, anisométropie, autre. Observations libres. Aucune inférence médicale automatique à partir des chiffres ; les choix appartiennent au professionnel.

Les champs décimaux acceptent la virgule française et le signe ; zéro est une valeur, distincte du vide. Un cylindre non nul nécessite un axe pour imprimer la prescription ; l’axe est compris entre 0 et 180 degrés. Les bornes de sphère/cylindre/addition, les pas usuels et la convention cylindre sont configurés et validés par le professionnel, sans exclure arbitrairement une mesure plausible. Ne jamais transposer silencieusement un cylindre positif en négatif.

## Prescription optique EE009 EE014

Prescription distincte des mesures objectives et subjectives. Copier une réfraction nécessite un bouton explicite et une confirmation ; éditer ensuite la prescription ne modifie pas l’examen source.

| Élément | Valeurs ou champs |
| --- | --- |
| Correction par œil | OD/OG sphère, cylindre, axe, addition, mention absence de correction si nécessaire |
| Centrage | DP totale ou monoculaire OD/OG, hauteur de montage OD/OG en mm |
| Usage | Vision de loin, vision de près, lunettes permanentes, progressifs, bifocaux, travail/ordinateur, autre |
| Type de verre | Unifocal, bifocal, progressif, anti-fatigue, autre |
| Indice | 1.50, 1.56, 1.59, 1.60, 1.61, 1.67, 1.74, autre |
| Traitements multiples | Anti-reflet, photochromique, UV, filtre lumière bleue/Blue-Cut, durci/anti-rayures, teinté, HMC, autre |

DP totale et monoculaire peuvent coexister si cohérentes. La somme OD+OG est proposée ; en cas d’écart supérieur à la tolérance configurée, exiger correction ou justification. Le système ne fabrique pas une DP monoculaire à partir du seul total. Hauteur et valeurs numériques ont une unité visible.

La prescription validée est imprimable et téléchargeable en PDF. Une prescription brouillon porte cette mention. Nom du professionnel et date sont présents ; les mentions professionnelles, signature et cachet réels doivent être fournis avant usage des documents officiels.

## Médicaments et orientation EE010 EE011

Plusieurs lignes : nom, forme collyre/pommade/comprimé/autre, dosage/concentration, posologie, fréquence, durée et instructions. La prescription peut référencer le catalogue, mais un médicament non vendu au cabinet peut être prescrit en texte libre. Prescrire ne décrémente pas le stock et ne crée pas automatiquement une vente.

Une durée structurée en nombre et unité accompagne le texte si un suivi doit être calculé. Le professionnel choisit le contrôle après collyre, par exemple 10, 12 ou 14 jours. Aucun choix médical automatique entre ces durées.

Conclusion texte. Orientation ophtalmologiste oui/non/non renseigné. Si oui, motif(s) : suspicion de glaucome, cataracte, baisse d’acuité inexpliquée, pathologie cornéenne, œil rouge/inflammation, traumatisme, pathologie rétinienne suspectée, autre. Niveau obligatoire : pas urgent, consultation recommandée, consultation rapide, urgence. Rendre ce niveau visible pour le professionnel ; ne pas l’exposer à la caisse ni aux outils commerciaux.

## Rendez-vous et contrôle EE012 EE031 EE032 EE033 EE034

Un contrôle recommandé possède un délai, une date de départ, une date calculée, un canal souhaité, une politique de rappel et des observations. Délais 10/12/14 jours, 3/6 mois, 1/2 ans et autre/date exacte. Routine : départ depuis consultation effective. Collyre : professionnel précise début/fin de traitement ou consultation ; pas de calcul sur durée supposée. Calendrier civil : 31 janvier + 1 mois donne le dernier jour de février. L’utilisateur peut corriger explicitement la date et en voir la provenance.

Un contrôle recommandé ne réserve pas automatiquement une heure. Le bouton Programmer un RDV choisit service, professionnel/ressource, début et durée, puis confirme. Agenda en vues jour et semaine ; RDV confirmé, arrivé, terminé, annulé, absent. Une modification met à jour les rappels et conserve la précédente programmation dans le journal.

Rappels DP : activé/désactivé, canal SMS/WhatsApp/interne, délai 1/3/7 jours ou 1 mois avant. Rappels PC sur RDV confirmé : J-1 à 18h00 et H-2. L0 calcule les tâches internes ; L2 active les transmissions externes. Une liste quotidienne permet contacté, RDV pris, sans réponse, refus et reporté. Un même patient n’est pas relancé deux fois pour le même événement et la même échéance.

## Historique et statistiques EE003 EE015 EE029

Liste chronologique des consultations avec date, motif, professionnel, acuité, réfraction, correction, traitement, conclusion, orientation et contrôle. Ouvrir une consultation en un clic. Comparaison de deux visites : mesures OD/OG, échelle, valeurs non mesurées et prescriptions ; jamais agréger des acuités d’échelles incompatibles.

Filtres aujourd’hui, semaine, mois, année et intervalle personnalisé. Semaine commence lundi ; période bornée dans le fuseau du cabinet. Patients du jour = patients distincts avec visite arrivée ce jour ; nouveaux = première visite au cabinet pendant la période, anciens = autres patients de la période. Séparer date de création administrative et première visite si une reprise historique a eu lieu.

Statistiques DP : consultations, nouveaux patients, répartition femmes/hommes/non renseigné, âge non renseigné et tranches configurables, motifs, prescriptions de lunettes, orientations, RDV programmés, patients à revoir. Afficher la définition et le dénominateur des ratios. Tranches proposées 0–17, 18–39, 40–59, 60+ à confirmer. Les tableaux sont suffisants en L0.

La gérante voit CA, encaissements par canal, créances, ventes par caissière, valeur du stock et alertes. Les autres rôles voient seulement leurs tâches et les informations autorisées. Une secrétaire ne voit pas de tableau financier global même si elle encaisse un examen.

## Atelier et stock EE020 EE025 EE026 EE027 EE028

Catalogue : référence unique, catégorie prestation/monture/verre/lentille/collyre/dermocosmétique/autre, marque, modèle, prix d’achat privé, prix de vente, stock géré ou service, seuil, image publique autorisée. Les verres sur mesure sont distingués des verres réellement stockés. Une paire de verres peut être une ligne unité « paire » ; ne pas la décrémenter comme deux unités sans configuration.

Atelier L1 : facture et prescription liée, monture, caractéristiques verres OD/OG, laboratoire, date commande, échéance, étapes commandé → reçu → taillé/monté → contrôle qualité → prêt → livré/ajusté. Enregistrer auteur et date de chaque étape, retards et reprises. Le passage Prêt crée une notification idempotente L2. Bon de commande labo sans éléments financiers patient inutiles.

Stock : réception validée, réserve de vente, remise physique, retour, casse, retour fournisseur, correction d’inventaire. Motif et gérante pour toute sortie non liée à une vente. Comptage physique, écarts, valorisation au coût d’achat connu et suivi des lots/péremptions. Les règles transactionnelles sont détaillées au fichier 03.

## Communication site et accompagnement EE035 à EE043

Kommo centralise les conversations conformément à PC. L’assistant répond aux FAQ validées, explique les produits, qualifie besoin et budget, propose 2–3 créneaux et transmet à un humain lorsque nécessaire. La gérante modifie FAQ et réponses types. L’application métier reste maîtresse des patients, factures, paiements et stocks ; le CRM ne devient pas le dossier médical.

Campagnes ciblées : critères administratifs autorisés, consentement par catégorie, prévisualisation de la cible, validation humaine de l’envoi et suivi des échecs. Le segment impayés sert à une relance de gestion, sans annoncer une dette à un numéro partagé ou en groupe. Détails au fichier 10.

Le site public expose présentation, showroom, réservation connectée, WhatsApp, localisation et horaires de PC ; seuls les produits et contenus approuvés deviennent publics. Le raccordement au site existant est décidé après audit. Emails `direction@eagleeyeoptic.com` et `contact@eagleeyeoptic.com` configurés sur l’infrastructure existante si disponibles. Formation métier pour L0, formation vidéo smartphone de PC en L3, guide de sauvegarde/restauration et support.
