/**
 * Comptes du personnel (F05, écrans F09) : profils, permissions et règles de saisie que l'écran applique
 * AVANT d'envoyer, à l'identique du serveur (`App\Domain\Access\PasswordPolicy`, `CreateStaffMember`). Le
 * serveur reste juge : ces règles évitent un aller-retour, elles n'ouvrent aucun droit.
 *
 * Les codes et libellés de permissions sont recopiés du référentiel seedé
 * (`database/02_reference_data.sql`, table `permissions`) : aucun droit n'est inventé ici.
 * `tests/frontend/domain/staff.test.ts` relit le SQL et exige l'égalité exacte (codes, libellés, ordre).
 * Règles pures : ni Vue, ni réseau, ni stockage.
 */

import { floorMod } from '../values/integer'

export const STAFF_ROLES = ['MANAGER', 'RECEPTION', 'CLINICIAN', 'CASHIER'] as const

export type StaffRole = (typeof STAFF_ROLES)[number]

export const ROLE_LABELS: Readonly<Record<StaffRole, string>> = {
  MANAGER: 'Gérante',
  RECEPTION: 'Accueil',
  CLINICIAN: 'Professionnel (examen)',
  CASHIER: 'Caisse',
}

export function roleLabel(role: string): string {
  return (STAFF_ROLES as readonly string[]).includes(role) ? ROLE_LABELS[role as StaffRole] : role
}

/** Profils d'un compte en clair (« Accueil, Caisse »), ou « Aucun profil ». */
export function rolesLabel(roles: readonly string[]): string {
  return roles.length === 0 ? 'Aucun profil' : roles.map((role) => roleLabel(role)).join(', ')
}

export interface PermissionEntry {
  readonly code: string
  readonly label: string
}

/** Référentiel `permissions` (49 codes), dans l'ordre du SQL. */
export const PERMISSIONS: readonly PermissionEntry[] = [
  { code: 'patient.read', label: 'Lire identité administrative' },
  { code: 'patient.create', label: 'Créer patient' },
  { code: 'patient.update', label: 'Modifier identité' },
  { code: 'patient.merge', label: 'Fusionner dossiers après revue' },
  { code: 'visit.register', label: 'Enregistrer arrivée/visite' },
  { code: 'exam_fee.collect', label: 'Encaisser seulement frais examen' },
  { code: 'clinical.read', label: 'Lire dossier médical' },
  { code: 'clinical.write', label: 'Saisir consultation' },
  { code: 'clinical.complete', label: 'Finaliser consultation' },
  { code: 'clinical.amend', label: 'Corriger avec révision' },
  { code: 'clinical.export', label: 'Exporter dossier/prescription' },
  { code: 'agenda.read', label: 'Voir agenda' },
  { code: 'agenda.write', label: 'Programmer rendez-vous' },
  { code: 'follow_up.write', label: 'Programmer contrôle recommandé' },
  { code: 'catalog.read', label: 'Lire catalogue et prix vente' },
  { code: 'catalog.manage', label: 'Gérer catalogue/prix' },
  { code: 'catalog.purchase_cost.read', label: 'Lire coûts achat' },
  { code: 'invoice.read', label: 'Lire facture autorisée' },
  { code: 'invoice.create', label: 'Créer brouillon' },
  { code: 'invoice.issue', label: 'Émettre facture' },
  { code: 'payment.collect', label: 'Encaisser paiement' },
  { code: 'payment.reverse', label: 'Exécuter contre-écriture autorisée' },
  { code: 'credit_note.issue', label: 'Émettre avoir autorisé' },
  { code: 'refund.execute', label: 'Exécuter remboursement autorisé' },
  { code: 'delivery.confirm', label: 'Confirmer remise liée à vente' },
  { code: 'approval.request', label: 'Demander autorisation' },
  { code: 'approval.decide', label: 'Autoriser/refuser opération sensible' },
  { code: 'stock.read', label: 'Voir stock disponible' },
  { code: 'stock.receive', label: 'Recevoir stock fournisseur' },
  { code: 'stock.adjust', label: 'Exécuter ajustement validé' },
  { code: 'stock.inventory', label: 'Gérer inventaire' },
  { code: 'stock.valuation.read', label: 'Lire valorisation globale' },
  { code: 'cash.own_session', label: 'Voir sa session de caisse' },
  { code: 'cash.manage', label: 'Gérer clôture et sorties autorisées' },
  { code: 'finance.global.read', label: 'Lire CA/encaissements/impayés globaux' },
  { code: 'finance.reconcile', label: 'Rapprocher modes règlement' },
  { code: 'statistics.activity', label: 'Voir statistiques administratives' },
  { code: 'statistics.clinical', label: 'Voir statistiques cliniques' },
  { code: 'workshop.manage', label: 'Gérer commandes atelier' },
  { code: 'communication.manage', label: 'Gérer relances/campagnes' },
  { code: 'conversation.takeover', label: 'Reprendre conversation' },
  { code: 'knowledge.manage', label: 'Éditer/valider FAQ' },
  { code: 'site.publish', label: 'Publier contenus et produits validés' },
  { code: 'integration.manage', label: 'Configurer intégrations' },
  { code: 'users.manage', label: 'Gérer utilisateurs et droits' },
  { code: 'audit.read', label: 'Consulter audit' },
  { code: 'export.global', label: 'Exporter données complètes' },
  { code: 'backup.manage', label: 'Contrôler sauvegarde/restauration' },
  { code: 'offline.enroll', label: 'Enrôler poste et allouer droits' },
]

