/**
 * Classes communes des contrôles de saisie : cible d'au moins 44 px, texte de 16 px (pas de zoom
 * forcé sur téléphone), bordure de champ contrastée (≥ 3:1), erreur signalée aussi par le message
 * et `aria-invalid`, état désactivé lisible.
 */
export function controlClasses(invalid: boolean, extra = ''): string[] {
  return [
    'min-h-11 w-full rounded-lg border bg-surface px-3 py-2 text-base text-ink shadow-[inset_0_1px_2px_rgb(35_31_32/0.04)]',
    'hover:border-primary disabled:cursor-not-allowed disabled:bg-disabled disabled:text-disabled-ink',
    invalid ? 'border-danger ring-1 ring-danger' : 'border-field',
    extra,
  ]
}
