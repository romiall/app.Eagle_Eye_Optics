# Sécurité confidentialité et droits

## Matrice de permissions L0

La matrice complète les rôles CDC §2. La gérante dispose de l’accès métier total décrit par CDC ; les droits techniques d’administration du serveur sont séparés. Les professionnels ont accès aux dossiers cliniques nécessaires au travail du cabinet ; une restriction future par patient/professionnel pourra être ajoutée après validation du fonctionnement réel.

| Action | Gérante | Accueil | Professionnel | Caissière |
| --- | --- | --- | --- | --- |
| Rechercher identité et contact | Oui | Oui | Oui | Oui |
| Créer/modifier identité | Oui | Oui | Lecture ; correction proposée | Lecture ; correction proposée |
| Créer visite et gérer agenda | Oui | Oui | RDV de suivi et lecture agenda | Lecture administrative |
| Lire/saisir données cliniques | Oui | Non | Oui | Non |
| Imprimer prescription/dossier clinique | Oui | Non par défaut | Oui | Non |
| Encaisser uniquement examen | Oui | Permission spéciale à attribuer | Non | Oui |
| Créer/émettre une vente | Oui | Non hors frais examen | Non | Oui |
| Paiements et reçus de vente | Oui | Non hors frais examen | Non | Oui |
| Modifier prix/remise | Oui | Non | Non | Sur approbation exacte uniquement |
| Lire stock disponible/prix de vente | Oui | Selon accueil vente | Lecture utile | Oui |
| Lire coûts et valeur globale du stock | Oui | Non | Non | Non |
| Confirmer remise liée à vente | Oui | Non par défaut | Non par défaut | Oui selon conditions |
| Ajuster stock/sortie hors vente | Oui avec motif | Non | Non | Demande seulement |
| Annuler/corriger facture ou paiement | Oui avec preuve/motif | Non | Non | Demande seulement |
| Avoir/remboursement | Oui | Non | Non | Exécution après approbation explicite |
| CA, créances et finance globale | Oui | Non | Non | Seulement facture sélectionnée et sa propre session |
| Statistiques cliniques | Oui | Activité administrative | Clinique | Non |
| Gérer utilisateurs/exports globaux/audit | Oui | Non | Non | Non |

La permission spéciale d’encaissement examen permet créer la ligne de prestation fixe, émettre la pièce et prendre le règlement ; elle ne permet pas ajouter un article, changer un tarif ou consulter le CA. Toute permission supplémentaire est attribuée explicitement, datée et journalisée.

## Authentification et sessions

Comptes individuels, mot de passe hashé via mécanisme Laravel, aucune inscription publique du personnel. Réinitialisation contrôlée par email ou gérante. Ralentissement et limitation des tentatives de connexion. Réauthentification pour approbations, export global et gestion des droits. MFA gérante recommandé dès L0 avec mécanisme standard testé, obligatoire avant activation d’accès d’administration distant selon politique adoptée.

Cookie sécurisé, HttpOnly, SameSite adapté, domaine du seul sous-domaine applicatif ; CSRF sur mutations de session. Inactivité proposée 30 minutes, avec avertissement. Logout invalide la session serveur et verrouille le profil local sans effacer les pièces en attente. Aucune donnée clinique déchiffrée ne reste affichée après verrouillage ; le coffre chiffré est conservé pour le hors-ligne.

Policies serveur pour API, commandes, sync, documents/exports ; autorisations signées et moteur local équivalent hors-ligne. Un paramètre `patient_id` ou `role` du navigateur n’est pas une autorisation. Tester URL/IDs directs, compartiments locaux d’un autre rôle et autre cabinet.

## Données et circulation

HTTPS obligatoire. Base et stockage privé accessibles seulement à l’application et aux opérations autorisées. Chiffrement des sauvegardes, secrets OAuth/API chiffrés avec clé conservée séparément et restaurable. Chiffrement au repos de l’hébergement à vérifier ; chiffrement applicatif des secrets et des payloads sensibles à prévoir si nécessaire, sans rendre la recherche patient inutilisable par un chiffrement mal conçu.

Factures/reçus en ligne via connexion du personnel. Pas de dossier ni facture dans un répertoire public, un lien devinable ou un bucket public. Un partage patient sécurisé est une extension future : identité vérifiée, token révocable et expiration ; aucune consultation anonyme par simple numéro de dossier.

Google/Kommo/Meta ne reçoivent pas réfraction, symptômes, antécédents, ordonnance ni diagnostic. Le motif de réservation transmis est une catégorie administrative générique. Quand nom/contact sont nécessaires aux agendas prévus par PC, utiliser un agenda dédié à accès limité et informer le client des flux avant activation. L’IA commerciale n’utilise pas la base clinique comme corpus.

