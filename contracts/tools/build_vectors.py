"""Vecteurs communs des règles pures (F08) : contracts/vectors/*.json.

Les attendus de chaque cas sont écrits À LA MAIN dans ce fichier, puis recoupés par une troisième implémentation
indépendante (Decimal en ROUND_HALF_UP, datetime, calendar) avant toute écriture : un attendu faux fait échouer le
script, rien n'est écrit. Les fichiers produits sont la référence rejouée par PHPUnit (tests/Unit/Rules) et
Vitest (npm run test:domain) ; ne pas les modifier à la main.

  python3 contracts/tools/build_vectors.py          # recoupe puis écrit les fichiers
  python3 contracts/tools/build_vectors.py --check  # échoue si un fichier est absent ou périmé (CI)

Aucune séquence d'échappement Unicode dans ce source : les caractères invisibles sont construits par chr().
"""
import calendar
import datetime as dt
import json
import pathlib
import sys
from decimal import ROUND_HALF_UP, Decimal

ROOT = pathlib.Path(__file__).resolve().parents[2]
CHECK = '--check' in sys.argv[1:]
OUT = ROOT / 'contracts' / 'vectors'
RULES_VERSION = '2026-10-14.1'

NBSP = chr(0xA0)
NNBSP = chr(0x202F)
THIN = chr(0x2009)
MINUS = chr(0x2212)
ACUTE = chr(0x301)
RSQUO = chr(0x2019)
GRIN = chr(0x1F600)
SHY = chr(0xAD)
ZWSP = chr(0x200B)
BOM = chr(0xFEFF)
CAPITAL_I_DOT = chr(0x130)
FINAL_SIGMA = chr(0x3C2)


def ok(case_id, operation, input_, expected, covers=None, description=None):
    case = {'id': case_id, 'operation': operation}
    if description:
        case['description'] = description
    if covers:
        case['covers'] = covers
    case['input'] = input_
    case['expected'] = expected
    return case


def ko(case_id, operation, input_, code, field, rule, details=None, covers=None, description=None):
    error = {'code': code, 'field': field, 'rule': rule}
    if details is not None:
        error['details'] = details
    case = {'id': case_id, 'operation': operation}
    if description:
        case['description'] = description
    if covers:
        case['covers'] = covers
    case['input'] = input_
    case['error'] = error
    return case


V = 'VALIDATION_FAILED'

# ---------------------------------------------------------------------------------------------------- texte
text = [
    ok('text.trim.espaces', 'text.trim', {'value': '  Emma  '}, 'Emma'),
    ok('text.trim.insecables', 'text.trim', {'value': NBSP + 'Ngo' + NNBSP}, 'Ngo'),
    ok('text.trim.controles-de-bord', 'text.trim', {'value': chr(9) + chr(13) + chr(10) + ' Emma ' + chr(10)}, 'Emma'),
    ok('text.trim.interieur-conserve', 'text.trim', {'value': ' Jean  Pierre '}, 'Jean  Pierre'),
    ok('text.trim.liste-fermee', 'text.trim', {'value': THIN + 'A'}, THIN + 'A',
       description='U+2009 ne fait pas partie de la liste fermee des blancs : il reste.'),
    ok('text.optional.blanc-vaut-null', 'text.optional', {'value': '   ', 'max_length': 10, 'field': 'note'}, None),
    ok('text.optional.null', 'text.optional', {'value': None, 'max_length': 10, 'field': 'note'}, None),
    ok('text.optional.longueur-en-caracteres', 'text.optional', {'value': 'Évêché', 'max_length': 6, 'field': 'note'}, 'Évêché'),
    ko('text.optional.trop-long', 'text.optional', {'value': 'Évêchés', 'max_length': 6, 'field': 'note'},
       V, 'note', 'text.too_long', {'max_length': 6}),
    ok('text.optional.unicode-original-conserve', 'text.optional',
       {'value': 'Jose' + ACUTE + ' Ngo', 'max_length': 20, 'field': 'first_names'}, 'Jose' + ACUTE + ' Ngo',
       description='La forme decomposee saisie est conservee telle quelle, sans normalisation.'),
    ok('text.search.apostrophe-tiret-accent', 'text.searchKey', {'value': "N'Guessan-Kouamé"}, 'n guessan kouame', covers=['EE045']),
    ok('text.search.majuscules-blancs', 'text.searchKey', {'value': '  ÉMILIE   Ndongo '}, 'emilie ndongo'),
    ok('text.search.vietnamien', 'text.searchKey', {'value': 'Ngô Thị Ánh'}, 'ngo thi anh'),
    ok('text.search.forme-decomposee', 'text.searchKey', {'value': 'Jose' + ACUTE}, 'jose'),
    ok('text.search.ligatures-conservees', 'text.searchKey', {'value': 'Œuvre Ærø'}, 'œuvre ærø'),
    ok('text.search.apostrophe-typographique', 'text.searchKey', {'value': 'D' + RSQUO + 'Almeida'}, 'd almeida'),
    ok('text.search.controles', 'text.searchKey', {'value': 'Marie\tClaire\nAnne'}, 'marie claire anne'),
    ok('text.search.sigma-final', 'text.searchKey', {'value': 'ΟΔΟΣ'}, 'οδο' + FINAL_SIGMA),
    ok('text.search.i-point-turc', 'text.searchKey', {'value': CAPITAL_I_DOT + 'stanbul'}, 'istanbul'),
    ok('text.search.symbole', 'text.searchKey', {'value': 'Awa ' + GRIN + ' Bello'}, 'awa bello'),
    ok('text.search.chiffres', 'text.searchKey', {'value': 'Patient 2'}, 'patient 2'),
    ok('text.search.trait-union-conditionnel', 'text.searchKey', {'value': 'Ma' + SHY + 'rie'}, 'marie',
       description='Caractères de format invisibles retirés de la clé (U+00AD, U+200B, U+FEFF).'),
    ok('text.search.espace-sans-chasse', 'text.searchKey', {'value': 'Jean' + ZWSP + 'Paul'}, 'jeanpaul'),
    ok('text.search.indicateur-ordre-octets', 'text.searchKey', {'value': BOM + 'Awa'}, 'awa'),
    ok('text.phone.international', 'text.phone', {'value': '+237 6 77 12 34 56', 'field': 'phone_primary'}, '+237677123456'),
    ok('text.phone.double-zero', 'text.phone', {'value': '00237 677-12-34-56', 'field': 'phone_primary'}, '+237677123456'),
    ok('text.phone.national', 'text.phone', {'value': '677.12.34.56', 'field': 'phone_primary'}, '677123456'),
    ok('text.phone.parentheses', 'text.phone', {'value': '(237) 677 12 34 56', 'field': 'phone_primary'}, '237677123456'),
    ok('text.phone.vide', 'text.phone', {'value': '  ', 'field': 'phone_primary'}, None),
    ko('text.phone.lettres', 'text.phone', {'value': '677 12 AB', 'field': 'phone_primary'}, V, 'phone_primary', 'phone.format'),
    ok('text.phone.trois-chiffres', 'text.phone', {'value': '123', 'field': 'phone_primary'}, '123'),
    ok('text.phone.trente-et-un-chiffres', 'text.phone', {'value': '1' * 31, 'field': 'phone_primary'}, '1' * 31),
    ko('text.phone.trente-deux-chiffres', 'text.phone', {'value': '1' * 32, 'field': 'phone_primary'}, V, 'phone_primary', 'phone.length'),
    ko('text.phone.trop-court', 'text.phone', {'value': '12', 'field': 'phone_primary'}, V, 'phone_primary', 'phone.length'),
    ko('text.phone.plus-seul-chiffre', 'text.phone', {'value': '+0', 'field': 'phone_primary'}, V, 'phone_primary', 'phone.length'),
]