/**
 * `users.manage` ne s'attribue ni ne se retire par dérogation (fichier 09, F05) : la liste des dérogations
 * possibles ne le propose pas (le serveur refuse de toute façon).
 */
export const NON_DELEGABLE_PERMISSIONS: readonly string[] = ['users.manage']

export function permissionLabel(code: string): string {
  return PERMISSIONS.find((entry) => entry.code === code)?.label ?? code
}

export const OVERRIDABLE_PERMISSIONS: readonly PermissionEntry[] = PERMISSIONS.filter(
  (entry) => !NON_DELEGABLE_PERMISSIONS.includes(entry.code),
)

export type OverrideEffect = 'ALLOW' | 'DENY' | 'CLEAR'

export const OVERRIDE_EFFECT_LABELS: Readonly<Record<OverrideEffect, string>> = {
  ALLOW: 'Autoriser en plus du profil',
  DENY: 'Retirer ce droit (l’emporte sur le profil)',
  CLEAR: 'Supprimer la dérogation existante',
}

/**
 * Identifiant de connexion (même motif que `CreateStaffMember::LOGIN_PATTERN`) : commence par une minuscule
 * ou un chiffre, puis minuscules, chiffres, point, tiret ou souligné ; 3 à 64 caractères. Le serveur le met en
 * minuscules : l'écran fait de même avant de contrôler.
 */
const LOGIN_PATTERN = /^[a-z0-9][a-z0-9._-]{2,63}$/

export function normalizeLogin(login: string): string {
  return login.trim().toLowerCase()
}

export function loginError(login: string): string | null {
  const normalized = normalizeLogin(login)
  if (normalized === '') return 'Saisissez l’identifiant de connexion.'
  if (!LOGIN_PATTERN.test(normalized)) {
    return 'L’identifiant commence par une lettre ou un chiffre, puis ne contient que des minuscules sans accent, des chiffres, « . », « _ » ou « - » (3 à 64 caractères).'
  }
  return null
}

export const PASSWORD_MIN_LENGTH = 12
/** Limite de bcrypt : au-delà de 72 octets, le reste du mot de passe serait ignoré. */
export const PASSWORD_MAX_BYTES = 72

function utf8Length(text: string): number {
  return new TextEncoder().encode(text).length
}

