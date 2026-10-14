# Règles de facturation encaissement et stock

Ces règles sont vérifiées côté serveur et dans le moteur transactionnel local du fichier 19 pour les écritures hors-ligne autorisées. Une restriction visuelle seule est insuffisante. Le serveur les revalide lors de la consolidation sans perdre les pièces en litige.

## Objets financiers et calculs EE021 EE022 EE023 EE045

La facture enregistre la créance ; le paiement enregistre un encaissement ; le reçu prouve cet encaissement. Le devis est facultatif en L1 et ne remplace aucun de ces objets. Un acompte ne crée pas une nouvelle vente lorsqu’un solde est réglé.

Monnaie unique initiale XAF, montants en entiers FCFA signés 64 bits, jamais en float. Quantités décimales exactes `DECIMAL(12,3)` ; prix unitaire entier ; arrondir chaque ligne au FCFA le plus proche, demi supérieur, puis sommer les lignes. Pour L0, les articles physiques usuels ont une quantité entière ; prestations fractionnables seulement si l’unité l’autorise.

Client TypeScript : BigInt pour les produits et calculs entiers, quantité convertie en milli-unités exactes, arrondi rationnel avant conversion. PHP : arithmétique exacte sans overflow via bibliothèque entière/décimale testée si nécessaire. API montants JSON entiers limités techniquement à 10^12 FCFA, bien sous la limite des entiers sûrs JS ; refuser dépassement, jamais tronquer. Les taxes décimales emploient la même arithmétique exacte.

Convention proposée à confirmer : prix catalogue TTC ; remise de ligne explicite en FCFA, comprise entre zéro et le brut de ligne ; taxes ventilées selon régime validé. La gérante fixe taxe et remise ; ne pas inventer de taux. Aucun document officiel avant validation régime/mentions. Démonstrations « DONNÉES DE TEST ».

```text
brut_ligne = arrondi_FCFA(quantité × prix_unitaire_TTC)
net_ligne = brut_ligne - remise_ligne
total_facture = somme(net_ligne)
solde_comptable = total_facture - avoirs_émis - paiements_confirmés + remboursements_confirmés
reste_à_payer = max(0, solde_comptable)
crédit_à_rembourser = max(0, -solde_comptable)
```

Les paiements annulés par une contre-écriture ne comptent plus comme encaissés nets ; conserver le paiement et sa contre-écriture. Les avoirs sont des montants positifs qui réduisent la créance. La formule est canonique pour tous les écrans et exports.

Exemple : facture 80 000 ; acompte 30 000 ; solde 50 000. Un paiement complémentaire 20 000 produit un deuxième reçu et un solde 30 000. Un avoir 10 000 ramène le solde à 20 000. Une annulation totale par avoirs 80 000 ferait apparaître 50 000 à rembourser ; après remboursement de 50 000, solde zéro. Ne pas transformer ce crédit en dette négative cachée.

## Numérotation et émission

Séquences distinctes par cabinet et année : dossier `EE-2026-0001`, facture `FAC-2026-000001`, reçu `REC-2026-000001`, avoir `AV-2026-000001`, remboursement `RMB-2026-000001`. Le format est une proposition ; choisir les préfixes et la reprise des numéros papier avant bascule.

Allocations centrales via `document_sequences` verrouillée ; le serveur réserve aussi les plages disjointes des postes hors-ligne et les exclut de sa prochaine émission. Le poste consomme sa plage en transaction locale. Interdit d’utiliser `COUNT + 1` ou `MAX + 1` sans verrou. Un numéro émis n’est jamais réutilisé ni remplacé à la synchronisation ; trous/plages conservés. L’année est celle de l’émission locale au fuseau cabinet avec contrôle d’horloge, puis consolidation serveur.

Brouillon : éditable par le créateur habilité, sans numéro définitif, PDF avec filigrane. Émettre : validation des lignes, patient, taxes configurées et droits de prix ; attribution du numéro ; snapshot de l’identité facturée, du cabinet, du catalogue, des prix et de la taxe ; réservation du stock ; création du document et journal dans une même transaction. Toute erreur annule l’ensemble.

Une facture émise conserve son prix, ses lignes, son destinataire et sa date. Le changement de nom du patient ou du prix catalogue n’altère pas la pièce existante. Son solde est une projection calculée ; ne pas réécrire son PDF original à chaque paiement. Une attestation de solde ou un état de compte daté complète la facture si nécessaire.

## Encaissement et reçu

Sélectionner une facture existante, afficher total, reçu cumulé et solde. Montant strictement positif, inférieur ou égal au solde actuel, mode obligatoire, référence obligatoire pour mobile money/virement selon paramétrage. Les acomptes 50 %/70 % de PC sont des raccourcis, pas une obligation de montant.

L0 signifie paiement constaté par un opérateur, pas vérification automatique auprès d’un opérateur télécom. Un virement non encore confirmé reste en attente et ne solde pas la facture ; l’examen demeure bloqué. Chaque confirmation crée le reçu dans la même transaction que le paiement. Réimprimer le reçu existant si une imprimante ou la génération PDF tombe en panne, sans encaisser à nouveau.

Au serveur, verrouiller la facture et recalculer le solde juste avant écriture. Hors-ligne, la facture est détenue exclusivement par le poste, son solde local et la commande sont protégés par transaction IndexedDB. `Idempotency-Key`/`command_id` stable empêche double clic, retry ou relecture. Même clé/contenu retourne la pièce existante ; contenu différent produit conflit. Un échec PDF après transaction laisse paiement confirmé localement ou centralement et reçu numéroté, avec génération à reprendre.

