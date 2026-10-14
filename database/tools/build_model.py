"""Generate DDL, relational model and dictionary from one complete definition."""
from pathlib import Path
from collections import defaultdict
import json,re,hashlib
from model_definition import TABLES

ROOT=Path(__file__).resolve().parents[2]
OUT=ROOT/'database'
SPEC=ROOT/'specifications'
OUT.mkdir(exist_ok=True)
by_name={t['name']:t for t in TABLES}

def q(x): return '`'+x+'`'
def qs(xs): return ', '.join(map(q,xs))
def esc(s): return str(s).replace("'","''")
def cname(prefix,table,index): return f'{prefix}_{table}_{index:02d}'
def column_sql(col):
    out=q(col['name'])+' '+col['type']
    if col['generated']: out+=' GENERATED ALWAYS AS ('+col['generated']+') STORED'
    else:
        out+=' NULL' if col['nullable'] else ' NOT NULL'
        if col['default'] is not None: out+=' DEFAULT '+col['default']
    if col['description']: out+=" COMMENT '"+esc(col['description'])+"'"
    return out

# Validate reference types and unique target keys before writing any DDL.
for t in TABLES:
    cols={c['name']:c for c in t['columns']}
    t['checks']=list(dict.fromkeys(t['checks']))
    for co in t['columns']:
        if co['type']=='JSON':
            typ='ARRAY' if co['name'] in ['permissions_snapshot','allowed_variables','treatments_snapshot','knowledge_version_ids','sanitized_tool_results'] else 'OBJECT'
            t['checks'].append(f"{q(co['name'])} IS NULL OR JSON_TYPE({q(co['name'])}) = '{typ}'")
    for fk in t['foreign']:
        p=by_name[fk['target']]; pc={c['name']:c for c in p['columns']}
        assert fk['target_columns'] in [p['primary']]+p['unique'],(t['name'],fk,'nonunique target')
        assert len(fk['columns'])==len(fk['target_columns'])
        for child,parent in zip(fk['columns'],fk['target_columns']):
            assert child in cols and parent in pc,(t['name'],fk)
            assert cols[child]['type']==pc[parent]['type'],(t['name'],child,cols[child]['type'],pc[parent]['type'])
        if 'clinic_id' in pc and 'clinic_id' in cols: assert 'clinic_id' in fk['columns']
    keys=[t['primary']]+t['unique']+t['indexes']
    for fk in t['foreign']:
        key=fk['columns']
        if not any(k[:len(key)]==key for k in keys):
            t['indexes'].append(key);keys.append(key)
    assert all(len(cname('fk',t['name'],i))<=64 for i in range(len(t['foreign'])))

header="""-- Eagle's Eye Optics Medical | Complete application schema | 2026-10-14
-- Target: MySQL 8.4 / InnoDB / utf8mb4. All domains are defined NOW.
-- Run on an EMPTY database selected by your client. No DROP, TRUNCATE or credentials.
-- No USE here: shared hosting database name is provided by its owner.
-- Installation order: 01_schema, 02_reference_data, 03_reporting_views, 04_history_guards.
-- Foreign keys are added after tables to handle the documented cyclic links.
-- Cross-row totals, permissions, cryptography and physical events still require app transactions.
SET NAMES utf8mb4 COLLATE utf8mb4_0900_ai_ci;
SET SESSION time_zone = '+00:00';
SET SESSION sql_mode = 'STRICT_TRANS_TABLES,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION,ONLY_FULL_GROUP_BY';

"""
ddl=[header]
for t in TABLES:
    ddl.append('-- '+t['domain']+' : '+t['description'])
    elements=[column_sql(co) for co in t['columns']]
    elements.append('PRIMARY KEY ('+qs(t['primary'])+')')
    for i,key in enumerate(t['unique'],1): elements.append('UNIQUE KEY '+q(cname('uq',t['name'],i))+' ('+qs(key)+')')
    for i,key in enumerate(t['indexes'],1): elements.append('KEY '+q(cname('ix',t['name'],i))+' ('+qs(key)+')')
    for i,expr in enumerate(t['checks'],1): elements.append('CONSTRAINT '+q(cname('ck',t['name'],i))+' CHECK ('+expr+')')
    ddl.append('CREATE TABLE '+q(t['name'])+' (\n  '+',\n  '.join(elements)+"\n) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci COMMENT='"+esc(t['description'])+"';\n")
