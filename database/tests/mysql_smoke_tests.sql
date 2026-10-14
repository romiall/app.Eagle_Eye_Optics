-- Synthetic TEST data only. Requires schema, reference data and history guards.
-- All fixture rows are rolled back. This checks database guarantees, not UI/PWA.
DELIMITER $$
CREATE PROCEDURE smoke_reject(IN test_label VARCHAR(150), IN test_sql TEXT, IN expected_errno INT)
BEGIN
  DECLARE actual_errno INT DEFAULT 0;
  DECLARE failure_message VARCHAR(255);
  SET @smoke_dynamic_sql = test_sql;
  BEGIN
    DECLARE CONTINUE HANDLER FOR SQLEXCEPTION GET DIAGNOSTICS CONDITION 1 actual_errno = MYSQL_ERRNO;
    PREPARE smoke_statement FROM @smoke_dynamic_sql;
    EXECUTE smoke_statement;
    DEALLOCATE PREPARE smoke_statement;
  END;
  IF actual_errno <> expected_errno THEN
    SET failure_message = CONCAT(test_label, ': expected ', expected_errno, ', obtained ', actual_errno);
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = failure_message;
  END IF;
  SELECT CONCAT('PASS ', test_label);
END$$
CREATE PROCEDURE smoke_equal(IN test_label VARCHAR(150), IN actual_value DECIMAL(40,3), IN expected_value DECIMAL(40,3))
BEGIN
  DECLARE failure_message VARCHAR(255);
  IF actual_value IS NULL OR actual_value <> expected_value THEN
    SET failure_message = CONCAT(test_label, ': expected ', expected_value, ', obtained ', COALESCE(actual_value,'NULL'));
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = failure_message;
  END IF;
  SELECT CONCAT('PASS ', test_label);
END$$
DELIMITER ;