# ---------------------------------------------------------------------------------------------------- montants
money = [
    ok('money.parse.chiffres', 'money.parse', {'text': '80000', 'field': 'amount'}, 80000),
    ok('money.parse.espace', 'money.parse', {'text': '80 000', 'field': 'amount'}, 80000),
    ok('money.parse.insecable', 'money.parse', {'text': '80' + NBSP + '000', 'field': 'amount'}, 80000),
    ok('money.parse.espace-fine', 'money.parse', {'text': '1' + NNBSP + '000' + NNBSP + '000', 'field': 'amount'}, 1000000),
    ok('money.parse.blancs-de-bord', 'money.parse', {'text': '  50 000 ', 'field': 'amount'}, 50000),
    ok('money.parse.vide-vaut-null', 'money.parse', {'text': '', 'field': 'amount'}, None),
    ok('money.parse.blanc-vaut-null', 'money.parse', {'text': '   ', 'field': 'amount'}, None),
    ok('money.parse.zero', 'money.parse', {'text': '0', 'field': 'amount'}, 0),
    ok('money.parse.zeros-initiaux', 'money.parse', {'text': '007', 'field': 'amount'}, 7),
    ok('money.parse.maximum', 'money.parse', {'text': '1 000 000 000 000', 'field': 'amount'}, 1000000000000),
    ko('money.parse.espace-fine-ordinaire', 'money.parse', {'text': '80' + THIN + '000', 'field': 'amount'}, V, 'amount', 'money.format',
       description='U+2009 ne sépare pas les milliers : seuls l’espace, U+00A0 et U+202F sont admis.'),
    ko('money.parse.groupe-incomplet', 'money.parse', {'text': '80 00', 'field': 'amount'}, V, 'amount', 'money.format'),
    ko('money.parse.point-ambigu', 'money.parse', {'text': '80.000', 'field': 'amount'}, V, 'amount', 'money.format'),
    ko('money.parse.decimales', 'money.parse', {'text': '80000,50', 'field': 'amount'}, V, 'amount', 'money.format'),
    ko('money.parse.negatif', 'money.parse', {'text': '-5000', 'field': 'amount'}, V, 'amount', 'money.format'),
    ko('money.parse.unite', 'money.parse', {'text': '5 000 FCFA', 'field': 'amount'}, V, 'amount', 'money.format'),
    ko('money.parse.chiffres-arabes-orientaux', 'money.parse', {'text': chr(0x661) + chr(0x662) + chr(0x663), 'field': 'amount'}, V, 'amount', 'money.format'),
    ko('money.parse.au-dela-du-maximum', 'money.parse', {'text': '1000000000001', 'field': 'amount'}, V, 'amount', 'money.out_of_range'),
    ko('money.parse.quatorze-chiffres', 'money.parse', {'text': '99999999999999', 'field': 'amount'}, V, 'amount', 'money.out_of_range'),
    ok('money.format.groupes', 'money.format', {'amount': 80000}, '80' + NNBSP + '000' + NBSP + 'FCFA'),
    ok('money.format.zero', 'money.format', {'amount': 0}, '0' + NBSP + 'FCFA'),
    ok('money.format.trois-chiffres', 'money.format', {'amount': 999}, '999' + NBSP + 'FCFA'),
    ok('money.format.mille', 'money.format', {'amount': 1000}, '1' + NNBSP + '000' + NBSP + 'FCFA'),
    ok('money.format.negatif', 'money.format', {'amount': -50000}, '-50' + NNBSP + '000' + NBSP + 'FCFA'),
    ok('money.format.maximum', 'money.format', {'amount': 1000000000000}, NNBSP.join(['1', '000', '000', '000', '000']) + NBSP + 'FCFA'),
    ok('money.positive.un', 'money.positive', {'value': 1, 'field': 'amount'}, 1),
    ko('money.positive.zero', 'money.positive', {'value': 0, 'field': 'amount'}, V, 'amount', 'money.positive_required'),
    ko('money.positive.au-dela', 'money.positive', {'value': 1000000000001, 'field': 'amount'}, V, 'amount', 'money.out_of_range'),
    ko('money.amount.negatif', 'money.amount', {'value': -1, 'field': 'amount'}, V, 'amount', 'money.out_of_range'),
]

# ---------------------------------------------------------------------------------------------------- décimaux
def dparse(case_id, text_, kind, field, expected=None, rule=None, details=None, **extra):
    input_ = {'text': text_, 'kind': kind, 'field': field}
    if rule:
        return ko(case_id, 'decimal.parse', input_, V, field, rule, details, **extra)
    return ok(case_id, 'decimal.parse', input_, expected, **extra)


