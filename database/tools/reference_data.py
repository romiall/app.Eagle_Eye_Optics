from pathlib import Path

ROOT=Path(__file__).resolve().parents[2]
OUT=ROOT/'database'
def lit(s):return "'"+s.replace("'","''")+"'"
def insert(table,columns,rows):
    return 'INSERT INTO `'+table+'` ('+', '.join('`'+x+'`' for x in columns)+') VALUES\n'+',\n'.join('  ('+', '.join(str(v) if isinstance(v,int) else lit(v) for v in row)+')' for row in rows)+';\n'

ROLES=[('MANAGER','Gérante / promotrice','Accès métier total et autorisations'),('RECEPTION','Accueil / réception','Identité et agenda ; frais examen si permission accordée'),('CLINICIAN','Professionnel / opticien','Dossier médical et prescriptions'),('CASHIER','Caissière / vendeuse','Ventes, paiements et remises contrôlées')]
PERMS={
 'patient.read':'Lire identité administrative','patient.create':'Créer patient','patient.update':'Modifier identité','patient.merge':'Fusionner dossiers après revue',
 'visit.register':'Enregistrer arrivée/visite','exam_fee.collect':'Encaisser seulement frais examen','clinical.read':'Lire dossier médical','clinical.write':'Saisir consultation','clinical.complete':'Finaliser consultation','clinical.amend':'Corriger avec révision','clinical.export':'Exporter dossier/prescription',
 'agenda.read':'Voir agenda','agenda.write':'Programmer rendez-vous','follow_up.write':'Programmer contrôle recommandé',
 'catalog.read':'Lire catalogue et prix vente','catalog.manage':'Gérer catalogue/prix','catalog.purchase_cost.read':'Lire coûts achat',
 'invoice.read':'Lire facture autorisée','invoice.create':'Créer brouillon','invoice.issue':'Émettre facture','payment.collect':'Encaisser paiement','payment.reverse':'Exécuter contre-écriture autorisée','credit_note.issue':'Émettre avoir autorisé','refund.execute':'Exécuter remboursement autorisé','delivery.confirm':'Confirmer remise liée à vente',
 'approval.request':'Demander autorisation','approval.decide':'Autoriser/refuser opération sensible',
 'stock.read':'Voir stock disponible','stock.receive':'Recevoir stock fournisseur','stock.adjust':'Exécuter ajustement validé','stock.inventory':'Gérer inventaire','stock.valuation.read':'Lire valorisation globale',
 'cash.own_session':'Voir sa session de caisse','cash.manage':'Gérer clôture et sorties autorisées','finance.global.read':'Lire CA/encaissements/impayés globaux','finance.reconcile':'Rapprocher modes règlement',
 'statistics.activity':'Voir statistiques administratives','statistics.clinical':'Voir statistiques cliniques','workshop.manage':'Gérer commandes atelier',
 'communication.manage':'Gérer relances/campagnes','conversation.takeover':'Reprendre conversation','knowledge.manage':'Éditer/valider FAQ','site.publish':'Publier contenus et produits validés',
 'integration.manage':'Configurer intégrations','users.manage':'Gérer utilisateurs et droits','audit.read':'Consulter audit','export.global':'Exporter données complètes','backup.manage':'Contrôler sauvegarde/restauration','offline.enroll':'Enrôler poste et allouer droits',
}
ROLE_PERMS={
 'MANAGER':list(PERMS),
 'RECEPTION':['patient.read','patient.create','patient.update','visit.register','agenda.read','agenda.write','statistics.activity','approval.request'],
 'CLINICIAN':['patient.read','visit.register','clinical.read','clinical.write','clinical.complete','clinical.amend','clinical.export','agenda.read','agenda.write','follow_up.write','catalog.read','stock.read','statistics.clinical','approval.request'],
 'CASHIER':['patient.read','agenda.read','catalog.read','invoice.read','invoice.create','invoice.issue','payment.collect','delivery.confirm','stock.read','cash.own_session','approval.request'],
}
GROUPS={
 'COMPLAINT':[('VISION_DECLINE','Baisse de vision'),('BLURRED_VISION','Vision floue'),('HEADACHE','Céphalées'),('VISUAL_FATIGUE','Fatigue visuelle'),('DISTANCE_DIFFICULTY','Difficulté de loin'),('NEAR_DIFFICULTY','Difficulté de près'),('GLASSES_RENEWAL','Renouvellement de lunettes'),('ROUTINE_CHECK','Contrôle de routine'),('REDNESS','Rougeur'),('TEARING','Larmoiement'),('ITCHING','Démangeaisons'),('PAIN','Douleur'),('PHOTOPHOBIA','Photophobie'),('OTHER','Autre')],
 'OCULAR_HISTORY':[('NONE','Aucun connu'),('TRAUMA','Traumatisme'),('SURGERY','Chirurgie'),('GLAUCOMA','Glaucome'),('CATARACT','Cataracte'),('INFECTION','Infection'),('CONTACT_LENSES','Lentilles'),('OTHER','Autre')],
 'MEDICAL_HISTORY':[('NONE','Aucun connu'),('DIABETES','Diabète'),('HYPERTENSION','Hypertension'),('ALLERGIES','Allergies'),('CURRENT_TREATMENT','Traitement en cours'),('OTHER','Autre')],
 'FAMILY_HISTORY':[('NONE','Aucun connu'),('GLAUCOMA','Glaucome'),('CATARACT','Cataracte'),('HIGH_MYOPIA','Forte myopie'),('OTHER','Autre')],
 'AMETROPIA':[('EMMETROPIA','Emmétropie'),('MYOPIA','Myopie'),('HYPERMETROPIA','Hypermétropie'),('ASTIGMATISM','Astigmatisme'),('PRESBYOPIA','Presbytie'),('ANISOMETROPIA','Anisométropie'),('OTHER','Autre')],
 'REFERRAL_REASON':[('SUSPECTED_GLAUCOMA','Suspicion de glaucome'),('CATARACT','Cataracte'),('UNEXPLAINED_ACUITY_LOSS','Baisse d’acuité inexpliquée'),('CORNEAL_DISEASE','Pathologie cornéenne'),('RED_EYE_INFLAMMATION','Œil rouge / inflammation'),('TRAUMA','Traumatisme'),('SUSPECTED_RETINAL_DISEASE','Pathologie rétinienne suspectée'),('OTHER','Autre')],
 'OPTICAL_USAGE':[('DISTANCE','Vision de loin'),('NEAR','Vision de près'),('PERMANENT','Lunettes permanentes'),('PROGRESSIVE','Progressifs'),('BIFOCAL','Bifocaux'),('COMPUTER_WORK','Travail / ordinateur'),('OTHER','Autre')],
 'LENS_TYPE':[('SINGLE_VISION','Unifocal'),('BIFOCAL','Bifocal'),('PROGRESSIVE','Progressif'),('ANTI_FATIGUE','Anti-fatigue'),('OTHER','Autre')],
 'LENS_TREATMENT':[('ANTI_REFLECTION','Anti-reflet'),('PHOTOCHROMIC','Photochromique'),('UV','UV'),('BLUE_LIGHT_FILTER','Filtre lumière bleue / Blue-Cut'),('HARD_ANTI_SCRATCH','Durci / anti-rayures'),('TINTED','Teinté'),('HMC','HMC'),('OTHER','Autre')],
 'LENS_INDEX':[(x,x) for x in ['1.50','1.56','1.59','1.60','1.61','1.67','1.74']]+[('OTHER','Autre')],
}
rows=[]
for group,terms in GROUPS.items():
    for index,(code,label) in enumerate(terms,1):rows.append((group,code,label,index,1))
