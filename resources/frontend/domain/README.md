# domain

Règles pures, erreurs et types : montants entiers XAF, quantités décimales exactes, dates civiles.
Aucun accès réseau, DOM ni stockage. Mêmes vecteurs JSON que le PHP (tâche F08).

`contracts/generated.ts` : types TypeScript des contrats (commandes, cœur signé, ACK, événements,
ressources, snapshots, erreurs), générés depuis `contracts/` par `npm run contracts:types` ; ne pas
modifier à la main (contrôle `--check` en CI, voir `docs/implementation/contracts.md`).
