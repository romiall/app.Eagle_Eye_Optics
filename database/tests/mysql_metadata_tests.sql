-- Architectural checks against the schema actually loaded by MySQL 8.4.
DELIMITER $$
CREATE PROCEDURE validate_schema_metadata()
BEGIN
  DECLARE violations BIGINT DEFAULT 0;
  SELECT COUNT(*) INTO violations FROM information_schema.tables
    WHERE table_schema = DATABASE() AND table_type = 'BASE TABLE';
  IF violations = 0 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'No business tables imported'; END IF;

  SELECT COUNT(*) INTO violations FROM information_schema.tables
    WHERE table_schema = DATABASE() AND table_type = 'BASE TABLE'
      AND (engine <> 'InnoDB' OR table_collation NOT LIKE 'utf8mb4%');
  IF violations > 0 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Tables require InnoDB and utf8mb4'; END IF;

  SELECT COUNT(*) INTO violations FROM information_schema.columns
    WHERE table_schema = DATABASE() AND data_type IN ('float', 'double');
  IF violations > 0 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Approximate numeric columns found'; END IF;

  SELECT COUNT(*) INTO violations FROM information_schema.table_constraints
    WHERE constraint_schema = DATABASE() AND constraint_type = 'CHECK' AND enforced <> 'YES';
  IF violations > 0 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Unenforced CHECK constraints found'; END IF;

  SELECT COUNT(*) INTO violations FROM information_schema.referential_constraints
    WHERE constraint_schema = DATABASE() AND delete_rule = 'CASCADE';
  IF violations > 0 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Historical schema contains DELETE CASCADE'; END IF;

  SELECT COUNT(*) INTO violations FROM (
    SELECT k.table_name, k.constraint_name
      FROM information_schema.key_column_usage k
      INNER JOIN information_schema.columns child_clinic
        ON child_clinic.table_schema = k.table_schema
       AND child_clinic.table_name = k.table_name AND child_clinic.column_name = 'clinic_id'
      INNER JOIN information_schema.columns parent_clinic
        ON parent_clinic.table_schema = k.referenced_table_schema
       AND parent_clinic.table_name = k.referenced_table_name AND parent_clinic.column_name = 'clinic_id'
      WHERE k.constraint_schema = DATABASE() AND k.referenced_table_name IS NOT NULL
      GROUP BY k.table_name, k.constraint_name
      HAVING SUM(k.column_name = 'clinic_id' AND k.referenced_column_name = 'clinic_id') = 0
  ) AS unscoped_fks;
  IF violations > 0 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Cross-clinic FK is not protected by clinic_id'; END IF;
END$$
DELIMITER ;
CALL validate_schema_metadata();
DROP PROCEDURE validate_schema_metadata;
SELECT 'metadata_checks_passed';
SELECT 'base_tables', COUNT(*) FROM information_schema.tables
 WHERE table_schema = DATABASE() AND table_type = 'BASE TABLE';
SELECT 'foreign_keys', COUNT(*) FROM information_schema.referential_constraints
 WHERE constraint_schema = DATABASE();
SELECT 'checks', COUNT(*) FROM information_schema.table_constraints
 WHERE constraint_schema = DATABASE() AND constraint_type = 'CHECK';
SELECT 'triggers', COUNT(*) FROM information_schema.triggers
 WHERE trigger_schema = DATABASE();
SELECT 'views', COUNT(*) FROM information_schema.views
 WHERE table_schema = DATABASE();
