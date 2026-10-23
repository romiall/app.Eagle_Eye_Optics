<script setup lang="ts">
/**
 * Icônes de l'interface (navigation, accueil, en-tête, en-têtes d'écran, cartes, tuiles, puces) :
 * tracés SVG en ligne dessinés pour l'application, au trait, couleur du texte (`currentColor`),
 * dans une grille 24 × 24. Purement décoratives : `aria-hidden`, jamais le seul porteur d'un sens
 * (le libellé visible reste toujours à côté). Aucune dépendance, aucune ressource distante
 * (hors-ligne, CSP). Un nom inconnu rend un point discret. Noms : docs/implementation/shell.md.
 */
import { computed } from 'vue'

const props = withDefaults(defineProps<{ name: string; size?: number }>(), { size: 20 })

/** Formes partagées par plusieurs icônes. */
const CALENDAR: readonly string[] = [
  'M4 6a2 2 0 0 1 2-2h12a2 2 0 0 1 2 2v13a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2z',
  'M8 2v4M16 2v4M4 10h16',
]
const CIRCLE = 'M12 21a9 9 0 1 0 0-18 9 9 0 0 0 0 18z'
const EYE: readonly string[] = [
  'M2 12s3.6-7 10-7 10 7 10 7-3.6 7-10 7S2 12 2 12z',
  'M12 15a3 3 0 1 0 0-6 3 3 0 0 0 0 6z',
]
const USERS: readonly string[] = [
  'M16 20v-1.5a3.5 3.5 0 0 0-3.5-3.5h-5A3.5 3.5 0 0 0 4 18.5V20',
  'M10 11a3.5 3.5 0 1 0 0-7 3.5 3.5 0 0 0 0 7z',
  'M20 20v-1.5a3.5 3.5 0 0 0-2.5-3.35M15.5 4.15a3.5 3.5 0 0 1 0 6.7',
]
const CLOUD = 'M7 18.5a4.6 4.6 0 0 1-.6-9 6 6 0 0 1 11.4 1.5 4 4 0 0 1-.3 7.5z'

