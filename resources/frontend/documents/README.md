# documents

Moteur de documents PDF **local** (D01, D02 ; fichier 11) : génération par jsPDF sur le poste, sans
serveur d'impression, sans appel réseau, sans ressource distante. Les modèles sont versionnés : une pièce
émise se régénère toujours avec la version de modèle de son snapshot. Détail et décisions :
`docs/implementation/documents.md`.

| Dossier | Rôle |
| --- | --- |
| `engine/` | Primitives de mise en page A4, A5 et ticket (`units`, `wrap`, `flow`, `blocks`, `chrome`, `layout`), jeu de caractères des PDF (`charset`), métriques de Helvetica (`metrics`), mise en forme française (`format`), rendu jsPDF (`pdf`, seul importeur de jsPDF), aperçu (`preview`), empreintes (`hash`) |
| `data/` | Données d'entrée des modèles (`financial`, `clinical`, `clinic`), libellés du référentiel (`labels`, `clinicalLabels`) et adaptateurs depuis les contrats du serveur (`financial`, `clinicalAdapters`) |
| `templates/` | Un modèle par type de document, fonctions pures `build<Type>(données, options) => DocumentModel` (`financial/`, `credits/`, `clinical/`) ; `registry.ts` choisit la version |
| `store/` | Port de conservation `DocumentStore` et version mémoire ; le coffre chiffré s'y branchera (O01 à O05) |
| `output/` | Nom de fichier sans donnée sensible, ouverture, téléchargement et impression |
| `demo/` | Jeu de documents de démonstration **fictifs** (aperçu, tests, rendu « golden ») |
| `fonts/` | Polices : voir `fonts/README.md` (polices standard du format PDF pour l'instant) |
| `access.ts` | Droits par type de document et compartiment du coffre |

Règles : aucun stockage local (Dexie, IndexedDB) ni `offline/` ici (règle ESLint d'architecture) ; aucun
appel réseau ; aucune donnée personnelle hors du poste ; aucun PDF ne dit « payé » ou « soldé » sans pièce
réelle. Les écrans passent par `app/services/documents.ts`.