decimals = [
    dparse('decimal.quantity.entier', '1', 'quantity', 'quantity', '1.000'),
    dparse('decimal.quantity.virgule', '1,5', 'quantity', 'quantity', '1.500'),
    dparse('decimal.quantity.trois-decimales', '1.125', 'quantity', 'quantity', '1.125'),
    dparse('decimal.quantity.blancs', ' 2 ', 'quantity', 'quantity', '2.000'),
    dparse('decimal.quantity.zero', '0', 'quantity', 'quantity', '0.000',
           description='La lecture accepte zéro ; la positivité est une règle de ligne distincte.'),
    dparse('decimal.quantity.vide-vaut-null', '', 'quantity', 'quantity', None),
    dparse('decimal.quantity.trop-de-decimales', '1,2345', 'quantity', 'quantity', rule='decimal.scale', details={'scale': 3},
           description='Jamais d’arrondi ni de troncature.'),
    dparse('decimal.quantity.separateur-final', '1.', 'quantity', 'quantity', rule='decimal.format'),
    dparse('decimal.quantity.sans-partie-entiere', ',5', 'quantity', 'quantity', rule='decimal.format'),
    dparse('decimal.quantity.groupement-refuse', '1 000', 'quantity', 'quantity', rule='decimal.format'),
    dparse('decimal.quantity.negative', '-1', 'quantity', 'quantity', rule='decimal.negative'),
    dparse('decimal.quantity.moins-zero', '-0', 'quantity', 'quantity', '0.000'),
    dparse('decimal.quantity.plus', '+2', 'quantity', 'quantity', '2.000'),
    dparse('decimal.quantity.zeros-initiaux', '0001.5', 'quantity', 'quantity', '1.500'),
    dparse('decimal.quantity.maximum-sql', '999999999.999', 'quantity', 'quantity', '999999999.999'),
    dparse('decimal.quantity.au-dela-sql', '1000000000', 'quantity', 'quantity', rule='decimal.out_of_range', details={'integer_digits': 9}),
    dparse('decimal.rate.virgule', '19,25', 'rate', 'rate_percent', '19.2500'),
    dparse('decimal.rate.cent', '100', 'rate', 'rate_percent', '100.0000'),
    dparse('decimal.rate.zero', '0', 'rate', 'rate_percent', '0.0000'),
    dparse('decimal.rate.au-dela-de-cent', '100,0001', 'rate', 'rate_percent', rule='rate.out_of_range'),
    dparse('decimal.rate.trop-de-decimales', '12.34567', 'rate', 'rate_percent', rule='decimal.scale', details={'scale': 4}),
    dparse('decimal.rate.mille', '1000', 'rate', 'rate_percent', rule='decimal.out_of_range', details={'integer_digits': 3}),
    dparse('decimal.diopter.plus-virgule', '+1,25', 'diopter', 'sphere', '1.25'),
    dparse('decimal.diopter.negatif', '-0.75', 'diopter', 'sphere', '-0.75'),
    dparse('decimal.diopter.signe-moins-typographique', MINUS + '2,50', 'diopter', 'sphere', '-2.50'),
    dparse('decimal.diopter.zero-est-une-mesure', '0', 'diopter', 'sphere', '0.00'),
    dparse('decimal.diopter.vide-non-mesure', '', 'diopter', 'sphere', None,
           description='Non mesuré : null, jamais remplacé par 0.00.'),
    dparse('decimal.diopter.moins-zero', '-0', 'diopter', 'sphere', '0.00'),
    dparse('decimal.diopter.trois-decimales', '1.255', 'diopter', 'sphere', rule='decimal.scale', details={'scale': 2}),
    dparse('decimal.diopter.au-dela-sql', '12345', 'diopter', 'sphere', rule='decimal.out_of_range', details={'integer_digits': 4}),
    ok('quantity.line.entier', 'quantity.forLine', {'quantity': '2.000', 'integer_only': True}, '2.000'),
    ko('quantity.line.unite-entiere', 'quantity.forLine', {'quantity': '1.500', 'integer_only': True}, V, 'quantity', 'quantity.integer_required'),
    ok('quantity.line.fractionnable', 'quantity.forLine', {'quantity': '1.500', 'integer_only': False}, '1.500'),
    ko('quantity.line.zero', 'quantity.forLine', {'quantity': '0.000', 'integer_only': False}, V, 'quantity', 'quantity.positive_required'),
]

# ---------------------------------------------------------------------------------------------------- dates
def add(case_id, date_, amount, unit, expected=None, rule=None, covers=None, description=None):
    input_ = {'date': date_, 'amount': amount, 'unit': unit}
    if rule:
        return ko(case_id, 'date.add', input_, V, 'date', rule, covers=covers, description=description)
    return ok(case_id, 'date.add', input_, expected, covers=covers, description=description)


DOUALA = 'Africa/Douala'
dates = [
    ok('date.parse.iso', 'date.parse', {'text': '2026-10-15'}, '2026-10-15'),
    ok('date.parse.francaise', 'date.parse', {'text': '15/10/2026'}, '2026-10-15'),
    ok('date.parse.francaise-courte', 'date.parse', {'text': '5/1/2026'}, '2026-01-05'),
    ok('date.parse.bissextile', 'date.parse', {'text': '29/02/2024'}, '2024-02-29'),
    ok('date.parse.blancs', 'date.parse', {'text': ' 2026-10-15 '}, '2026-10-15'),
    ok('date.parse.vide-vaut-null', 'date.parse', {'text': ''}, None, description='Date inconnue : null, jamais une date inventée.'),
    ko('date.parse.29-fevrier-1900', 'date.parse', {'text': '29/02/1900'}, V, 'date', 'date.invalid', description='Année séculaire non bissextile.'),
    ok('date.parse.29-fevrier-2000', 'date.parse', {'text': '29/02/2000'}, '2000-02-29', description='Divisible par 400 : bissextile.'),
    ko('date.parse.29-fevrier-2100', 'date.parse', {'text': '2100-02-29'}, V, 'date', 'date.invalid'),
    ko('date.parse.29-fevrier-non-bissextile', 'date.parse', {'text': '29/02/2026'}, V, 'date', 'date.invalid'),
    ko('date.parse.30-fevrier', 'date.parse', {'text': '2026-02-30'}, V, 'date', 'date.invalid'),
    ko('date.parse.mois-13', 'date.parse', {'text': '2026-13-01'}, V, 'date', 'date.invalid'),
    ko('date.parse.tirets-francais', 'date.parse', {'text': '15-10-2026'}, V, 'date', 'date.format'),
    ko('date.parse.annee-courte', 'date.parse', {'text': '15/10/26'}, V, 'date', 'date.format'),
    ko('date.parse.avant-sql', 'date.parse', {'text': '0999-12-31'}, V, 'date', 'date.out_of_range'),
    add('date.add.fin-janvier-plus-un-mois', '2026-01-31', 1, 'MONTH', '2026-02-28', covers=['AT22']),
    add('date.add.fin-janvier-bissextile', '2024-01-31', 1, 'MONTH', '2024-02-29'),
    add('date.add.moins-un-mois', '2026-03-31', -1, 'MONTH', '2026-02-28'),
    add('date.add.trois-mois-fin-novembre', '2026-11-30', 3, 'MONTH', '2027-02-28'),
    add('date.add.trois-mois-fin-janvier', '2026-01-31', 3, 'MONTH', '2026-04-30', covers=['AT22']),
    add('date.add.six-mois-fin-aout', '2026-08-31', 6, 'MONTH', '2027-02-28'),
    add('date.add.deux-mois-vers-bissextile', '2023-12-31', 2, 'MONTH', '2024-02-29'),
    add('date.add.fin-annee', '2026-12-31', 1, 'DAY', '2027-01-01'),
    add('date.add.fin-fevrier-2100', '2100-02-28', 1, 'DAY', '2100-03-01'),
    add('date.add.29-fevrier-plus-un-an', '2024-02-29', 1, 'YEAR', '2025-02-28'),
    add('date.add.29-fevrier-plus-quatre-ans', '2024-02-29', 4, 'YEAR', '2028-02-29'),
    add('date.add.dix-jours', '2026-10-20', 10, 'DAY', '2026-10-30'),
    add('date.add.quatorze-jours', '2026-10-25', 14, 'DAY', '2026-11-08'),
    add('date.add.moins-sept-jours', '2026-10-15', -7, 'DAY', '2026-10-08'),
    add('date.add.au-dela-sql', '9999-12-31', 1, 'DAY', rule='date.out_of_range'),
    ok('date.instant.douala-apres-minuit', 'date.fromInstant', {'instant': '2026-10-14T23:30:00.000Z', 'timezone': DOUALA}, '2026-10-15',
       description='23 h 30 UTC est déjà le lendemain à Douala (UTC+1) : jamais de minuit UTC implicite.'),
    ok('date.instant.douala-veille', 'date.fromInstant', {'instant': '2026-10-14T22:59:59.999Z', 'timezone': DOUALA}, '2026-10-14'),
    ok('date.instant.douala-minuit-local', 'date.fromInstant', {'instant': '2026-10-14T23:00:00.000Z', 'timezone': DOUALA}, '2026-10-15'),
    ok('date.instant.douala-nouvel-an', 'date.fromInstant', {'instant': '2026-12-31T23:00:00.000Z', 'timezone': DOUALA}, '2027-01-01',
       description='L’année d’une pièce est celle de l’émission locale au fuseau du cabinet.'),
    ok('date.instant.douala-saint-sylvestre', 'date.fromInstant', {'instant': '2026-12-31T22:59:59.999Z', 'timezone': DOUALA}, '2026-12-31'),
    ok('date.instant.douala-fin-fevrier', 'date.fromInstant', {'instant': '2026-02-28T23:15:00.000Z', 'timezone': DOUALA}, '2026-03-01'),
    ok('date.instant.utc', 'date.fromInstant', {'instant': '2026-10-14T23:30:00.000Z', 'timezone': 'UTC'}, '2026-10-14'),
    ok('date.age.anniversaire', 'date.age', {'birth_date': '1990-10-15', 'reference_date': '2026-10-15'}, 36),
    ok('date.age.veille', 'date.age', {'birth_date': '1990-10-16', 'reference_date': '2026-10-15'}, 35),
    ok('date.age.29-fevrier-avant-mars', 'date.age', {'birth_date': '2000-02-29', 'reference_date': '2026-02-28'}, 25),
    ok('date.age.29-fevrier-premier-mars', 'date.age', {'birth_date': '2000-02-29', 'reference_date': '2026-03-01'}, 26),
    ok('date.age.29-fevrier-bissextile', 'date.age', {'birth_date': '2000-02-29', 'reference_date': '2024-02-29'}, 24),
    ok('date.age.nouveau-ne', 'date.age', {'birth_date': '2026-10-15', 'reference_date': '2026-10-15'}, 0),
    ko('date.age.naissance-future', 'date.age', {'birth_date': '2026-10-16', 'reference_date': '2026-10-15'}, V, 'birth_date', 'date.after_reference'),
]


