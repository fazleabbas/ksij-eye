-- A counter sale to a walk-in customer has no patient, so its refund cannot name one.
-- A refund now belongs to a patient or to the walk-in the bill was raised for, like the
-- bill and its payment already do.

ALTER TABLE refunds
  MODIFY COLUMN patient_id BIGINT UNSIGNED NULL,
  ADD COLUMN walkin_id BIGINT UNSIGNED NULL COMMENT 'pharmacy walk-in, when the bill has no patient' AFTER patient_id,
  ADD CONSTRAINT fk_refunds_walkin FOREIGN KEY (walkin_id) REFERENCES pharmacy_walkins (id);
