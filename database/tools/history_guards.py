from pathlib import Path
import json
ROOT=Path(__file__).resolve().parents[2]
tables=json.loads((ROOT/'database/schema_catalog.json').read_text(encoding='utf-8'))['tables']
parts=["-- History guards, MySQL 8.4. Import after schema/reference/views.\n-- Requires TRIGGER privilege on this database, no server-wide setting.\n-- No stored procedure or EVENT is installed. Aggregated invariants remain application transactions.\nDELIMITER $$\n"]
count=0
def trigger(table,event,body,label='guard'):
 global count
 name='tr_'+table+'_'+event.lower()+'_'+label
 assert len(name)<=64,name
 parts.append('CREATE TRIGGER `'+name+'` BEFORE '+event+' ON `'+table+'`\nFOR EACH ROW\nBEGIN\n'+body+'\nEND$$\n')
 count+=1
def reject_when(condition,message):
 return "  IF "+condition+" THEN\n    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='"+message+"';\n  END IF;"
def parent_state(row,parent,column,terminal):
 return "(SELECT status FROM `"+parent+"` WHERE clinic_id="+row+".clinic_id AND id="+row+'.'+column+")='"+terminal+"'"
parent_guards={
 'invoice_child':('invoices','invoice_id','ISSUED'),
 'credit_child':('credit_notes','credit_note_id','ISSUED'),
 'prescription_child':('optical_prescriptions','prescription_id','VALIDATED'),
 'goods_child':('goods_receipts','receipt_id','VALIDATED'),
 'inventory_child':('inventory_counts','inventory_id','VALIDATED'),
 'delivery_child':('deliveries','delivery_id','CONFIRMED'),
}
for table in tables:
 name=table['name'];guard=table['guard']
 if table['immutable']:
  for event in ['UPDATE','DELETE']:trigger(name,event,"  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Append-only history: create a new linked event';")
 elif guard in parent_guards:
  parent,col,terminal=parent_guards[guard]
  for event in ['INSERT','UPDATE','DELETE']:
   cond=[]
   if event!='INSERT':cond.append(parent_state('OLD',parent,col,terminal))
   if event!='DELETE':cond.append(parent_state('NEW',parent,col,terminal))
   trigger(name,event,reject_when(' OR '.join(cond),'Issued or finalized parent: child is immutable'))
 elif guard:
  cond={'issued':"OLD.status='ISSUED'",'validated':"OLD.status='VALIDATED'",'confirmed_payment':"OLD.status='CONFIRMED'",'confirmed_delivery':"OLD.status='CONFIRMED'",'closed_session':'OLD.closed_at IS NOT NULL','ready_document':"OLD.status='READY'"}.get(guard)
  if cond:
   for event in ['UPDATE','DELETE']:trigger(name,event,reject_when(cond,'Finalized record is immutable; use a linked correction'))
  elif guard=='signed_grant':
   cols=[x['name'] for x in table['columns'] if x['name'] not in ['revoked_at','updated_at']]
   condition=' OR '.join('NOT (NEW.`'+co+'` <=> OLD.`'+co+'`)' for co in cols)
   trigger(name,'UPDATE',reject_when(condition,'Signed authorization fields are immutable; issue a new grant'))
   trigger(name,'DELETE',"  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Signed grant history cannot be deleted';")

trigger('payments','INSERT',reject_when("(SELECT status FROM invoices WHERE clinic_id=NEW.clinic_id AND id=NEW.invoice_id)<>'ISSUED'",'Payment requires an issued invoice'),label='invoice')
trigger('receipts','INSERT',reject_when("(SELECT status FROM payments WHERE clinic_id=NEW.clinic_id AND id=NEW.payment_id)<>'CONFIRMED'",'Receipt requires a confirmed payment'),label='payment')
trigger('refunds','INSERT',reject_when("(SELECT status FROM credit_notes WHERE clinic_id=NEW.clinic_id AND id=NEW.credit_note_id)<>'ISSUED'",'Refund requires an issued credit note'),label='credit')
for table,sessioncol in [('payments','cash_session_id'),('refunds','cash_session_id'),('cash_movements','cash_session_id')]:
 trigger(table,'INSERT',reject_when('NEW.'+sessioncol+' IS NOT NULL AND (SELECT closed_at FROM cash_sessions WHERE clinic_id=NEW.clinic_id AND id=NEW.'+sessioncol+') IS NOT NULL','New cash operation requires an open session'),label='session')

# Perishable batches have an explicit expiry date; time-of-delivery checks are in the app.
for event in ['INSERT','UPDATE']:
 trigger('stock_lots',event,reject_when('(SELECT requires_expiry FROM catalog_items WHERE clinic_id=NEW.clinic_id AND id=NEW.item_id)=1 AND NEW.expires_on IS NULL','Perishable item requires an expiry date'),label='expiry')
trigger('stock_movements','INSERT',reject_when('(SELECT requires_expiry FROM catalog_items WHERE clinic_id=NEW.clinic_id AND id=NEW.item_id)=1 AND NEW.lot_id IS NULL','Perishable movement requires a batch'),label='batch')
trigger('stock_reservations','INSERT',reject_when('(SELECT requires_expiry FROM catalog_items WHERE clinic_id=NEW.clinic_id AND id=NEW.item_id)=1 AND NEW.stock_lot_id IS NULL','Perishable reservation requires a batch'),label='batch')
trigger('delivery_lines','INSERT',reject_when('(SELECT requires_expiry FROM catalog_items WHERE clinic_id=NEW.clinic_id AND id=NEW.item_id)=1 AND NEW.stock_lot_id IS NULL','Perishable delivery requires a batch'),label='batch')
parts.append('DELIMITER ;\n')
(ROOT/'database/04_history_guards.sql').write_text('\n'.join(parts),encoding='utf-8')
print('History triggers: '+str(count))