def instant_case(case_id, text_, expected_canonical=None, rule=None, field='occurred_at'):
    if rule:
        return ko(case_id, 'instant.parse', {'text': text_}, V, field, rule)
    utc = dt.datetime.strptime(text_, '%Y-%m-%dT%H:%M:%S.%fZ').replace(tzinfo=dt.timezone.utc)
    epoch = int((utc - dt.datetime(1970, 1, 1, tzinfo=dt.timezone.utc)) / dt.timedelta(milliseconds=1))
    return ok(case_id, 'instant.parse', {'text': text_}, {'canonical': expected_canonical, 'epoch_ms': epoch})


dates += [
    instant_case('instant.parse.contrat', '2026-10-14T09:30:00.000Z', '2026-10-14T09:30:00.000Z'),
    instant_case('instant.parse.origine', '1970-01-01T00:00:00.000Z', '1970-01-01T00:00:00.000Z'),
    instant_case('instant.parse.avant-origine', '1969-12-31T23:59:59.999Z', rule='instant.out_of_range'),
    instant_case('instant.parse.minute-soixante', '2026-10-14T09:60:00.000Z', rule='instant.format'),
    instant_case('instant.parse.seconde-soixante', '2026-10-14T09:30:60.000Z', rule='instant.format'),
    instant_case('instant.parse.saut-de-ligne-final', '2026-10-14T09:30:00.000Z' + chr(10), rule='instant.format'),
    instant_case('instant.parse.bissextile-millisecondes', '2024-02-29T12:00:00.123Z', '2024-02-29T12:00:00.123Z'),
    instant_case('instant.parse.sans-millisecondes', '2026-10-14T09:30:00Z', rule='instant.format'),
    instant_case('instant.parse.vingt-quatre-heures', '2026-10-14T24:00:00.000Z', rule='instant.format'),
    instant_case('instant.parse.decalage-refuse', '2026-10-14T09:30:00.000+01:00', rule='instant.format'),
    instant_case('instant.parse.date-inexistante', '2026-02-29T10:00:00.000Z', rule='date.invalid'),
]

# ---------------------------------------------------------------------------------------------------- compteurs
counters = [
    ok('counter.parse.zero', 'counter.parse', {'value': '0'}, '0'),
    ok('counter.parse.simple', 'counter.parse', {'value': '42'}, '42'),
    ok('counter.parse.maximum', 'counter.parse', {'value': '18446744073709551615'}, '18446744073709551615'),
    ko('counter.parse.zero-initial', 'counter.parse', {'value': '007'}, V, 'device_sequence', 'counter.format'),
    ko('counter.parse.negatif', 'counter.parse', {'value': '-1'}, V, 'device_sequence', 'counter.format'),
    ko('counter.parse.saut-de-ligne-final', 'counter.parse', {'value': '1' + chr(10)}, V, 'device_sequence', 'counter.format'),
    ko('counter.parse.vide', 'counter.parse', {'value': ''}, V, 'device_sequence', 'counter.format'),
    ko('counter.parse.au-dela', 'counter.parse', {'value': '18446744073709551616'}, V, 'device_sequence', 'counter.out_of_range'),
    ok('counter.next.zero', 'counter.next', {'value': '0'}, '1'),
    ok('counter.next.au-dela-du-sur-javascript', 'counter.next', {'value': '9007199254740992'}, '9007199254740993',
       description='Au-delà de Number.MAX_SAFE_INTEGER : calcul exact en chaîne et BigInt.'),
    ok('counter.next.avant-maximum', 'counter.next', {'value': '18446744073709551614'}, '18446744073709551615'),
    ko('counter.next.maximum', 'counter.next', {'value': '18446744073709551615'}, V, 'device_sequence', 'counter.out_of_range'),
    ok('counter.compare.numerique', 'counter.compare', {'left': '10', 'right': '9'}, 1,
       description='Comparaison numérique, pas lexicale.'),
    ok('counter.compare.grand', 'counter.compare', {'left': '9007199254740993', 'right': '9007199254740992'}, 1),
    ok('counter.compare.egal', 'counter.compare', {'left': '18446744073709551615', 'right': '18446744073709551615'}, 0),
    ok('counter.compare.inferieur', 'counter.compare', {'left': '1', 'right': '2'}, -1),
]

