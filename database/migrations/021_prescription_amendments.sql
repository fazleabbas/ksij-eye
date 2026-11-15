-- UAT — an issued prescription may be changed by the doctor who issued it, within 24 hours,
-- with a reason. The RX number stays; every change keeps the before and after (encrypted)
-- and what it did at the pharmacy. Once the pharmacy has been paid or has handed medicine
-- over, the medicines it stocks can no longer change.

ALTER TABLE prescriptions
  ADD COLUMN amend_count      SMALLINT UNSIGNED NOT NULL DEFAULT 0 AFTER issued_by,
  ADD COLUMN amended_at       DATETIME(3)       NULL AFTER amend_count,
  ADD COLUMN amended_by       INT UNSIGNED      NULL AFTER amended_at,
  ADD COLUMN amend_reason_enc VARBINARY(900)    NULL COMMENT 'reason of the latest change' AFTER amended_by,
  ADD CONSTRAINT fk_prescriptions_amended_by FOREIGN KEY (amended_by) REFERENCES users (id);

CREATE TABLE prescription_amendments (
  id                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  prescription_id   BIGINT UNSIGNED NOT NULL,
  reason_enc        VARBINARY(900)  NOT NULL,
  before_enc        BLOB            NOT NULL COMMENT 'items + pharmacy instructions before the change, JSON',
  after_enc         BLOB            NOT NULL,
  pharmacy_effect   ENUM('none','order_rebuilt','bill_cancelled','order_cancelled') NOT NULL DEFAULT 'none',
  pharmacy_order_id BIGINT UNSIGNED NULL,
  cancelled_bill_id BIGINT UNSIGNED NULL,
  amended_at        DATETIME(3)     NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  amended_by        INT UNSIGNED    NOT NULL,
  PRIMARY KEY (id),
  KEY ix_prescription_amendments_rx (prescription_id, amended_at),
  CONSTRAINT fk_prescription_amendments_rx FOREIGN KEY (prescription_id) REFERENCES prescriptions (id),
  CONSTRAINT fk_prescription_amendments_by FOREIGN KEY (amended_by) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
