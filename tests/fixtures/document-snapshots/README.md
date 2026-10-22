# Références des documents de démonstration (D02)

Un fichier `<document>.txt` par document de `DEMO_DOCUMENTS` (`resources/frontend/documents/demo/`) :
première ligne `# <id> : <n> page(s), PDF sha256 <empreinte>`, puis le texte de mise en page page par page.
Données **fictives** uniquement.

Les fichiers sont produits par `EEO_DOCUMENT_GOLDEN=update npx vitest run tests/frontend/documents/golden`
**après** la revue visuelle décrite dans `tests/frontend/documents/golden/README.md`. Tant qu'ils
n'existent pas, la comparaison est ignorée (aucune vérification n'est prétendue).

Un fichier qui diffère signale qu'un modèle publié (`TEST-1`) a changé de rendu : on ajoute une nouvelle
version de modèle au lieu de réécrire la référence (« l'ancienne version conserve ses fontes et son modèle »).
