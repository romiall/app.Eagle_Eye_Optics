/**
 * Contenu de l'aide intégrée (page « Aide ») : ce que chaque personne du cabinet peut faire dans cette
 * version, avec les libellés exacts des écrans. Chaque section n'est montrée qu'à un compte qui détient l'un
 * de ses droits ET dont le module est ouvert par l'exploitant (mise en service progressive) : l'accueil et la
 * caisse ne voient jamais l'aide des examens, un module fermé n'apparaît nulle part.
 *
 * Règles pures : ni Vue, ni réseau, ni stockage. Aucun texte ne présente comme disponible ce que cette version
 * ne fait pas (hors-ligne, avoirs à l'écran, agenda…) ; aucun ne dit qu'un examen est « payé » ou « gratuit ».
 */

export type HelpBlock =
  | { readonly kind: 'steps'; readonly title?: string; readonly items: readonly string[] }
  | { readonly kind: 'points'; readonly title?: string; readonly items: readonly string[] }
  | { readonly kind: 'note'; readonly text: string }

export interface HelpSection {
  /** Identifiant stable (ancre du sommaire). */
  readonly id: string
  readonly title: string
  /** Module requis (null : toute personne connectée). */
  readonly module: string | null
  /** L'un de ces droits suffit ; liste vide : toute personne connectée. */
  readonly anyPermission: readonly string[]
  readonly intro?: string
  readonly blocks: readonly HelpBlock[]
}