# ---------------------------------------------------------------------------------------------------- factures
def line(q, price, discount=0, rate=None, integer_only=False):
    return {'quantity': q, 'unit_price_gross': price, 'discount_gross': discount, 'tax_rate_percent': rate, 'integer_only': integer_only}


def amounts(gross, discount, net, tax):
    return {'gross': gross, 'discount': discount, 'net': net, 'tax_amount': tax}


DEMO = 'Taux FICTIF de démonstration : aucun taux fiscal réel n’est fourni (Q03).'
invoices = [
    ok('invoice.line.contrat-1125-x-1001', 'invoice.line', line('1.125', 1001), amounts(1126, 0, 1126, None), covers=['IT03'],
       description='Vecteur du contrat 23 : 1,125 × 1001 = 1126,125 → 1126.'),
    ok('invoice.line.monture', 'invoice.line', line('1.000', 80000, integer_only=True), amounts(80000, 0, 80000, None), covers=['AT10']),
    ok('invoice.line.remise', 'invoice.line', line('2.000', 35000, 5000, integer_only=True), amounts(70000, 5000, 65000, None)),
    ok('invoice.line.demi-superieur', 'invoice.line', line('0.500', 1001), amounts(501, 0, 501, None),
       description='500,5 → 501 : arrondi au FCFA le plus proche, demi supérieur (ROUND de MySQL).'),
    ok('invoice.line.sous-le-demi', 'invoice.line', line('0.001', 499), amounts(0, 0, 0, None)),
    ok('invoice.line.demi-exact', 'invoice.line', line('0.001', 500), amounts(1, 0, 1, None)),
    ok('invoice.line.prestation-gratuite', 'invoice.line', line('3.000', 0), amounts(0, 0, 0, None)),
    ok('invoice.line.remise-totale', 'invoice.line', line('1.000', 5000, 5000), amounts(5000, 5000, 0, None)),
    ok('invoice.line.prix-maximum', 'invoice.line', line('1.000', 1000000000000), amounts(1000000000000, 0, 1000000000000, None)),
    ok('invoice.line.quantite-maximum', 'invoice.line', line('999999999.999', 1000), amounts(999999999999, 0, 999999999999, None)),
    ko('invoice.line.quantite-au-dela-sql', 'invoice.line', line('1000000000.000', 999), V, 'quantity', 'decimal.out_of_range', {'integer_digits': 9},
       description='Au-delà de DECIMAL(12,3) : refus avant tout calcul (MySQL ne pourrait pas stocker la ligne).'),
    ko('invoice.line.remise-superieure', 'invoice.line', line('1.000', 5000, 5001), V, 'discount_gross', 'line.discount_exceeds_gross', {'gross': 5000}),
    ko('invoice.line.unite-entiere', 'invoice.line', line('1.500', 5000, integer_only=True), V, 'quantity', 'quantity.integer_required'),
    ko('invoice.line.quantite-nulle', 'invoice.line', line('0.000', 5000), V, 'quantity', 'quantity.positive_required'),
    ko('invoice.line.au-dela-du-maximum', 'invoice.line', line('2.000', 1000000000000), V, 'unit_price_gross', 'line.amount_out_of_range'),
    ko('invoice.line.prix-negatif', 'invoice.line', line('1.000', -1), V, 'unit_price_gross', 'money.out_of_range'),
    ko('invoice.line.remise-negative', 'invoice.line', line('1.000', 5000, -1), V, 'discount_gross', 'money.out_of_range'),
    ok('invoice.line.taxe-comprise-exacte', 'invoice.line', line('1.000', 11000, rate='10.0000'), amounts(11000, 0, 11000, 1000), description=DEMO),
    ok('invoice.line.taxe-comprise-arrondie', 'invoice.line', line('1.000', 1000, rate='10.0000'), amounts(1000, 0, 1000, 91), description=DEMO),
    ok('invoice.line.taxe-douze-et-demi', 'invoice.line', line('1.000', 1005, rate='12.5000'), amounts(1005, 0, 1005, 112), description=DEMO),
    ok('invoice.line.taxe-nulle', 'invoice.line', line('1.000', 1005, rate='0.0000'), amounts(1005, 0, 1005, 0)),
    ok('invoice.line.taxe-demi-superieur', 'invoice.line', line('1.000', 1, rate='100.0000'), amounts(1, 0, 1, 1),
       description='1 × 100/200 = 0,5 → 1 : même règle d’arrondi que la ligne. ' + DEMO),
    ok('invoice.line.taxe-sur-le-net', 'invoice.line', line('2.000', 5500, 1000, rate='10.0000'), amounts(11000, 1000, 10000, 909),
       description='La taxe comprise se calcule sur le net après remise. ' + DEMO),
    ok('invoice.total.trois-lignes', 'invoice.total',
       {'lines': [line('1.000', 80000, integer_only=True), line('2.000', 35000, 5000, integer_only=True), line('1.125', 1001)]},
       {'lines': [amounts(80000, 0, 80000, None), amounts(70000, 5000, 65000, None), amounts(1126, 0, 1126, None)], 'total': 146126}),
    ok('invoice.total.maximum-exact', 'invoice.total', {'lines': [line('1.000', 999999999999), line('1.000', 1)]},
       {'lines': [amounts(999999999999, 0, 999999999999, None), amounts(1, 0, 1, None)], 'total': 1000000000000}),
    ko('invoice.total.au-dela-du-maximum', 'invoice.total',
       {'lines': [line('1.000', 1000000000000), line('1.000', 1)]}, V, 'lines', 'invoice.total_out_of_range'),
    ok('invoice.tax.zero', 'invoice.includedTax', {'net': 0, 'rate_percent': '10.0000'}, 0),
    ok('invoice.tax.maximum', 'invoice.includedTax', {'net': 1000000000000, 'rate_percent': '100.0000'}, 500000000000),
]

# ---------------------------------------------------------------------------------------------------- soldes
def ledger(total, payments=(), reversals=(), credits=(), refunds=()):
    return {'total_gross': total, 'payments': list(payments), 'reversals': list(reversals), 'credits': list(credits), 'refunds': list(refunds)}


def balance(signed, creditable, version):
    return {'signed_balance': signed, 'amount_due': max(0, signed), 'refundable_credit': max(0, -signed), 'creditable': creditable, 'ledger_version': version}


