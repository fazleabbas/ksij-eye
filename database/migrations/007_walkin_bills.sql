-- ═══════════════════════════════════════════════════════════════════════
-- 007_walkin_bills — a pharmacy walk-in buys medicine without being a
-- hospital patient, but still pays at the cashier. A bill therefore belongs
-- to a patient or to a walk-in, and exactly one of the two.
-- ═══════════════════════════════════════════════════════════════════════

ALTER TABLE bills
  MODIFY COLUMN patient_id BIGINT UNSIGNED NULL,
  ADD COLUMN walkin_id BIGINT UNSIGNED NULL COMMENT 'pharmacy walk-in customer' AFTER patient_id,
  ADD KEY ix_bills_walkin (walkin_id),
  ADD CONSTRAINT fk_bills_walkin FOREIGN KEY (walkin_id) REFERENCES pharmacy_walkins (id),
  ADD CONSTRAINT ck_bills_owner CHECK ((patient_id IS NULL) <> (walkin_id IS NULL));

-- A receipt, likewise, belongs to a patient or to a walk-in.
ALTER TABLE payments
  MODIFY COLUMN patient_id BIGINT UNSIGNED NULL,
  ADD COLUMN walkin_id BIGINT UNSIGNED NULL AFTER patient_id,
  ADD KEY ix_payments_walkin (walkin_id),
  ADD CONSTRAINT fk_payments_walkin FOREIGN KEY (walkin_id) REFERENCES pharmacy_walkins (id),
  ADD CONSTRAINT ck_payments_owner CHECK ((patient_id IS NULL) <> (walkin_id IS NULL));