/** Mêmes règles que `PasswordPolicy` du serveur : 12 caractères, lettres ET chiffres, 72 octets, ≠ identifiant. */
export function passwordError(password: string, login: string): string | null {
  if (password === '') return 'Saisissez le mot de passe.'
  if ([...password].length < PASSWORD_MIN_LENGTH) {
    return `Le mot de passe compte au moins ${PASSWORD_MIN_LENGTH} caractères.`
  }
  if (utf8Length(password) > PASSWORD_MAX_BYTES) {
    return `Le mot de passe ne dépasse pas ${PASSWORD_MAX_BYTES} octets (environ 70 caractères sans accent).`
  }
  if (!/\p{L}/u.test(password) || !/\p{N}/u.test(password)) {
    return 'Le mot de passe contient des lettres et des chiffres.'
  }
  if (login.trim() !== '' && password.trim().toLowerCase() === login.trim().toLowerCase()) {
    return 'Le mot de passe est différent de l’identifiant.'
  }
  return null
}

/** Alphabet sans caractère ambigu à la lecture (0/O, 1/l/I) : le mot de passe se transmet en personne. */
const GENERATED_LETTERS = 'abcdefghijkmnpqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ'
const GENERATED_DIGITS = '23456789'
export const GENERATED_PASSWORD_LENGTH = 16

/**
 * Mot de passe initial aléatoire (aléa cryptographique du navigateur), 16 caractères, lettres et chiffres.
 * Il n'est ni conservé ni journalisé par l'écran : affiché une fois à la gérante, qui le transmet en personne.
 */
export function generatePassword(
  random: (values: Uint32Array<ArrayBuffer>) => Uint32Array = (values) =>
    crypto.getRandomValues(values),
): string {
  const alphabet = GENERATED_LETTERS + GENERATED_DIGITS
  for (;;) {
    const values = random(new Uint32Array(GENERATED_PASSWORD_LENGTH))
    let candidate = ''
    for (const value of values) candidate += alphabet[value % alphabet.length]
    if (/[a-zA-Z]/.test(candidate) && /[0-9]/.test(candidate)) return candidate
  }
}

/** Décalage (ms) du fuseau à un instant donné : heure murale du fuseau moins heure UTC. */
function zoneOffsetMs(epochMs: number, timeZone: string): number {
  const parts = new Intl.DateTimeFormat('en-US', {
    timeZone,
    hourCycle: 'h23',
    year: 'numeric',
    month: '2-digit',
    day: '2-digit',
    hour: '2-digit',
    minute: '2-digit',
    second: '2-digit',
  }).formatToParts(new Date(epochMs))
  const value = (type: string): number =>
    Number(parts.find((part) => part.type === type)?.value ?? '0')
  const wall = Date.UTC(
    value('year'),
    value('month') - 1,
    value('day'),
    value('hour'),
    value('minute'),
    value('second'),
  )
  // Instant ramené à la seconde entière (les parties formatées n'ont pas de millisecondes), en entier exact.
  return wall - (epochMs - floorMod(epochMs, 1000))
}

/**
 * Fin d'un jour civil du cabinet (« valable jusqu'au 30/11/2026 inclus ») en instant UTC à la milliseconde
 * (`AAAA-MM-JJTHH:MM:SS.mmmZ`) : minuit du lendemain À L'HEURE DU CABINET, jamais minuit UTC. Rend null pour
 * une date mal formée.
 */
export function endOfCivilDayUtc(day: string, timeZone: string): string | null {
  const match = /^(\d{4})-(\d{2})-(\d{2})$/.exec(day)
  if (match === null) return null
  const [year, month, date] = [Number(match[1]), Number(match[2]), Number(match[3])]
  const nextMidnightWall = Date.UTC(year, month - 1, date + 1, 0, 0, 0)
  if (Number.isNaN(nextMidnightWall)) return null
  let instant = nextMidnightWall - zoneOffsetMs(nextMidnightWall, timeZone)
  // Second passage : le décalage à l'instant trouvé (changement d'heure éventuel entre les deux).
  instant = nextMidnightWall - zoneOffsetMs(instant, timeZone)
  return new Date(instant).toISOString()
}

/**
 * Motif exigé par le serveur pour désactiver un compte ou lui retirer un profil (F05).
 */
export function changeNeedsReason(
  before: { active: boolean; roles: readonly string[] },
  after: { active: boolean; roles: readonly string[] },
): boolean {
  if (before.active && !after.active) return true
  return before.roles.some((role) => !after.roles.includes(role))
}