ledgers = [
    ok('ledger.balance.facture-emise', 'ledger.balance', ledger(80000), balance(80000, 80000, 1), covers=['AT10']),
    ok('ledger.balance.apres-acompte', 'ledger.balance', ledger(80000, [30000]), balance(50000, 80000, 2), covers=['AT10']),
    ok('ledger.balance.soldee', 'ledger.balance', ledger(80000, [30000, 50000]), balance(0, 80000, 3), covers=['AT10'],
       description='Deux reçus, même facture : l’impayé disparaît sans nouvelle vente.'),
    ok('ledger.balance.avoir-partiel', 'ledger.balance', ledger(80000, [30000, 20000], credits=[10000]), balance(20000, 70000, 4), covers=['AT14'],
       description='Vecteur du contrat 23 : avoir 10 000, paiements 50 000, dette 20 000.'),
    ok('ledger.balance.avoir-total', 'ledger.balance', ledger(80000, [50000], credits=[80000]), balance(-50000, 0, 3), covers=['AT14'],
       description='Avoir total 80 000 après paiement de 50 000 : 50 000 à rembourser, jamais une dette négative cachée.'),
    ok('ledger.balance.rembourse', 'ledger.balance', ledger(80000, [50000], credits=[80000], refunds=[50000]), balance(0, 0, 4), covers=['AT14']),
    ok('ledger.balance.contre-ecriture', 'ledger.balance', ledger(80000, [30000], reversals=[30000]), balance(80000, 80000, 3),
       description='Le paiement contre-passé ne compte plus comme encaissé ; les deux faits restent.'),
    ok('ledger.balance.reprise-papier', 'ledger.balance', ledger(50000, [20000]), balance(30000, 50000, 2), covers=['AT26']),
    ko('ledger.balance.paiements-au-dela', 'ledger.balance', ledger(1000000000000, [1000000000000, 1]), V, 'payments', 'money.out_of_range'),
    ko('ledger.balance.solde-signe-au-dela', 'ledger.balance', ledger(0, [1000000000000], credits=[1000000000000]), V, 'balance', 'money.out_of_range',
       description='Solde signé au-delà de -10^12 : refus, jamais de troncature.'),
    ko('ledger.balance.paiement-nul', 'ledger.balance', ledger(80000, [0]), V, 'payments', 'money.positive_required'),
    ok('ledger.payment.premier-de-deux', 'ledger.payment', {'ledger': ledger(80000, [30000]), 'amount': 40000}, balance(10000, 80000, 3), covers=['AT12']),
    ko('ledger.payment.second-de-deux', 'ledger.payment', {'ledger': ledger(80000, [30000, 40000]), 'amount': 40000},
       'PAYMENT_EXCEEDS_BALANCE', 'amount', 'payment.exceeds_balance', {'amount_due': 10000}, covers=['AT12'],
       description='Le second encaissement simultané de 40 000 est refusé et propose le solde de 10 000.'),
    ok('ledger.payment.solde-exact', 'ledger.payment', {'ledger': ledger(80000, [30000]), 'amount': 50000}, balance(0, 80000, 3), covers=['AT10']),
    ko('ledger.payment.montant-nul', 'ledger.payment', {'ledger': ledger(80000), 'amount': 0}, V, 'amount', 'money.positive_required'),
    ko('ledger.payment.facture-soldee', 'ledger.payment', {'ledger': ledger(80000, [80000]), 'amount': 1},
       'PAYMENT_EXCEEDS_BALANCE', 'amount', 'payment.exceeds_balance', {'amount_due': 0}),
    ok('ledger.payment.reprise-papier', 'ledger.payment', {'ledger': ledger(50000), 'amount': 20000}, balance(30000, 50000, 2), covers=['AT26']),
    ok('ledger.credit.partiel', 'ledger.creditNote', {'ledger': ledger(80000, [30000, 20000]), 'amount': 10000}, balance(20000, 70000, 4), covers=['AT14']),
    ok('ledger.credit.total', 'ledger.creditNote', {'ledger': ledger(80000, [50000]), 'amount': 80000}, balance(-50000, 0, 3), covers=['AT14']),
    ko('ledger.credit.plafonne', 'ledger.creditNote', {'ledger': ledger(80000, [50000], credits=[10000]), 'amount': 80000},
       V, 'amount', 'credit_note.exceeds_creditable', {'creditable': 70000}, covers=['AT14']),
    ok('ledger.refund.credit-exact', 'ledger.refund', {'ledger': ledger(80000, [50000], credits=[80000]), 'amount': 50000}, balance(0, 0, 4), covers=['AT14']),
    ko('ledger.refund.au-dela-du-credit', 'ledger.refund', {'ledger': ledger(80000, [50000], credits=[80000]), 'amount': 50001},
       V, 'amount', 'refund.exceeds_credit', {'refundable_credit': 50000}, covers=['AT14']),
    ko('ledger.refund.sans-credit', 'ledger.refund', {'ledger': ledger(80000, [30000]), 'amount': 1},
       V, 'amount', 'refund.exceeds_credit', {'refundable_credit': 0}),
    ok('ledger.reversal.totale', 'ledger.reversal', {'ledger': ledger(80000, [30000]), 'payment_amount': 30000, 'already_reversed': 0, 'amount': 30000},
       balance(80000, 80000, 3)),
    ko('ledger.reversal.deja-contre-passe', 'ledger.reversal', {'ledger': ledger(80000, [30000], reversals=[30000]), 'payment_amount': 30000, 'already_reversed': 30000, 'amount': 1},
       V, 'amount', 'reversal.exceeds_payment', {'reversible': 0}),
    ok('legacy.opening.reprise', 'legacy.openingBalance', {'original_total': 80000, 'already_paid': 30000}, 50000, covers=['AT26']),
    ko('legacy.opening.soldee', 'legacy.openingBalance', {'original_total': 80000, 'already_paid': 80000}, V, 'already_paid', 'legacy.nothing_due'),
    ko('legacy.opening.trop-paye', 'legacy.openingBalance', {'original_total': 80000, 'already_paid': 90000}, V, 'already_paid', 'legacy.paid_exceeds_total'),
    ko('legacy.opening.total-nul', 'legacy.openingBalance', {'original_total': 0, 'already_paid': 0}, V, 'original_total', 'money.positive_required'),
    ok('legacy.revenue.reprise', 'legacy.revenue', {'origin': 'LEGACY_BALANCE', 'total_gross': 50000}, 0, covers=['AT26'],
       description='Une créance reprise n’est pas une vente de la période : chiffre d’affaires initial 0.'),
    ok('legacy.revenue.native', 'legacy.revenue', {'origin': 'NATIVE', 'total_gross': 80000}, 80000),
    ko('legacy.revenue.origine-inconnue', 'legacy.revenue', {'origin': 'OTHER', 'total_gross': 80000}, V, 'origin', 'invoice.origin_unknown'),
]

