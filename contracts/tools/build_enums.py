"""Énumérations des contrats (F06) générées depuis le catalogue canonique de la base.

Chaque colonne du catalogue qui porte `allowed_values` (CHECK SQL `IN (...)`) devient une définition
`$defs/<table>.<colonne>` de contracts/schemas/enums.json. Les contrats API et de synchronisation
référencent ces définitions au lieu de recopier des listes : une valeur admise par l'API est toujours
admise par la base, et inversement.

  python3 contracts/tools/build_enums.py          # écrit contracts/schemas/enums.json
  python3 contracts/tools/build_enums.py --check  # échoue si le fichier est absent ou périmé (CI)
"""
from pathlib import Path
import json
import sys

ROOT = Path(__file__).resolve().parents[2]
CATALOG = ROOT / 'database' / 'schema_catalog.json'
TARGET = ROOT / 'contracts' / 'schemas' / 'enums.json'


def build():
    catalog = json.loads(CATALOG.read_text(encoding='utf-8'))
    defs = {}
    for table in catalog['tables']:
        for column in table['columns']:
            values = column.get('allowed_values')
            if values:
                defs[table['name'] + '.' + column['name']] = {
                    'type': 'string',
                    'enum': list(values),
                    'description': (column.get('description') or '') + ' (catalogue : ' + table['name'] + '.' + column['name'] + ')',
                }
    return {
        '$schema': 'https://json-schema.org/draft/2020-12/schema',
        '$id': 'https://eeo.invalid/contracts/schemas/enums.json',
        'title': 'Énumérations du catalogue canonique',
        'description': 'Généré par contracts/tools/build_enums.py depuis database/schema_catalog.json : ne pas modifier à la main.',
        '$defs': dict(sorted(defs.items())),
    }


def main():
    text = json.dumps(build(), ensure_ascii=False, indent=2) + '\n'
    if '--check' in sys.argv:
        if not TARGET.exists() or TARGET.read_text(encoding='utf-8') != text:
            print('ECHEC : contracts/schemas/enums.json absent ou périmé : relancer contracts/tools/build_enums.py', file=sys.stderr)
            sys.exit(1)
        print('Énumérations à jour.')
        return
    TARGET.write_text(text, encoding='utf-8')
    print(str(TARGET.relative_to(ROOT)) + ' : ' + str(len(json.loads(text)['$defs'])) + ' énumérations')


if __name__ == '__main__':
    main()
