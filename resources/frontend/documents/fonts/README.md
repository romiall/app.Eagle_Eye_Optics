# Polices des PDF

## État actuel

Les PDF utilisent les **polices standard du format PDF** (Helvetica, Helvetica-Bold, Helvetica-Oblique,
Helvetica-BoldOblique) avec l'encodage WinAnsi :

- aucune police n'est téléchargée ni embarquée : tout lecteur PDF les connaît, aucune licence à gérer,
  aucun CDN (fichiers 04 et 11) ;
- elles couvrent toutes les lettres accentuées du français (é è ê ë à â î ï ô ö ù û ü ÿ ç œ Œ…), les
  guillemets « », l'apostrophe typographique, les tirets, « ° », « × », « € » et l'espace insécable ;
- les caractères hors de ce jeu (lettres de langues locales comme « Ɓ » ou « Ɗ », symboles, émojis) **ne
  peuvent pas être dessinés** : le moteur les remplace (lettre sans accent si possible, sinon « ? ») et le
  **signale** dans les avertissements du document (`charset.ts`, `layout.ts`) ; il ne les supprime jamais en
  silence.

## Écart avec la spécification

Le fichier 11 demande une « fonte française embarquée » et la tâche D01 prévoit
`resources/frontend/documents/fonts/`. Aucune police sous licence libre n'est disponible hors ligne dans
l'environnement de développement (aucun téléchargement n'est permis, aucune fonte système ne peut être
redistribuée). Les polices standard remplissent l'exigence fonctionnelle (accents, hors ligne) ; embarquer
une police Unicode reste à décider si des noms de patients utilisent des lettres hors WinAnsi.

## Embarquer une police plus tard (sans toucher aux modèles)

1. Choisir une police sous licence libre qui autorise la redistribution (par exemple DejaVu Sans ou
   Liberation Sans, licences à joindre au dépôt dans ce dossier) ; ne jamais copier une police du système.
2. L'ajouter ici (`.ttf`), l'enregistrer dans jsPDF (`addFileToVFS`, `addFont`) **dans
   `engine/pdf.ts` uniquement**, et remplacer les largeurs de `engine/metrics.ts` par celles de la police
   (la mesure et le dessin doivent rester d'accord).
3. Élargir `engine/charset.ts` au jeu de la nouvelle police.
4. **Créer une nouvelle version de modèle** (voir `docs/implementation/documents.md`) : le rendu d'une pièce
   déjà émise ne change jamais. L'ancienne version garde ses polices standard.
5. Vérifier le poids du paquet (le fichier de police est précaché par le service worker) et le test
   `tests/frontend/documents/no-network.test.ts`, qui interdit aujourd'hui tout fichier de police.
