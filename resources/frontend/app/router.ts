import { nextTick } from 'vue'
import { createRouter, createWebHistory, type Router, type RouterHistory } from 'vue-router'

import { DOCUMENT_PERMISSIONS } from '@/documents/access'
import ChangePasswordPage from '@/pages/account/ChangePasswordPage.vue'
import ConsultationHistoryPage from '@/pages/consultations/ConsultationHistoryPage.vue'
import ConsultationListPage from '@/pages/consultations/ConsultationListPage.vue'
import ConsultationPage from '@/pages/consultations/ConsultationPage.vue'
import PrescriptionsPage from '@/pages/consultations/PrescriptionsPage.vue'
import DocumentPreviewPage from '@/pages/documents/DocumentPreviewPage.vue'
import ForbiddenPage from '@/pages/ForbiddenPage.vue'
import HomePage from '@/pages/HomePage.vue'
import LoginPage from '@/pages/LoginPage.vue'
import NotFoundPage from '@/pages/NotFoundPage.vue'
import PatientActivityPage from '@/pages/patients/PatientActivityPage.vue'
import PatientCreatePage from '@/pages/patients/PatientCreatePage.vue'
import PatientEditPage from '@/pages/patients/PatientEditPage.vue'
import PatientListPage from '@/pages/patients/PatientListPage.vue'
import PatientViewPage from '@/pages/patients/PatientViewPage.vue'
import ApprovalsPage from '@/pages/manager/Approvals.vue'
import CashDayPage from '@/pages/cash/CashDayPage.vue'
import CashSessionPage from '@/pages/cash/CashSessionPage.vue'
import CatalogPage from '@/pages/sales/CatalogPage.vue'
import InvoiceCreatePage from '@/pages/sales/InvoiceCreatePage.vue'
import InvoiceListPage from '@/pages/sales/InvoiceListPage.vue'
import InvoicePage from '@/pages/sales/InvoicePage.vue'
import SalesHomePage from '@/pages/sales/SalesHomePage.vue'
import TaxRulesPage from '@/pages/sales/TaxRulesPage.vue'
import ClinicSettingsPage from '@/pages/settings/ClinicSettingsPage.vue'
import SettingsHomePage from '@/pages/settings/SettingsHomePage.vue'
import StaffCreatePage from '@/pages/settings/StaffCreatePage.vue'
import StaffListPage from '@/pages/settings/StaffListPage.vue'
import StaffMemberPage from '@/pages/settings/StaffMemberPage.vue'
import StockPage from '@/pages/stock/StockPage.vue'
import ArrivalPage from '@/pages/today/ArrivalPage.vue'
import TodayPage from '@/pages/today/TodayPage.vue'

import { canAny, enabledModules, refreshSession, session } from './session'

declare module 'vue-router' {
  interface RouteMeta {
    /** Page accessible sans session (connexion). */
    public?: boolean
    /** Permissions dont l'une suffit pour ouvrir la page (le serveur revérifie chaque action). */
    permissions?: readonly string[]
    /** Titre de l'onglet (WCAG 2.4.2), suivi du nom de l'application. */
    title?: string
    /**
     * Module de mise en service progressive dont dépend la page : tant que l'exploitant ne l'a pas
     * ouvert, la page répond « introuvable », comme la route du serveur (404).
     */
    module?: string
  }
}

export const APPLICATION_TITLE = 'Eagle’s Eye Optics Medical'

/**
 * Identifiant ULID d'une fiche ou d'une consultation dans l'adresse : toute autre forme est « page
 * introuvable ». C'est un identifiant opaque : l'adresse ne porte jamais de donnée de santé.
 */
const ULID_ID = ':id([0-9A-HJKMNP-TV-Z]{26})'

