# Documents imprimés et disponibles en ligne

## Accès et conservation

Les documents sont consultables en ligne par les comptes autorisés, téléchargeables en PDF et imprimables. « Disponible en ligne » ne signifie pas publication publique. A4 est le format par défaut ; ticket thermique est une option après confirmation de l’imprimante.

Un document émis est stocké avec son snapshot, son hash et sa version de template. Une nouvelle impression retourne la même pièce ; journaliser la réimpression et signaler Duplicata si le modèle validé le prévoit. Les données du document ne changent pas parce que le patient ou le catalogue est modifié.

Le logo, coordonnées, mentions fiscales/professionnelles et signatures sont attendus du client. Tant qu’ils ne sont pas fournis, utiliser un modèle de démonstration explicitement identifié ; ne pas inventer ces mentions. Les noms de modèles sont proposés, leurs dispositions réglementaires doivent être validées avant émission réelle.

## Facture

En-tête cabinet, numéro/date, identité facturée minimale, référence dossier, lignes référence/désignation/quantité/unité/prix/remise/taxe selon configuration, total FCFA, conditions/due date si définies, auteur et contacts. Ne pas imprimer antécédents, symptômes ou détails de réfraction dans la facture.

Facture brouillon : filigrane BROUILLON, pas de numéro définitif. Facture émise : document figé. L’état de paiement à l’émission peut être mentionné avec sa date ; les paiements ultérieurs figurent sur leurs reçus et sur un état de compte daté. Lorsque le solde devient zéro, produire une attestation/facture acquittée complémentaire avec référence de la facture et des paiements, en conservant l’original. Si elle est dépassée par une correction ultérieure, la marquer comme historique dans l’interface sans en réécrire le contenu.

## Reçu de paiement

Numéro unique, date/heure effective et date d’émission si différente, référence facture, patient, montant du versement, mode et référence non sensible, total facturé, cumul encaissé net au moment de l’émission, reste après ce paiement, utilisateur. Mention « Acompte » ou « Règlement du solde » selon calcul, pas saisie libre contradictoire.

Chaque acompte/paiement complémentaire a son reçu. Impossible de modifier un reçu émis. Une erreur se corrige par pièces liées et audit. Une panne d’impression propose Réimprimer le reçu et ne déclenche aucun nouveau paiement.

## Avoir remboursement et état de compte

Avoir : numéro, facture source, lignes/montants corrigés, motif commercial compréhensible, taxes selon régime, auteur/autorisation, date. Reçu de remboursement : numéro, avoir/facture, montant, mode, référence et bénéficiaire vérifié. La décision d’approbation complète reste privée.

État de compte : date de calcul, factures, paiements, avoirs, remboursements et solde par pièce/patient. Signaler créance d’ouverture papier distincte. Aucun encaissement ancien ne doit apparaître comme paiement reçu aujourd’hui.

## Prescription optique

Cabinet, patient/dossier, date de consultation, professionnel, version, tableau OD/OG avec sphère/cylindre/axe/addition, DP total/monoculaire, hauteur si utile, usage, type/indice/traitements et observations approuvées. Unités et signes lisibles ; zéro visible, non mesuré indiqué par tiret/mention, jamais zéro par défaut.

Bouton Imprimer prescription distinct du dossier complet. Une prescription brouillon porte BROUILLON ; une prescription validée est une pièce versionnée. Le tableau respecte les deux lignes OD/OG du modèle DP. Les descriptions médicales additionnelles ne sont pas générées par l’IA.

## Ordonnance médicaments et dossier

Ordonnance : patient, date, professionnel, produits/forme/dosage/posologie/fréquence/durée/instructions issus de la saisie, selon capacités professionnelles et modèle validés par le cabinet. Pas de génération de posologie manquante.

Dossier clinique PDF : identité, consultations sélectionnées, mesures, prescriptions, traitement, conclusion, orientation et suivi, auteur/version. Export restreint au professionnel/gérante ; un reçu ne donne pas accès à ce PDF. Permettre exporter une seule consultation ou l’historique, avec indication de la sélection et de la date d’export.

## Bon laboratoire L1

Référence commande, code patient ou identité minimale nécessaire, date/échéance, laboratoire, monture, verres OD/OG, DP, hauteur et traitements, prescription versionnée, remarques techniques. Ne pas inclure les antécédents ou le chiffre d’affaires du patient. Suivi complet : commande, réception, montage, contrôle qualité, prêt, livraison/ajustement.

## Exigences de rendu

Marges A4 12–15 mm proposées, police lisible avec accents, pagination pour plusieurs pages et répétition des en-têtes de lignes. Pas de ligne produit coupée entre pages ni totaux seuls sur une page si évitable. Impression noir et blanc, tableaux contrastés, longues désignations et nom patient long prévus.

jsPDF : fonte française embarquée, tableaux paginés, logo/templates locaux, génération sans CDN ou réseau. Conserver PDF imprimé et snapshot, uploader après sync, ne pas remplacer silencieusement l’original par un rendu serveur différent. Si Dompdf sert aux rapports ultérieurs, limiter ses ressources à des fichiers autorisés. Test visuel facture courte/longue, reçu acompte/solde et prescription ; un nom de professionnel ou signature de commande du poste n’est pas une signature médicale électronique certifiée.

Les tests de recette contrôlent que numéros, montants, patient, unités et version correspondent exactement à la base, et que la réimpression d’une pièce n’émet pas une nouvelle pièce.
