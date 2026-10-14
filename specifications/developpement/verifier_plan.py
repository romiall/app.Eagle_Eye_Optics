"""Independently check planning coverage/order and unchanged validated SQL."""
from pathlib import Path, PurePosixPath
import json, re, hashlib

ROOT=Path(__file__).resolve().parents[2]
SPEC=ROOT/'specifications'
OUT=SPEC/'developpement'
plan=json.loads((OUT/'backlog.json').read_text(encoding='utf-8'))
tasks=plan['tasks']; by_id={t['id']:t for t in tasks}
assert len(tasks)==len(by_id)==plan['task_count']
order=plan['execution_order']
assert len(order)==len(set(order))==len(tasks) and set(order)==set(by_id)
positions={tid:i for i,tid in enumerate(order)}
for t in tasks:
    assert t['execution_order']==positions[t['id']]+1
    assert t['status']=='NOT_STARTED' and not t['implemented'] and not t['evidence']
    for dep in t['depends_on']:
        assert dep in by_id and positions[dep]<positions[t['id']], (t['id'],dep)
        assert by_id[dep]['lot']<=t['lot']
    assert len(t['steps'])>=2 and len(t['exit_criteria'])>=2
    assert t['acceptance_scenarios'] or t['engineering_checks']
    for f in t['expected_files']:
        p=PurePosixPath(f)
        assert not p.is_absolute() and '..' not in p.parts and ':' not in f

def ancestors(tid):
    result=set(); todo=list(by_id[tid]['depends_on'])
    while todo:
        dep=todo.pop()
        if dep not in result:
            result.add(dep); todo.extend(by_id[dep]['depends_on'])
    return result

assert 'I00' in ancestors('B03'), 'Reservation service must precede issuance'
assert {'O01','O02','O03','O04','O05','D01','P01','B03','B04','B05'}<=ancestors('G1')
assert {t['id'] for t in tasks if t['lot']=='L0'}-{'G4'} <= ancestors('G4')
assert set(by_id)-{'G7'} == ancestors('G7'), 'Final gate must cover all tasks'
assert {t['id'] for t in tasks if t['lot']=='L1'}-{'G5'} <= ancestors('G5')
assert {t['id'] for t in tasks if t['lot']=='L2'}-{'G6'} <= ancestors('G6')

catalog_path=ROOT/'database/schema_catalog.json'
catalog=json.loads(catalog_path.read_text(encoding='utf-8'))
table_names={t['name'] for t in catalog['tables']}
assert plan['baseline_catalog_sha256']==hashlib.sha256(catalog_path.read_bytes()).hexdigest()
assert set(plan['table_ownership'])==table_names
assert len(table_names)==plan['baseline_table_count']==117
listed=[n for t in tasks for n in t['primary_tables']]
assert len(listed)==len(set(listed))==117 and set(listed)==table_names
for name,owner in plan['table_ownership'].items(): assert name in by_id[owner]['primary_tables']
for t in tasks:
    assert all(plan['table_ownership'][n]==t['id'] for n in t['primary_tables'])

trace=(SPEC/'17-matrice-tracabilite.md').read_text(encoding='utf-8')
reqs=set(re.findall(r'^\| (EE\d{3}) \|',trace,re.M))
recipe=(SPEC/'14-recette-et-scenarios.md').read_text(encoding='utf-8')
ats=set(re.findall(r'^\| (AT\d{2}) \|',recipe,re.M))
assert reqs=={r for t in tasks for r in t['requirements']}==set(plan['coverage']['requirements'])
assert ats=={a for t in tasks for a in t['acceptance_scenarios']}==set(plan['coverage']['acceptance_scenarios'])
assert set(by_id['G3']['acceptance_scenarios'])=={f'AT{i:02d}' for i in list(range(1,30))+list(range(36,46))}
legacy_text=(SPEC/'13-plan-livraison-et-backlog.md').read_text(encoding='utf-8')
legacy=set(re.findall(r'\bL[0123]-\d{2}\b',legacy_text))
assert legacy<={r for t in tasks for r in t['legacy_tickets']}
assert not (legacy & set(by_id)), 'Detailed task IDs collide with delivery IDs'

cards=(SPEC/'24-taches-developpement.md').read_text(encoding='utf-8')
anchors=[tid.lower() for tid in re.findall(r'^### ([A-Z0-9]+)$',cards,re.M)]
assert len(anchors)==len(set(anchors))==len(tasks)
assert set(anchors)=={tid.lower() for tid in by_id}
for t in tasks:
    assert f'### {t["id"]}\n\n**{t["title"]}**' in cards
    assert all(f'`{name}`' in cards for name in t['primary_tables'])

validation=json.loads((ROOT/'database/validation_report.json').read_text(encoding='utf-8'))
for relative,digest in validation['file_sha256'].items():
    assert hashlib.sha256((ROOT/'database'/relative).read_bytes()).hexdigest()==digest,relative
for required in ['22-plan-developpement-detaille.md','23-contrats-ingenierie.md','24-taches-developpement.md','25-validation-et-bascule.md']:
    assert required in (SPEC/'README.md').read_text(encoding='utf-8')
    assert required in (SPEC/'16-demarrer-dans-Claude.md').read_text(encoding='utf-8')

report=dict(date='2026-10-14',scope='PLANNING_COHERENCE_ONLY',result='PASS',
    application_tests_executed=False,tasks=len(tasks),L0_tasks=sum(t['lot']=='L0' for t in tasks),
    dependency_graph='ACYCLIC',execution_order='ALL_DEPENDENCIES_BEFORE_CONSUMERS',
    tables_with_functional_owner=len(table_names),domains=len({t['domain'] for t in catalog['tables']}),
    requirements=len(reqs),acceptance_scenarios=len(ats),L0_acceptance_scenarios=len(by_id['G3']['acceptance_scenarios']),
    legacy_delivery_tickets_mapped=len(legacy),task_id_collisions=0,
    initial_statuses='ALL_NOT_STARTED',gate_G4_covers_entire_L0=True,gate_G7_covers_all_tasks=True,
    stock_reservation_precedes_invoice=True,validated_sql_hashes_unchanged=True,
    backlog_sha256=hashlib.sha256((OUT/'backlog.json').read_bytes()).hexdigest())
(OUT/'validation-plan.json').write_text(json.dumps(report,ensure_ascii=False,indent=2)+'\n',encoding='utf-8')
print(json.dumps(report,ensure_ascii=True))
