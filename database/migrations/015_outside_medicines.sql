-- UAT round 3 — a doctor may prescribe a medicine the pharmacy does not stock by
-- typing its name. The line is kept as text; the pharmacy never bills or dispenses
-- it, and shows it to the patient as "not stocked — buy outside".
ALTER TABLE prescription_items
  MODIFY COLUMN drug_id INT UNSIGNED NULL,
  ADD COLUMN outside_name VARCHAR(150) NULL COMMENT 'typed medicine, not in the catalogue' AFTER drug_id,
  ADD CONSTRAINT ck_prescription_items_medicine CHECK (drug_id IS NOT NULL OR outside_name IS NOT NULL);
