-- History guards, MySQL 8.4. Import after schema/reference/views.
-- Requires TRIGGER privilege on this database, no server-wide setting.
-- No stored procedure or EVENT is installed. Aggregated invariants remain application transactions.
DELIMITER $$

CREATE TRIGGER `tr_audit_events_update_guard` BEFORE UPDATE ON `audit_events`
FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Append-only history: create a new linked event';
END$$

CREATE TRIGGER `tr_audit_events_delete_guard` BEFORE DELETE ON `audit_events`
FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Append-only history: create a new linked event';
END$$

CREATE TRIGGER `tr_patient_merges_update_guard` BEFORE UPDATE ON `patient_merges`
FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Append-only history: create a new linked event';
END$$

CREATE TRIGGER `tr_patient_merges_delete_guard` BEFORE DELETE ON `patient_merges`
FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Append-only history: create a new linked event';
END$$

CREATE TRIGGER `tr_clinical_revisions_update_guard` BEFORE UPDATE ON `clinical_revisions`
FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Append-only history: create a new linked event';
END$$

CREATE TRIGGER `tr_clinical_revisions_delete_guard` BEFORE DELETE ON `clinical_revisions`
FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Append-only history: create a new linked event';
END$$

CREATE TRIGGER `tr_optical_prescriptions_update_guard` BEFORE UPDATE ON `optical_prescriptions`
FOR EACH ROW
BEGIN
  IF OLD.status='VALIDATED' THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Finalized record is immutable; use a linked correction';
  END IF;
END$$

CREATE TRIGGER `tr_optical_prescriptions_delete_guard` BEFORE DELETE ON `optical_prescriptions`
FOR EACH ROW
BEGIN
  IF OLD.status='VALIDATED' THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Finalized record is immutable; use a linked correction';
  END IF;
END$$

CREATE TRIGGER `tr_optical_prescription_eyes_insert_guard` BEFORE INSERT ON `optical_prescription_eyes`
FOR EACH ROW
BEGIN
  IF (SELECT status FROM `optical_prescriptions` WHERE clinic_id=NEW.clinic_id AND id=NEW.prescription_id)='VALIDATED' THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Issued or finalized parent: child is immutable';
  END IF;
END$$

CREATE TRIGGER `tr_optical_prescription_eyes_update_guard` BEFORE UPDATE ON `optical_prescription_eyes`
FOR EACH ROW
BEGIN
  IF (SELECT status FROM `optical_prescriptions` WHERE clinic_id=OLD.clinic_id AND id=OLD.prescription_id)='VALIDATED' OR (SELECT status FROM `optical_prescriptions` WHERE clinic_id=NEW.clinic_id AND id=NEW.prescription_id)='VALIDATED' THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Issued or finalized parent: child is immutable';
  END IF;
END$$

CREATE TRIGGER `tr_optical_prescription_eyes_delete_guard` BEFORE DELETE ON `optical_prescription_eyes`
FOR EACH ROW
BEGIN
  IF (SELECT status FROM `optical_prescriptions` WHERE clinic_id=OLD.clinic_id AND id=OLD.prescription_id)='VALIDATED' THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Issued or finalized parent: child is immutable';
  END IF;
END$$

CREATE TRIGGER `tr_optical_prescription_treatments_insert_guard` BEFORE INSERT ON `optical_prescription_treatments`
FOR EACH ROW
BEGIN
  IF (SELECT status FROM `optical_prescriptions` WHERE clinic_id=NEW.clinic_id AND id=NEW.prescription_id)='VALIDATED' THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Issued or finalized parent: child is immutable';
  END IF;
END$$

CREATE TRIGGER `tr_optical_prescription_treatments_update_guard` BEFORE UPDATE ON `optical_prescription_treatments`
FOR EACH ROW
BEGIN
  IF (SELECT status FROM `optical_prescriptions` WHERE clinic_id=OLD.clinic_id AND id=OLD.prescription_id)='VALIDATED' OR (SELECT status FROM `optical_prescriptions` WHERE clinic_id=NEW.clinic_id AND id=NEW.prescription_id)='VALIDATED' THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Issued or finalized parent: child is immutable';
  END IF;
END$$

