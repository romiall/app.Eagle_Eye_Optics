import type { PatientFormField } from '@/domain/patients/form'

/**
 * Identifiants des contrôles du formulaire d'identité : cibles du résumé d'erreurs (chaque erreur
 * mène à son champ). Fixes, donc stables d'un rendu à l'autre et testables.
 */
export const FIELD_IDS: Readonly<Record<PatientFormField, string>> = {
  lastName: 'patient-nom',
  firstNames: 'patient-prenoms',
  sex: 'patient-sexe',
  birthDate: 'patient-naissance',
  birthDateMissingReason: 'patient-naissance-raison',
  phonePrimary: 'patient-telephone',
  phoneSecondary: 'patient-telephone-secondaire',
  phoneMissingReason: 'patient-telephone-raison',
  address: 'patient-adresse',
  profession: 'patient-profession',
}
