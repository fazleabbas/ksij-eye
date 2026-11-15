-- UAT round 1 — corrections the clinic asked for after reviewing the system.

-- The Price List needs a Medicine category: until now medicines were filed under "Other".
ALTER TABLE price_items
  MODIFY COLUMN category ENUM('consultation','investigation','surgery','procedure','lens','medicine','other') NOT NULL;

-- When the next of kin's relationship is "Other", the clinic can now say what it is
-- (neighbour, employer, and so on). Free text, so it is encrypted like the other
-- details of the person named.
ALTER TABLE patients
  ADD COLUMN kin_relationship_other_enc VARBINARY(300) NULL AFTER kin_relationship;

-- A file attached to a diagnostic report can now be taken back off when it was
-- uploaded by mistake. The row stays for the audit trail; the file is marked void.
ALTER TABLE diagnostic_result_files
  ADD COLUMN voided_at DATETIME(3)  NULL AFTER file_id,
  ADD COLUMN voided_by INT UNSIGNED NULL AFTER voided_at,
  ADD CONSTRAINT fk_diagnostic_result_files_voided_by FOREIGN KEY (voided_by) REFERENCES users (id);