CREATE TRIGGER `tr_optical_prescription_treatments_delete_guard` BEFORE DELETE ON `optical_prescription_treatments`
FOR EACH ROW
BEGIN
  IF (SELECT status FROM `optical_prescriptions` WHERE clinic_id=OLD.clinic_id AND id=OLD.prescription_id)='VALIDATED' THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Issued or finalized parent: child is immutable';
  END IF;
END$$

CREATE TRIGGER `tr_document_number_usages_update_guard` BEFORE UPDATE ON `document_number_usages`
FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Append-only history: create a new linked event';
END$$

CREATE TRIGGER `tr_document_number_usages_delete_guard` BEFORE DELETE ON `document_number_usages`
FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Append-only history: create a new linked event';
END$$

CREATE TRIGGER `tr_cash_sessions_update_guard` BEFORE UPDATE ON `cash_sessions`
FOR EACH ROW
BEGIN
  IF OLD.closed_at IS NOT NULL THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Finalized record is immutable; use a linked correction';
  END IF;
END$$

CREATE TRIGGER `tr_cash_sessions_delete_guard` BEFORE DELETE ON `cash_sessions`
FOR EACH ROW
BEGIN
  IF OLD.closed_at IS NOT NULL THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Finalized record is immutable; use a linked correction';
  END IF;
END$$

CREATE TRIGGER `tr_invoices_update_guard` BEFORE UPDATE ON `invoices`
FOR EACH ROW
BEGIN
  IF OLD.status='ISSUED' THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Finalized record is immutable; use a linked correction';
  END IF;
END$$

CREATE TRIGGER `tr_invoices_delete_guard` BEFORE DELETE ON `invoices`
FOR EACH ROW
BEGIN
  IF OLD.status='ISSUED' THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Finalized record is immutable; use a linked correction';
  END IF;
END$$

CREATE TRIGGER `tr_invoice_lines_insert_guard` BEFORE INSERT ON `invoice_lines`
FOR EACH ROW
BEGIN
  IF (SELECT status FROM `invoices` WHERE clinic_id=NEW.clinic_id AND id=NEW.invoice_id)='ISSUED' THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Issued or finalized parent: child is immutable';
  END IF;
END$$

CREATE TRIGGER `tr_invoice_lines_update_guard` BEFORE UPDATE ON `invoice_lines`
FOR EACH ROW
BEGIN
  IF (SELECT status FROM `invoices` WHERE clinic_id=OLD.clinic_id AND id=OLD.invoice_id)='ISSUED' OR (SELECT status FROM `invoices` WHERE clinic_id=NEW.clinic_id AND id=NEW.invoice_id)='ISSUED' THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Issued or finalized parent: child is immutable';
  END IF;
END$$

CREATE TRIGGER `tr_invoice_lines_delete_guard` BEFORE DELETE ON `invoice_lines`
FOR EACH ROW
BEGIN
  IF (SELECT status FROM `invoices` WHERE clinic_id=OLD.clinic_id AND id=OLD.invoice_id)='ISSUED' THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Issued or finalized parent: child is immutable';
  END IF;
END$$

CREATE TRIGGER `tr_payments_update_guard` BEFORE UPDATE ON `payments`
FOR EACH ROW
BEGIN
  IF OLD.status='CONFIRMED' THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Finalized record is immutable; use a linked correction';
  END IF;
END$$

CREATE TRIGGER `tr_payments_delete_guard` BEFORE DELETE ON `payments`
FOR EACH ROW
BEGIN
  IF OLD.status='CONFIRMED' THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Finalized record is immutable; use a linked correction';
  END IF;
END$$

CREATE TRIGGER `tr_receipts_update_guard` BEFORE UPDATE ON `receipts`
FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Append-only history: create a new linked event';
END$$

CREATE TRIGGER `tr_receipts_delete_guard` BEFORE DELETE ON `receipts`
FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Append-only history: create a new linked event';
END$$

CREATE TRIGGER `tr_payment_reversals_update_guard` BEFORE UPDATE ON `payment_reversals`
FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Append-only history: create a new linked event';
END$$

CREATE TRIGGER `tr_payment_reversals_delete_guard` BEFORE DELETE ON `payment_reversals`
FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Append-only history: create a new linked event';
END$$

CREATE TRIGGER `tr_credit_notes_update_guard` BEFORE UPDATE ON `credit_notes`
FOR EACH ROW
BEGIN
  IF OLD.status='ISSUED' THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Finalized record is immutable; use a linked correction';
  END IF;