START TRANSACTION;
SET @c=LPAD(1,26,'0'), @c_other=LPAD(2,26,'0'), @actor=LPAD(11,26,'0'), @actor_other=LPAD(12,26,'0');
SET @patient=LPAD(21,26,'0'), @patient_other=LPAD(22,26,'0'), @patient_sameclinic_other=LPAD(23,26,'0'), @visit=LPAD(31,26,'0');
SET @invoice=LPAD(40,26,'0'), @line=LPAD(41,26,'0'), @item=LPAD(130,26,'0');
SET @device=LPAD(110,26,'0'), @device2=LPAD(111,26,'0'), @grant=LPAD(120,26,'0');
INSERT INTO clinics(id,name) VALUES (@c,'TEST CABINET A'),(@c_other,'TEST CABINET B');
INSERT INTO users(id,clinic_id,display_name,login,password_hash) VALUES (@actor,@c,'TEST MANAGER','test-a','NO REAL PASSWORD'),(@actor_other,@c_other,'TEST OTHER','test-b','NO REAL PASSWORD');
INSERT INTO patient_records(id,clinic_id,record_number,last_name,name_search,birth_date_missing_reason,phone_missing_reason,created_by) VALUES (@patient,@c,'EE-TEST-1','TEST PATIENT','test patient','TEST unknown date','TEST no phone',@actor),(@patient_other,@c_other,'EE-TEST-2','TEST OTHER','test other','TEST unknown date','TEST no phone',@actor_other),(@patient_sameclinic_other,@c,'EE-TEST-3','TEST OTHER SAME CLINIC','test same clinic','TEST unknown date','TEST no phone',@actor);
INSERT INTO visits(id,clinic_id,patient_id,reason,occurred_at,status,created_by) VALUES (@visit,@c,@patient,'DIRECT_PURCHASE',UTC_TIMESTAMP(6),'ARRIVED',@actor);
INSERT INTO catalog_categories(code,label) VALUES ('TEST_FRAME','TEST FRAME');
INSERT INTO units(code,label,integer_only) VALUES ('TEST_UNIT','TEST UNIT',1);
INSERT INTO catalog_items(id,clinic_id,reference,category_code,name,unit_code,sale_price,stock_mode) VALUES (@item,@c,'TEST-FRAME','TEST_FRAME','TEST FRAME','TEST_UNIT',40000,'STOCKED');
INSERT INTO invoices(id,clinic_id,patient_id,visit_id,total_gross,created_by) VALUES (@invoice,@c,@patient,@visit,80000,@actor);
INSERT INTO invoice_lines(id,clinic_id,patient_id,invoice_id,position,catalog_item_id,description_snapshot,category_snapshot,unit_snapshot,quantity,unit_price_gross,line_total_gross,tax_code_snapshot) VALUES (@line,@c,@patient,@invoice,1,@item,'TEST FRAME','TEST_FRAME','TEST_UNIT',2,40000,80000,'TEST TAX');
CALL smoke_reject('FK prevents patient from another clinic',"INSERT INTO invoices(id,clinic_id,patient_id,created_by) VALUES (LPAD(301,26,'0'),@c,@patient_other,@actor)",1452);
CALL smoke_reject('FK prevents visit/patient mismatch',"INSERT INTO consultations(id,clinic_id,visit_id,patient_id,clinician_id,effective_at) VALUES (LPAD(302,26,'0'),@c,@visit,@patient_sameclinic_other,@actor,UTC_TIMESTAMP(6))",1452);
CALL smoke_reject('CHECK rejects inconsistent exact line total',"UPDATE invoice_lines SET line_total_gross=79999 WHERE id=@line",3819);
CALL smoke_reject('CHECK rejects negative money',"UPDATE catalog_items SET sale_price=-1 WHERE id=@item",3819);
CALL smoke_reject('CHECK rejects legacy balance NULL',"INSERT INTO invoices(id,clinic_id,patient_id,created_by,origin,legacy_reference) VALUES (LPAD(303,26,'0'),@c,@patient,@actor,'LEGACY_BALANCE','TEST PAPER')",3819);
CALL smoke_equal('Exact half-up FCFA rounding',ROUND(CAST('0.125' AS DECIMAL(12,3))*100,0),13);
INSERT INTO document_number_usages(id,clinic_id,document_type,document_year,number_value,rendered_number,entity_type,entity_id,actor_id,consumed_at,status) VALUES (LPAD(42,26,'0'),@c,'INVOICE',2026,1,'FAC-TEST-1','invoices',@invoice,@actor,UTC_TIMESTAMP(6),'USED');
UPDATE invoices SET status='ISSUED',number='FAC-TEST-1',number_usage_id=LPAD(42,26,'0'),issued_at=UTC_TIMESTAMP(6),issued_by=@actor,patient_snapshot=JSON_OBJECT('test',TRUE),clinic_snapshot=JSON_OBJECT('test',TRUE),tax_snapshot=JSON_OBJECT('test',TRUE) WHERE id=@invoice;
CALL smoke_reject('Issued invoice cannot change price',"UPDATE invoices SET total_gross=90000 WHERE id=@invoice",1644);
CALL smoke_reject('Issued invoice line cannot change',"UPDATE invoice_lines SET quantity=3,line_total_gross=120000 WHERE id=@line",1644);
CALL smoke_reject('Issued invoice cannot be deleted',"DELETE FROM invoices WHERE id=@invoice",1644);
CALL smoke_reject('Number consumption cannot repeat',"INSERT INTO document_number_usages(id,clinic_id,document_type,document_year,number_value,rendered_number,entity_type,entity_id,actor_id,consumed_at,status) VALUES (LPAD(304,26,'0'),@c,'INVOICE',2026,1,'FAC-TEST-DUP','invoices',@invoice,@actor,UTC_TIMESTAMP(6),'USED')",1062);

