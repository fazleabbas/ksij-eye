-- A diagnostic report can prescribe medicine. It goes to the pharmacy like any other
-- prescription (reviewed, billed at today's price, paid at the cashier, handed over).
-- The report remembers the prescription it sent.

ALTER TABLE prescriptions
  MODIFY COLUMN source ENUM('optometry','ophthalmology','theatre_operation','theatre_postop','diagnostic') NOT NULL;

ALTER TABLE diagnostic_results
  ADD COLUMN prescription_id BIGINT UNSIGNED NULL COMMENT 'medicine sent to the pharmacy' AFTER comments_enc,
  ADD CONSTRAINT fk_diagnostic_results_prescription FOREIGN KEY (prescription_id) REFERENCES prescriptions (id);