END$$

CREATE TRIGGER `tr_credit_notes_delete_guard` BEFORE DELETE ON `credit_notes`
FOR EACH ROW
BEGIN
  IF OLD.status='ISSUED' THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Finalized record is immutable; use a linked correction';
  END IF;
END$$

CREATE TRIGGER `tr_credit_note_lines_insert_guard` BEFORE INSERT ON `credit_note_lines`
FOR EACH ROW
BEGIN
  IF (SELECT status FROM `credit_notes` WHERE clinic_id=NEW.clinic_id AND id=NEW.credit_note_id)='ISSUED' THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Issued or finalized parent: child is immutable';
  END IF;
END$$

CREATE TRIGGER `tr_credit_note_lines_update_guard` BEFORE UPDATE ON `credit_note_lines`
FOR EACH ROW
BEGIN
  IF (SELECT status FROM `credit_notes` WHERE clinic_id=OLD.clinic_id AND id=OLD.credit_note_id)='ISSUED' OR (SELECT status FROM `credit_notes` WHERE clinic_id=NEW.clinic_id AND id=NEW.credit_note_id)='ISSUED' THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Issued or finalized parent: child is immutable';
  END IF;
END$$

CREATE TRIGGER `tr_credit_note_lines_delete_guard` BEFORE DELETE ON `credit_note_lines`
FOR EACH ROW
BEGIN
  IF (SELECT status FROM `credit_notes` WHERE clinic_id=OLD.clinic_id AND id=OLD.credit_note_id)='ISSUED' THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Issued or finalized parent: child is immutable';
  END IF;
END$$

CREATE TRIGGER `tr_refunds_update_guard` BEFORE UPDATE ON `refunds`
FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Append-only history: create a new linked event';
END$$

CREATE TRIGGER `tr_refunds_delete_guard` BEFORE DELETE ON `refunds`
FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Append-only history: create a new linked event';
END$$

CREATE TRIGGER `tr_legacy_balance_imports_update_guard` BEFORE UPDATE ON `legacy_balance_imports`
FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Append-only history: create a new linked event';
END$$

CREATE TRIGGER `tr_legacy_balance_imports_delete_guard` BEFORE DELETE ON `legacy_balance_imports`
FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Append-only history: create a new linked event';
END$$

CREATE TRIGGER `tr_cash_movements_update_guard` BEFORE UPDATE ON `cash_movements`
FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Append-only history: create a new linked event';
END$$

CREATE TRIGGER `tr_cash_movements_delete_guard` BEFORE DELETE ON `cash_movements`
FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Append-only history: create a new linked event';
END$$

CREATE TRIGGER `tr_goods_receipts_update_guard` BEFORE UPDATE ON `goods_receipts`
FOR EACH ROW
BEGIN
  IF OLD.status='VALIDATED' THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Finalized record is immutable; use a linked correction';
  END IF;
END$$

CREATE TRIGGER `tr_goods_receipts_delete_guard` BEFORE DELETE ON `goods_receipts`
FOR EACH ROW
BEGIN
  IF OLD.status='VALIDATED' THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Finalized record is immutable; use a linked correction';
  END IF;
END$$

CREATE TRIGGER `tr_goods_receipt_lines_insert_guard` BEFORE INSERT ON `goods_receipt_lines`
FOR EACH ROW
BEGIN
  IF (SELECT status FROM `goods_receipts` WHERE clinic_id=NEW.clinic_id AND id=NEW.receipt_id)='VALIDATED' THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Issued or finalized parent: child is immutable';
  END IF;
END$$

CREATE TRIGGER `tr_goods_receipt_lines_update_guard` BEFORE UPDATE ON `goods_receipt_lines`
FOR EACH ROW
BEGIN
  IF (SELECT status FROM `goods_receipts` WHERE clinic_id=OLD.clinic_id AND id=OLD.receipt_id)='VALIDATED' OR (SELECT status FROM `goods_receipts` WHERE clinic_id=NEW.clinic_id AND id=NEW.receipt_id)='VALIDATED' THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Issued or finalized parent: child is immutable';
  END IF;
END$$

CREATE TRIGGER `tr_goods_receipt_lines_delete_guard` BEFORE DELETE ON `goods_receipt_lines`
FOR EACH ROW
BEGIN
  IF (SELECT status FROM `goods_receipts` WHERE clinic_id=OLD.clinic_id AND id=OLD.receipt_id)='VALIDATED' THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Issued or finalized parent: child is immutable';
  END IF;