export const HELP_SECTIONS: readonly HelpSection[] = [
  {
    id: 'essentiel',
    title: 'À savoir avant de commencer',
    module: null,
    anyPermission: [],
    blocks: [
      {
        kind: 'points',
        items: [
          'Cette version fonctionne avec une connexion Internet. Une saisie est enregistrée quand l’écran affiche « Enregistré sur le serveur ». Le travail sans Internet arrivera dans une version suivante.',
          'Rien n’est jamais écrasé : une fiche patient modifiée garde son historique, un examen terminé se corrige par une nouvelle révision avec un motif, une facture émise ne se modifie plus.',
          'Tant que le cabinet n’a pas validé ses mentions officielles, les documents PDF portent la mention « DOCUMENT DE TEST » et ne doivent pas être remis comme pièces officielles.',
          'Certaines actions sensibles demandent de retaper votre mot de passe (« Confirmer et reprendre ») : l’action reprend ensuite, sans être envoyée deux fois.',
          'Si l’écran indique « Session expirée », reconnectez-vous : vos saisies en cours sont conservées.',
        ],
      },
    ],
  },
  {
    id: 'premiers-pas',
    title: 'Premiers pas',
    module: null,
    anyPermission: [],
    blocks: [
      {
        kind: 'steps',
        items: [
          'Connectez-vous avec l’identifiant et le mot de passe remis par la gérante (« Se connecter »).',
          'À la première connexion, remplacez votre mot de passe : lien « Changer mon mot de passe » de l’accueil (mot de passe actuel, nouveau mot de passe, confirmation).',
          'L’accueil propose les raccourcis de votre travail ; le menu ne montre que les écrans ouverts à votre compte.',
        ],
      },
    ],
  },
  {
    id: 'patients',
    title: 'Patients : rechercher, créer, modifier',
    module: 'patients',
    anyPermission: ['patient.read'],
    blocks: [
      {
        kind: 'points',
        title: 'Rechercher',
        items: [
          'Écran « Patients » : par nom (accents et ordre des mots indifférents), par téléphone (numéro national ou international) ou par numéro de dossier (EE-AAAA-NNNN).',
          'Le dossier patient montre l’identité et l’onglet « Activité » : visites, examens, ordonnances et pièces, chacun seulement si votre compte y a droit.',
        ],
      },
      {
        kind: 'steps',
        title: 'Créer une fiche',
        items: [
          '« Nouveau patient » : nom (obligatoire), prénom(s), sexe, date de naissance, téléphone principal…',
          'Si un patient qui ressemble existe déjà, l’écran le signale : vérifiez avant d’enregistrer. Rien n’est fusionné automatiquement.',
          '« Enregistrer le patient » : le numéro de dossier est attribué par le serveur.',
        ],
      },
      {
        kind: 'note',
        text: 'L’identité saisie une fois est reprise automatiquement par l’arrivée, l’examen, les ordonnances et les factures : elle n’est jamais ressaisie.',
      },
    ],
  },
  {
    id: 'arrivees',
    title: 'Enregistrer une arrivée',
    module: 'today',
    anyPermission: ['visit.register'],
    blocks: [
      {
        kind: 'steps',
        items: [
          'Depuis le dossier du patient ou l’écran « Aujourd’hui » : « Enregistrer une arrivée ».',
          'Choisissez le motif de la visite : examen de vue, contrôle de suivi, achat direct ou retrait de commande.',
          'La note administrative est facultative et ne contient aucune information médicale.',
          '« Enregistrer l’arrivée » : la visite apparaît dans la file du jour.',
        ],
      },
    ],
  },
  {
    id: 'file-du-jour',
    title: 'File du jour (« Aujourd’hui »)',
    module: 'today',
    anyPermission: ['visit.register', 'agenda.read'],
    blocks: [
      {
        kind: 'points',
        items: [
          'Les arrivées du jour du cabinet, de la plus ancienne à la plus récente, avec leur statut. Les boutons « Jour précédent », « Aujourd’hui » et « Jour suivant » changent le jour affiché.',
          'Selon votre compte, chaque visite propose « Démarrer l’examen », « Reprendre l’examen » ou « Créer une facture ».',
          'Le marqueur « Paiement de l’examen non contrôlé » signifie que ce cabinet n’a pas activé le contrôle du règlement de l’examen : l’examen peut démarrer sans vérification.',
        ],
      },
    ],
  },
  {
    id: 'examen',
    title: 'Examen : saisir, terminer, corriger',
    module: 'consultations',
    anyPermission: ['clinical.read'],
    blocks: [
      {
        kind: 'steps',
        items: [
          'Dans la file du jour : « Démarrer l’examen ». L’en-tête rappelle l’identité du patient.',
          'Sept étapes : Motif et lunettes, Antécédents, Acuité visuelle, Réfraction, Centrage, Médicaments, Conclusion et orientation.',
          '« Suivant » enregistre l’étape sur le serveur avant d’avancer ; « Précédent » ne perd rien. Une étape modifiée mais pas encore enregistrée est marquée « non enregistrée ».',
          '« Terminer l’examen » le fige : il passe en lecture seule.',
          'Pour corriger un examen terminé, créez une correction avec son motif : « Révisions et comparaison » montre les versions côte à côte.',
        ],
      },
      {
        kind: 'points',
        title: 'Bon à savoir',
        items: [
          '« Non renseigné » n’est jamais confondu avec « Non » ni avec « Aucun connu ».',
          'Les acuités se saisissent dans l’échelle choisie par le cabinet, telles que lues, sans conversion. L’autoréfracteur et la réfraction subjective restent distincts.',
          'Si une autre personne a modifié l’examen pendant votre saisie, l’écran le dit, garde votre saisie et vous propose de recharger.',
          'L’écran « Consultations » liste les examens en cours et terminés, sans afficher leur contenu.',
        ],
      },
    ],
  },
  {
    id: 'ordonnances',
    title: 'Prescription optique, ordonnances et impression',
    module: 'consultations',
    anyPermission: ['clinical.read'],
    blocks: [
      {
        kind: 'steps',
        title: 'Prescription optique',
        items: [
          'Depuis l’examen : « Prescription optique », puis « Nouvelle prescription ».',
          '« Enregistrer le brouillon », puis « Valider la prescription » : une prescription validée ne change plus ; pour la modifier, créez une nouvelle version.',
          'La réfraction n’est jamais recopiée sans que vous la choisissiez explicitement.',
        ],
      },
      {
        kind: 'steps',
        title: 'Ordonnance externe (rédigée par un autre prescripteur)',
        items: [
          'Dossier du patient, onglet « Prescriptions », puis « Transcrire une ordonnance externe ».',
          'Renseignez le prescripteur et la date de l’ordonnance, recopiez les valeurs telles qu’elles sont écrites, puis « Enregistrer le brouillon » et « Valider la prescription ».',
        ],
      },
      {
        kind: 'points',
        title: 'Imprimer (examen terminé)',
        items: [
          '« Aperçu de la fiche de consultation », « Aperçu de l’ordonnance de médicaments » (si des médicaments sont saisis), « Aperçu de la lettre d’orientation » (si une orientation est proposée), « Aperçu de la prescription » (version validée).',
          'Puis « Télécharger le PDF » ou impression. Un brouillon ne s’imprime jamais : il ne se remet pas au patient.',
        ],
      },
    ],
  },
  {
    id: 'caisse',
    title: 'Caisse : ouvrir, clôturer',
    module: 'sales',
    anyPermission: ['cash.own_session', 'cash.manage'],
    blocks: [
      {
        kind: 'steps',
        items: [
          '« Ventes et caisse », puis « Caisse » : saisissez le fond de caisse et « Ouvrir la caisse ». On n’encaisse que dans une session ouverte.',
          'En fin de journée, clôturez avec le comptage du tiroir ; un écart demande un motif.',
          '« Caisse du jour » : encaissements nets par mode, espèces attendues dans le tiroir, factures émises et relevé de session en PDF.',
        ],
      },
    ],
  },
  {
    id: 'factures',
    title: 'Facturer et encaisser',
    module: 'sales',
    anyPermission: ['invoice.read'],
    blocks: [
      {
        kind: 'steps',
        items: [
          '« Créer une facture » depuis le dossier patient, la file du jour ou « Nouvelle facture » : le patient est repris automatiquement.',
          '« Ajouter un article du catalogue », quantités et remise dans la limite de votre compte (au-delà : la gérante), puis « Enregistrer le brouillon ». Un brouillon inutile se supprime par « Abandonner le brouillon », sans consommer de numéro.',
          '« Émettre la facture » : elle ne pourra plus être modifiée ; son numéro définitif s’affiche après la réponse du serveur.',
          'Choisissez le mode de règlement et le montant encaissé (acompte ou solde), puis « Encaisser ». Chaque encaissement produit son propre reçu, jamais renuméroté ; « Nouvel encaissement » pour un second paiement.',
          'Imprimez la facture et les reçus en PDF depuis la fiche facture.',
        ],
      },
      {
        kind: 'note',
        text: 'L’écran affiche « Reste à encaisser » et « Total encaissé » tels que le serveur les calcule ; la liste « Factures » se filtre par jour d’émission et par état.',
      },
    ],
  },
  {
    id: 'devis',
    title: 'Devis',
    module: 'sales',
    anyPermission: ['invoice.read', 'invoice.create'],
    intro:
      'Un devis est une proposition remise au client : il ne vaut pas facture, n’enregistre aucun règlement et ne réserve aucun article.',
    blocks: [
      {
        kind: 'steps',
        title: 'Établir et remettre un devis',
        items: [
          '« Ventes », « Devis », puis « Nouveau devis » : choisissez un patient du cabinet ou un prospect (nom et téléphone).',
          'Ajoutez les articles du catalogue, indiquez la date de validité, puis « Enregistrer le brouillon ».',
          '« Émettre le devis » : le serveur lui donne un numéro et fige cette première révision. « Aperçu du devis (PDF) » l’imprime.',
        ],
      },
      {
        kind: 'steps',
        title: 'Corriger, enregistrer la réponse, convertir',
        items: [
          '« Réviser le devis » : nouvelles lignes, nouvelle validité et motif. Chaque révision reste réimprimable telle qu’elle a été remise.',
          '« Accepté par le client » ou « Refusé par le client » : rien n’est facturé ni encaissé.',
          '« Convertir en facture » : choisissez la révision, comparez les prix du devis à ceux du catalogue, choisissez la base de prix et cochez la confirmation. Un brouillon de facture s’ouvre : vérifiez-le, puis émettez-le comme toute facture.',
        ],
      },
      {
        kind: 'note',
        text: 'Un devis expiré (date de validité passée) ne s’accepte ni ne se convertit : révisez-le avec une nouvelle date. Un devis déjà converti ne se convertit pas une seconde fois : la facture existante est proposée.',
      },
    ],
  },
  {
    id: 'catalogue',
    title: 'Catalogue, taxes et stock',
    module: 'sales',
    anyPermission: ['catalog.manage'],
    blocks: [
      {
        kind: 'points',
        items: [
          '« Catalogue » : articles et prix ; « Règles fiscales » : taxes. Les valeurs sont celles saisies par le cabinet.',
          '« Importer un fichier CSV » (depuis le catalogue) : téléchargez le modèle, remplissez-le dans un tableur, enregistrez-le en CSV, choisissez-le, « Vérifier sur le serveur », puis « Importer ». Toutes les lignes sont importées, ou aucune si une seule est à corriger.',
          'Le stock d’ouverture se saisit dans « Stock » (si le module est ouvert), ligne par ligne ou avec « Remplir depuis un fichier CSV » ; les lignes reprises se relisent avant « Enregistrer ». Les disponibilités sont consultées pendant la facturation.',
        ],
      },
    ],
  },
  {
    id: 'remises',
    title: 'Remettre les articles au patient',
    module: 'sales',
    anyPermission: ['delivery.confirm'],
    intro:
      'La remise se fait depuis la fiche d’une facture émise, section « Remise au patient ». Elle sort l’article du stock ; elle ne change ni la facture ni ses reçus.',
    blocks: [
      {
        kind: 'steps',
        items: [
          'Ouvrez la facture, puis « Remettre au patient » : chaque ligne affiche ce qui est facturé, déjà remis et le reste à remettre.',
          'Indiquez la quantité remise maintenant (par défaut : tout le reste). Laissez vide une ligne qui sera remise plus tard (par exemple des verres encore en fabrication).',
          '« Confirmer la remise » : la remise s’enregistre sur le serveur et apparaît dans « Remises enregistrées », avec la date, le nom de la personne et le lot.',
          'Une remise partielle se complète plus tard de la même façon, jusqu’à « Tous les articles sont remis ».',
        ],
      },
      {
        kind: 'points',
        title: 'Bon à savoir',
        items: [
          'Les articles en stock sont pris sur la réserve faite à l’émission de la facture : la marchandise d’une autre vente n’est jamais prise.',
          'Un collyre d’un lot expiré ou en quarantaine n’est pas remis : l’écran l’indique, voyez la gérante.',
          'Facture avec un reste à encaisser : la remise exige l’autorisation de la gérante pour ces quantités. La caisse la demande (« Demander l’autorisation de remise »), puis confirme la remise après l’accord ; la gérante peut la faire directement.',
          'Une remise confirmée ne se modifie pas : une erreur se corrige par un retour.',
        ],
      },
    ],
  },
  {
    id: 'retours-stock',
    title: 'Retours, casse et ajustements de stock',
    module: 'stock',
    anyPermission: ['stock.adjust'],
    blocks: [
      {
        kind: 'steps',
        title: 'Retour d’un article remis',
        items: [
          'Ouvrez la facture, section « Remise au patient », puis « Enregistrer un retour » sur la ligne remise.',
          'Indiquez la quantité rapportée, l’état constaté et le motif ; cochez « Remettre en stock vendable » seulement pour un article intact.',
          'Confirmez votre mot de passe si l’écran le demande, puis « Enregistrer le retour ».',
        ],
      },
      {
        kind: 'note',
        text: 'Un retour ne crée pas d’avoir et ne rend pas d’argent. Si le patient doit être remboursé, émettez ensuite un avoir puis le remboursement depuis la même facture ; un avoir déjà émis peut être relié au retour.',
      },
      {
        kind: 'steps',
        title: 'Casse ou écart d’inventaire',
        items: [
          '« Stock », puis « Casse ou ajustement ».',
          'Choisissez l’article, l’emplacement et, pour un collyre, le lot ; puis la nature : casse (sortie) ou ajustement après comptage (sortie ou entrée).',
          'Indiquez la quantité et le motif, puis « Enregistrer le mouvement ». La marchandise réservée pour une vente ou confiée à un poste ne peut pas être retirée.',
        ],
      },
    ],
  },
  {
    id: 'creances',
    title: 'Créances du carnet papier',
    module: 'sales',
    anyPermission: ['finance.global.read'],
    blocks: [
      {
        kind: 'steps',
        items: [
          '« Catalogue », puis « Importer un fichier CSV » : la section « Créances papier » est en bas de la page.',
          'Déposez d’abord le scan de la pièce ou de la page du carnet (PDF, JPEG ou PNG) : il est obligatoire.',
          'Saisissez « Une créance » (patient retrouvé par la recherche, référence papier, total, déjà réglé) ou choisissez « Un fichier CSV » pour tout le carnet.',
          '« Vérifier sur le serveur », puis « Reprendre » : toutes les créances sont reprises, ou aucune si une seule est à corriger.',
        ],
      },
      {
        kind: 'note',
        text: 'Seul le reste dû est repris : la vente et l’acompte d’origine restent dans le carnet papier. Le reste dû s’encaisse ensuite depuis la facture, avec un reçu pour chaque encaissement. Une référence papier ne se reprend qu’une fois.',
      },
    ],
  },
  {
    id: 'personnel',
    title: 'Personnel et cabinet',
    module: 'settings',
    anyPermission: ['users.manage'],
    blocks: [
      {
        kind: 'steps',
        title: 'Créer un compte',
        items: [
          '« Paramètres », « Personnel », puis « Créer un compte » : identifiant de connexion, nom affiché, profil.',
          'Avec « Générer un mot de passe », le mot de passe initial est montré une seule fois (« J’ai noté, ouvrir le compte ») : remettez-le à la personne, qui le changera à sa première connexion.',
        ],
      },
      {
        kind: 'points',
        title: 'Gérer',
        items: [
          'Fiche d’un compte : profil, dérogations de droits, désactivation, réinitialisation du mot de passe.',
          '« Cabinet et documents » : nom, adresse, téléphone, mentions, en-tête et pied des documents imprimés.',
        ],
      },
    ],
  },
  {
    id: 'approbations',
    title: 'Approbations',
    module: 'settings',
    anyPermission: ['approval.decide', 'approval.request'],
    blocks: [
      {
        kind: 'points',
        items: [
          'Une action réservée (par exemple une remise au-delà du plafond) peut faire l’objet d’une demande d’approbation.',
          'La gérante accorde ou refuse avec un motif ; une approbation ne vaut que pour cette action précise, une seule fois, pendant une durée limitée.',
        ],
      },
    ],
  },
  {
    id: 'agenda',
    title: 'Agenda et rendez-vous',
    module: 'agenda',
    anyPermission: ['agenda.read'],
    blocks: [
      {
        kind: 'steps',
        title: 'Prendre un rendez-vous',
        items: [
          '« Agenda », puis « Nouveau rendez-vous » (ou « Rendez-vous » depuis l’accueil) : choisissez le patient, la prestation, le professionnel ou la salle et le jour.',
          'Choisissez un créneau libre parmi ceux lus sur le serveur, puis « Confirmer le rendez-vous ». Il n’est confirmé qu’à la réponse du serveur ; un créneau pris entre-temps est refusé et les créneaux sont relus.',
        ],
      },
      {
        kind: 'points',
        title: 'Au quotidien',
        items: [
          'Vues « Jour » et « Semaine », filtre par ressource ; chaque rendez-vous dit son statut : confirmé, arrivé, terminé, annulé, absent.',
          '« Enregistrer l’arrivée » le jour même ouvre l’arrivée avec le patient déjà choisi : le rendez-vous passe « arrivé ».',
          '« Déplacer » propose les créneaux libres ; « Annuler » demande un motif ; « Absent » se note une fois l’heure passée.',
          'La gérante règle les professionnels, salles, horaires, fermetures et prestations dans « Configuration » : aucun horaire n’est proposé par défaut.',
        ],
      },
    ],
  },
  {
    id: 'suivis',
    title: 'Patients à rappeler et contrôles recommandés',
    module: 'agenda',
    anyPermission: ['agenda.read'],
    blocks: [
      {
        kind: 'points',
        items: [
          'Le professionnel programme un « Contrôle recommandé » depuis la consultation : date de départ choisie, délai (10, 12 ou 14 jours, 3 ou 6 mois, 1 ou 2 ans, ou autre), rappel interne et canal souhaité.',
          '« Patients à rappeler » donne la liste du jour (retards compris) et les patients à revoir des 30 prochains jours.',
          'L’application n’envoie aucun SMS ni WhatsApp dans cette version : appelez ou écrivez vous-même, puis « Noter un contact » (contacté, RDV pris, sans réponse, refus, reporté).',
          'Un patient n’est jamais relancé deux fois pour le même rendez-vous ou le même contrôle.',
        ],
      },
    ],
  },
  {
    id: 'depenses',
    title: 'Dépenses et mouvements de caisse',
    module: 'sales',
    anyPermission: ['cash.own_session', 'cash.manage'],
    blocks: [
      {
        kind: 'points',
        items: [
          'Dans « Caisse », sous la session ouverte : « Enregistrer un mouvement ». Choisissez la nature (dépense, retrait, dépôt, correction de l’ouverture), le montant, la catégorie pour une dépense, le motif et, si vous l’avez, le numéro du justificatif.',
          'Caissière : le bouton devient « Demander l’autorisation ». Après l’accord de la gérante, renvoyez le même mouvement. Gérante : le mouvement s’enregistre directement, avec la même traçabilité.',
          'Un mouvement n’est jamais un paiement de patient : il change seulement les espèces attendues du tiroir. Une sortie ne peut pas dépasser ce qui est attendu dans le tiroir.',
          'Une session clôturée est figée : plus aucun mouvement. Une erreur se corrige dans une session ouverte, avec son motif.',
          'Gérante : les catégories se créent dans « Catégories de dépenses » ; l’écart d’une session clôturée se revoit depuis la liste des sessions (« Revoir l’écart » : accepter, ou contester avec une note).',
        ],
      },
    ],
  },
  {
    id: 'comparaison',
    title: 'Comparer deux examens d’un patient',
    module: 'consultations',
    anyPermission: ['clinical.read'],
    blocks: [
      {
        kind: 'points',
        items: [
          'Depuis la fiche du patient, ouvrez « Comparer deux examens » : l’avant-dernier examen terminé sert de référence, le dernier est comparé ; changez-les avec les deux listes.',
          'Chaque mesure est affichée côte à côte avec son écart en texte (identique, modifié, ajouté, retiré). « Seulement les différences » masque le reste.',
          'Une acuité notée dans une autre échelle n’est jamais convertie : la ligne est signalée « non comparable ».',
          'La prescription comparée est la dernière version validée de chaque examen ; un brouillon n’est pas pris en compte.',
        ],
      },
    ],
  },
  {
    id: 'fusion',
    title: 'Patient enregistré deux fois',
    module: 'patients',
    anyPermission: ['patient.merge'],
    blocks: [
      {
        kind: 'points',
        items: [
          'Ouvrez la fiche en double (celle à faire disparaître), puis « Fusionner avec une autre fiche ».',
          'Cherchez la fiche à conserver : les deux identités s’affichent côte à côte, les différences sont surlignées. Vérifiez qu’il s’agit bien du même patient.',
          'La fusion est refusée tant qu’une visite ou un examen est en cours, qu’un brouillon de facture existe, ou qu’un rendez-vous à venir est pris sur la fiche en double : terminez-les ou annulez-les d’abord.',
          'Indiquez le motif, confirmez, puis votre mot de passe. La fiche en double garde son numéro et son historique ; elle renvoie vers la fiche conservée, dont l’onglet « Activité » réunit les deux. Une fusion ne se défait pas.',
        ],
      },
    ],
  },
  {
    id: 'statistiques',
    title: 'Statistiques',
    module: 'reports',
    anyPermission: ['statistics.activity', 'statistics.clinical'],
    blocks: [
      {
        kind: 'points',
        items: [
          'Écran « Statistiques » (depuis l’accueil) : choisissez « Aujourd’hui », « Cette semaine », « Ce mois », « Cette année » ou une période « Du … Au … », puis « Afficher la période ».',
          'Selon votre compte : l’activité de l’accueil (visites, patients, nouveaux et anciens, sexe, tranches d’âge) et les examens (motifs, prescriptions, ordonnances, orientations).',
          'Chaque tableau donne ses définitions et le nombre sur lequel ses parts sont calculées. Aucune information ne permet d’identifier un patient.',
        ],
      },
    ],
  },
  {
    id: 'exports',
    title: 'Exports des données',
    module: 'settings',
    anyPermission: ['export.global', 'clinical.export'],
    blocks: [
      {
        kind: 'points',
        items: [
          'Le dossier d’un patient : depuis sa fiche, « Exporter le dossier » (mot de passe demandé).',
          'Patients, ventes, stock ou toutes les données (gérante) : « Paramètres », « Exports », puis « Demander l’export ».',
          'Le serveur prépare l’archive en quelques minutes : « Actualiser la liste », puis « Télécharger l’archive » (disponible 48 heures).',
          'L’archive contient un fichier par table à ouvrir dans un tableur, le sens de chaque colonne et un contrôle d’intégrité. Conservez-la de façon sécurisée.',
        ],
      },
    ],
  },
  {
    id: 'a-venir',
    title: 'Pas encore disponible dans cette version',
    module: null,
    anyPermission: [],
    blocks: [
      {
        kind: 'points',
        items: [
          'Le travail sans connexion Internet.',
          'Les écrans d’avoir, de remboursement et de correction d’un encaissement, et l’annulation d’une visite.',
          'L’envoi automatique des rappels (SMS, WhatsApp), l’atelier et les exports comptables.',
        ],
      },
    ],
  },
]

/** Sections que ce compte peut lire : un de leurs droits et leur module ouvert. */
export function visibleHelpSections(
  permissions: readonly string[],
  enabledModules: readonly string[],
  sections: readonly HelpSection[] = HELP_SECTIONS,
): HelpSection[] {
  return sections.filter(
    (section) =>
      (section.module === null || enabledModules.includes(section.module)) &&
      (section.anyPermission.length === 0 ||
        section.anyPermission.some((permission) => permissions.includes(permission))),
  )
}
