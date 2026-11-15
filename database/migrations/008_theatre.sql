-- ═══════════════════════════════════════════════════════════════════════
-- 008_theatre — one surgical case from booking to discharge: the booking
-- and its bill, pre-op clearance, the operation itself, post-op visits and
-- discharge. Clinical free text is encrypted; measurements stay plain so
-- they can be reported on.
--
-- The case status lives on the booking and moves in one direction:
--   booked → cleared → in_theatre → post_op → discharged
-- with cancellation decided in the approval queue.
-- ═══════════════════════════════════════════════════════════════════════

CREATE TABLE theatre_bookings (
  id                   BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  booking_no           VARCHAR(24)     NOT NULL,
  patient_id           BIGINT UNSIGNED NOT NULL,
  visit_id             BIGINT UNSIGNED NULL,
  recommendation_id    BIGINT UNSIGNED NULL COMMENT 'the ophthalmology recommendation this came from',
  surgery_item_id      INT UNSIGNED    NULL COMMENT 'price list: the surgery; null for a procedure-only case',
  eye                  ENUM('right','left','both') NOT NULL,
  surgeon_user_id      INT UNSIGNED    NOT NULL,
  theatre_room_id      INT UNSIGNED    NULL COMMENT 'lookup theatre_room',
  scheduled_for        DATETIME        NOT NULL,
  timing               ENUM('now','later') NOT NULL DEFAULT 'now',
  status               ENUM('booked','cleared','in_theatre','post_op','discharged',
                            'cancellation_requested','cancelled') NOT NULL DEFAULT 'booked',
  total_amount         DECIMAL(12,2)   NOT NULL DEFAULT 0,
  bill_id              BIGINT UNSIGNED NULL,
  bill_deferred        TINYINT(1)      NOT NULL DEFAULT 0 COMMENT 'emergency: operate before payment',
  defer_reason_enc     VARBINARY(900)  NULL,
  deferred_by          INT UNSIGNED    NULL,
  cancel_approval_id   BIGINT UNSIGNED NULL,
  cancelled_at         DATETIME(3)     NULL,
  discharged_at        DATETIME(3)     NULL,
  discharged_by        INT UNSIGNED    NULL,
  discharge_remarks_enc VARBINARY(2100) NULL,
  row_version          INT UNSIGNED    NOT NULL DEFAULT 1,
  created_at           DATETIME(3)     NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  created_by           INT UNSIGNED    NOT NULL,
  updated_at           DATETIME(3)     NULL,
  updated_by           INT UNSIGNED    NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_theatre_bookings_no (booking_no),
  UNIQUE KEY uq_theatre_bookings_recommendation (recommendation_id),
  KEY ix_theatre_bookings_status (status, scheduled_for),
  KEY ix_theatre_bookings_patient (patient_id, scheduled_for),
  KEY ix_theatre_bookings_surgeon (surgeon_user_id, scheduled_for),
  CONSTRAINT fk_theatre_bookings_patient FOREIGN KEY (patient_id) REFERENCES patients (id),
  CONSTRAINT fk_theatre_bookings_visit FOREIGN KEY (visit_id) REFERENCES visits (id),
  CONSTRAINT fk_theatre_bookings_recommendation FOREIGN KEY (recommendation_id) REFERENCES surgery_recommendations (id),
  CONSTRAINT fk_theatre_bookings_surgery FOREIGN KEY (surgery_item_id) REFERENCES price_items (id),
  CONSTRAINT fk_theatre_bookings_room FOREIGN KEY (theatre_room_id) REFERENCES lookup_values (id),
  CONSTRAINT fk_theatre_bookings_surgeon FOREIGN KEY (surgeon_user_id) REFERENCES users (id),
  CONSTRAINT fk_theatre_bookings_bill FOREIGN KEY (bill_id) REFERENCES bills (id),
  CONSTRAINT fk_theatre_bookings_created_by FOREIGN KEY (created_by) REFERENCES users (id),
  CONSTRAINT ck_theatre_bookings_total CHECK (total_amount >= 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Procedures done alongside (or instead of) the surgery. Avastin is charged per
-- injection, so the quantity matters; the price is copied when the bill is raised.
CREATE TABLE theatre_booking_procedures (
  id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  booking_id     BIGINT UNSIGNED NOT NULL,
  price_item_id  INT UNSIGNED    NOT NULL,
  quantity       SMALLINT UNSIGNED NOT NULL DEFAULT 1,
  unit_price     DECIMAL(12,2)   NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_theatre_booking_procedures (booking_id, price_item_id),
  CONSTRAINT fk_theatre_procedures_booking FOREIGN KEY (booking_id) REFERENCES theatre_bookings (id),
  CONSTRAINT fk_theatre_procedures_item FOREIGN KEY (price_item_id) REFERENCES price_items (id),
  CONSTRAINT ck_theatre_procedures CHECK (quantity > 0 AND unit_price >= 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Lens and biometry figures for a cataract case. Measurements, so kept plain.
CREATE TABLE theatre_cataract_biometry (
  booking_id         BIGINT UNSIGNED NOT NULL,
  iol_model          VARCHAR(60)    NULL,
  iol_power          DECIMAL(5,2)   NULL,
  a_constant         DECIMAL(6,2)   NULL,
  steep_k            DECIMAL(5,2)   NULL,
  flat_k             DECIMAL(5,2)   NULL,
  axial_length       DECIMAL(5,2)   NULL COMMENT 'mm',
  corneal_thickness  SMALLINT UNSIGNED NULL COMMENT 'microns',
  acd                DECIMAL(5,2)   NULL COMMENT 'mm',
  lens_thickness     DECIMAL(5,2)   NULL COMMENT 'mm',
  white_to_white     DECIMAL(5,2)   NULL COMMENT 'mm',
  remarks_enc        VARBINARY(2100) NULL,
  updated_at         DATETIME(3)    NULL,
  updated_by         INT UNSIGNED   NULL,
  PRIMARY KEY (booking_id),
  CONSTRAINT fk_theatre_biometry_booking FOREIGN KEY (booking_id) REFERENCES theatre_bookings (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─── Pre-op ─────────────────────────────────────────────────────────────

CREATE TABLE theatre_preop_assessments (
  id                    BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  booking_id            BIGINT UNSIGNED NOT NULL,
  patient_id            BIGINT UNSIGNED NOT NULL,
  status                ENUM('draft','on_hold','cleared','cancelled') NOT NULL DEFAULT 'draft',
  va_right              VARCHAR(8)      NULL,
  va_left               VARCHAR(8)      NULL,
  iop_right             TINYINT UNSIGNED NULL COMMENT 'mmHg',
  iop_left              TINYINT UNSIGNED NULL COMMENT 'mmHg',
  bp_systolic           SMALLINT UNSIGNED NULL,
  bp_diastolic          SMALLINT UNSIGNED NULL,
  blood_sugar           DECIMAL(5,2)    NULL COMMENT 'mmol/L',
  fasting_since         DATETIME        NULL,
  icd10_code_id         INT UNSIGNED    NULL,
  findings_enc          VARBINARY(8100) NULL,
  allergies_enc         VARBINARY(2100) NULL,
  anaesthesia_type      ENUM('local','general') NULL,
  retrobulbar_notes_enc VARBINARY(2100) NULL,
  consent_given         TINYINT(1)      NOT NULL DEFAULT 0,
  consent_notes_enc     VARBINARY(2100) NULL,
  -- Safety checks before the patient goes through
  check_identity        TINYINT(1)      NOT NULL DEFAULT 0,
  check_procedure       TINYINT(1)      NOT NULL DEFAULT 0,
  check_site_marked     TINYINT(1)      NOT NULL DEFAULT 0,
  check_consent_signed  TINYINT(1)      NOT NULL DEFAULT 0,
  check_allergies       TINYINT(1)      NOT NULL DEFAULT 0,
  surgeon_note_enc      VARBINARY(2100) NULL,
  hold_reason_enc       VARBINARY(900)  NULL,
  cancel_reason_enc     VARBINARY(900)  NULL,
  cleared_at            DATETIME(3)     NULL,
  cleared_by            INT UNSIGNED    NULL,
  completed_at          DATETIME(3)     NULL COMMENT 'when cleared; starts the correction window',
  completed_by          INT UNSIGNED    NULL,
  row_version           INT UNSIGNED    NOT NULL DEFAULT 1,
  created_at            DATETIME(3)     NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  created_by            INT UNSIGNED    NOT NULL,
  updated_at            DATETIME(3)     NULL,
  updated_by            INT UNSIGNED    NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_theatre_preop_booking (booking_id),
  CONSTRAINT fk_theatre_preop_booking FOREIGN KEY (booking_id) REFERENCES theatre_bookings (id),
  CONSTRAINT fk_theatre_preop_patient FOREIGN KEY (patient_id) REFERENCES patients (id),
  CONSTRAINT fk_theatre_preop_icd FOREIGN KEY (icd10_code_id) REFERENCES icd10_codes (id),
  CONSTRAINT fk_theatre_preop_created_by FOREIGN KEY (created_by) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─── The operation ──────────────────────────────────────────────────────

CREATE TABLE theatre_operations (
  id                    BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  booking_id            BIGINT UNSIGNED NOT NULL,
  patient_id            BIGINT UNSIGNED NOT NULL,
  status                ENUM('draft','completed') NOT NULL DEFAULT 'draft',
  anaesthesia_type      ENUM('local','general') NULL,
  retrobulbar_notes_enc VARBINARY(2100) NULL,
  started_at            DATETIME        NULL COMMENT 'when surgery actually started',
  ended_at              DATETIME        NULL,
  completed_as_planned  TINYINT(1)      NULL,
  actual_procedure_enc  VARBINARY(2100) NULL,
  change_reason_enc     VARBINARY(2100) NULL,
  surgical_notes_enc    VARBINARY(16400) NULL,
  complications_enc     VARBINARY(8100) NULL,
  remarks_enc           VARBINARY(8100) NULL,
  prescription_id       BIGINT UNSIGNED NULL COMMENT 'medicine prescribed in theatre',
  completed_at          DATETIME(3)     NULL,
  completed_by          INT UNSIGNED    NULL,
  row_version           INT UNSIGNED    NOT NULL DEFAULT 1,
  created_at            DATETIME(3)     NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  created_by            INT UNSIGNED    NOT NULL,
  updated_at            DATETIME(3)     NULL,
  updated_by            INT UNSIGNED    NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_theatre_operations_booking (booking_id),
  KEY ix_theatre_operations_dates (started_at),
  CONSTRAINT fk_theatre_operations_booking FOREIGN KEY (booking_id) REFERENCES theatre_bookings (id),
  CONSTRAINT fk_theatre_operations_patient FOREIGN KEY (patient_id) REFERENCES patients (id),
  CONSTRAINT fk_theatre_operations_prescription FOREIGN KEY (prescription_id) REFERENCES prescriptions (id),
  CONSTRAINT fk_theatre_operations_created_by FOREIGN KEY (created_by) REFERENCES users (id),
  CONSTRAINT ck_theatre_operations_times CHECK (ended_at IS NULL OR started_at IS NULL OR ended_at >= started_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Who was in theatre. One row per person per role, so a report can answer
-- "which cases did this nurse scrub for".
CREATE TABLE theatre_operation_team (
  operation_id  BIGINT UNSIGNED NOT NULL,
  user_id       INT UNSIGNED    NOT NULL,
  role          ENUM('surgeon','assistant','anaesthetist','scrub_nurse','anaesthesia_nurse','runner') NOT NULL,
  PRIMARY KEY (operation_id, role, user_id),
  KEY ix_theatre_team_user (user_id),
  CONSTRAINT fk_theatre_team_operation FOREIGN KEY (operation_id) REFERENCES theatre_operations (id),
  CONSTRAINT fk_theatre_team_user FOREIGN KEY (user_id) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─── Post-op ────────────────────────────────────────────────────────────

CREATE TABLE theatre_postop_visits (
  id                     BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  booking_id             BIGINT UNSIGNED NOT NULL,
  patient_id             BIGINT UNSIGNED NOT NULL,
  visit_number           SMALLINT UNSIGNED NOT NULL,
  visit_date             DATE            NOT NULL,
  status                 ENUM('draft','completed') NOT NULL DEFAULT 'draft',
  recovery_notes_enc     VARBINARY(8100) NULL,
  va_right               VARCHAR(8)      NULL,
  va_left                VARCHAR(8)      NULL,
  iop_right              TINYINT UNSIGNED NULL,
  iop_left               TINYINT UNSIGNED NULL,
  bp_systolic            SMALLINT UNSIGNED NULL,
  bp_diastolic           SMALLINT UNSIGNED NULL,
  blood_sugar            DECIMAL(5,2)    NULL,
  pain_score             TINYINT UNSIGNED NULL COMMENT '0-10',
  wound_status           ENUM('clean','inflamed','leaking','other') NULL,
  eye_exam_enc           VARBINARY(8100) NULL,
  complications_enc      VARBINARY(8100) NULL,
  additional_findings_enc VARBINARY(8100) NULL,
  counselling_type       VARCHAR(60)     NULL,
  counselling_notes_enc  VARBINARY(8100) NULL,
  follow_up_notes_enc    VARBINARY(2100) NULL,
  follow_up_id           BIGINT UNSIGNED NULL COMMENT 'the follow-up booked at reception',
  prescription_id        BIGINT UNSIGNED NULL COMMENT 'medicine sent to the pharmacy',
  completed_at           DATETIME(3)     NULL,
  completed_by           INT UNSIGNED    NULL,
  row_version            INT UNSIGNED    NOT NULL DEFAULT 1,
  created_at             DATETIME(3)     NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  created_by             INT UNSIGNED    NOT NULL,
  updated_at             DATETIME(3)     NULL,
  updated_by             INT UNSIGNED    NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_theatre_postop_visit (booking_id, visit_number),
  KEY ix_theatre_postop_patient (patient_id, visit_date),
  CONSTRAINT fk_theatre_postop_booking FOREIGN KEY (booking_id) REFERENCES theatre_bookings (id),
  CONSTRAINT fk_theatre_postop_patient FOREIGN KEY (patient_id) REFERENCES patients (id),
  CONSTRAINT fk_theatre_postop_followup FOREIGN KEY (follow_up_id) REFERENCES follow_ups (id),
  CONSTRAINT fk_theatre_postop_prescription FOREIGN KEY (prescription_id) REFERENCES prescriptions (id),
  CONSTRAINT fk_theatre_postop_created_by FOREIGN KEY (created_by) REFERENCES users (id),
  CONSTRAINT ck_theatre_postop_pain CHECK (pain_score IS NULL OR pain_score <= 10)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─── Existing tables ────────────────────────────────────────────────────

-- Cancelling a booked operation is decided in the approval queue.
ALTER TABLE approval_requests
  MODIFY COLUMN type ENUM('discount','bill_cancellation','refund','diagnostic_cancellation',
                          'optical_cancellation','theatre_cancellation') NOT NULL,
  ADD COLUMN theatre_booking_id BIGINT UNSIGNED NULL AFTER optical_order_id,
  ADD KEY ix_approval_requests_theatre (theatre_booking_id),
  ADD CONSTRAINT fk_approval_requests_theatre FOREIGN KEY (theatre_booking_id) REFERENCES theatre_bookings (id);

ALTER TABLE theatre_bookings
  ADD CONSTRAINT fk_theatre_bookings_cancel_approval FOREIGN KEY (cancel_approval_id) REFERENCES approval_requests (id);

-- Theatre records follow the same correction and addendum rule as the rest.
ALTER TABLE clinical_addenda
  MODIFY COLUMN source ENUM('triage','optometry','ophthalmology','diagnostic',
                            'theatre_preop','theatre_operation','theatre_postop') NOT NULL;

-- A prescription can now also come out of theatre.
ALTER TABLE prescriptions
  MODIFY COLUMN source ENUM('optometry','ophthalmology','theatre_operation','theatre_postop') NOT NULL,
  MODIFY COLUMN visit_id BIGINT UNSIGNED NULL COMMENT 'theatre prescriptions may have no clinic visit';