END$$

CREATE TRIGGER `tr_inventory_counts_update_guard` BEFORE UPDATE ON `inventory_counts`
FOR EACH ROW
BEGIN
  IF OLD.status='VALIDATED' THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Finalized record is immutable; use a linked correction';
  END IF;
END$$

CREATE TRIGGER `tr_inventory_counts_delete_guard` BEFORE DELETE ON `inventory_counts`
FOR EACH ROW
BEGIN
  IF OLD.status='VALIDATED' THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Finalized record is immutable; use a linked correction';
  END IF;
END$$

CREATE TRIGGER `tr_inventory_count_lines_insert_guard` BEFORE INSERT ON `inventory_count_lines`
FOR EACH ROW
BEGIN
  IF (SELECT status FROM `inventory_counts` WHERE clinic_id=NEW.clinic_id AND id=NEW.inventory_id)='VALIDATED' THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Issued or finalized parent: child is immutable';
  END IF;
END$$

CREATE TRIGGER `tr_inventory_count_lines_update_guard` BEFORE UPDATE ON `inventory_count_lines`
FOR EACH ROW
BEGIN
  IF (SELECT status FROM `inventory_counts` WHERE clinic_id=OLD.clinic_id AND id=OLD.inventory_id)='VALIDATED' OR (SELECT status FROM `inventory_counts` WHERE clinic_id=NEW.clinic_id AND id=NEW.inventory_id)='VALIDATED' THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Issued or finalized parent: child is immutable';
  END IF;
END$$

CREATE TRIGGER `tr_inventory_count_lines_delete_guard` BEFORE DELETE ON `inventory_count_lines`
FOR EACH ROW
BEGIN
  IF (SELECT status FROM `inventory_counts` WHERE clinic_id=OLD.clinic_id AND id=OLD.inventory_id)='VALIDATED' THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Issued or finalized parent: child is immutable';
  END IF;
END$$

CREATE TRIGGER `tr_deliveries_update_guard` BEFORE UPDATE ON `deliveries`
FOR EACH ROW
BEGIN
  IF OLD.status='CONFIRMED' THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Finalized record is immutable; use a linked correction';
  END IF;
END$$

CREATE TRIGGER `tr_deliveries_delete_guard` BEFORE DELETE ON `deliveries`
FOR EACH ROW
BEGIN
  IF OLD.status='CONFIRMED' THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Finalized record is immutable; use a linked correction';
  END IF;
END$$

CREATE TRIGGER `tr_delivery_lines_insert_guard` BEFORE INSERT ON `delivery_lines`
FOR EACH ROW
BEGIN
  IF (SELECT status FROM `deliveries` WHERE clinic_id=NEW.clinic_id AND id=NEW.delivery_id)='CONFIRMED' THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Issued or finalized parent: child is immutable';
  END IF;
END$$

CREATE TRIGGER `tr_delivery_lines_update_guard` BEFORE UPDATE ON `delivery_lines`
FOR EACH ROW
BEGIN
  IF (SELECT status FROM `deliveries` WHERE clinic_id=OLD.clinic_id AND id=OLD.delivery_id)='CONFIRMED' OR (SELECT status FROM `deliveries` WHERE clinic_id=NEW.clinic_id AND id=NEW.delivery_id)='CONFIRMED' THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Issued or finalized parent: child is immutable';
  END IF;
END$$

CREATE TRIGGER `tr_delivery_lines_delete_guard` BEFORE DELETE ON `delivery_lines`
FOR EACH ROW
BEGIN
  IF (SELECT status FROM `deliveries` WHERE clinic_id=OLD.clinic_id AND id=OLD.delivery_id)='CONFIRMED' THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Issued or finalized parent: child is immutable';
  END IF;
END$$

CREATE TRIGGER `tr_physical_returns_update_guard` BEFORE UPDATE ON `physical_returns`
FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Append-only history: create a new linked event';
END$$

CREATE TRIGGER `tr_physical_returns_delete_guard` BEFORE DELETE ON `physical_returns`
FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Append-only history: create a new linked event';
END$$

CREATE TRIGGER `tr_stock_movements_update_guard` BEFORE UPDATE ON `stock_movements`
FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Append-only history: create a new linked event';
END$$

