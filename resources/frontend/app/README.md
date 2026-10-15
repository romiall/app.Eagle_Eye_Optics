# app

Routeur, amorçage, navigation, session, réseau et enregistrement du service worker. `services/`
(F10) est le seul accès des écrans aux lectures, aux mutations et à l'état de synchronisation :
un écran n'importe jamais `dexie` ni `@/offline/*`. Aucune règle métier.