# ---------------------------------------------------------------------------------------------------- stock
S = 'STOCK_UNAVAILABLE'
stock = [
    ok('stock.central.quota-local', 'stock.central', {'physical': '5.000', 'central_reserved': '0.000', 'offline_allocated': '3.000'}, '2.000', covers=['AT40', 'IT15']),
    ok('stock.central.apres-sync', 'stock.central', {'physical': '4.000', 'central_reserved': '0.000', 'offline_allocated': '2.000'}, '2.000', covers=['AT40', 'IT15']),
    ok('stock.central.decimal', 'stock.central', {'physical': '10.000', 'central_reserved': '2.500', 'offline_allocated': '3.000'}, '4.500'),
    ok('stock.central.incoherent-rendu-tel-quel', 'stock.central', {'physical': '2.000', 'central_reserved': '1.000', 'offline_allocated': '3.000'}, '-2.000',
       description='Valeur rendue telle quelle pour le rapprochement ; aucune réservation n’est alors accordée.'),
    ok('stock.local.quota-entier', 'stock.local', {'allocated': '3.000', 'consumed': '0.000', 'returned': '0.000', 'local_reserved': '0.000'}, '3.000', covers=['AT40']),
    ok('stock.local.apres-remise-locale', 'stock.local', {'allocated': '3.000', 'consumed': '1.000', 'returned': '0.000', 'local_reserved': '0.000'}, '2.000', covers=['AT40']),
    ok('stock.local.reservation-locale', 'stock.local', {'allocated': '3.000', 'consumed': '0.000', 'returned': '0.000', 'local_reserved': '1.000'}, '2.000', covers=['IT15']),
    ko('stock.local.quota-depasse', 'stock.local', {'allocated': '3.000', 'consumed': '2.000', 'returned': '2.000', 'local_reserved': '0.000'},
       V, 'allocation', 'stock.allocation_overdrawn'),
    ok('stock.take.exact', 'stock.take', {'requested': '2.000', 'available': '2.000'}, True),
    ko('stock.take.insuffisant', 'stock.take', {'requested': '2.500', 'available': '2.000'}, S, 'quantity', 'stock.unavailable', {'available': '2.000'}),
    ko('stock.take.disponible-negatif', 'stock.take', {'requested': '1.000', 'available': '-2.000'}, S, 'quantity', 'stock.unavailable', {'available': '0.000'}),
    ko('stock.take.quantite-nulle', 'stock.take', {'requested': '0.000', 'available': '5.000'}, V, 'quantity', 'quantity.positive_required'),
    ok('stock.sync.remise-sous-quota', 'stock.syncQuotaDelivery',
       {'physical': '5.000', 'central_reserved': '0.000', 'allocated': '3.000', 'consumed': '0.000', 'returned': '0.000', 'quantity': '1.000'},
       {'physical': '4.000', 'consumed': '1.000', 'remaining_quota': '2.000', 'central_available': '2.000'}, covers=['AT40', 'IT15'],
       description='Physique 5, quota A 3, A remet 1 hors-ligne : après sync physique 4, quota 2, disponible central 2.'),
    ko('stock.sync.quota-epuise', 'stock.syncQuotaDelivery',
       {'physical': '5.000', 'central_reserved': '0.000', 'allocated': '3.000', 'consumed': '3.000', 'returned': '0.000', 'quantity': '1.000'},
       S, 'quantity', 'stock.quota_exceeded', {'available': '0.000'}),
    ko('stock.sync.physique-negatif', 'stock.syncQuotaDelivery',
       {'physical': '0.000', 'central_reserved': '0.000', 'allocated': '3.000', 'consumed': '0.000', 'returned': '0.000', 'quantity': '1.000'},
       S, 'quantity', 'stock.physical_negative', {'available': '0.000'}),
    ok('lot.deliverable.expire-ce-jour', 'lot.deliverable', {'status': 'SELLABLE', 'expires_on': '2026-10-15', 'local_date': '2026-10-15'}, True),
    ko('lot.deliverable.expire-la-veille', 'lot.deliverable', {'status': 'SELLABLE', 'expires_on': '2026-10-14', 'local_date': '2026-10-15'},
       'LOT_EXPIRED', 'lot_id', 'lot.expired', {'expires_on': '2026-10-14'}),
    ko('lot.deliverable.quarantaine', 'lot.deliverable', {'status': 'QUARANTINE', 'expires_on': '2027-01-01', 'local_date': '2026-10-15'},
       S, 'lot_id', 'lot.quarantined'),
    ok('lot.deliverable.sans-date', 'lot.deliverable', {'status': 'SELLABLE', 'expires_on': None, 'local_date': '2026-10-15'}, True),
    ok('lot.fefo.ordre', 'lot.fefo',
       {'lots': [{'id': 'LOT-B', 'expires_on': '2027-03-01'}, {'id': 'LOT-A', 'expires_on': None},
                 {'id': 'LOT-D', 'expires_on': '2026-12-31'}, {'id': 'LOT-C', 'expires_on': '2026-12-31'}]},
       ['LOT-C', 'LOT-D', 'LOT-B', 'LOT-A'], description='Expiration la plus proche d’abord, lots sans date ensuite, identifiant en dernier ressort.'),
]

# ---------------------------------------------------------------------------------------------------- contrôles
follow = [
    ok('followup.recommended.trois-mois-fin-de-mois', 'followUp.recommended', {'anchor': '2026-01-31', 'value': 3, 'unit': 'MONTH'}, '2026-04-30', covers=['AT22']),
    ok('followup.recommended.un-mois-fin-octobre', 'followUp.recommended', {'anchor': '2026-10-31', 'value': 1, 'unit': 'MONTH'}, '2026-11-30', covers=['AT22']),
    ok('followup.recommended.collyre-dix-jours', 'followUp.recommended', {'anchor': '2026-10-15', 'value': 10, 'unit': 'DAY'}, '2026-10-25', covers=['AT22'],
       description='Départ choisi par le professionnel (début ou fin de traitement) : aucune durée supposée.'),
    ok('followup.recommended.collyre-quatorze-jours', 'followUp.recommended', {'anchor': '2026-10-20', 'value': 14, 'unit': 'DAY'}, '2026-11-03', covers=['AT22']),
    ok('followup.recommended.un-an', 'followUp.recommended', {'anchor': '2026-10-15', 'value': 1, 'unit': 'YEAR'}, '2027-10-15'),
    ok('followup.recommended.deux-ans-29-fevrier', 'followUp.recommended', {'anchor': '2024-02-29', 'value': 2, 'unit': 'YEAR'}, '2026-02-28'),
    ko('followup.recommended.delai-nul', 'followUp.recommended', {'anchor': '2026-10-15', 'value': 0, 'unit': 'DAY'}, V, 'interval_value', 'follow_up.interval_positive'),
    ko('followup.recommended.unite-inconnue', 'followUp.recommended', {'anchor': '2026-10-15', 'value': 1, 'unit': 'WEEK'}, V, 'interval_unit', 'follow_up.unit_unknown'),
    ok('followup.reminder.sept-jours', 'followUp.reminder', {'recommended': '2026-04-30', 'advance': 7, 'unit': 'DAY'}, '2026-04-23'),
    ok('followup.reminder.un-mois-fin-de-mois', 'followUp.reminder', {'recommended': '2026-03-31', 'advance': 1, 'unit': 'MONTH'}, '2026-02-28'),
    ok('followup.reminder.le-jour-meme', 'followUp.reminder', {'recommended': '2026-10-15', 'advance': 0, 'unit': 'DAY'}, '2026-10-15'),
    ko('followup.reminder.unite-annee', 'followUp.reminder', {'recommended': '2026-10-15', 'advance': 1, 'unit': 'YEAR'}, V, 'advance_unit', 'follow_up.unit_unknown'),
    ko('followup.reminder.avance-negative', 'followUp.reminder', {'recommended': '2026-10-15', 'advance': -1, 'unit': 'DAY'}, V, 'advance_value', 'follow_up.advance_negative'),
]