INSERT INTO cash_registers(id,clinic_id,code,name) VALUES (LPAD(60,26,'0'),@c,'TEST','TEST REGISTER');
INSERT INTO cash_sessions(id,clinic_id,cash_register_id,cashier_id,opened_at,opening_amount) VALUES (LPAD(61,26,'0'),@c,LPAD(60,26,'0'),@actor,UTC_TIMESTAMP(6),10000);
CALL smoke_reject('One active session per cash register',"INSERT INTO cash_sessions(id,clinic_id,cash_register_id,cashier_id,opened_at,opening_amount) VALUES (LPAD(305,26,'0'),@c,LPAD(60,26,'0'),@actor,UTC_TIMESTAMP(6),0)",1062);
INSERT INTO idempotency_requests(id,clinic_id,namespace,command_name,request_key,payload_hash,status) VALUES (LPAD(53,26,'0'),@c,'TEST','payment','TEST-PAY-1',REPEAT('1',64),'COMMITTED'),(LPAD(57,26,'0'),@c,'TEST','payment','TEST-PAY-2',REPEAT('2',64),'COMMITTED');
INSERT INTO payments(id,clinic_id,invoice_id,amount,method,effective_at,status,confirmed_at,cash_session_id,received_by,idempotency_id) VALUES (LPAD(51,26,'0'),@c,@invoice,30000,'CASH',UTC_TIMESTAMP(6),'CONFIRMED',UTC_TIMESTAMP(6),LPAD(61,26,'0'),@actor,LPAD(53,26,'0')),(LPAD(54,26,'0'),@c,@invoice,20000,'CASH',UTC_TIMESTAMP(6),'CONFIRMED',UTC_TIMESTAMP(6),LPAD(61,26,'0'),@actor,LPAD(57,26,'0'));
INSERT INTO document_number_usages(id,clinic_id,document_type,document_year,number_value,rendered_number,entity_type,entity_id,actor_id,consumed_at,status) VALUES (LPAD(52,26,'0'),@c,'RECEIPT',2026,1,'REC-TEST-1','receipts',LPAD(50,26,'0'),@actor,UTC_TIMESTAMP(6),'USED'),(LPAD(56,26,'0'),@c,'RECEIPT',2026,2,'REC-TEST-2','receipts',LPAD(55,26,'0'),@actor,UTC_TIMESTAMP(6),'USED');
INSERT INTO receipts(id,clinic_id,payment_id,invoice_id,number,number_usage_id,issued_at,issued_by,snapshot) VALUES (LPAD(50,26,'0'),@c,LPAD(51,26,'0'),@invoice,'REC-TEST-1',LPAD(52,26,'0'),UTC_TIMESTAMP(6),@actor,JSON_OBJECT('test',TRUE)),(LPAD(55,26,'0'),@c,LPAD(54,26,'0'),@invoice,'REC-TEST-2',LPAD(56,26,'0'),UTC_TIMESTAMP(6),@actor,JSON_OBJECT('test',TRUE));
CALL smoke_reject('Idempotency key cannot repeat',"INSERT INTO idempotency_requests(id,clinic_id,namespace,command_name,request_key,payload_hash,status) VALUES (LPAD(306,26,'0'),@c,'TEST','payment','TEST-PAY-1',REPEAT('3',64),'COMMITTED')",1062);
CALL smoke_reject('Confirmed payment cannot change',"UPDATE payments SET amount=100 WHERE id=LPAD(51,26,'0')",1644);
CALL smoke_reject('Receipt is immutable',"UPDATE receipts SET number='REC-TEST-ALTERED' WHERE id=LPAD(50,26,'0')",1644);
CALL smoke_reject('Payment cannot have two receipts',"INSERT INTO receipts(id,clinic_id,payment_id,invoice_id,number,number_usage_id,issued_at,issued_by,snapshot) VALUES (LPAD(307,26,'0'),@c,LPAD(51,26,'0'),@invoice,'REC-TEST-OTHER',LPAD(56,26,'0'),UTC_TIMESTAMP(6),@actor,JSON_OBJECT('test',TRUE))",1062);

