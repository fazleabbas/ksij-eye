-- ═══════════════════════════════════════════════════════════════════════
-- 005_optical — spectacle orders from optometry referrals: the lens the
-- optometrist chose (price copied at order time), supplier and production
-- tracking, and dispensing once the bill is settled.
-- Discounts and cancellations are decided in the approval queue.
-- ═══════════════════════════════════════════════════════════════════════

CREATE TABLE optical_orders (
  id                   BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  order_no             VARCHAR(24)     NOT NULL,
  patient_id           BIGINT UNSIGNED NOT NULL,
  visit_id             BIGINT UNSIGNED NOT NULL,
  assessment_id        BIGINT UNSIGNED NOT NULL COMMENT 'the optometry assessment that referred the patient',
  status               ENUM('ordered','sent_to_supplier','lens_received','ready_for_dispensing',
                            'dispensed','cancellation_requested','cancelled') NOT NULL DEFAULT 'ordered',
  bill_id              BIGINT UNSIGNED NULL,
  supplier_id          INT UNSIGNED    NULL COMMENT 'lookup optical_supplier',
  supplier_order_date  DATE            NULL,
  supplier_ref         VARCHAR(190)    NULL COMMENT 'supplier order reference, not clinical',
  lens_received_date   DATE            NULL,
  ready_date           DATE            NULL,
  dispensed_at         DATETIME(3)     NULL,
  dispensed_by         INT UNSIGNED    NULL,
  cancel_approval_id   BIGINT UNSIGNED NULL,
  cancelled_at         DATETIME(3)     NULL,
  total_amount         DECIMAL(12,2)   NOT NULL DEFAULT 0 COMMENT 'sum of the items, locked at order time',
  row_version          INT UNSIGNED    NOT NULL DEFAULT 1,
  created_at           DATETIME(3)     NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  created_by           INT UNSIGNED    NOT NULL,
  updated_at           DATETIME(3)     NULL,
  updated_by           INT UNSIGNED    NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_optical_orders_no (order_no),
  UNIQUE KEY uq_optical_orders_assessment (assessment_id),
  KEY ix_optical_orders_status (status, created_at),
  KEY ix_optical_orders_patient (patient_id, created_at),
  KEY ix_optical_orders_bill (bill_id),
  CONSTRAINT fk_optical_orders_patient FOREIGN KEY (patient_id) REFERENCES patients (id),
  CONSTRAINT fk_optical_orders_visit FOREIGN KEY (visit_id) REFERENCES visits (id),
  CONSTRAINT fk_optical_orders_assessment FOREIGN KEY (assessment_id) REFERENCES optometry_assessments (id),
  CONSTRAINT fk_optical_orders_supplier FOREIGN KEY (supplier_id) REFERENCES lookup_values (id),
  CONSTRAINT fk_optical_orders_bill FOREIGN KEY (bill_id) REFERENCES bills (id),
  CONSTRAINT fk_optical_orders_dispensed_by FOREIGN KEY (dispensed_by) REFERENCES users (id),
  CONSTRAINT fk_optical_orders_created_by FOREIGN KEY (created_by) REFERENCES users (id),
  CONSTRAINT ck_optical_orders_total CHECK (total_amount >= 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- The lens the optometrist chose. The price is copied here so a later price
-- change never alters an order that has already been raised.
CREATE TABLE optical_order_items (
  id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  order_id       BIGINT UNSIGNED NOT NULL,
  price_item_id  INT UNSIGNED    NOT NULL,
  description    VARCHAR(190)    NOT NULL,
  unit_price     DECIMAL(12,2)   NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_optical_order_items (order_id, price_item_id),
  CONSTRAINT fk_optical_order_items_order FOREIGN KEY (order_id) REFERENCES optical_orders (id),
  CONSTRAINT fk_optical_order_items_price_item FOREIGN KEY (price_item_id) REFERENCES price_items (id),
  CONSTRAINT ck_optical_order_items_price CHECK (unit_price >= 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Cancelling a spectacle order is decided in the approval queue.
ALTER TABLE approval_requests
  MODIFY COLUMN type ENUM('discount','bill_cancellation','refund','diagnostic_cancellation','optical_cancellation') NOT NULL,
  ADD COLUMN optical_order_id BIGINT UNSIGNED NULL AFTER booking_id,
  ADD KEY ix_approval_requests_optical (optical_order_id),
  ADD CONSTRAINT fk_approval_requests_optical FOREIGN KEY (optical_order_id) REFERENCES optical_orders (id);

ALTER TABLE optical_orders
  ADD CONSTRAINT fk_optical_orders_cancel_approval FOREIGN KEY (cancel_approval_id) REFERENCES approval_requests (id);
