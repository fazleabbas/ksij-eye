-- The Optical Room may change the lens Optometry chose (the patient wants another lens type):
-- on the confirm page, or on an order not yet sent to the supplier whose bill is unpaid.
-- The prescription values and the Optometry record never change; every change is kept here.

CREATE TABLE optical_lens_changes (
  id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  order_id     BIGINT UNSIGNED NOT NULL,
  set_no       SMALLINT UNSIGNED NULL COMMENT 'which prescription (pair); null on an external sale',
  rx_label     VARCHAR(60)     NULL,
  stage        ENUM('confirm','order') NOT NULL COMMENT 'changed while confirming, or on the order afterwards',
  from_lenses  VARCHAR(500)    NOT NULL,
  to_lenses    VARCHAR(500)    NOT NULL,
  from_amount  DECIMAL(12,2)   NOT NULL,
  to_amount    DECIMAL(12,2)   NOT NULL,
  reason_enc   VARBINARY(1100) NOT NULL,
  old_bill_id  BIGINT UNSIGNED NULL COMMENT 'unpaid bill cancelled and raised again',
  changed_at   DATETIME(3)     NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  changed_by   INT UNSIGNED    NOT NULL,
  PRIMARY KEY (id),
  KEY ix_optical_lens_changes_order (order_id, changed_at),
  CONSTRAINT fk_optical_lens_changes_order FOREIGN KEY (order_id) REFERENCES optical_orders (id),
  CONSTRAINT fk_optical_lens_changes_bill FOREIGN KEY (old_bill_id) REFERENCES bills (id),
  CONSTRAINT fk_optical_lens_changes_by FOREIGN KEY (changed_by) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