INSERT INTO approvals(id,clinic_id,action_code,target_type,target_id,payload_hash,requested_by,reason,status,decided_by,decided_at) VALUES (LPAD(70,26,'0'),@c,'TEST','invoices',@invoice,REPEAT('a',64),@actor,'TEST approval','APPROVED',@actor,UTC_TIMESTAMP(6));
INSERT INTO idempotency_requests(id,clinic_id,namespace,command_name,request_key,payload_hash,status) VALUES (LPAD(83,26,'0'),@c,'TEST','credit','TEST-CREDIT-1',REPEAT('4',64),'COMMITTED'),(LPAD(87,26,'0'),@c,'TEST','credit','TEST-CREDIT-2',REPEAT('5',64),'COMMITTED'),(LPAD(92,26,'0'),@c,'TEST','refund','TEST-REFUND',REPEAT('6',64),'COMMITTED'),(LPAD(101,26,'0'),@c,'TEST','reverse','TEST-REVERSE',REPEAT('7',64),'COMMITTED');
INSERT INTO credit_notes(id,clinic_id,invoice_id,amount_gross,reason,approval_id,idempotency_id) VALUES (LPAD(80,26,'0'),@c,@invoice,10000,'TEST partial credit',LPAD(70,26,'0'),LPAD(83,26,'0')),(LPAD(84,26,'0'),@c,@invoice,70000,'TEST remaining credit',LPAD(70,26,'0'),LPAD(87,26,'0'));
INSERT INTO credit_note_lines(id,clinic_id,credit_note_id,invoice_id,invoice_line_id,amount_gross) VALUES (LPAD(81,26,'0'),@c,LPAD(80,26,'0'),@invoice,@line,10000),(LPAD(85,26,'0'),@c,LPAD(84,26,'0'),@invoice,@line,70000);
INSERT INTO document_number_usages(id,clinic_id,document_type,document_year,number_value,rendered_number,entity_type,entity_id,actor_id,consumed_at,status) VALUES (LPAD(82,26,'0'),@c,'CREDIT_NOTE',2026,1,'AV-TEST-1','credit_notes',LPAD(80,26,'0'),@actor,UTC_TIMESTAMP(6),'USED'),(LPAD(86,26,'0'),@c,'CREDIT_NOTE',2026,2,'AV-TEST-2','credit_notes',LPAD(84,26,'0'),@actor,UTC_TIMESTAMP(6),'USED'),(LPAD(91,26,'0'),@c,'REFUND',2026,1,'RMB-TEST-1','refunds',LPAD(90,26,'0'),@actor,UTC_TIMESTAMP(6),'USED');
UPDATE credit_notes SET status='ISSUED',number=CASE WHEN id=LPAD(80,26,'0') THEN 'AV-TEST-1' ELSE 'AV-TEST-2' END,number_usage_id=CASE WHEN id=LPAD(80,26,'0') THEN LPAD(82,26,'0') ELSE LPAD(86,26,'0') END,issued_at=UTC_TIMESTAMP(6),issued_by=@actor,snapshot=JSON_OBJECT('test',TRUE) WHERE id IN (LPAD(80,26,'0'),LPAD(84,26,'0'));
INSERT INTO payment_reversals(id,clinic_id,payment_id,amount,reason,approval_id,recorded_at,created_by,idempotency_id) VALUES (LPAD(100,26,'0'),@c,LPAD(51,26,'0'),5000,'TEST correction',LPAD(70,26,'0'),UTC_TIMESTAMP(6),@actor,LPAD(101,26,'0'));
INSERT INTO refunds(id,clinic_id,invoice_id,credit_note_id,amount,method,approval_id,number,number_usage_id,effective_at,cash_session_id,idempotency_id,created_by,snapshot) VALUES (LPAD(90,26,'0'),@c,@invoice,LPAD(84,26,'0'),45000,'CASH',LPAD(70,26,'0'),'RMB-TEST-1',LPAD(91,26,'0'),UTC_TIMESTAMP(6),LPAD(61,26,'0'),LPAD(92,26,'0'),@actor,JSON_OBJECT('test',TRUE));
SET @balance=(SELECT total_gross FROM invoices WHERE id=@invoice) - (SELECT SUM(amount_gross) FROM credit_notes WHERE invoice_id=@invoice AND status='ISSUED') - (SELECT SUM(amount) FROM payments WHERE invoice_id=@invoice AND status='CONFIRMED') + (SELECT SUM(pr.amount) FROM payment_reversals pr JOIN payments p ON p.id=pr.payment_id WHERE p.invoice_id=@invoice) + (SELECT SUM(amount) FROM refunds WHERE invoice_id=@invoice);
CALL smoke_equal('Finance canonical total credit payment reversal refund',@balance,0);
CALL smoke_equal('Balance view avoids multi-payment multi-credit fanout',(SELECT signed_balance FROM vw_invoice_balances WHERE invoice_id=@invoice),0);
CALL smoke_equal('Balance view counts confirmed payments once',(SELECT payments_confirmed FROM vw_invoice_balances WHERE invoice_id=@invoice),50000);
CALL smoke_equal('Balance view counts issued credits once',(SELECT credits_issued FROM vw_invoice_balances WHERE invoice_id=@invoice),80000);
CALL smoke_equal('Balance view retains accounting reversals',(SELECT payment_reversals FROM vw_invoice_balances WHERE invoice_id=@invoice),5000);
CALL smoke_equal('Balance view counts actual refunds once',(SELECT refunds_confirmed FROM vw_invoice_balances WHERE invoice_id=@invoice),45000);
CALL smoke_equal('Revenue excludes receipts as new sales',(SELECT SUM(signed_amount) FROM vw_revenue_events WHERE invoice_id=@invoice),0);
CALL smoke_equal('Collection events net payments reversals refunds',(SELECT SUM(signed_amount) FROM vw_collection_events WHERE invoice_id=@invoice),0);
CALL smoke_equal('Cash projection accounts for opening and corrections',(SELECT recomputed_cash_amount FROM vw_cash_session_totals WHERE cash_session_id=LPAD(61,26,'0')),10000);