/** Chaque icône : liste de tracés dans une grille 24 × 24. */
const PATHS: Readonly<Record<string, readonly string[]>> = {
  // Navigation et accueil.
  home: ['M3 10.5 12 3l9 7.5', 'M5 9.5V20a1 1 0 0 0 1 1h4v-6h4v6h4a1 1 0 0 0 1-1V9.5'],
  today: [...CALENDAR, 'm9 15 2 2 4-4'],
  patients: USERS,
  consultations: EYE,
  agenda: [...CALENDAR, 'M8 14h.01M12 14h.01M16 14h.01M8 17.5h.01M12 17.5h.01'],
  sales: ['M6 2h12v20l-3-2-3 2-3-2-3 2z', 'M9 7h6M9 11h6M9 15h4'],
  stock: ['m12 2 9 5v10l-9 5-9-5V7z', 'm3 7 9 5 9-5M12 12v10'],
  workshop: [
    'M14.7 6.3a4 4 0 0 0-5.4 5.4L3 18l3 3 6.3-6.3a4 4 0 0 0 5.4-5.4l-2.6 2.6-2.4-.6-.6-2.4z',
  ],
  communication: ['M21 12a8 8 0 0 1-11.6 7.1L4 20l1-4.6A8 8 0 1 1 21 12z'],
  reports: ['M4 20V4', 'M4 20h16', 'M8 16v-5M12 16V8M16 16v-3M20 16V6'],
  settings: [
    'M12 15a3 3 0 1 0 0-6 3 3 0 0 0 0 6z',
    'M19.4 15a1.7 1.7 0 0 0 .3 1.8l.1.1a2 2 0 1 1-2.8 2.8l-.1-.1a1.7 1.7 0 0 0-1.8-.3 1.7 1.7 0 0 0-1 1.5V21a2 2 0 1 1-4 0v-.1a1.7 1.7 0 0 0-1.1-1.5 1.7 1.7 0 0 0-1.8.3l-.1.1a2 2 0 1 1-2.8-2.8l.1-.1a1.7 1.7 0 0 0 .3-1.8 1.7 1.7 0 0 0-1.5-1H3a2 2 0 1 1 0-4h.1a1.7 1.7 0 0 0 1.5-1.1 1.7 1.7 0 0 0-.3-1.8l-.1-.1a2 2 0 1 1 2.8-2.8l.1.1a1.7 1.7 0 0 0 1.8.3H9a1.7 1.7 0 0 0 1-1.5V3a2 2 0 1 1 4 0v.1a1.7 1.7 0 0 0 1 1.5 1.7 1.7 0 0 0 1.8-.3l.1-.1a2 2 0 1 1 2.8 2.8l-.1.1a1.7 1.7 0 0 0-.3 1.8V9a1.7 1.7 0 0 0 1.5 1H21a2 2 0 1 1 0 4h-.1a1.7 1.7 0 0 0-1.5 1z',
  ],
  offline: [
    'M5 4h14a1 1 0 0 1 1 1v10a1 1 0 0 1-1 1H5a1 1 0 0 1-1-1V5a1 1 0 0 1 1-1z',
    'M8 20h8M12 16v4',
  ],
  'user-plus': [
    'M15 20v-1.5a3.5 3.5 0 0 0-3.5-3.5h-5A3.5 3.5 0 0 0 3 18.5V20',
    'M9 11a3.5 3.5 0 1 0 0-7 3.5 3.5 0 0 0 0 7z',
    'M19 8v6M16 11h6',
  ],
  search: ['M11 18a7 7 0 1 0 0-14 7 7 0 0 0 0 14z', 'm21 21-4.4-4.4'],
  'calendar-plus': [...CALENDAR, 'M12 13v5M9.5 15.5h5'],
  arrival: ['M15 3h4a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-4', 'M10 17l5-5-5-5', 'M15 12H3'],
  list: ['M8 6h13M8 12h13M8 18h13', 'M3.5 6h.01M3.5 12h.01M3.5 18h.01'],
  cash: [
    'M3 7h18v11H3z',
    'M12 15.5a3 3 0 1 0 0-6 3 3 0 0 0 0 6z',
    'M6 10.5h.01M18 14.5h.01',
    'M5 4h14',
  ],
  'file-plus': [
    'M14 3H7a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V8z',
    'M14 3v5h5',
    'M12 11v6M9 14h6',
  ],
  file: [
    'M14 3H7a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V8z',
    'M14 3v5h5',
    'M9 13h6M9 17h6',
  ],
  building: [
    'M4 21V5a2 2 0 0 1 2-2h8a2 2 0 0 1 2 2v16',
    'M16 9h2a2 2 0 0 1 2 2v10',
    'M3 21h18',
    'M8 7h4M8 11h4M8 15h4',
  ],
  tag: ['M20.6 13.4 13.4 20.6a2 2 0 0 1-2.8 0L3 13V3h10l7.6 7.6a2 2 0 0 1 0 2.8z', 'M7.5 7.5h.01'],
  key: [
    'M15.5 7.5a3.5 3.5 0 1 1-4.9 3.2L3 18.3V21h2.7l1-1v-1.5h1.5l1-1v-1.5h1.5l1.3-1.3a3.5 3.5 0 0 1 3.5-7.2z',
  ],
  help: [
    'M12 22a10 10 0 1 0 0-20 10 10 0 0 0 0 20z',
    'M9.1 9a3 3 0 0 1 5.8 1c0 2-3 3-3 3',
    'M12 17h.01',
  ],
  logout: ['M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4', 'm16 17 5-5-5-5', 'M21 12H9'],
  more: ['M5 12h.01M12 12h.01M19 12h.01'],
  shield: ['M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z', 'm9 12 2 2 4-4'],
  dot: ['M12 12h.01'],

  // Personnes et coordonnées.
  user: [
    'M12 12a4 4 0 1 0 0-8 4 4 0 0 0 0 8z',
    'M4.5 21v-1A5.5 5.5 0 0 1 10 14.5h4a5.5 5.5 0 0 1 5.5 5.5v1',
  ],
  users: USERS,
  'id-card': [
    'M4 5h16a1 1 0 0 1 1 1v12a1 1 0 0 1-1 1H4a1 1 0 0 1-1-1V6a1 1 0 0 1 1-1z',
    'M9 12a2 2 0 1 0 0-4 2 2 0 0 0 0 4z',
    'M6 16a3 3 0 0 1 6 0',
    'M15 10h3M15 14h3',
  ],
  phone: [
    'M5 3.5h3.2l1.6 4.2-2.2 1.5a12 12 0 0 0 7.2 7.2l1.5-2.2 4.2 1.6V19a1.5 1.5 0 0 1-1.5 1.5A16 16 0 0 1 3.5 5 1.5 1.5 0 0 1 5 3.5z',
  ],
  smartphone: [
    'M8 2.5h8a1.5 1.5 0 0 1 1.5 1.5v16a1.5 1.5 0 0 1-1.5 1.5H8A1.5 1.5 0 0 1 6.5 20V4A1.5 1.5 0 0 1 8 2.5z',
    'M11 18h2',
  ],
  mail: [
    'M4.5 5h15A1.5 1.5 0 0 1 21 6.5v11a1.5 1.5 0 0 1-1.5 1.5h-15A1.5 1.5 0 0 1 3 17.5v-11A1.5 1.5 0 0 1 4.5 5z',
    'm3.5 7 8.5 6 8.5-6',
  ],
  'map-pin': [
    'M12 21s7-6.1 7-11.5A7 7 0 0 0 5 9.5C5 14.9 12 21 12 21z',
    'M12 12a2.5 2.5 0 1 0 0-5 2.5 2.5 0 0 0 0 5z',
  ],

  // Examens et optique.
  eye: EYE,
  'eye-off': [
    'M3 3l18 18',
    'M10.6 5.1A10 10 0 0 1 12 5c6.4 0 10 7 10 7a17.6 17.6 0 0 1-2.9 3.9',
    'M6.6 6.6C3.8 8.5 2 12 2 12s3.6 7 10 7a9.8 9.8 0 0 0 5.4-1.6',
    'M9.9 9.9a3 3 0 0 0 4.2 4.2',
  ],
  glasses: [
    'M6.5 17.5a3.5 3.5 0 1 0 0-7 3.5 3.5 0 0 0 0 7z',
    'M17.5 17.5a3.5 3.5 0 1 0 0-7 3.5 3.5 0 0 0 0 7z',
    'M10 13.5c.6-.6 1.3-.9 2-.9s1.4.3 2 .9',
    'M3 14 4.6 7.6A2 2 0 0 1 6.5 6H7',
    'M21 14l-1.6-6.4A2 2 0 0 0 17.5 6H17',
  ],
  stethoscope: [
    'M7 3H5v6a5 5 0 0 0 10 0V3h-2',
    'M10 14v2a5 5 0 0 0 10 0v-3',
    'M20 13a2 2 0 1 0 0-4 2 2 0 0 0 0 4z',
  ],
  clipboard: [
    'M9 4.5H7a2 2 0 0 0-2 2V20a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V6.5a2 2 0 0 0-2-2h-2',
    'M10 2.5h4a1 1 0 0 1 1 1v2a1 1 0 0 1-1 1h-4a1 1 0 0 1-1-1v-2a1 1 0 0 1 1-1z',
    'M9 12h6M9 16h4',
  ],

  // Temps et historique.
  calendar: CALENDAR,
  clock: [CIRCLE, 'M12 7v5l3 2'],
  history: ['M3.5 12a8.5 8.5 0 1 0 2.5-6L3.5 8.5', 'M3.5 3.5v5h5', 'M12 7.5V12l3 2'],

  // Ventes, caisse et documents.
  receipt: ['M5 3h14v18l-2.5-1.5L14 21l-2-1.5L10 21l-2.5-1.5L5 21z', 'M9 8h6M9 12h6M9 16h3'],
  wallet: [
    'M19 8V5.5a1.5 1.5 0 0 0-1.5-1.5H6a2 2 0 0 0 0 4h13a1 1 0 0 1 1 1v10a1 1 0 0 1-1 1H6a2 2 0 0 1-2-2V6',
    'M16 14h.01',
  ],
  percent: [
    'M19 5 5 19',
    'M7 9.5a2.5 2.5 0 1 0 0-5 2.5 2.5 0 0 0 0 5z',
    'M17 19.5a2.5 2.5 0 1 0 0-5 2.5 2.5 0 0 0 0 5z',
  ],
  chart: ['M4 4v16h16', 'm7.5 14.5 3.5-4 3 3 5-6'],
  printer: [
    'M7 9V3.5h10V9',
    'M7 17.5H5a2 2 0 0 1-2-2V11a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2v4.5a2 2 0 0 1-2 2h-2',
    'M7 14h10v7H7z',
  ],
  download: ['M12 4v11', 'm7 10.5 5 5 5-5', 'M5 20h14'],
  upload: ['M12 15.5V4.5', 'm7 9 5-5 5 5', 'M5 20h14'],
  folder: ['M3 7a2 2 0 0 1 2-2h4.2l2 2.5H19a2 2 0 0 1 2 2V18a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z'],
  archive: ['M3.5 4h17v4h-17z', 'M5 8v11a1 1 0 0 0 1 1h12a1 1 0 0 0 1-1V8', 'M10 12h4'],

  // Actions.
  plus: ['M12 5v14M5 12h14'],
  minus: ['M5 12h14'],
  edit: ['M16.5 3.5a2.1 2.1 0 0 1 3 3L8 18l-4 1 1-4z', 'm14.5 5.5 3 3'],
  trash: [
    'M4 6.5h16',
    'M9 6.5V4h6v2.5',
    'M6 6.5l.9 13.1a1.5 1.5 0 0 0 1.5 1.4h7.2a1.5 1.5 0 0 0 1.5-1.4L18 6.5',
    'M10 11v5.5M14 11v5.5',
  ],
  filter: ['M3 4.5h18l-7 8.2V19l-4 2v-8.3z'],
  refresh: [
    'M19.5 11a7.5 7.5 0 0 0-13.4-4.6L4.5 8.5',
    'M4.5 3.5v5h5',
    'M4.5 13a7.5 7.5 0 0 0 13.4 4.6l1.6-2.1',
    'M19.5 20.5v-5h-5',
  ],
  merge: ['M6 3v3c0 3 2 5 6 7', 'M18 3v3c0 3-2 5-6 7v8', 'm9 18 3 3 3-3'],
  send: ['M21 3 10.5 13.5', 'M21 3l-6.5 18-4-7.5L3 9.5z'],
  'external-link': [
    'M14 4h6v6',
    'M20 4l-9 9',
    'M18 14v5a1 1 0 0 1-1 1H5a1 1 0 0 1-1-1V7a1 1 0 0 1 1-1h5',
  ],
  lock: [
    'M6.5 11h11a1.5 1.5 0 0 1 1.5 1.5v7a1.5 1.5 0 0 1-1.5 1.5h-11A1.5 1.5 0 0 1 5 19.5v-7A1.5 1.5 0 0 1 6.5 11z',
    'M8 11V7.5a4 4 0 0 1 8 0V11',
  ],
  bell: ['M6 16v-5a6 6 0 0 1 12 0v5l1.5 2h-15z', 'M10 21h4'],

  // Directions.
  'chevron-right': ['m9 6 6 6-6 6'],
  'chevron-left': ['m15 6-6 6 6 6'],
  'chevron-down': ['m6 9 6 6 6-6'],
  'chevron-up': ['m6 15 6-6 6 6'],
  'arrow-left': ['M19 12H5', 'm11 6-6 6 6 6'],
  'arrow-right': ['M5 12h14', 'm13 6 6 6-6 6'],

  // États (toujours à côté d'un libellé).
  check: ['m4.5 12.5 5 5L19.5 7'],
  'check-circle': [CIRCLE, 'm8 12.5 2.8 2.8L16 9.8'],
  x: ['M18 6 6 18M6 6l12 12'],
  'x-circle': [CIRCLE, 'm9 9 6 6M15 9l-6 6'],
  alert: [
    'M10.3 4 2.6 17.5A2 2 0 0 0 4.3 20.5h15.4a2 2 0 0 0 1.7-3L13.7 4a2 2 0 0 0-3.4 0z',
    'M12 9.5v4',
    'M12 17h.01',
  ],
  info: [CIRCLE, 'M12 11v5', 'M12 7.8h.01'],
  cloud: [CLOUD],
  'cloud-off': [CLOUD, 'M3 3l18 18'],
}

const paths = computed(() => PATHS[props.name] ?? PATHS['dot'] ?? [])
</script>

<template>
  <svg
    xmlns="http://www.w3.org/2000/svg"
    :width="props.size"
    :height="props.size"
    viewBox="0 0 24 24"
    fill="none"
    stroke="currentColor"
    stroke-width="1.75"
    stroke-linecap="round"
    stroke-linejoin="round"
    aria-hidden="true"
    focusable="false"
    class="shrink-0"
  >
    <path v-for="(d, index) in paths" :key="index" :d="d" />
  </svg>
</template>
