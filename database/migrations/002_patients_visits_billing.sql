-- ═══════════════════════════════════════════════════════════════════════
-- 002_patients_visits_billing — patients (encrypted identity), visits,
-- bills, payments, refunds and approval requests
-- Columns ending in _enc hold AES-256-GCM ciphertext; _bidx / token columns
-- hold keyed HMAC blind indexes. Money is DECIMAL(12,2), timestamps UTC.
-- ═══════════════════════════════════════════════════════════════════════

CREATE TABLE patients (
  id                 BIGINT UNSIGNED   NOT NULL AUTO_INCREMENT,
  reg_no             VARCHAR(20)       NOT NULL,
  first_name_enc     VARBINARY(400)    NOT NULL,
  middle_name_enc    VARBINARY(400)    NULL,
  last_name_enc      VARBINARY(400)    NOT NULL,
  gender             ENUM('male','female') NOT NULL,
  date_of_birth_enc  VARBINARY(100)    NOT NULL,
  birth_year         SMALLINT UNSIGNED NOT NULL COMMENT 'plain, for age-band reports',
  phone_enc          VARBINARY(100)    NOT NULL,
  address_enc        VARBINARY(1100)   NOT NULL,
  kin_name_enc       VARBINARY(700)    NULL,
  kin_relationship   VARCHAR(30)       NULL,
  kin_phone_enc      VARBINARY(100)    NULL,
  identity_bidx      BINARY(32)        NOT NULL COMMENT 'first+last name+DOB, duplicate detection',
  phone_bidx         BINARY(32)        NOT NULL COMMENT 'exact phone, duplicate detection',
  status             ENUM('active','inactive') NOT NULL DEFAULT 'active',
  row_version        INT UNSIGNED      NOT NULL DEFAULT 1,
  registered_at      DATETIME(3)       NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  registered_by      INT UNSIGNED      NOT NULL,
  updated_at         DATETIME(3)       NULL,
  updated_by         INT UNSIGNED      NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_patients_reg_no (reg_no),
  KEY ix_patients_identity (identity_bidx),
  KEY ix_patients_phone (phone_bidx),
  KEY ix_patients_registered (registered_at),
  CONSTRAINT fk_patients_registered_by FOREIGN KEY (registered_by) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE patient_search_tokens (
  token       BINARY(32)      NOT NULL,
  patient_id  BIGINT UNSIGNED NOT NULL,
  PRIMARY KEY (token, patient_id),
  KEY ix_patient_search_tokens_patient (patient_id),
  CONSTRAINT fk_patient_search_tokens_patient FOREIGN KEY (patient_id) REFERENCES patients (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE stored_files (
  id                 BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  storage_key        CHAR(64)        NOT NULL COMMENT 'random; names the encrypted file on disk',
  original_name_enc  VARBINARY(700)  NOT NULL,
  mime_type          VARCHAR(100)    NOT NULL,
  size_bytes         INT UNSIGNED    NOT NULL,
  sha256             BINARY(32)      NOT NULL COMMENT 'of the plaintext, verified on read',
  uploaded_at        DATETIME(3)     NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  uploaded_by        INT UNSIGNED    NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_stored_files_key (storage_key),
  CONSTRAINT fk_stored_files_uploaded_by FOREIGN KEY (uploaded_by) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE patient_files (
  id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  patient_id   BIGINT UNSIGNED NOT NULL,
  file_id      BIGINT UNSIGNED NOT NULL,
  category     ENUM('scanned_record','other') NOT NULL DEFAULT 'scanned_record',
  created_at   DATETIME(3)     NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  created_by   INT UNSIGNED    NOT NULL,
  voided_at    DATETIME(3)     NULL,
  voided_by    INT UNSIGNED    NULL,
  void_reason  VARCHAR(255)    NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_patient_files_file (file_id),
  KEY ix_patient_files_patient (patient_id, voided_at),
  CONSTRAINT fk_patient_files_patient FOREIGN KEY (patient_id) REFERENCES patients (id),
  CONSTRAINT fk_patient_files_file FOREIGN KEY (file_id) REFERENCES stored_files (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE visits (
  id                        BIGINT UNSIGNED   NOT NULL AUTO_INCREMENT,
  visit_no                  VARCHAR(20)       NOT NULL,
  patient_id                BIGINT UNSIGNED   NOT NULL,
  visit_date                DATE              NOT NULL COMMENT 'local (EAT) calendar date',
  visit_type                ENUM('consultation','follow_up') NOT NULL,
  sub_type                  ENUM('normal','fast_track','doctor_follow_up','surgery_follow_up') NOT NULL,
  destination               ENUM('doctor_room','triage','investigation') NOT NULL,
  room                      VARCHAR(30)       NULL,
  investigation_item_id     INT UNSIGNED      NULL,
  investigation_eye         ENUM('right','left','both') NULL,
  referring_doctor_id       INT UNSIGNED      NULL,
  investigation_reason_enc  VARBINARY(2100)   NULL,
  status                    ENUM('pending_payment','waiting_triage','waiting_doctor','waiting_investigation',
                                 'waiting_optometry','waiting_ophthalmology','in_progress','completed','cancelled') NOT NULL,
  patient_age_years         TINYINT UNSIGNED  NOT NULL,
  is_emergency              TINYINT(1)        NOT NULL DEFAULT 0,
  emergency_reason_enc      VARBINARY(1100)   NULL,
  fee_exempt                TINYINT(1)        NOT NULL DEFAULT 0,
  exemption_reason          VARCHAR(255)      NULL,
  cancelled_at              DATETIME(3)       NULL,
  cancelled_by              INT UNSIGNED      NULL,
  cancel_reason_enc         VARBINARY(2100)   NULL,
  row_version               INT UNSIGNED      NOT NULL DEFAULT 1,
  created_at                DATETIME(3)       NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  created_by                INT UNSIGNED      NOT NULL,
  updated_at                DATETIME(3)       NULL,
  updated_by                INT UNSIGNED      NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_visits_no (visit_no),
  KEY ix_visits_date_status (visit_date, status),
  KEY ix_visits_patient (patient_id, visit_date),
  CONSTRAINT fk_visits_patient FOREIGN KEY (patient_id) REFERENCES patients (id),
  CONSTRAINT fk_visits_investigation_item FOREIGN KEY (investigation_item_id) REFERENCES price_items (id),
  CONSTRAINT fk_visits_referring_doctor FOREIGN KEY (referring_doctor_id) REFERENCES users (id),
  CONSTRAINT fk_visits_created_by FOREIGN KEY (created_by) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE visit_status_history (
  id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  visit_id     BIGINT UNSIGNED NOT NULL,
  from_status  VARCHAR(30)     NULL,
  to_status    VARCHAR(30)     NOT NULL,
  changed_at   DATETIME(3)     NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  changed_by   INT UNSIGNED    NULL,
  note         VARCHAR(255)    NULL,
  PRIMARY KEY (id),
  KEY ix_visit_status_history_visit (visit_id, changed_at),
  CONSTRAINT fk_visit_status_history_visit FOREIGN KEY (visit_id) REFERENCES visits (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE bills (
  id                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  bill_no           VARCHAR(24)     NOT NULL,
  patient_id        BIGINT UNSIGNED NOT NULL,
  visit_id          BIGINT UNSIGNED NULL,
  bill_type         ENUM('consultation','investigation','optical','surgery','pharmacy','other') NOT NULL,
  source_type       VARCHAR(30)     NOT NULL DEFAULT 'visit',
  source_id         BIGINT UNSIGNED NULL,
  description       VARCHAR(255)    NOT NULL,
  status            ENUM('pending','discount_requested','cancellation_requested','paid','exempted',
                         'cancelled','refunded','partially_refunded') NOT NULL,
  gross_amount      DECIMAL(12,2)   NOT NULL,
  discount_amount   DECIMAL(12,2)   NOT NULL DEFAULT 0,
  net_amount        DECIMAL(12,2)   AS (gross_amount - discount_amount) PERSISTENT,
  paid_amount       DECIMAL(12,2)   NOT NULL DEFAULT 0,
  refunded_amount   DECIMAL(12,2)   NOT NULL DEFAULT 0,
  discount_type     VARCHAR(50)     NULL,
  discount_sponsor  VARCHAR(150)    NULL,
  exemption_reason  VARCHAR(255)    NULL,
  is_deferred       TINYINT(1)      NOT NULL DEFAULT 0 COMMENT 'emergency: service before payment',
  currency          CHAR(3)         NOT NULL DEFAULT 'TZS',
  row_version       INT UNSIGNED    NOT NULL DEFAULT 1,
  created_at        DATETIME(3)     NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  created_by        INT UNSIGNED    NOT NULL,
  updated_at        DATETIME(3)     NULL,
  updated_by        INT UNSIGNED    NULL,
  paid_at           DATETIME(3)     NULL,
  cancelled_at      DATETIME(3)     NULL,
  cancelled_by      INT UNSIGNED    NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_bills_no (bill_no),
  KEY ix_bills_status (status, created_at),
  KEY ix_bills_patient (patient_id, created_at),
  KEY ix_bills_visit (visit_id),
  CONSTRAINT fk_bills_patient FOREIGN KEY (patient_id) REFERENCES patients (id),
  CONSTRAINT fk_bills_visit FOREIGN KEY (visit_id) REFERENCES visits (id),
  CONSTRAINT fk_bills_created_by FOREIGN KEY (created_by) REFERENCES users (id),
  CONSTRAINT ck_bills_amounts CHECK (gross_amount >= 0 AND discount_amount >= 0 AND discount_amount <= gross_amount
                                     AND paid_amount >= 0 AND refunded_amount >= 0 AND refunded_amount <= paid_amount)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE bill_items (
  id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  bill_id        BIGINT UNSIGNED NOT NULL,
  price_item_id  INT UNSIGNED    NULL,
  description    VARCHAR(255)    NOT NULL,
  quantity       DECIMAL(10,2)   NOT NULL DEFAULT 1,
  unit_price     DECIMAL(12,2)   NOT NULL COMMENT 'copied from the price list when billed',
  line_total     DECIMAL(12,2)   AS (ROUND(quantity * unit_price, 2)) PERSISTENT,
  PRIMARY KEY (id),
  KEY ix_bill_items_bill (bill_id),
  CONSTRAINT fk_bill_items_bill FOREIGN KEY (bill_id) REFERENCES bills (id),
  CONSTRAINT fk_bill_items_price_item FOREIGN KEY (price_item_id) REFERENCES price_items (id),
  CONSTRAINT ck_bill_items_amounts CHECK (quantity > 0 AND unit_price >= 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE payments (
  id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  receipt_no   VARCHAR(24)     NOT NULL,
  bill_id      BIGINT UNSIGNED NOT NULL,
  patient_id   BIGINT UNSIGNED NOT NULL,
  amount       DECIMAL(12,2)   NOT NULL,
  method       ENUM('cash','lipa_number') NOT NULL,
  txn_ref      VARCHAR(60)     NULL,
  received_at  DATETIME(3)     NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  received_by  INT UNSIGNED    NOT NULL,
  voided_at    DATETIME(3)     NULL,
  voided_by    INT UNSIGNED    NULL,
  void_reason  VARCHAR(255)    NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_payments_receipt (receipt_no),
  UNIQUE KEY uq_payments_txn_ref (method, txn_ref),
  KEY ix_payments_bill (bill_id),
  KEY ix_payments_received (received_at),
  CONSTRAINT fk_payments_bill FOREIGN KEY (bill_id) REFERENCES bills (id),
  CONSTRAINT fk_payments_patient FOREIGN KEY (patient_id) REFERENCES patients (id),
  CONSTRAINT fk_payments_received_by FOREIGN KEY (received_by) REFERENCES users (id),
  CONSTRAINT ck_payments_amount CHECK (amount > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE refunds (
  id                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  refund_no         VARCHAR(24)     NOT NULL,
  bill_id           BIGINT UNSIGNED NOT NULL,
  payment_id        BIGINT UNSIGNED NOT NULL,
  patient_id        BIGINT UNSIGNED NOT NULL,
  requested_amount  DECIMAL(12,2)   NOT NULL,
  approved_amount   DECIMAL(12,2)   NULL,
  reason_enc        VARBINARY(2100) NOT NULL,
  status            ENUM('requested','approved','rejected','paid_out') NOT NULL,
  approval_id       BIGINT UNSIGNED NULL,
  requested_at      DATETIME(3)     NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  requested_by      INT UNSIGNED    NOT NULL,
  paid_out_at       DATETIME(3)     NULL,
  paid_out_by       INT UNSIGNED    NULL,
  payout_method     ENUM('cash','lipa_number') NULL,
  payout_ref        VARCHAR(60)     NULL,
  row_version       INT UNSIGNED    NOT NULL DEFAULT 1,
  PRIMARY KEY (id),
  UNIQUE KEY uq_refunds_no (refund_no),
  KEY ix_refunds_bill (bill_id, status),
  KEY ix_refunds_status (status, requested_at),
  CONSTRAINT fk_refunds_bill FOREIGN KEY (bill_id) REFERENCES bills (id),
  CONSTRAINT fk_refunds_payment FOREIGN KEY (payment_id) REFERENCES payments (id),
  CONSTRAINT fk_refunds_patient FOREIGN KEY (patient_id) REFERENCES patients (id),
  CONSTRAINT fk_refunds_requested_by FOREIGN KEY (requested_by) REFERENCES users (id),
  CONSTRAINT ck_refunds_amounts CHECK (requested_amount > 0 AND (approved_amount IS NULL OR (approved_amount > 0 AND approved_amount <= requested_amount)))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE approval_requests (
  id                    BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  approval_no           VARCHAR(24)     NOT NULL,
  type                  ENUM('discount','bill_cancellation','refund') NOT NULL,
  status                ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
  patient_id            BIGINT UNSIGNED NULL,
  bill_id               BIGINT UNSIGNED NULL,
  refund_id             BIGINT UNSIGNED NULL,
  requested_amount      DECIMAL(12,2)   NULL,
  approved_amount       DECIMAL(12,2)   NULL,
  discount_type         VARCHAR(50)     NULL,
  sponsor               VARCHAR(150)    NULL,
  request_remarks_enc   VARBINARY(2100) NULL,
  decision_remarks_enc  VARBINARY(2100) NULL,
  requested_at          DATETIME(3)     NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  requested_by          INT UNSIGNED    NOT NULL,
  decided_at            DATETIME(3)     NULL,
  decided_by            INT UNSIGNED    NULL,
  row_version           INT UNSIGNED    NOT NULL DEFAULT 1,
  PRIMARY KEY (id),
  UNIQUE KEY uq_approval_requests_no (approval_no),
  KEY ix_approval_requests_status (status, type, requested_at),
  KEY ix_approval_requests_bill (bill_id),
  CONSTRAINT fk_approval_requests_patient FOREIGN KEY (patient_id) REFERENCES patients (id),
  CONSTRAINT fk_approval_requests_bill FOREIGN KEY (bill_id) REFERENCES bills (id),
  CONSTRAINT fk_approval_requests_refund FOREIGN KEY (refund_id) REFERENCES refunds (id),
  CONSTRAINT fk_approval_requests_requested_by FOREIGN KEY (requested_by) REFERENCES users (id),
  CONSTRAINT fk_approval_requests_decided_by FOREIGN KEY (decided_by) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE approval_files (
  approval_id  BIGINT UNSIGNED NOT NULL,
  file_id      BIGINT UNSIGNED NOT NULL,
  PRIMARY KEY (approval_id, file_id),
  UNIQUE KEY uq_approval_files_file (file_id),
  CONSTRAINT fk_approval_files_approval FOREIGN KEY (approval_id) REFERENCES approval_requests (id),
  CONSTRAINT fk_approval_files_file FOREIGN KEY (file_id) REFERENCES stored_files (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE refunds
  ADD CONSTRAINT fk_refunds_approval FOREIGN KEY (approval_id) REFERENCES approval_requests (id);

ALTER TABLE record_access_log
  ADD CONSTRAINT fk_record_access_patient FOREIGN KEY (patient_id) REFERENCES patients (id);