INSERT INTO enrolled_devices(id,clinic_id,device_code,name,public_key_jwk,status,enrolled_by,responsible_user_id,enrolled_at,app_version) VALUES (@device,@c,'TEST-A','TEST DEVICE A','TEST PUBLIC KEY','ACTIVE',@actor,@actor,UTC_TIMESTAMP(6),'TEST'),(@device2,@c,'TEST-B','TEST DEVICE B','TEST PUBLIC KEY','ACTIVE',@actor,@actor,UTC_TIMESTAMP(6),'TEST');
INSERT INTO offline_grants(id,clinic_id,device_id,user_id,permissions_snapshot,rules_version,catalog_version,issued_at,valid_until,signed_payload) VALUES (@grant,@c,@device,@actor,JSON_ARRAY('TEST'),'TEST','TEST',UTC_TIMESTAMP(6),DATE_ADD(UTC_TIMESTAMP(6),INTERVAL 72 HOUR),'TEST SIGNATURE');
INSERT INTO invoice_write_owners(id,clinic_id,invoice_id,device_id,epoch,status,granted_at) VALUES (LPAD(150,26,'0'),@c,@invoice,@device,1,'ACTIVE',UTC_TIMESTAMP(6));
CALL smoke_reject('Only one financial writer per invoice',"INSERT INTO invoice_write_owners(id,clinic_id,invoice_id,device_id,epoch,status,granted_at) VALUES (LPAD(151,26,'0'),@c,@invoice,@device2,2,'CLOSING',UTC_TIMESTAMP(6))",1062);
INSERT INTO sync_commands(id,clinic_id,device_id,actor_id,grant_id,device_sequence,event_hash,signature,schema_version,rules_version,command_name,occurred_at_local,last_server_time,entity_type,entity_id,payload_ciphertext,encryption_key_id,received_at) VALUES (LPAD(160,26,'0'),@c,@device,@actor,@grant,1,REPEAT('b',64),'TEST SIGNATURE','TEST','TEST','TEST',UTC_TIMESTAMP(6),UTC_TIMESTAMP(6),'invoices',@invoice,'TEST CIPHERTEXT','TEST KEY',UTC_TIMESTAMP(6));
CALL smoke_reject('Device sequence cannot replay as another command',"INSERT INTO sync_commands(id,clinic_id,device_id,actor_id,grant_id,device_sequence,event_hash,signature,schema_version,rules_version,command_name,occurred_at_local,last_server_time,entity_type,entity_id,payload_ciphertext,encryption_key_id,received_at) SELECT LPAD(161,26,'0'),clinic_id,device_id,actor_id,grant_id,device_sequence,event_hash,signature,schema_version,rules_version,command_name,occurred_at_local,last_server_time,entity_type,entity_id,payload_ciphertext,encryption_key_id,received_at FROM sync_commands WHERE id=LPAD(160,26,'0')",1062);
CALL smoke_reject('Sync command original cannot be overwritten',"UPDATE sync_commands SET payload_ciphertext='ALTERED' WHERE id=LPAD(160,26,'0')",1644);
CALL smoke_reject('Grant must belong to command device',"INSERT INTO sync_commands(id,clinic_id,device_id,actor_id,grant_id,device_sequence,event_hash,signature,schema_version,rules_version,command_name,occurred_at_local,last_server_time,entity_type,entity_id,payload_ciphertext,encryption_key_id,received_at) SELECT LPAD(162,26,'0'),clinic_id,@device2,actor_id,grant_id,2,event_hash,signature,schema_version,rules_version,command_name,occurred_at_local,last_server_time,entity_type,entity_id,payload_ciphertext,encryption_key_id,received_at FROM sync_commands WHERE id=LPAD(160,26,'0')",1452);