ddl.append('-- Foreign keys: default NO ACTION/RESTRICT, never cascade business history.')
for t in TABLES:
    if not t['foreign']:continue
    clauses=[]
    for i,fk in enumerate(t['foreign'],1):
        clauses.append('ADD CONSTRAINT '+q(cname('fk',t['name'],i))+' FOREIGN KEY ('+qs(fk['columns'])+') REFERENCES '+q(fk['target'])+' ('+qs(fk['target_columns'])+')')
    ddl.append('ALTER TABLE '+q(t['name'])+'\n  '+',\n  '.join(clauses)+';\n')
(OUT/'01_schema.sql').write_text('\n'.join(ddl),encoding='utf-8')
(OUT/'00_create_database_optional.sql').write_text("-- OPTIONAL: only when you have CREATE DATABASE permission.\n-- Shared hosting normally supplies a pre-created empty database.\nCREATE DATABASE eagle_eye_optics CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci;\n-- Select this database in your client before importing 01_schema.sql.\n",encoding='utf-8')
catalog=dict(version='2.0',date='2026-10-14',scope='ENTIRE_APPLICATION',database_target='MySQL 8.4',priority_location='specifications/13-plan-livraison-et-backlog.md',tables=TABLES)
(OUT/'schema_catalog.json').write_text(json.dumps(catalog,ensure_ascii=False,indent=2)+'\n',encoding='utf-8')

domains=defaultdict(list)
for t in TABLES: domains[t['domain']].append(t)
mld=['# Modèle relationnel complet de l’application','',
 'Version 2.0 du 14 octobre 2026. Toutes les relations de l’application sont définies maintenant. Les lots du planning déterminent l’ordre de développement et d’activation, sans retirer de tables du socle.',
 '',f'Le modèle contient {len(TABLES)} tables. Il est généré à partir du même catalogue que [le SQL](../database/01_schema.sql) et [le dictionnaire](07-dictionnaire-donnees.md). [Le MCD](06-modele-conceptuel.md) explique leurs cardinalités métier. Aucun patient, tarif ou taux fiscal réel n’est inventé.',
 '', '## Conventions','',
 'PK = clé primaire ; FK = clé étrangère ; UQ = clé candidate unique. Les identifiants ULID sont conservés hors-ligne/serveur. Les tables métier incluent `clinic_id` et une clé unique `(clinic_id,id)` ; leurs FK composées interdisent les liens entre cabinets. Suppressions et changements d’identifiants sont restreints, sans cascade.',
 '', 'Les colonnes générées fournissent les unicités conditionnelles MySQL (session ouverte, propriétaire financier actif) et les clés de lot/consentement même en présence de NULL. Les sommes, chevauchements et signatures nécessitent les actions transactionnelles décrites dans [les invariants](21-invariants-base-donnees.md).',
 '', '## Domaines','', '| Domaine | Tables |', '| --- | --- |']