# ---------------------------------------------------------------------------------------------------- recoupements indépendants
def check(condition, message):
    if not condition:
        raise SystemExit('RECOUPEMENT ÉCHOUÉ : ' + message)


def round_half_up(value):
    return int(value.quantize(Decimal('1'), rounding=ROUND_HALF_UP))


for case in invoices:
    if case['operation'] == 'invoice.line' and 'expected' in case:
        i = case['input']
        gross = round_half_up(Decimal(i['quantity']) * i['unit_price_gross'])
        net = gross - i['discount_gross']
        tax = None if i['tax_rate_percent'] is None else round_half_up(Decimal(net) * Decimal(i['tax_rate_percent']) / (100 + Decimal(i['tax_rate_percent'])))
        check(case['expected'] == amounts(gross, i['discount_gross'], net, tax), case['id'])


def add_months(date_, months):
    index = date_.year * 12 + date_.month - 1 + months
    year, month = divmod(index, 12)
    month += 1
    return dt.date(year, month, min(date_.day, calendar.monthrange(year, month)[1]))


for case in dates + follow:
    i, e = case['input'], case.get('expected')
    if case['operation'] == 'date.add' and e is not None:
        start = dt.date.fromisoformat(i['date'])
        got = start + dt.timedelta(days=i['amount']) if i['unit'] == 'DAY' else add_months(start, i['amount'] * (12 if i['unit'] == 'YEAR' else 1))
        check(got.isoformat() == e, case['id'])
    if case['operation'] == 'date.fromInstant':
        offset = dt.timedelta(hours=1 if i['timezone'] == DOUALA else 0)  # Africa/Douala : UTC+1 sans heure d'été
        utc = dt.datetime.strptime(i['instant'], '%Y-%m-%dT%H:%M:%S.%fZ')
        check((utc + offset).date().isoformat() == e, case['id'])
    if case['operation'] == 'date.age' and e is not None:
        b, r = dt.date.fromisoformat(i['birth_date']), dt.date.fromisoformat(i['reference_date'])
        check(r.year - b.year - ((r.month, r.day) < (b.month, b.day)) == e, case['id'])
    if case['operation'] == 'followUp.recommended' and e is not None:
        a = dt.date.fromisoformat(i['anchor'])
        got = a + dt.timedelta(days=i['value']) if i['unit'] == 'DAY' else add_months(a, i['value'] * (12 if i['unit'] == 'YEAR' else 1))
        check(got.isoformat() == e, case['id'])
    if case['operation'] == 'followUp.reminder' and e is not None:
        r = dt.date.fromisoformat(i['recommended'])
        got = r - dt.timedelta(days=i['advance']) if i['unit'] == 'DAY' else add_months(r, -i['advance'])
        check(got.isoformat() == e, case['id'])

for case in ledgers:
    if case['operation'].startswith('ledger.') and 'expected' in case:
        i = case['input']
        led = dict(i['ledger']) if 'ledger' in i else dict(i)
        led = {k: list(v) if isinstance(v, list) else v for k, v in led.items()}
        key = {'ledger.payment': 'payments', 'ledger.creditNote': 'credits', 'ledger.refund': 'refunds', 'ledger.reversal': 'reversals'}.get(case['operation'])
        if key:
            led[key].append(i['amount'])
        signed = led['total_gross'] - sum(led['credits']) - sum(led['payments']) + sum(led['reversals']) + sum(led['refunds'])
        version = 1 + sum(len(led[k]) for k in ('payments', 'reversals', 'credits', 'refunds'))
        check(case['expected'] == balance(signed, max(0, led['total_gross'] - sum(led['credits'])), version), case['id'])

for case in stock:
    i, e = case['input'], case.get('expected')
    if case['operation'] == 'stock.central':
        check(Decimal(i['physical']) - Decimal(i['central_reserved']) - Decimal(i['offline_allocated']) == Decimal(e), case['id'])
    if case['operation'] == 'stock.local' and e is not None:
        check(Decimal(i['allocated']) - Decimal(i['consumed']) - Decimal(i['returned']) - Decimal(i['local_reserved']) == Decimal(e), case['id'])

# ---------------------------------------------------------------------------------------------------- écriture
FILES = {
    'text.json': ('Texte : blancs de bord, vide → null, Unicode conservé, clé de recherche et téléphone normalisés.', text),
    'money.json': ('Montants XAF entiers : saisie française, bornes SQL 0..10^12, affichage.', money),
    'decimal.json': ('Décimaux exacts DECIMAL(12,3), (7,4) et (6,2) : virgule, signe, vide ≠ zéro, jamais de troncature.', decimals),
    'dates.json': ('Dates civiles et instants : fin de mois, année, fuseau Africa/Douala, âge, forme UTC à la milliseconde.', dates),
    'counter.json': ('Compteurs BIGINT UNSIGNED en chaîne, au-delà des entiers sûrs de JavaScript.', counters),
    'invoice.json': ('Lignes et total de facture : arrondi au FCFA demi supérieur, remise, taxe comprise (taux fictifs).', invoices),
    'ledger.json': ('Solde d’une facture émise, encaissements, avoirs, remboursements, contre-écritures et reprise papier.', ledgers),
    'stock.json': ('Disponible central et local, quotas de poste, remise synchronisée, lots expirés et FEFO.', stock),
    'follow-up.json': ('Dates de contrôle recommandé et de rappel interne au calendrier civil.', follow),
}

INVISIBLE = [0xA0, 0x202F, 0x2009, 0x301, 0xAD, 0x200B, 0xFEFF]
OUT.mkdir(parents=True, exist_ok=True)
ids = set()
stale = []
for name, (description, cases) in FILES.items():
    for case in cases:
        check(case['id'] not in ids, 'identifiant en double ' + case['id'])
        ids.add(case['id'])
    document = {'rules_version': RULES_VERSION, 'description': description, 'cases': cases}
    text_ = json.dumps(document, ensure_ascii=False, indent=2) + chr(10)
    for code in INVISIBLE:
        text_ = text_.replace(chr(code), chr(92) + 'u%04x' % code)
    target = OUT / name
    if CHECK:
        if not target.exists() or target.read_bytes() != text_.encode('utf-8'):
            stale.append(name)
    else:
        target.write_text(text_, encoding='utf-8', newline=chr(10))
if CHECK:
    if stale:
        print('ECHEC : vecteurs absents ou périmés : ' + ', '.join(stale) + ' (relancer contracts/tools/build_vectors.py)')
        sys.exit(1)
    print(f'Vecteurs à jour ({len(ids)} cas, {len(FILES)} fichiers).')
else:
    print(f'{len(ids)} vecteurs recoupés et écrits dans {len(FILES)} fichiers.')
