/**
 * Valeurs usuelles proposées en saisie d'une acuité (C02 ; fichier 02 : « sélection de l'échelle utilisée, valeurs
 * usuelles configurables et saisie libre ; conserver la valeur originale »).
 *
 * Aucune liste n'est VALIDÉE cliniquement : les échelles réelles du cabinet et leurs valeurs usuelles attendent la
 * cliente (Q06, décision G11 ouverte). Seules les échelles de DÉMONSTRATION fictives (`eeo:clinical:scales --demo`)
 * ont ici des suggestions ; une échelle d'un autre code n'en propose aucune : la saisie reste libre et la valeur lue
 * est conservée telle quelle. Une suggestion n'est jamais appliquée seule et n'implique aucune conversion entre
 * échelles. Règles pures : ni Vue, ni réseau, ni stockage.
 */
export const DEMO_ACUITY_SUGGESTIONS: Readonly<Record<string, readonly string[]>> = {
  DEMO_DIXIEMES: ['10/10', '9/10', '8/10', '7/10', '6/10', '5/10', '4/10', '3/10', '2/10', '1/10'],
  DEMO_SNELLEN_6: ['6/6', '6/9', '6/12', '6/18', '6/24', '6/36', '6/60'],
  DEMO_PRES: ['P1,5', 'P2', 'P3', 'P4', 'P5', 'P6', 'P8', 'P10', 'P14', 'P20'],
}

/** Suggestions pour le code d'une échelle (vide si aucune : saisie libre). */
export function acuitySuggestions(scaleCode: string | null): readonly string[] {
  return scaleCode === null ? [] : (DEMO_ACUITY_SUGGESTIONS[scaleCode] ?? [])
}

/** Vrai pour une échelle de démonstration (code `DEMO_…`) : l'écran le dit, ce n'est pas une échelle validée. */
export function isDemoScale(scaleCode: string): boolean {
  return scaleCode.startsWith('DEMO_')
}