Les modalités de consentement, conservation, localisation des données et mentions doivent être validées pour le cabinet au Cameroun. Ce dossier ne revendique pas une certification réglementaire ou une conformité juridique déjà vérifiée. La collecte technique minimise les données, contrôle les rôles et permet restitution/export.

## Audit

Journal de création/modification identité, enregistrement/finalisation/correction clinique, prix, remise, émission, paiement, réimpression/export, avoir, remboursement, livraison, stock, dérogation, droits, connexion et intégration. Conserver auteur, nom à la date de l’action, instant serveur, cible, action, motif, corrélation et modification utile.

Pas d’interface ni permission applicative pour effacer l’historique. Compte DB d’application sans UPDATE/DELETE sur tables d’audit et écritures immuables si l’hébergeur permet les droits séparés ; sinon bloquer par conventions/contrôles et documenter le moindre niveau de protection. Export quotidien hors serveur ; chaîne de hash facultative pour détecter altération, pas une garantie contre un administrateur qui possède toutes les clés.

Un journal applicatif est inviolable par les rôles métier mais ne peut pas être présenté comme inviolable par tout administrateur de la base. Les lectures de dossiers sensibles et exports sont tracés sans recopier leurs contenus médicaux dans les logs techniques. Les différences cliniques détaillées restent chiffrées/restreintes ou dans les révisions cliniques, pas dans un log ouvert au support.

## Protection de l’application

Validation des entrées, requêtes paramétrées, échappement HTML, refus du HTML libre injecté dans PDF, limites de taille et de débit. Fichiers autorisés par type réel, antivirus si documents importés, stockage privé et nom serveur. Dompdf n’accède qu’aux ressources locales autorisées ; accès distant désactivé et pas de lecture de chemins fournis par l’utilisateur.

Secrets hors Git et racine web. `APP_DEBUG=false`, erreurs filtrées, CSP adaptée à Vue/PWA sans scripts tiers inutiles, protections iframe/contenu, API sensibles no-store et noindex. Le service worker cache les ressources statiques, pas les réponses médicales. Site public isolé : aucune extension WordPress n’accède aux patients.

## Sauvegardes et incident

Minimum PC : sauvegarde quotidienne. Objectif proposé de production : RPO ≤1 h pour données financières si l’offre autorise des sauvegardes incrémentales/binlogs ; sinon RPO jusqu’à 24 h à documenter et à traiter dans la décision d’hébergement. RTO cible 4 h, à mesurer par restauration réelle. Ces valeurs sont des objectifs techniques proposés, pas des garanties déjà acquises.

Sauvegarder base, pièces privées, paramètres, clés nécessaires et manifestes. Copie chiffrée hors serveur ; rotation proposée 7 quotidiennes, 4 hebdomadaires, 3 mensuelles, à ajuster à la politique de conservation. Vérifier quotidiennement succès/taille, alerter en échec et tester une restauration avant production puis périodiquement.

Incident : bloquer accès compromis, préserver journaux, révoquer secrets concernés, identifier l’état fiable, restaurer en environnement contrôlé, réconcilier pièces/paiements depuis la dernière sauvegarde et informer les personnes responsables selon les obligations applicables. Ne pas remettre en production une base restaurée sans vérifier les derniers numéros et règlements.

## Sécurité des postes hors-ligne

Le fichier 19 fait partie de L0 : coffre IndexedDB chiffré, profils/compartiments séparés, verrouillage, poste enrôlé, autorisations bornées, événements signés, backups locaux et ressources réservées. Compartiment administratif partageable entre profils autorisés ; clinique seulement professionnel/gérante, finance seulement caisse/gérante. Les clés de compartiment sont enveloppées pour les profils autorisés, sans clé universelle exposée dans le JS.

La révocation n’est pas instantanée sur un poste déconnecté et les derniers paiements restent absents du dashboard distant avant sync. Disque chiffré et contrôle physique des postes recommandés. Signature et hash ne rendent pas un navigateur compromis inviolable. Aucun service de sync tiers ne reçoit les dossiers cliniques.

Backups serveur ne couvrent pas les pièces encore locales : export chiffré, clé de récupération contrôlée et restauration testée obligatoires. Mode privé, suppression du coffre et mise à jour destructive interdits sur postes de production.

## Sortie du prestataire

Export autorisé en CSV/JSON pour données structurées et PDF pour pièces, avec dictionnaire, manifestes et pièces privées dans archive chiffrée. Remise contrôlée des accès du domaine et des comptes appartenant au client. Journaliser export et transfert ; aucune copie de données réelles pour les tests Claude ou les démonstrations publiques.