for domain,tables in domains.items(): mld.append('| '+domain+' | '+', '.join('`'+t['name']+'`' for t in tables)+' |')
dictionary=['# Dictionnaire complet des données','',
 'Version 2.0. Ce dictionnaire remplace les silhouettes de tables L1–L3 du premier socle : toutes les tables, colonnes et relations sont définies dès maintenant. Le planning seul donne la priorité au chantier urgent.',
 '', 'Le [catalogue JSON](../database/schema_catalog.json), [SQL MySQL](../database/01_schema.sql) et [modèle relationnel](20-modele-relationnel.md) sont générés ensemble par `database/tools/build_model.py`. Modifier la définition canonique puis régénérer, sans maintenir des noms divergents.',
 '', '## Conventions de données','',
 'ULID ASCII sensible à la casse ; monnaie XAF en entiers FCFA BIGINT, plafonds techniques explicités par CHECK ; quantités DECIMAL(12,3) ; dioptries/centrage DECIMAL(6,2), jamais FLOAT. Instants UTC en DATETIME(6), dates civiles DATE. Age calculé et non stocké. NULL distingue non mesuré de zéro et inconnu de non.',
 '', 'Sélections cliniques multiples normalisées : les anciennes listes DTO `complaint_codes`, `ametropia_codes`, `referral_codes`, `treatment_codes` se convertissent vers leurs tables de liaison. Les snapshots de révision restent JSON immuables. `known_none` représente Aucun connu ; absence de termes avec faux signifie non renseigné. Les antécédents sont liés à leur consultation.',
 '', 'Prescription interne ou transcription d’une ordonnance externe identifiée pour l’achat direct ; jamais créer un examen fictif. La transcription externe est une précision de conception à confirmer avec le cabinet, rattachée au patient et au professionnel qui la valide. Les codes médicaux viennent de DP/PC ; aucune règle ne produit une ordonnance à partir de symptômes.',
 '', 'Les fichiers et clés privées ne sont pas publics. Les champs `*_ciphertext` contiennent une enveloppe chiffrée par le service, pas une fonction de chiffrement MySQL ni une clé stockée dans le même champ. Les contraintes de confidentialité et de flux restent celles du fichier 09.',
 '', '## Référentiels et absence de données inventées','',
 'Le script 02 ne contient que rôles, permissions, catégories/unités et codes cliniques des sources. Aucun cabinet réel, utilisateur avec mot de passe, patient, prix, taux fiscal, fournisseur, template approuvé ou credential n’est créé. Les jours ouvrés et échelles cliniques sont configurés avant usage ; les sources imposent les champs, pas une échelle universelle.',
 '', '## Données par table','']
for domain,tables in domains.items():
    mld += ['','## '+domain,'']
    dictionary += ['','## '+domain,'']
    for t in tables:
        mld += ['### '+t['name'],'',t['description']+'.','', '**Attributs** : '+', '.join('`'+co['name']+'`'+(' ?' if co['nullable'] else '') for co in t['columns'])+'.', '', '**PK** : `'+','.join(t['primary'])+'`.']
        if t['unique']: mld += ['','**UQ** : '+ '; '.join('`('+','.join(key)+')`' for key in t['unique'])+'.']
        if t['foreign']:
            mld += ['','| FK locale | Relation cible |','| --- | --- |']
            for fk in t['foreign']:mld.append('| `('+','.join(fk['columns'])+')` | `'+fk['target']+'('+','.join(fk['target_columns'])+')` |')
        if t['checks']:mld += ['','**Contrôles CHECK** : '+str(len(t['checks']))+' ; expressions exactes dans le SQL et le catalogue.']
        if t['immutable'] or t['guard']:mld+=['','**Historique** : '+('append-only, UPDATE/DELETE refusés par le script 04.' if t['immutable'] else 'garde de cycle de vie `'+t['guard']+'` dans le script 04.')]
        dictionary += ['### '+t['name'],'',t['description']+'.','','| Colonne | Type SQL | NULL | Défaut ou calcul | Sens ou relation |','| --- | --- | --- | --- | --- |']
        for co in t['columns']:
            val=co['generated'] or co['default'] or '—'
            meaning=co['description'] or ('FK '+co['ref'] if co['ref'] else '')
            if co.get('allowed_values'):meaning+=' Valeurs : '+', '.join(co['allowed_values'])
            dictionary.append('| `'+co['name']+'` | `'+co['type']+'` | '+('Oui' if co['nullable'] else 'Non')+' | '+('`'+val+'`' if val!='—' else val)+' | '+meaning.replace('|','/')+' |')
(SPEC/'20-modele-relationnel.md').write_text('\n'.join(mld)+'\n',encoding='utf-8')
(SPEC/'07-dictionnaire-donnees.md').write_text('\n'.join(dictionary)+'\n',encoding='utf-8')
summary=dict(tables=len(TABLES),foreign_keys=sum(len(t['foreign']) for t in TABLES),checks=sum(len(t['checks']) for t in TABLES),domains=len(domains))
print(json.dumps(summary,ensure_ascii=False,indent=2))
