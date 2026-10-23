/**
 * Statistiques du dossier patient (R02 ; fichier 02 « Historique et statistiques ») : périodes, ratios et
 * définitions affichées avec chaque tableau. Règles pures (ni Vue, ni réseau, ni stockage) et sans
 * arithmétique flottante : un ratio se calcule en dixièmes de pour cent par division entière exacte.
 *
 * Périodes : aujourd'hui, semaine (du lundi au dimanche), mois, année civils du cabinet, ou intervalle
 * personnalisé ; bornes incluses, 366 jours au plus (même règle que le serveur).
 */
import { CivilDate } from '@/domain/values/civilDate'
import { divideRoundHalfUp, floorMod, intDiv } from '@/domain/values/integer'

export type PeriodPreset = 'day' | 'week' | 'month' | 'year'

export const PERIOD_PRESETS: readonly PeriodPreset[] = ['day', 'week', 'month', 'year']

export const PERIOD_LABELS: Readonly<Record<PeriodPreset, string>> = {
  day: 'Aujourd’hui',
  week: 'Cette semaine',
  month: 'Ce mois',
  year: 'Cette année',
}

export const MAX_PERIOD_DAYS = 366

export interface Period {
  readonly from: CivilDate
  readonly to: CivilDate
}

/** Jour de la semaine, lundi = 0 … dimanche = 6 (le 1970-01-01 était un jeudi). */
export function weekdayIndex(day: CivilDate): number {
  return floorMod(day.dayNumber() + 3, 7)
}

/** Période civile entière qui contient `today` (semaine du lundi au dimanche). */
export function presetPeriod(preset: PeriodPreset, today: CivilDate): Period {
  switch (preset) {
    case 'day':
      return { from: today, to: today }
    case 'week': {
      const monday = today.addDays(-weekdayIndex(today))
      return { from: monday, to: monday.addDays(6) }
    }
    case 'month': {
      const first = CivilDate.of(today.year, today.month, 1)
      return { from: first, to: first.addMonths(1).addDays(-1) }
    }
    case 'year':
      return { from: CivilDate.of(today.year, 1, 1), to: CivilDate.of(today.year, 12, 31) }
  }
}

/** Erreur lisible d'un intervalle personnalisé, ou null s'il est acceptable. */
export function periodError(from: CivilDate | null, to: CivilDate | null): string | null {
  if (from === null || to === null) return 'Indiquez le début et la fin de la période.'
  if (to.compare(from) < 0) return 'La fin de la période précède son début.'
  if (to.dayNumber() - from.dayNumber() + 1 > MAX_PERIOD_DAYS) {
    return `La période couvre au plus ${MAX_PERIOD_DAYS} jours.`
  }
  return null
}

/**
 * Part `count` sur `denominator`, en pour cent à une décimale (« 33,3 % »), arrondie au plus proche ;
 * « — » quand le dénominateur est nul (aucun ratio n'est inventé).
 */
export function percentLabel(count: number, denominator: number): string {
  if (denominator <= 0 || count < 0) return '—'
  const tenths = Number(divideRoundHalfUp(BigInt(count) * 1000n, BigInt(denominator)))
  return `${intDiv(tenths, 10)},${floorMod(tenths, 10)} %`
}

/** Libellé d'une tranche d'âge (« 18 à 39 ans », « 60 ans et plus »). */
export function ageBandLabel(minAge: number, maxAge: number | null): string {
  if (maxAge === null) return `${minAge} ans et plus`
  return `${minAge} à ${maxAge} ans`
}

/** Définitions affichées sous chaque tableau (fichier 02 : « Afficher la définition et le dénominateur des ratios »). */
export const ACTIVITY_DEFINITIONS: readonly string[] = [
  'Visites : arrivées enregistrées pendant la période, visites annulées exclues.',
  'Patients de la période : patients distincts ayant au moins une visite non annulée dans la période. C’est le dénominateur des répartitions par sexe et par âge.',
  'Nouveaux patients : patients dont la première visite connue au cabinet tombe dans la période (la date de première visite d’un dossier repris de l’historique papier compte). Anciens : les autres patients de la période.',
  'Âge : âge révolu au dernier jour de la période. Une date de naissance absente est comptée en « âge non renseigné ». Tranches proposées par le cahier des charges, à confirmer par le cabinet.',
  'Rendez-vous programmés : rendez-vous non annulés qui commencent dans la période. Patients à revoir : patients distincts dont un contrôle recommandé non annulé tombe dans la période. Comptés seulement quand l’agenda est ouvert.',
]

export const CLINICAL_DEFINITIONS: readonly string[] = [
  'Consultations : examens dont la date effective tombe dans la période, quel que soit leur état. C’est le dénominateur des ratios cliniques.',
  'Motifs : une consultation compte une fois pour chaque motif coché ; une consultation peut avoir plusieurs motifs.',
  'Prescriptions de lunettes : consultations avec au moins une prescription optique validée. Ordonnances de médicaments : consultations avec au moins un médicament prescrit.',
  'Orientations : consultations dont la conclusion propose une orientation vers un ophtalmologiste, par niveau d’urgence.',
  'Aucune mesure n’est additionnée ni moyennée : les acuités relevées dans des échelles différentes ne se comparent pas.',
]