CREATE TRIGGER `tr_stock_movements_delete_guard` BEFORE DELETE ON `stock_movements`
FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Append-only history: create a new linked event';
END$$

CREATE TRIGGER `tr_workshop_events_update_guard` BEFORE UPDATE ON `workshop_events`
FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Append-only history: create a new linked event';
END$$

CREATE TRIGGER `tr_workshop_events_delete_guard` BEFORE DELETE ON `workshop_events`
FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Append-only history: create a new linked event';
END$$

CREATE TRIGGER `tr_conversation_handoffs_update_guard` BEFORE UPDATE ON `conversation_handoffs`
FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Append-only history: create a new linked event';
END$$

CREATE TRIGGER `tr_conversation_handoffs_delete_guard` BEFORE DELETE ON `conversation_handoffs`
FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Append-only history: create a new linked event';
END$$

CREATE TRIGGER `tr_contact_attempts_update_guard` BEFORE UPDATE ON `contact_attempts`
FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Append-only history: create a new linked event';
END$$

CREATE TRIGGER `tr_contact_attempts_delete_guard` BEFORE DELETE ON `contact_attempts`
FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Append-only history: create a new linked event';
END$$

CREATE TRIGGER `tr_communication_consents_update_guard` BEFORE UPDATE ON `communication_consents`
FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Append-only history: create a new linked event';
END$$

CREATE TRIGGER `tr_communication_consents_delete_guard` BEFORE DELETE ON `communication_consents`
FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Append-only history: create a new linked event';
END$$

CREATE TRIGGER `tr_offline_grants_update_guard` BEFORE UPDATE ON `offline_grants`
FOR EACH ROW
BEGIN
  IF NOT (NEW.`id` <=> OLD.`id`) OR NOT (NEW.`clinic_id` <=> OLD.`clinic_id`) OR NOT (NEW.`device_id` <=> OLD.`device_id`) OR NOT (NEW.`user_id` <=> OLD.`user_id`) OR NOT (NEW.`permissions_snapshot` <=> OLD.`permissions_snapshot`) OR NOT (NEW.`rules_version` <=> OLD.`rules_version`) OR NOT (NEW.`catalog_version` <=> OLD.`catalog_version`) OR NOT (NEW.`issued_at` <=> OLD.`issued_at`) OR NOT (NEW.`valid_until` <=> OLD.`valid_until`) OR NOT (NEW.`signed_payload` <=> OLD.`signed_payload`) OR NOT (NEW.`created_at` <=> OLD.`created_at`) THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Signed authorization fields are immutable; issue a new grant';
  END IF;
END$$

CREATE TRIGGER `tr_offline_grants_delete_guard` BEFORE DELETE ON `offline_grants`
FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Signed grant history cannot be deleted';
END$$

CREATE TRIGGER `tr_sync_commands_update_guard` BEFORE UPDATE ON `sync_commands`
FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Append-only history: create a new linked event';
END$$

CREATE TRIGGER `tr_sync_commands_delete_guard` BEFORE DELETE ON `sync_commands`
FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Append-only history: create a new linked event';
END$$

CREATE TRIGGER `tr_sync_change_log_update_guard` BEFORE UPDATE ON `sync_change_log`
FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Append-only history: create a new linked event';
END$$

CREATE TRIGGER `tr_sync_change_log_delete_guard` BEFORE DELETE ON `sync_change_log`
FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Append-only history: create a new linked event';
END$$

CREATE TRIGGER `tr_generated_documents_update_guard` BEFORE UPDATE ON `generated_documents`
FOR EACH ROW
BEGIN
  IF OLD.status='READY' THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Finalized record is immutable; use a linked correction';
  END IF;
END$$

CREATE TRIGGER `tr_generated_documents_delete_guard` BEFORE DELETE ON `generated_documents`
FOR EACH ROW
BEGIN
  IF OLD.status='READY' THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Finalized record is immutable; use a linked correction';
  END IF;
END$$

CREATE TRIGGER `tr_quote_revisions_update_guard` BEFORE UPDATE ON `quote_revisions`
FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Append-only history: create a new linked event';
END$$

CREATE TRIGGER `tr_quote_revisions_delete_guard` BEFORE DELETE ON `quote_revisions`
FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Append-only history: create a new linked event';
END$$