Deux encaissements simultanés de 40 000 sur un solde de 50 000 : le premier passe ; le second reçoit un conflit et doit proposer le solde de 10 000, sans débit ni reçu partiel inventé.

## Corrections annulations et sorties d’argent EE024 EE030

Une facture brouillon peut être abandonnée. Une facture émise est corrigée par avoir partiel ou total lié aux lignes d’origine, motif et autorisation gérante. Un remplacement éventuel possède un nouveau numéro et référence la pièce corrigée. Ne jamais effacer la vente.

Un encaissement erroné reçoit une contre-écriture explicitement liée au paiement initial, puis un paiement correct si nécessaire. Un vrai remboursement est un objet distinct avec mode, référence, montant et reçu de remboursement, limité au crédit à rembourser et approuvé. L0 couvre ces opérations ; jamais de bouton « supprimer le paiement ».

Approbation : demandeur, opération cible, contenu exact ou empreinte, motif, montant, décision gérante, date et expiration. Approbation consommable une fois ; si le contenu change, nouvelle demande. La gérante se réauthentifie avant autorisation sensible. Une approbation distante passe par son compte personnel ; un code partagé permanent n’est pas suffisant. Elle peut exécuter directement avec la même traçabilité.

Les sorties de caisse réelles (remboursement, dépôt, retrait, dépense) sont enregistrées et autorisées. L0 peut utiliser un journal de mouvements simple ; L1 ajoute catégories de dépenses et export de gestion. Aucune sortie d’espèces ne doit être masquée par une remise commerciale fictive.

## Stock et remise EE025 EE026 EE027

```text
stock_physique_théorique = entrées_validées - sorties_validées
stock_disponible = stock_physique_théorique - réservations_actives
```

Émettre une facture réserve les articles gérés en stock ; cette réserve est une allocation et ne décrémente pas le stock physique. Une livraison confirmée décrémente le physique et consomme la réserve dans une transaction. Ainsi, la vente et la livraison ne sortent pas deux fois le même article. Une facture annulée libère ses réserves ; un avoir ne rentre pas automatiquement un article déjà remis.

Une commande de verres fabriqués à la demande peut être facturée sans stock de verre fini : article configuré fabrication, ordre atelier et absence de sortie d’un stock inexistant. Les articles physiques doivent être reçus avant leur remise. Les prestations n’ont aucun mouvement de stock.

Pour une remise, exiger facture émise, quantité non déjà remise et disponible, lots valides et utilisateur habilité. En cas de solde positif, validation gérante « livraison à crédit » avec motif ; la dette reste visible. Livraison partielle autorisée, quantités cumulées par ligne ≤ quantité facturée non créditée. Aucun retour arrière de statut qui ferait disparaître un mouvement.

Sortie hors vente (casse, retour fournisseur, correction d’inventaire) : motif, quantité et approbation gérante. Réception fournisseur et stock initial : quantité, coût et origine documentés, validés. Retours patient : lien à la livraison initiale, quantité et état physique ; remise en stock seulement après contrôle/acceptation. Un avoir financier sans retour physique ne modifie pas le stock.

Verrouiller dans l’ordre facture, lignes, positions de stock par identifiant croissant, réservations et lots. Aucun stock disponible négatif. Une vente concurrente du dernier article doit accepter une seule réservation. Le journal d’événements de stock est conservé ; les soldes rapides sont des projections vérifiables par recomptage.

Collyres : numéro de lot, expiration, quantité et statut vendable/quarantaine. Ne pas délivrer un lot expiré à la date locale de remise ; appliquer d’abord les lots expirant le plus tôt. Seuil et délai d’alerte configurables. L0 garantit lot, blocage et sortie ; L1 complète le réapprovisionnement et les inventaires.

## Frais d’examen

La facture de frais d’examen est liée à la visite/consultation visée. Le démarrage exige solde nul et encaissement confirmé, ou une dérogation spécifique. Un paiement pour une monture ne prouve pas le règlement de l’examen. La vérification se fait côté serveur lors du passage En cours, et l’approbation est journalisée.

## Caisse et indicateurs

CA période = factures émises pendant la période moins avoirs émis pendant la période, selon définition de gestion validée. Encaissements période = paiements confirmés pendant la période moins remboursements/contre-écritures de la période. Une ancienne facture réglée aujourd’hui augmente les encaissements d’aujourd’hui sans devenir une nouvelle vente d’aujourd’hui.

Créances = somme des restes dus des factures émises, y compris périodes anciennes. Afficher séparément les crédits à rembourser. Vente par caissière = auteur de l’émission ; encaissement par caissière = auteur du paiement. Ne pas fusionner ces deux responsabilités.

Caisse espèces théorique de session = ouverture + encaissements espèces + entrées autorisées - remboursements espèces - sorties autorisées. Clôture compare le comptage physique, exige motif d’écart, puis fige la session. Mobile money et virements ont des rapprochements distincts, pas un tiroir espèces. Valeur stock = quantité physique × coût de référence documenté ; un coût manquant donne une valeur inconnue, pas zéro arbitraire.

Ce reporting est de la gestion opérationnelle. Le plan comptable, les déclarations fiscales, la méthode officielle de valorisation et les exports attendus par le comptable restent à définir avant de promettre une comptabilité réglementaire complète.
