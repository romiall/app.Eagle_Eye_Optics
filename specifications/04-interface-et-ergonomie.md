# Interface et ergonomie

## Direction proposée

Interface française calme, lisible et rapide pendant une consultation. Les éléments cliniques utilisent des tableaux OD/OG/OU stables ; les éléments financiers mettent en évidence le total, le payé et le reste. Cette direction est une proposition de design ; aucun logo ou fichier de charte n’a été fourni.

Palette initiale : fond `#F6F8FB`, surface blanche, texte `#172B3A`, action principale bleu `#155E75`, accent vert `#167D65`. Vérifier le contraste de chaque association et des états désactivés. Ne jamais communiquer un état uniquement par couleur. Police système avec accents français ; taille de texte 16 px courante ; contrôles tactiles d’au moins 44 px. Pas de dépendance à un service externe de police pour les écrans cliniques ou les PDF.

## Navigation

Navigation ordinateur : Aujourd’hui, Patients, Consultations, Agenda, Ventes et caisse, Stock, Atelier, Communication, Rapports, Paramètres. Afficher les modules réellement actifs et autorisés. Sur téléphone : raccourcis Aujourd’hui, Patients, Agenda et Plus ; menus accessibles au clavier et lecteurs d’écran. L0 n’affiche pas des écrans futurs vides comme si leurs fonctions existaient.

En-tête « EAGLE’S EYE OPTICS MEDICAL – GESTION DES PATIENTS », recherche rapide, compte connecté et état réseau/synchronisation. Actions d’accueil DP : Nouveau patient, Rechercher, Rendez-vous, Consultations du jour, Statistiques, Paramètres. La recherche globale ne doit pas montrer à la caisse des extraits cliniques.

## Écrans L0

| Écran | Contenu et action principale |
| --- | --- |
| Aujourd’hui | File patients, RDV, contrôles et alertes ; Nouveau patient et Nouvelle vente selon rôle |
| Patients | Recherche, résultats, filtres simples ; créer seulement après vérification des résultats |
| Nouveau patient | Identité, contact, doublons possibles, Enregistrer puis Nouvelle consultation |
| Dossier | En-tête identité ; onglets Identité, Consultation, Examens, Réfraction, Prescription, Suivi, Historique |
| Consultation | Étapes DP avec enregistrement visible, tableau OD/OG/OU, prochaine étape |
| Facture | Patient sélectionné, lignes catalogue, totaux et paiements ; Émettre puis Encaisser |
| Encaissement | Facture/solde visibles, montant/mode/référence ; Confirmer et imprimer le reçu |
| Impayés | Patients, pièces, ancienneté et reste ; ouvrir la facture existante |
| Agenda | Jour/semaine, créer/modifier RDV, état et disponibilité |
| Gestion gérante | Approbations, paramètres, audit, exports et état des sauvegardes |

La section Ventes du dossier est un accès séparé, conditionné par les droits ; elle n’efface pas les sept onglets cliniques demandés. Les coordonnées patient sont communes ; les consultations et prix d’achat restent restreints.

## Consultation guidée

Étapes : Motif → Antécédents → Acuité → Autorefractomètre → Réfraction → Prescription optique et médicaments → Conclusion/orientation → Suivi/RDV. Dans le dossier, les onglets regroupent ces étapes. L’utilisateur peut revenir à une étape sans perdre les saisies déjà confirmées.

En-tête patient fixe sur écran large, compact sur mobile. Boutons Précédent, Enregistrer, Suivant, Terminer. « Suivant » valide et sauvegarde l’étape durablement dans le coffre IndexedDB, puis la synchronise. Afficher « Enregistré sur ce poste à 10h42 », « Synchronisé » ou « Échec de l’enregistrement ». Une sauvegarde automatique temporisée est possible, mais la sauvegarde explicite reste disponible.

Quitter une page avec données non sauvegardées demande confirmation. Ne pas persister de données médicales dans `localStorage`. Le coffre local chiffré du fichier 19 conserve les saisies après fermeture/reprise ; les données ne sont déclarées sauvegardées qu’après commit local réussi. Un patient absent du cache apparaît comme « Recherche serveur indisponible », jamais « Aucun dossier existant » avec certitude.

Pour les mesures, OD toujours avant OG, OU seulement pour l’acuité. Libellés et unités répétés au besoin. Sur mobile, blocs OD puis OG, sans tableau illisible ni défilement horizontal nécessaire pour saisir. Une prescription ne copie pas automatiquement une réfraction en arrière-plan.

## Facturation rapide

```text
Patient : [ Rechercher un dossier ]       Facture : Brouillon
Article : [ Rechercher dès 2 caractères ]
Référence / Désignation       Qté    Prix     Remise     Total
Examen de vue                 1      ...      ...        ...
Monture ...                   1      ...      ...        ...

Total : ... FCFA     Versé : ... FCFA     Reste : ... FCFA
[Enregistrer brouillon] [Émettre la facture]
Après émission : [Encaisser] [Imprimer] [Remettre article]
```

Le prix catalogue est proposé. Si la caissière souhaite une remise, afficher Demander une autorisation. Avant l’encaissement, résumer montant, mode et solde après paiement ; un bouton désactivé pendant la commande réduit les doubles clics, complété par l’idempotence locale et serveur. Après commit local durable ou confirmation serveur, ouvrir le reçu existant avec Imprimer et Télécharger, avec son état de synchronisation visible dans l’application.

Le montant reçu en espèces et la monnaie rendue peuvent être une aide de caisse distincte du paiement enregistré. Ne pas enregistrer un paiement supérieur au solde sous prétexte de rendre la monnaie.

## États et accessibilité

États chargement, vide, succès, erreur, conflit de version, droits insuffisants et connexion indisponible conçus pour chaque écran. Erreurs sous le champ et résumé focalisé en haut ; conserver les saisies valides. Les boutons d’action précisent leur conséquence : Émettre la facture, Enregistrer le paiement, Confirmer la remise.

Clavier : ordre de tabulation logique, focus visible, sélection du catalogue avec flèches/Entrée ; modales avec focus contenu et restitution au déclencheur. Labels réels, boutons nommés, messages d’enregistrement annoncés sans répétition excessive. Cibles WCAG 2.2 AA comme objectif de qualité, à vérifier par tests clavier et contraste.

Éviter effets animés inutiles, notifications qui masquent les boutons et dashboards surchargés. Filtres conservés pendant la navigation de la session sans conserver de dossier patient dans l’URL publique. Pagination serveur et chargement du seul onglet nécessaire.

## Critères ergonomiques de recette

Sur catalogue et données de test, un opérateur formé doit pouvoir créer une identité simple en moins d’une minute, retrouver un dossier en quelques secondes et émettre une facture simple puis un reçu sans ressaisie du patient. Ce sont des objectifs à mesurer pendant la recette, pas des performances déjà prouvées.

Tester ordinateur 1366×768, tablette et téléphone 360 px de large, zoom navigateur 200 %, clavier, Android/iPhone, impression A4 en noir et blanc. La gérante doit retrouver un impayé et une demande d’autorisation depuis son téléphone.