CREATE TRIGGER `tr_payments_insert_invoice` BEFORE INSERT ON `payments`
FOR EACH ROW
BEGIN
  IF (SELECT status FROM invoices WHERE clinic_id=NEW.clinic_id AND id=NEW.invoice_id)<>'ISSUED' THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Payment requires an issued invoice';
  END IF;
END$$

CREATE TRIGGER `tr_receipts_insert_payment` BEFORE INSERT ON `receipts`
FOR EACH ROW
BEGIN
  IF (SELECT status FROM payments WHERE clinic_id=NEW.clinic_id AND id=NEW.payment_id)<>'CONFIRMED' THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Receipt requires a confirmed payment';
  END IF;
END$$

CREATE TRIGGER `tr_refunds_insert_credit` BEFORE INSERT ON `refunds`
FOR EACH ROW
BEGIN
  IF (SELECT status FROM credit_notes WHERE clinic_id=NEW.clinic_id AND id=NEW.credit_note_id)<>'ISSUED' THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Refund requires an issued credit note';
  END IF;
END$$

CREATE TRIGGER `tr_payments_insert_session` BEFORE INSERT ON `payments`
FOR EACH ROW
BEGIN
  IF NEW.cash_session_id IS NOT NULL AND (SELECT closed_at FROM cash_sessions WHERE clinic_id=NEW.clinic_id AND id=NEW.cash_session_id) IS NOT NULL THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='New cash operation requires an open session';
  END IF;
END$$

CREATE TRIGGER `tr_refunds_insert_session` BEFORE INSERT ON `refunds`
FOR EACH ROW
BEGIN
  IF NEW.cash_session_id IS NOT NULL AND (SELECT closed_at FROM cash_sessions WHERE clinic_id=NEW.clinic_id AND id=NEW.cash_session_id) IS NOT NULL THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='New cash operation requires an open session';
  END IF;
END$$

CREATE TRIGGER `tr_cash_movements_insert_session` BEFORE INSERT ON `cash_movements`
FOR EACH ROW
BEGIN
  IF NEW.cash_session_id IS NOT NULL AND (SELECT closed_at FROM cash_sessions WHERE clinic_id=NEW.clinic_id AND id=NEW.cash_session_id) IS NOT NULL THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='New cash operation requires an open session';
  END IF;
END$$

CREATE TRIGGER `tr_stock_lots_insert_expiry` BEFORE INSERT ON `stock_lots`
FOR EACH ROW
BEGIN
  IF (SELECT requires_expiry FROM catalog_items WHERE clinic_id=NEW.clinic_id AND id=NEW.item_id)=1 AND NEW.expires_on IS NULL THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Perishable item requires an expiry date';
  END IF;
END$$

CREATE TRIGGER `tr_stock_lots_update_expiry` BEFORE UPDATE ON `stock_lots`
FOR EACH ROW
BEGIN
  IF (SELECT requires_expiry FROM catalog_items WHERE clinic_id=NEW.clinic_id AND id=NEW.item_id)=1 AND NEW.expires_on IS NULL THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Perishable item requires an expiry date';
  END IF;
END$$

CREATE TRIGGER `tr_stock_movements_insert_batch` BEFORE INSERT ON `stock_movements`
FOR EACH ROW
BEGIN
  IF (SELECT requires_expiry FROM catalog_items WHERE clinic_id=NEW.clinic_id AND id=NEW.item_id)=1 AND NEW.lot_id IS NULL THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Perishable movement requires a batch';
  END IF;
END$$

CREATE TRIGGER `tr_stock_reservations_insert_batch` BEFORE INSERT ON `stock_reservations`
FOR EACH ROW
BEGIN
  IF (SELECT requires_expiry FROM catalog_items WHERE clinic_id=NEW.clinic_id AND id=NEW.item_id)=1 AND NEW.stock_lot_id IS NULL THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Perishable reservation requires a batch';
  END IF;
END$$

CREATE TRIGGER `tr_delivery_lines_insert_batch` BEFORE INSERT ON `delivery_lines`
FOR EACH ROW
BEGIN
  IF (SELECT requires_expiry FROM catalog_items WHERE clinic_id=NEW.clinic_id AND id=NEW.item_id)=1 AND NEW.stock_lot_id IS NULL THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Perishable delivery requires a batch';
  END IF;
END$$

DELIMITER ;
