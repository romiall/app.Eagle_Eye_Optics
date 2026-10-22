import { inject, type InjectionKey } from 'vue'

/**
 * Entrées de navigation (fichier 04) : chacune déclare ses permissions (l'une suffit, codes du
 * référentiel seedé) et la tâche du backlog qui livre son écran. Une entrée n'apparaît que si elle
 * est LIVRÉE et autorisée : L0 n'affiche jamais un écran futur vide comme si sa fonction existait.
 * Livrer un écran, c'est passer `delivered` à true dans la même modification que son écran, sa
 * route et ses tests. Les chemins des entrées non livrées sont indicatifs : la tâche qui livre
 * l'écran fixe le sien.
 */
export interface NavigationEntry {
  id: string
  label: string
  path: string
  /** Codes `permissions` : l'un d'eux suffit (le serveur revérifie chaque action). */
  permissions: readonly string[]
  /** Tâche du backlog qui livre l'écran. */
  deliveredBy: string
  delivered: boolean
  /**
   * Module de mise en service progressive dont l'entrée dépend (code de `EEO_ENABLED_MODULES`) ;
   * à défaut, l'identifiant de l'entrée (un module de la navigation est son propre code).
   */
  module?: string
}

/** Code du module qui doit être ouvert pour que l'entrée apparaisse. */
export function moduleOf(entry: NavigationEntry): string {
  return entry.module ?? entry.id
}

/** Module de la navigation principale, dans l'ordre du fichier 04. */
export interface AppModule extends NavigationEntry {
  /** Raccourci de la barre du téléphone (Aujourd'hui, Patients, Agenda ; le reste sous « Plus »). */
  mobileShortcut: boolean
}

/** Action d'accueil du modèle de dossier patient (fichier 04, « Actions d'accueil DP »). */
export type HomeAction = NavigationEntry

export const APP_MODULES: readonly AppModule[] = [
  {
    id: 'today',
    label: 'Aujourd’hui',
    path: '/aujourdhui',
    // Comme la route de la file du serveur : l'une OU l'autre suffit (aucune donnée clinique).
    permissions: ['visit.register', 'agenda.read'],
    deliveredBy: 'P03',
    delivered: true,
    mobileShortcut: true,
  },
  {
    id: 'patients',
    label: 'Patients',
    path: '/patients',
    permissions: ['patient.read'],
    deliveredBy: 'P02',
    delivered: true,
    mobileShortcut: true,
  },
  {
    id: 'consultations',
    label: 'Consultations',
    path: '/consultations',
    permissions: ['clinical.read'],
    deliveredBy: 'C01',
    delivered: false,
    mobileShortcut: false,
  },
  {
    id: 'agenda',
    label: 'Agenda',
    path: '/agenda',
    permissions: ['agenda.read'],
    deliveredBy: 'S01',
    delivered: false,
    mobileShortcut: true,
  },
  {
    id: 'sales',
    label: 'Ventes et caisse',
    path: '/ventes',
    permissions: ['invoice.read', 'payment.collect', 'cash.own_session', 'exam_fee.collect'],
    deliveredBy: 'B02',
    delivered: false,
    mobileShortcut: false,
  },
  {
    id: 'stock',
    label: 'Stock',
    path: '/stock',
    permissions: ['stock.read'],
    deliveredBy: 'I01',
    delivered: false,
    mobileShortcut: false,
  },
  {
    id: 'workshop',
    label: 'Atelier',
    path: '/atelier',
    permissions: ['workshop.manage'],
    deliveredBy: 'L1D04',
    delivered: false,
    mobileShortcut: false,
  },
  {
    id: 'communication',
    label: 'Communication',
    path: '/communication',
    permissions: ['communication.manage'],
    deliveredBy: 'L2D06',
    delivered: false,
    mobileShortcut: false,
  },
  {
    id: 'reports',
    label: 'Rapports',
    path: '/rapports',
    permissions: ['finance.global.read', 'statistics.activity', 'statistics.clinical'],
    deliveredBy: 'R01',
    delivered: false,
    mobileShortcut: false,
  },
  {
    id: 'settings',
    label: 'Paramètres',
    path: '/parametres',
    permissions: ['users.manage', 'approval.decide'],
    deliveredBy: 'B06',
    delivered: false,
    mobileShortcut: false,
  },
]

export const HOME_ACTIONS: readonly HomeAction[] = [
  {
    id: 'new-patient',
    label: 'Nouveau patient',
    path: '/patients/nouveau',
    permissions: ['patient.create'],
    deliveredBy: 'P01',
    delivered: true,
    module: 'patients',
  },
  {
    id: 'search',
    label: 'Rechercher',
    path: '/patients',
    permissions: ['patient.read'],
    deliveredBy: 'P02',
    delivered: true,
    module: 'patients',
  },
  {
    id: 'appointment',
    label: 'Rendez-vous',
    path: '/agenda',
    permissions: ['agenda.write'],
    deliveredBy: 'S01',
    delivered: false,
    module: 'agenda',
  },
  {
    id: 'consultations-today',
    label: 'Consultations du jour',
    path: '/aujourdhui',
    permissions: ['visit.register', 'agenda.read'],
    deliveredBy: 'P03',
    delivered: true,
    module: 'today',
  },
  {
    id: 'statistics',
    label: 'Statistiques',
    path: '/rapports/statistiques',
    permissions: ['statistics.activity', 'statistics.clinical'],
    deliveredBy: 'R02',
    delivered: false,
    module: 'reports',
  },
  {
    id: 'settings',
    label: 'Paramètres',
    path: '/parametres',
    permissions: ['users.manage', 'approval.decide'],
    deliveredBy: 'B06',
    delivered: false,
    module: 'settings',
  },
]

/**
 * Entrées à présenter à un compte : livrées dans le code, autorisées par ses droits ET ouvertes par
 * l'exploitant (`enabledModules`), dans l'ordre déclaré. Sans liste de modules ouverts, aucune entrée
 * n'apparaît : un module terminé reste caché tant qu'il n'est pas activé.
 */
export function visibleEntries<T extends NavigationEntry>(
  permissions: readonly string[],
  entries: readonly T[],
  enabledModules: readonly string[],
): T[] {
  return entries.filter(
    (entry) =>
      entry.delivered &&
      enabledModules.includes(moduleOf(entry)) &&
      entry.permissions.some((permission) => permissions.includes(permission)),
  )
}

export function visibleModules(
  permissions: readonly string[],
  enabledModules: readonly string[],
  modules: readonly AppModule[] = APP_MODULES,
): AppModule[] {
  return visibleEntries(permissions, modules, enabledModules)
}

export function visibleHomeActions(
  permissions: readonly string[],
  enabledModules: readonly string[],
  actions: readonly HomeAction[] = HOME_ACTIONS,
): HomeAction[] {
  return visibleEntries(permissions, actions, enabledModules)
}

/** Catalogue de navigation de l'application ; les tests en injectent un autre (écrans fictifs). */
export interface NavigationCatalog {
  modules: readonly AppModule[]
  homeActions: readonly HomeAction[]
}

export const DEFAULT_NAVIGATION: NavigationCatalog = {
  modules: APP_MODULES,
  homeActions: HOME_ACTIONS,
}

export const NAVIGATION_KEY: InjectionKey<NavigationCatalog> = Symbol('eeo-navigation')

export function useNavigationCatalog(): NavigationCatalog {
  return inject(NAVIGATION_KEY, DEFAULT_NAVIGATION)
}
