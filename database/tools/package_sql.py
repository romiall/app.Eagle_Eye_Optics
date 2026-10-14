from pathlib import Path
ROOT=Path(__file__).resolve().parents[2]
DB=ROOT/'database'
parts=["-- Complete Eagle's Eye Optics Medical database, version 2.0, 2026-10-14.\n-- Select a NEW EMPTY MySQL 8.4 database in your client before import.\n-- Alternative to importing 01..04 separately; do not do both.\n-- No application data, credentials or database-wide configuration is created.\n"]
for name in ['01_schema.sql','02_reference_data.sql','03_reporting_views.sql','04_history_guards.sql']:
    parts.extend(['\n-- BEGIN '+name+'\n',(DB/name).read_text(encoding='utf-8'),'\n-- END '+name+'\n'])
target=DB/'eagle_eye_optics_complet.sql'
target.write_text(''.join(parts),encoding='utf-8')
print(f'{target.name}: {target.stat().st_size} bytes')