parts=["-- Complete reference data from PC/CDC/DP only. No patients, users, tax rates, prices or credentials.\n-- Import after 01_schema.sql, once, on a fresh database. Do not use INSERT IGNORE.\nSET NAMES utf8mb4 COLLATE utf8mb4_0900_ai_ci;\nSTART TRANSACTION;\n"]
parts.append(insert('roles',['code','label','description'],ROLES))
parts.append(insert('permissions',['code','label'],list(PERMS.items())))
parts.append(insert('role_permissions',['role_code','permission_code'],[(role,p) for role,perms in ROLE_PERMS.items() for p in perms]))
parts.append(insert('clinical_terms',['group_code','code','label','sort_order','active'],rows))
parts.append(insert('catalog_categories',['code','label'],[('SERVICE','Prestation / examen'),('FRAME','Monture'),('LENS','Verre'),('CONTACT_LENS','Lentille'),('COLLYRE','Collyre'),('DERMOCOSMETIC','Dermocosmétique'),('OTHER','Autre')]))
parts.append(insert('units',['code','label','integer_only'],[('UNIT','Unité',1),('PAIR','Paire',1),('SERVICE','Prestation',0),('OTHER','Autre unité configurée',0)]))
parts.append('COMMIT;\n')
(OUT/'02_reference_data.sql').write_text('\n'.join(parts),encoding='utf-8')
print('Reference data: '+str(len(rows))+' clinical terms, '+str(len(PERMS))+' permissions, 4 roles; no invented business values.')