INSERT INTO stock_locations(id,clinic_id,code,name) VALUES (LPAD(131,26,'0'),@c,'TEST','TEST LOCATION');
INSERT INTO stock_positions(id,clinic_id,item_id,location_id,on_hand,reserved,offline_allocated) VALUES (LPAD(132,26,'0'),@c,@item,LPAD(131,26,'0'),5,0,3);
INSERT INTO stock_lots(id,clinic_id,item_id,location_id,stock_position_id,batch_number,received_at,status,on_hand,reserved,offline_allocated) VALUES (LPAD(133,26,'0'),@c,@item,LPAD(131,26,'0'),LPAD(132,26,'0'),'TEST-A',UTC_TIMESTAMP(6),'SELLABLE',3,0,3),(LPAD(134,26,'0'),@c,@item,LPAD(131,26,'0'),LPAD(132,26,'0'),'TEST-B',UTC_TIMESTAMP(6),'SELLABLE',2,0,0);
INSERT INTO device_stock_allocations(id,clinic_id,device_id,stock_position_id,stock_lot_id,allocated_quantity,status,allocated_at) VALUES (LPAD(140,26,'0'),@c,@device,LPAD(132,26,'0'),LPAD(133,26,'0'),3,'ACTIVE',UTC_TIMESTAMP(6));
INSERT INTO idempotency_requests(id,clinic_id,namespace,command_name,request_key,payload_hash,status) VALUES (LPAD(192,26,'0'),@c,'TEST','opening','TEST-STOCK-OPEN-A',REPEAT('8',64),'COMMITTED'),(LPAD(193,26,'0'),@c,'TEST','opening','TEST-STOCK-OPEN-B',REPEAT('9',64),'COMMITTED');
INSERT INTO stock_movements(id,clinic_id,item_id,position_id,lot_id,kind,signed_quantity,approval_id,reason,occurred_at,actor_id,idempotency_id,movement_position) VALUES (LPAD(190,26,'0'),@c,@item,LPAD(132,26,'0'),LPAD(133,26,'0'),'OPENING',3,LPAD(70,26,'0'),'TEST stock opening',UTC_TIMESTAMP(6),@actor,LPAD(192,26,'0'),1),(LPAD(191,26,'0'),@c,@item,LPAD(132,26,'0'),LPAD(134,26,'0'),'OPENING',2,LPAD(70,26,'0'),'TEST stock opening',UTC_TIMESTAMP(6),@actor,LPAD(192,26,'0'),2);
CALL smoke_equal('One command can produce multiple stock movements',(SELECT COUNT(*) FROM stock_movements WHERE idempotency_id=LPAD(192,26,'0')),2);
INSERT INTO invoices(id,clinic_id,patient_id,total_gross,created_by) VALUES (LPAD(200,26,'0'),@c,@patient,40000,@actor);
INSERT INTO invoice_lines(id,clinic_id,patient_id,invoice_id,position,catalog_item_id,description_snapshot,category_snapshot,unit_snapshot,quantity,unit_price_gross,line_total_gross,tax_code_snapshot) VALUES (LPAD(201,26,'0'),@c,@patient,LPAD(200,26,'0'),1,@item,'TEST FRAME 2','TEST_FRAME','TEST_UNIT',1,40000,40000,'TEST TAX');
INSERT INTO document_number_usages(id,clinic_id,document_type,document_year,number_value,rendered_number,entity_type,entity_id,actor_id,consumed_at,status) VALUES (LPAD(202,26,'0'),@c,'INVOICE',2026,2,'FAC-TEST-2','invoices',LPAD(200,26,'0'),@actor,UTC_TIMESTAMP(6),'USED');
UPDATE invoices SET status='ISSUED',number='FAC-TEST-2',number_usage_id=LPAD(202,26,'0'),issued_at=UTC_TIMESTAMP(6),issued_by=@actor,patient_snapshot=JSON_OBJECT('test',TRUE),clinic_snapshot=JSON_OBJECT('test',TRUE),tax_snapshot=JSON_OBJECT('test',TRUE) WHERE id=LPAD(200,26,'0');
INSERT INTO stock_reservations(id,clinic_id,item_id,invoice_line_id,stock_position_id,stock_lot_id,device_allocation_id,quantity,status) VALUES (LPAD(141,26,'0'),@c,@item,LPAD(201,26,'0'),LPAD(132,26,'0'),LPAD(133,26,'0'),LPAD(140,26,'0'),1,'ACTIVE');
CALL smoke_equal('Offline quota excluded once from central availability',(SELECT central_available FROM stock_positions WHERE id=LPAD(132,26,'0')),2);
CALL smoke_equal('Stock ledger view excludes local reservation from central reserved',(SELECT central_reserved FROM vw_stock_position_balances WHERE stock_position_id=LPAD(132,26,'0')),0);
CALL smoke_equal('Stock ledger view counts quota once',(SELECT central_available FROM vw_stock_position_balances WHERE stock_position_id=LPAD(132,26,'0')),2);
CALL smoke_reject('CHECK prevents negative central stock availability',"UPDATE stock_positions SET reserved=3 WHERE id=LPAD(132,26,'0')",3819);
CALL smoke_reject('Quota cannot reserve another lot',"INSERT INTO stock_reservations(id,clinic_id,item_id,invoice_line_id,stock_position_id,stock_lot_id,device_allocation_id,quantity,status) VALUES (LPAD(142,26,'0'),@c,@item,LPAD(201,26,'0'),LPAD(132,26,'0'),LPAD(134,26,'0'),LPAD(140,26,'0'),1,'ACTIVE')",1452);
CALL smoke_reject('Quota cannot be consumed twice',"UPDATE device_stock_allocations SET consumed_quantity=4 WHERE id=LPAD(140,26,'0')",3819);
INSERT INTO approvals(id,clinic_id,action_code,target_type,target_id,payload_hash,requested_by,reason,status,decided_by,decided_at) VALUES (LPAD(75,26,'0'),@c,'TEST DELIVER ON CREDIT','invoices',LPAD(200,26,'0'),REPEAT('c',64),@actor,'TEST delivery on credit','APPROVED',@actor,UTC_TIMESTAMP(6));
INSERT INTO idempotency_requests(id,clinic_id,namespace,command_name,request_key,payload_hash,status) VALUES (LPAD(206,26,'0'),@c,'TEST','delivery','TEST-DELIVERY',REPEAT('d',64),'COMMITTED');
INSERT INTO deliveries(id,clinic_id,invoice_id,status,credit_delivery_approval_id,idempotency_id,patient_snapshot) VALUES (LPAD(204,26,'0'),@c,LPAD(200,26,'0'),'DRAFT',LPAD(75,26,'0'),LPAD(206,26,'0'),JSON_OBJECT('test',TRUE));
INSERT INTO delivery_lines(id,clinic_id,item_id,stock_position_id,delivery_id,invoice_id,invoice_line_id,quantity,stock_lot_id,reservation_id) VALUES (LPAD(205,26,'0'),@c,@item,LPAD(132,26,'0'),LPAD(204,26,'0'),LPAD(200,26,'0'),LPAD(201,26,'0'),1,LPAD(133,26,'0'),LPAD(141,26,'0'));
UPDATE deliveries SET status='CONFIRMED',delivered_at=UTC_TIMESTAMP(6),delivered_by=@actor WHERE id=LPAD(204,26,'0');
INSERT INTO stock_movements(id,clinic_id,item_id,position_id,lot_id,kind,signed_quantity,delivery_line_id,reason,occurred_at,actor_id,idempotency_id) VALUES (LPAD(208,26,'0'),@c,@item,LPAD(132,26,'0'),LPAD(133,26,'0'),'SALE_DELIVERY',-1,LPAD(205,26,'0'),'TEST local delivery',UTC_TIMESTAMP(6),@actor,LPAD(206,26,'0'));
UPDATE stock_reservations SET consumed_quantity=1,status='CONSUMED' WHERE id=LPAD(141,26,'0');
UPDATE device_stock_allocations SET consumed_quantity=1 WHERE id=LPAD(140,26,'0');
UPDATE stock_positions SET on_hand=4,offline_allocated=2 WHERE id=LPAD(132,26,'0');
UPDATE stock_lots SET on_hand=2,offline_allocated=2 WHERE id=LPAD(133,26,'0');
CALL smoke_equal('Synced local sale decreases physical and quota once',(SELECT central_available FROM stock_positions WHERE id=LPAD(132,26,'0')),2);
CALL smoke_equal('Remaining local quota after one delivery',(SELECT allocated_quantity-consumed_quantity-returned_quantity FROM device_stock_allocations WHERE id=LPAD(140,26,'0')),2);
CALL smoke_equal('Stock position ledger reconciles after delivery',(SELECT central_available FROM vw_stock_position_balances WHERE stock_position_id=LPAD(132,26,'0')),2);
CALL smoke_equal('Stock physical ledger matches cached on-hand',(SELECT ledger_on_hand FROM vw_stock_position_balances WHERE stock_position_id=LPAD(132,26,'0')),4);
CALL smoke_equal('Delivered lot ledger matches cached on-hand',(SELECT ledger_on_hand FROM vw_stock_lot_balances WHERE stock_lot_id=LPAD(133,26,'0')),2);
CALL smoke_reject('Confirmed delivery cannot be changed',"UPDATE deliveries SET status='DRAFT' WHERE id=LPAD(204,26,'0')",1644);

ROLLBACK;
DROP PROCEDURE smoke_reject;
DROP PROCEDURE smoke_equal;
SELECT 'smoke_checks_passed_and_fixtures_rolled_back';
