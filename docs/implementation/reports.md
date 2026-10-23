# Statistiques du dossier patient (R02)

Module `reports` (fermé par défaut, `EEO_ENABLED_MODULES`). Écran « Statistiques » (`/rapports/statistiques`,
action d'accueil « Statistiques »), EN LIGNE. Code : `app/Domain/Clinical/ActivityReports.php`,
`app/Http/Controllers/Api/V1/StatisticsController.php`, `resources/frontend/pages/reports/**`,
`resources/frontend/domain/reports/statistics.ts`, `app/services/reports.ts`. État : codé le 2026-10-23,
vérifié à la prochaine passe de fin de lot.

## Lectures et droits

| Route | Droit | Profils seedés | Contenu |
| --- | --- | --- | --- |
| `GET /api/v1/reports/activity?from&to` | `statistics.activity` | gérante, accueil | visites non annulées (par motif), patients distincts, nouveaux et anciens, sexe, tranches d'âge, âge non renseigné ; `appointments` (rendez-vous non annulés commençant dans la période) et `follow_ups_due` (patients distincts dont un contrôle recommandé non annulé tombe dans la période) comptés quand le module `agenda` est ouvert, sinon `null` |
| `GET /api/v1/reports/clinical?from&to` | `statistics.clinical` | gérante, professionnel | consultations (dont terminées), motifs, sans motif, consultations avec prescription optique validée, avec ordonnance de médicaments, orientations par urgence, orientation non renseignée, amétropies |

Accès administratif et clinique **séparés** (fiche R02) : l'accueil ne lit jamais les chiffres cliniques, le
professionnel ne lit pas l'activité de l'accueil ; la caisse ne lit aucun des deux. Comptages seulement : aucun
nom, numéro de dossier ni texte clinique ; aucune mesure additionnée ou moyennée (acuités d'échelles différentes).

## Définitions (affichées à l'écran avec le dénominateur de chaque part)

- Période : jours civils du cabinet (Africa/Douala), bornes incluses, 366 jours au plus ; semaine du lundi au
  dimanche ; préréglages aujourd'hui, semaine, mois, année.
- Patients de la période : patients distincts avec au moins une visite non annulée dans la période.
- Nouveau : première visite connue au cabinet dans la période ; la date de première visite d'un dossier repris
  (`first_known_visit_on`) compte si elle est plus ancienne. Ancien : les autres.
- Âge : âge révolu au dernier jour de la période ; tranches 0–17, 18–39, 40–59, 60+ (proposées par le fichier
  02, **à confirmer** par le cabinet : constante `ActivityReports::AGE_BANDS`).
- Une consultation appartient à la période par sa date effective ; elle compte une fois par motif, une fois si
  elle a au moins une prescription optique validée (interne), une fois si elle a au moins un médicament.
- Parts en dixièmes de pour cent, arrondi au plus proche, sans calcul flottant (`divideRoundHalfUp`).

## Reste à faire

Tranches d'âge configurables par le cabinet si la
cliente le demande ; mesure des performances sur un volume réel ; export des tableaux.
