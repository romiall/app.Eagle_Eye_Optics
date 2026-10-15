// IndexedDB simulée pour les tests Vitest du moteur local (Dexie). La preuve finale de persistance
// utilise un vrai navigateur (Playwright), jamais fake-indexeddb.
import 'fake-indexeddb/auto'

// jsdom n'implémente pas le défilement ; le routeur remonte en haut de l'écran à chaque navigation.
// Certains fichiers de tests s'exécutent sans DOM (environnement node) : rien à remplacer alors.
if (typeof window !== 'undefined') {
  Object.defineProperty(window, 'scrollTo', { value: () => undefined, writable: true })
}
