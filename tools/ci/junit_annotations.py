"""Publie les échecs PHPUnit (rapports JUnit XML) en annotations GitHub Actions.

Le journal brut d'une exécution n'est lisible qu'avec les droits d'administration du dépôt ; les annotations le
sont par l'API publique des « check runs ». Ce script ne publie que le nom du test et le début de son message
d'échec (données fictives uniquement, comme toute la suite de tests).

Usage (CI, étape « if: failure() ») : python3 tools/ci/junit_annotations.py "build/junit-*.xml"
"""
import glob
import sys
import xml.etree.ElementTree as ElementTree

MAX_LINES = 12
MAX_CHARACTERS = 900


def escape_data(value: str) -> str:
    return value.replace('%', '%25').replace('\r', '%0D').replace('\n', '%0A')


def escape_property(value: str) -> str:
    return escape_data(value).replace(':', '%3A').replace(',', '%2C')


def main(patterns: list[str]) -> int:
    paths = sorted({path for pattern in patterns for path in glob.glob(pattern)})
    published = 0
    for path in paths:
        for case in ElementTree.parse(path).iter('testcase'):
            for node in [*case.findall('failure'), *case.findall('error')]:
                name = f"{case.get('class') or case.get('classname') or '?'}::{case.get('name') or '?'}"
                text = (node.text or node.get('message') or '').strip()
                summary = '\n'.join(text.splitlines()[:MAX_LINES])[:MAX_CHARACTERS]
                print(f'::error title={escape_property(name)}::{escape_data(summary)}')
                published += 1
    print(f'{published} échec(s) PHPUnit publiés en annotations ({len(paths)} rapport(s) JUnit).')
    return 0


if __name__ == '__main__':
    sys.exit(main(sys.argv[1:]))
