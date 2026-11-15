-- ═══════════════════════════════════════════════════════════════════════
-- 006_pharmacy — dispensing medicine against an issued prescription, and
-- selling to walk-in customers. Stock is a running balance with every
-- change recorded; the pharmacy reviews an order, the cashier takes the
-- money, and the medicine is then handed over in full or in part.
-- Refills repeat a completed order free of charge, as the clinic does today.
-- ═══════════════════════════════════════════════════════════════════════

-- Medicines need a price. It is administrator-managed, like every other price,
-- and copied onto the order when the pharmacy reviews it.
ALTER TABLE drugs
  ADD COLUMN unit_price DECIMAL(12,2) NOT NULL DEFAULT 0 COMMENT 'price per unit, administrator-managed' AFTER default_duration,
  ADD CONSTRAINT ck_drugs_price CHECK (unit_price >= 0);

-- ─── Stock ──────────────────────────────────────────────────────────────

CREATE TABLE pharmacy_stock (
  drug_id        INT UNSIGNED  NOT NULL,
  quantity       INT           NOT NULL DEFAULT 0,
  reorder_level  INT UNSIGNED  NOT NULL DEFAULT 0,
  expiry_date    DATE          NULL COMMENT 'earliest expiry held',
  row_version    INT UNSIGNED  NOT NULL DEFAULT 1,
  updated_at     DATETIME(3)   NULL,
  updated_by     INT UNSIGNED  NULL,
  PRIMARY KEY (drug_id),
  KEY ix_pharmacy_stock_expiry (expiry_date),
  CONSTRAINT fk_pharmacy_stock_drug FOREIGN KEY (drug_id) REFERENCES drugs (id),
  CONSTRAINT ck_pharmacy_stock_quantity CHECK (quantity >= 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Append-only: every movement explains how the balance got where it is.
CREATE TABLE pharmacy_stock_movements (
  id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  drug_id        INT UNSIGNED    NOT NULL,
  change_qty     INT             NOT NULL COMMENT 'positive in, negative out',
  balance_after  INT             NOT NULL,
  reason         ENUM('received','dispensed','adjusted','returned') NOT NULL,
  note           VARCHAR(190)    NULL,
  order_id       BIGINT UNSIGNED NULL,
  created_at     DATETIME(3)     NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  created_by     INT UNSIGNED    NOT NULL,
  PRIMARY KEY (id),
  KEY ix_pharmacy_movements_drug (drug_id, created_at),
  CONSTRAINT fk_pharmacy_movements_drug FOREIGN KEY (drug_id) REFERENCES drugs (id),
  CONSTRAINT fk_pharmacy_movements_created_by FOREIGN KEY (created_by) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─── Walk-in customers ──────────────────────────────────────────────────

-- Someone buying medicine without being a hospital patient. Kept apart from
-- patients on purpose; their details are encrypted like any other person's.
CREATE TABLE pharmacy_walkins (
  id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  walkin_no   VARCHAR(24)     NOT NULL,
  name_enc    VARBINARY(400)  NOT NULL,
  phone_enc   VARBINARY(200)  NOT NULL,
  gender      ENUM('male','female') NULL,
  age_years   TINYINT UNSIGNED NULL,
  created_at  DATETIME(3)     NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  created_by  INT UNSIGNED    NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_pharmacy_walkins_no (walkin_no),
  CONSTRAINT fk_pharmacy_walkins_created_by FOREIGN KEY (created_by) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─── Orders ─────────────────────────────────────────────────────────────

CREATE TABLE pharmacy_orders (
  id                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  order_no          VARCHAR(24)     NOT NULL,
  source            ENUM('prescription','walk_in') NOT NULL,
  prescription_id   BIGINT UNSIGNED NULL,
  patient_id        BIGINT UNSIGNED NULL COMMENT 'hospital patient; null for a walk-in',
  walkin_id         BIGINT UNSIGNED NULL,
  visit_id          BIGINT UNSIGNED NULL,
  status            ENUM('pending_review','awaiting_payment','paid','partially_dispensed','completed','cancelled')
                    NOT NULL DEFAULT 'pending_review',
  bill_id           BIGINT UNSIGNED NULL,
  total_amount      DECIMAL(12,2)   NOT NULL DEFAULT 0,
  refill_count      INT UNSIGNED    NOT NULL DEFAULT 0,
  reviewed_at       DATETIME(3)     NULL,
  reviewed_by       INT UNSIGNED    NULL,
  completed_at      DATETIME(3)     NULL,
  cancel_reason_enc VARBINARY(900)  NULL,
  cancelled_at      DATETIME(3)     NULL,
  cancelled_by      INT UNSIGNED    NULL,
  refund_id         BIGINT UNSIGNED NULL COMMENT 'raised when a paid remainder is cancelled',
  row_version       INT UNSIGNED    NOT NULL DEFAULT 1,
  created_at        DATETIME(3)     NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  created_by        INT UNSIGNED    NOT NULL,
  updated_at        DATETIME(3)     NULL,
  updated_by        INT UNSIGNED    NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_pharmacy_orders_no (order_no),
  UNIQUE KEY uq_pharmacy_orders_prescription (prescription_id),
  KEY ix_pharmacy_orders_status (status, created_at),
  KEY ix_pharmacy_orders_patient (patient_id, created_at),
  KEY ix_pharmacy_orders_bill (bill_id),
  CONSTRAINT fk_pharmacy_orders_prescription FOREIGN KEY (prescription_id) REFERENCES prescriptions (id),
  CONSTRAINT fk_pharmacy_orders_patient FOREIGN KEY (patient_id) REFERENCES patients (id),
  CONSTRAINT fk_pharmacy_orders_walkin FOREIGN KEY (walkin_id) REFERENCES pharmacy_walkins (id),
  CONSTRAINT fk_pharmacy_orders_visit FOREIGN KEY (visit_id) REFERENCES visits (id),
  CONSTRAINT fk_pharmacy_orders_bill FOREIGN KEY (bill_id) REFERENCES bills (id),
  CONSTRAINT fk_pharmacy_orders_refund FOREIGN KEY (refund_id) REFERENCES refunds (id),
  CONSTRAINT fk_pharmacy_orders_created_by FOREIGN KEY (created_by) REFERENCES users (id),
  CONSTRAINT ck_pharmacy_orders_total CHECK (total_amount >= 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- What the patient is charged for and what has been handed over so far.
-- catalogue_price keeps the list price beside the price actually charged, so a
-- counter price change can always be seen.
CREATE TABLE pharmacy_order_items (
  id                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  order_id          BIGINT UNSIGNED NOT NULL,
  drug_id           INT UNSIGNED    NOT NULL,
  description       VARCHAR(190)    NOT NULL,
  dose              VARCHAR(60)     NULL,
  frequency         VARCHAR(40)     NULL,
  duration          VARCHAR(40)     NULL,
  instructions_enc  VARBINARY(900)  NULL,
  quantity          INT UNSIGNED    NOT NULL,
  dispensed_qty     INT UNSIGNED    NOT NULL DEFAULT 0,
  unit_price        DECIMAL(12,2)   NOT NULL,
  catalogue_price   DECIMAL(12,2)   NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_pharmacy_order_items (order_id, drug_id),
  CONSTRAINT fk_pharmacy_order_items_order FOREIGN KEY (order_id) REFERENCES pharmacy_orders (id),
  CONSTRAINT fk_pharmacy_order_items_drug FOREIGN KEY (drug_id) REFERENCES drugs (id),
  CONSTRAINT ck_pharmacy_order_items CHECK (quantity > 0 AND dispensed_qty >= 0 AND unit_price >= 0 AND catalogue_price >= 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─── Hand-overs ─────────────────────────────────────────────────────────

CREATE TABLE pharmacy_dispensings (
  id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  dispensing_no  VARCHAR(24)     NOT NULL,
  order_id       BIGINT UNSIGNED NOT NULL,
  kind           ENUM('dispense','refill') NOT NULL DEFAULT 'dispense',
  notes_enc      VARBINARY(900)  NULL,
  dispensed_at   DATETIME(3)     NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  dispensed_by   INT UNSIGNED    NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_pharmacy_dispensings_no (dispensing_no),
  KEY ix_pharmacy_dispensings_order (order_id, dispensed_at),
  CONSTRAINT fk_pharmacy_dispensings_order FOREIGN KEY (order_id) REFERENCES pharmacy_orders (id),
  CONSTRAINT fk_pharmacy_dispensings_by FOREIGN KEY (dispensed_by) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE pharmacy_dispensing_items (
  dispensing_id  BIGINT UNSIGNED NOT NULL,
  drug_id        INT UNSIGNED    NOT NULL,
  quantity       INT UNSIGNED    NOT NULL,
  PRIMARY KEY (dispensing_id, drug_id),
  CONSTRAINT fk_pharmacy_dispensing_items_dispensing FOREIGN KEY (dispensing_id) REFERENCES pharmacy_dispensings (id),
  CONSTRAINT fk_pharmacy_dispensing_items_drug FOREIGN KEY (drug_id) REFERENCES drugs (id),
  CONSTRAINT ck_pharmacy_dispensing_items CHECK (quantity > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE pharmacy_stock_movements
  ADD CONSTRAINT fk_pharmacy_movements_order FOREIGN KEY (order_id) REFERENCES pharmacy_orders (id);