export function createEeoRouter(history: RouterHistory = createWebHistory()): Router {
  const router = createRouter({
    history,
    routes: [
      {
        path: '/connexion',
        name: 'connexion',
        component: LoginPage,
        meta: { public: true, title: 'Connexion' },
      },
      { path: '/', name: 'accueil', component: HomePage, meta: { title: 'Accueil' } },
      {
        path: '/acces-refuse',
        name: 'acces-refuse',
        component: ForbiddenPage,
        meta: { title: 'Accès refusé' },
      },
      {
        // La file se lit avec l'une OU l'autre de ces permissions, comme la route du serveur.
        path: '/aujourdhui',
        name: 'aujourdhui',
        component: TodayPage,
        meta: {
          title: 'Aujourd’hui',
          permissions: ['visit.register', 'agenda.read'],
          module: 'today',
        },
      },
      {
        path: '/aujourdhui/arrivee',
        name: 'arrivee',
        component: ArrivalPage,
        meta: {
          title: 'Enregistrer une arrivée',
          permissions: ['visit.register'],
          module: 'today',
        },
      },
      {
        path: '/patients',
        name: 'patients',
        component: PatientListPage,
        meta: { title: 'Patients', permissions: ['patient.read'], module: 'patients' },
      },
      {
        path: '/patients/nouveau',
        name: 'patient-nouveau',
        component: PatientCreatePage,
        meta: { title: 'Nouveau patient', permissions: ['patient.create'], module: 'patients' },
      },
      {
        path: `/patients/${ULID_ID}`,
        name: 'patient',
        component: PatientViewPage,
        meta: { title: 'Dossier patient', permissions: ['patient.read'], module: 'patients' },
      },
      {
        path: `/patients/${ULID_ID}/modifier`,
        name: 'patient-modifier',
        component: PatientEditPage,
        meta: { title: 'Modifier le patient', permissions: ['patient.update'], module: 'patients' },
      },
      {
        // Onglet « Activité » du dossier : visites, et selon les droits du compte, examens, ordonnances
        // et pièces financières (le serveur n'envoie que les sections autorisées).
        path: `/patients/${ULID_ID}/activite`,
        name: 'patient-activite',
        component: PatientActivityPage,
        meta: { title: 'Activité du patient', permissions: ['patient.read'], module: 'patients' },
      },
      {
        // Liste des examens du cabinet (patient, date, état, professionnel ; aucune valeur clinique).
        path: '/consultations',
        name: 'consultations',
        component: ConsultationListPage,
        meta: { title: 'Consultations', permissions: ['clinical.read'], module: 'consultations' },
      },
      {
        // Consultation par étapes (C01) : lecture avec clinical.read ; l'écran n'offre l'enregistrement
        // qu'avec clinical.write (le serveur revérifie). Module « consultations » à ouvrir.
        path: `/consultations/${ULID_ID}`,
        name: 'consultation',
        component: ConsultationPage,
        meta: { title: 'Consultation', permissions: ['clinical.read'], module: 'consultations' },
      },
      {
        // Révisions figées d'une consultation terminée et comparaison côte à côte (C03).
        path: `/consultations/${ULID_ID}/historique`,
        name: 'consultation-historique',
        component: ConsultationHistoryPage,
        meta: {
          title: 'Révisions de la consultation',
          permissions: ['clinical.read'],
          module: 'consultations',
        },
      },
      {
        // Prescription optique de la consultation : brouillon, versions, validation (C04).
        path: `/consultations/${ULID_ID}/prescriptions`,
        name: 'consultation-prescriptions',
        component: PrescriptionsPage,
        meta: {
          title: 'Prescription optique',
          permissions: ['clinical.read'],
          module: 'consultations',
        },
      },
      {
        // Aperçu des modèles de PDF (D01, D02), données fictives. Rattaché au module « sales » (le plus
        // proche : factures, reçus) ; chaque document n'est proposé que si le compte a son droit
        // (un document clinique demande clinical.read ou clinical.export, jamais un droit de caisse).
        path: '/documents/apercu',
        name: 'documents-apercu',
        component: DocumentPreviewPage,
        meta: {
          title: 'Aperçu des documents',
          permissions: [...new Set(Object.values(DOCUMENT_PERMISSIONS).flat())],
          module: 'sales',
        },
      },
      // Ventes et caisse (B01 à B05), EN LIGNE, module « sales » ; les permissions sont celles des
      // routes du serveur (x-permission), qui revérifie chaque action. Les identifiants de l'adresse sont
      // des ULID opaques ; un patient ou une visite présélectionnés passent en paramètre opaque.
      {
        path: '/ventes',
        name: 'ventes',
        component: SalesHomePage,
        meta: {
          title: 'Ventes et caisse',
          permissions: ['invoice.read', 'payment.collect', 'cash.own_session', 'exam_fee.collect'],
          module: 'sales',
        },
      },
      {
        path: '/ventes/caisse',
        name: 'caisse',
        component: CashSessionPage,
        meta: { title: 'Caisse', permissions: ['cash.own_session'], module: 'sales' },
      },
      {
        // Caisse du jour (R01 minimal) : la gérante lit tout le cabinet, une caissière ses seuls encaissements.
        path: '/ventes/caisse-du-jour',
        name: 'caisse-du-jour',
        component: CashDayPage,
        meta: {
          title: 'Caisse du jour',
          permissions: ['finance.global.read', 'cash.own_session'],
          module: 'sales',
        },
      },
      {
        path: '/ventes/factures',
        name: 'factures',
        component: InvoiceListPage,
        meta: { title: 'Factures', permissions: ['invoice.read'], module: 'sales' },
      },
      {
        path: '/ventes/factures/nouvelle',
        name: 'facture-nouvelle',
        component: InvoiceCreatePage,
        meta: {
          title: 'Nouvelle facture',
          permissions: ['invoice.create', 'exam_fee.collect'],
          module: 'sales',
        },
      },
      {
        path: `/ventes/factures/${ULID_ID}`,
        name: 'facture',
        component: InvoicePage,
        meta: { title: 'Facture', permissions: ['invoice.read'], module: 'sales' },
      },
      {
        path: '/ventes/catalogue',
        name: 'catalogue',
        component: CatalogPage,
        meta: { title: 'Catalogue', permissions: ['catalog.manage'], module: 'sales' },
      },
      {
        path: '/ventes/taxes',
        name: 'taxes',
        component: TaxRulesPage,
        meta: { title: 'Règles fiscales', permissions: ['catalog.manage'], module: 'sales' },
      },
      {
        // Disponibilités et stock initial (I00), module « stock » ; les écrans d'exploitation du stock
        // (remises, réserves sous quota) viennent avec I01.
        path: '/stock',
        name: 'stock',
        component: StockPage,
        meta: { title: 'Stock', permissions: ['stock.read'], module: 'stock' },
      },
      // Paramètres (module « settings ») : comptes du personnel et identité du cabinet, gérante seulement
      // (users.manage ; le serveur revérifie et exige une réauthentification pour chaque écriture).
      {
        path: '/parametres',
        name: 'parametres',
        component: SettingsHomePage,
        meta: {
          title: 'Paramètres',
          permissions: ['users.manage', 'approval.decide'],
          module: 'settings',
        },
      },
      {
        path: '/parametres/personnel',
        name: 'parametres-personnel',
        component: StaffListPage,
        meta: { title: 'Personnel', permissions: ['users.manage'], module: 'settings' },
      },
      {
        path: '/parametres/personnel/nouveau',
        name: 'parametres-personnel-nouveau',
        component: StaffCreatePage,
        meta: { title: 'Créer un compte', permissions: ['users.manage'], module: 'settings' },
      },
      {
        path: `/parametres/personnel/${ULID_ID}`,
        name: 'parametres-compte',
        component: StaffMemberPage,
        meta: { title: 'Compte du personnel', permissions: ['users.manage'], module: 'settings' },
      },
      {
        // Approbations exactes (B06) : la gérante décide (réauthentification à chaque décision) ; un compte qui
        // demande seulement voit ses propres demandes et leur décision (le serveur filtre).
        path: '/parametres/approbations',
        name: 'approbations',
        component: ApprovalsPage,
        meta: {
          title: 'Approbations',
          permissions: ['approval.decide', 'approval.request'],
          module: 'settings',
        },
      },
      {
        path: '/parametres/cabinet',
        name: 'parametres-cabinet',
        component: ClinicSettingsPage,
        meta: { title: 'Cabinet et documents', permissions: ['users.manage'], module: 'settings' },
      },
      {
        // Tout compte connecté change son propre mot de passe (mot de passe initial transmis par la gérante).
        path: '/mon-compte/mot-de-passe',
        name: 'mot-de-passe',
        component: ChangePasswordPage,
        meta: { title: 'Changer mon mot de passe' },
      },
      {
        path: '/:pathMatch(.*)*',
        name: 'introuvable',
        component: NotFoundPage,
        meta: { title: 'Page introuvable' },
      },
    ],
    scrollBehavior: (_to, _from, savedPosition) => savedPosition ?? { top: 0 },
  })

  // Garde de navigation : la session est lue une fois, puis chaque page vérifie ses permissions. Une
  // session non vérifiable n'est pas une déconnexion : la coquille l'affiche sans rien conclure.
  router.beforeEach(async (to) => {
    if (session.status === 'unknown') {
      await refreshSession().catch(() => undefined)
    }
    if (to.meta.public === true) {
      return to.name === 'connexion' && session.status === 'authenticated'
        ? { name: 'accueil' }
        : true
    }
    if (session.status === 'anonymous' || session.status === 'expired') {
      // Seul le chemin interne est conservé, jamais de paramètre de requête (aucun dossier dans l'URL).
      return { name: 'connexion', query: to.path === '/' ? {} : { suite: to.path } }
    }
    if (to.meta.module !== undefined && session.status === 'authenticated') {
      if (!enabledModules.value.includes(to.meta.module)) {
        const pathMatch = to.path.split('/').slice(1)
        return { name: 'introuvable', params: { pathMatch }, replace: true }
      }
    }
    const required = to.meta.permissions
    if (required !== undefined) {
      // Droits non vérifiables (serveur muet ou en erreur) : l'accueil explique l'état, rien ne s'ouvre.
      if (session.status !== 'authenticated') return { name: 'accueil' }
      if (!canAny(required)) return { name: 'acces-refuse' }
    }
    return true
  })

  // Après chaque navigation : titre de l'onglet, puis focus sur le contenu (sauf au premier
  // affichage) pour qu'un lecteur d'écran annonce le nouvel écran au lieu de rester sur le lien.
  router.afterEach((to, from, failure) => {
    if (failure !== undefined || typeof document === 'undefined') return
    document.title =
      to.meta.title === undefined ? APPLICATION_TITLE : `${to.meta.title} – ${APPLICATION_TITLE}`
    if (from.matched.length > 0) {
      void nextTick(() => document.getElementById('contenu')?.focus({ preventScroll: true }))
    }
  })

  return router
}
