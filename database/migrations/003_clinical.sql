-- ═══════════════════════════════════════════════════════════════════════
-- 003_clinical — triage, optometry and ophthalmology records; clinical
-- catalogues (ICD-10 codes, examination findings, drugs); prescriptions,
-- follow-ups, investigation requests, surgery recommendations and addenda.
-- Free text is encrypted (_enc). Measurements and codes stay plain for
-- reporting — they are linked only to the internal patient id.
-- ═══════════════════════════════════════════════════════════════════════

ALTER TABLE visits
  ADD COLUMN clinical_stage     ENUM('triage','optometry','ophthalmology') NULL COMMENT 'stage being recorded while in_progress' AFTER status,
  ADD COLUMN attending_user_id  INT UNSIGNED    NULL AFTER clinical_stage,
  ADD COLUMN attending_since    DATETIME(3)     NULL AFTER attending_user_id,
  ADD COLUMN completed_at       DATETIME(3)     NULL AFTER attending_since,
  ADD COLUMN follow_up_id       BIGINT UNSIGNED NULL COMMENT 'visit booked from this follow-up' AFTER completed_at,
  ADD KEY ix_visits_stage (status, clinical_stage),
  ADD CONSTRAINT fk_visits_attending_user FOREIGN KEY (attending_user_id) REFERENCES users (id);

-- ─── Catalogues (administrator-managed) ─────────────────────────────────

CREATE TABLE icd10_codes (
  id           INT UNSIGNED      NOT NULL AUTO_INCREMENT,
  code         VARCHAR(12)       NOT NULL,
  description  VARCHAR(255)      NOT NULL,
  category     VARCHAR(80)       NULL,
  sort_order   SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  is_active    TINYINT(1)        NOT NULL DEFAULT 1,
  row_version  INT UNSIGNED      NOT NULL DEFAULT 1,
  created_at   DATETIME(3)       NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  created_by   INT UNSIGNED      NULL,
  updated_at   DATETIME(3)       NULL,
  updated_by   INT UNSIGNED      NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_icd10_codes_code (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE exam_findings (
  id           INT UNSIGNED      NOT NULL AUTO_INCREMENT,
  section      VARCHAR(30)       NOT NULL,
  label        VARCHAR(100)      NOT NULL,
  is_normal    TINYINT(1)        NOT NULL DEFAULT 0,
  sort_order   SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  is_active    TINYINT(1)        NOT NULL DEFAULT 1,
  row_version  INT UNSIGNED      NOT NULL DEFAULT 1,
  created_at   DATETIME(3)       NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  created_by   INT UNSIGNED      NULL,
  updated_at   DATETIME(3)       NULL,
  updated_by   INT UNSIGNED      NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_exam_findings (section, label)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE drugs (
  id                 INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  name               VARCHAR(150)  NOT NULL,
  category           VARCHAR(60)   NOT NULL,
  dosage_form        VARCHAR(40)   NULL,
  default_dose       VARCHAR(40)   NULL,
  default_frequency  VARCHAR(20)   NULL,
  default_duration   VARCHAR(40)   NULL,
  is_active          TINYINT(1)    NOT NULL DEFAULT 1,
  row_version        INT UNSIGNED  NOT NULL DEFAULT 1,
  created_at         DATETIME(3)   NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  created_by         INT UNSIGNED  NULL,
  updated_at         DATETIME(3)   NULL,
  updated_by         INT UNSIGNED  NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_drugs_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─── Triage ─────────────────────────────────────────────────────────────

CREATE TABLE triage_assessments (
  id                   BIGINT UNSIGNED   NOT NULL AUTO_INCREMENT,
  visit_id             BIGINT UNSIGNED   NOT NULL,
  patient_id           BIGINT UNSIGNED   NOT NULL,
  status               ENUM('draft','completed') NOT NULL DEFAULT 'draft',
  bp_systolic          SMALLINT UNSIGNED NULL,
  bp_diastolic         SMALLINT UNSIGNED NULL,
  blood_sugar_type     ENUM('fasting','random') NULL,
  blood_sugar_mmol     DECIMAL(4,1)      NULL,
  weight_kg            DECIMAL(5,1)      NULL,
  height_m             DECIMAL(3,2)      NULL,
  bmi                  DECIMAL(7,2)      AS (IF(weight_kg > 0 AND height_m > 0, ROUND(weight_kg / (height_m * height_m), 2), NULL)) PERSISTENT,
  va_unaided_right     VARCHAR(8)        NULL,
  va_unaided_left      VARCHAR(8)        NULL,
  va_aided_right       VARCHAR(8)        NULL,
  va_aided_left        VARCHAR(8)        NULL,
  iop_right            TINYINT UNSIGNED  NULL COMMENT 'mmHg',
  iop_left             TINYINT UNSIGNED  NULL COMMENT 'mmHg',
  remarks_enc          VARBINARY(4100)   NULL,
  emergency_notes_enc  VARBINARY(4100)   NULL,
  route_to             ENUM('optometry','ophthalmology') NULL,
  row_version          INT UNSIGNED      NOT NULL DEFAULT 1,
  created_at           DATETIME(3)       NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  created_by           INT UNSIGNED      NOT NULL,
  updated_at           DATETIME(3)       NULL,
  updated_by           INT UNSIGNED      NULL,
  completed_at         DATETIME(3)       NULL,
  completed_by         INT UNSIGNED      NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_triage_assessments_visit (visit_id),
  KEY ix_triage_assessments_patient (patient_id, status),
  KEY ix_triage_assessments_completed (completed_by, completed_at),
  CONSTRAINT fk_triage_assessments_visit FOREIGN KEY (visit_id) REFERENCES visits (id),
  CONSTRAINT fk_triage_assessments_patient FOREIGN KEY (patient_id) REFERENCES patients (id),
  CONSTRAINT fk_triage_assessments_created_by FOREIGN KEY (created_by) REFERENCES users (id),
  CONSTRAINT fk_triage_assessments_completed_by FOREIGN KEY (completed_by) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─── Optometry ──────────────────────────────────────────────────────────

CREATE TABLE optometry_assessments (
  id                     BIGINT UNSIGNED  NOT NULL AUTO_INCREMENT,
  visit_id               BIGINT UNSIGNED  NOT NULL,
  patient_id             BIGINT UNSIGNED  NOT NULL,
  status                 ENUM('draft','completed') NOT NULL DEFAULT 'draft',
  va_right               VARCHAR(8)       NULL,
  va_left                VARCHAR(8)       NULL,
  iop_right              TINYINT UNSIGNED NULL COMMENT 'mmHg',
  iop_left               TINYINT UNSIGNED NULL COMMENT 'mmHg',
  va_remarks_enc         VARBINARY(2100)  NULL,
  pmt_notes_enc          VARBINARY(2100)  NULL,
  pcp_enc                VARBINARY(2100)  NULL,
  anterior_segment_enc   VARBINARY(8100)  NULL,
  posterior_segment_enc  VARBINARY(8100)  NULL,
  management_plan_enc    VARBINARY(8100)  NULL,
  orthoptic_enc          VARBINARY(8100)  NULL,
  low_vision_enc         VARBINARY(8100)  NULL,
  remarks_enc            VARBINARY(8100)  NULL,
  refer_optical          TINYINT(1)       NOT NULL DEFAULT 0,
  refer_ophthalmology    TINYINT(1)       NOT NULL DEFAULT 0,
  row_version            INT UNSIGNED     NOT NULL DEFAULT 1,
  created_at             DATETIME(3)      NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  created_by             INT UNSIGNED     NOT NULL,
  updated_at             DATETIME(3)      NULL,
  updated_by             INT UNSIGNED     NULL,
  completed_at           DATETIME(3)      NULL,
  completed_by           INT UNSIGNED     NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_optometry_assessments_visit (visit_id),
  KEY ix_optometry_assessments_patient (patient_id, status),
  KEY ix_optometry_assessments_completed (completed_by, completed_at),
  KEY ix_optometry_assessments_optical (refer_optical, status),
  CONSTRAINT fk_optometry_assessments_visit FOREIGN KEY (visit_id) REFERENCES visits (id),
  CONSTRAINT fk_optometry_assessments_patient FOREIGN KEY (patient_id) REFERENCES patients (id),
  CONSTRAINT fk_optometry_assessments_created_by FOREIGN KEY (created_by) REFERENCES users (id),
  CONSTRAINT fk_optometry_assessments_completed_by FOREIGN KEY (completed_by) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE optometry_refractions (
  assessment_id  BIGINT UNSIGNED   NOT NULL,
  kind           ENUM('dry_ark','cyclo','pmt','final') NOT NULL,
  sph_right      DECIMAL(5,2)      NULL,
  cyl_right      DECIMAL(5,2)      NULL,
  axis_right     SMALLINT UNSIGNED NULL,
  cva_right      VARCHAR(8)        NULL,
  add_right      DECIMAL(4,2)      NULL,
  sph_left       DECIMAL(5,2)      NULL,
  cyl_left       DECIMAL(5,2)      NULL,
  axis_left      SMALLINT UNSIGNED NULL,
  cva_left       VARCHAR(8)        NULL,
  add_left       DECIMAL(4,2)      NULL,
  pd_mm          DECIMAL(4,1)      NULL,
  npc            VARCHAR(20)       NULL,
  PRIMARY KEY (assessment_id, kind),
  CONSTRAINT fk_optometry_refractions_assessment FOREIGN KEY (assessment_id) REFERENCES optometry_assessments (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE optometry_lens_items (
  assessment_id  BIGINT UNSIGNED NOT NULL,
  price_item_id  INT UNSIGNED    NOT NULL,
  PRIMARY KEY (assessment_id, price_item_id),
  CONSTRAINT fk_optometry_lens_items_assessment FOREIGN KEY (assessment_id) REFERENCES optometry_assessments (id),
  CONSTRAINT fk_optometry_lens_items_price_item FOREIGN KEY (price_item_id) REFERENCES price_items (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─── Ophthalmology ──────────────────────────────────────────────────────

CREATE TABLE ophthalmology_consultations (
  id                        BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  visit_id                  BIGINT UNSIGNED NOT NULL,
  patient_id                BIGINT UNSIGNED NOT NULL,
  status                    ENUM('draft','completed') NOT NULL DEFAULT 'draft',
  pupil_size_right          DECIMAL(3,1)    NULL COMMENT 'mm',
  pupil_size_left           DECIMAL(3,1)    NULL COMMENT 'mm',
  pupil_shape_right         ENUM('round','irregular','oval','keyhole') NULL,
  pupil_shape_left          ENUM('round','irregular','oval','keyhole') NULL,
  pupil_light_right         ENUM('brisk','sluggish','non_reactive','rapd') NULL,
  pupil_light_left          ENUM('brisk','sluggish','non_reactive','rapd') NULL,
  pupil_consensual_right    ENUM('present','absent') NULL,
  pupil_consensual_left     ENUM('present','absent') NULL,
  provisional_dx_notes_enc  VARBINARY(4100) NULL,
  final_dx_notes_enc        VARBINARY(4100) NULL,
  clinical_notes_enc        BLOB            NULL,
  diagrams_enc              MEDIUMBLOB      NULL COMMENT 'encrypted JSON drawing annotations',
  row_version               INT UNSIGNED    NOT NULL DEFAULT 1,
  created_at                DATETIME(3)     NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  created_by                INT UNSIGNED    NOT NULL,
  updated_at                DATETIME(3)     NULL,
  updated_by                INT UNSIGNED    NULL,
  completed_at              DATETIME(3)     NULL,
  completed_by              INT UNSIGNED    NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_ophthalmology_consultations_visit (visit_id),
  KEY ix_ophthalmology_consultations_patient (patient_id, status),
  KEY ix_ophthalmology_consultations_completed (completed_by, completed_at),
  CONSTRAINT fk_ophthalmology_consultations_visit FOREIGN KEY (visit_id) REFERENCES visits (id),
  CONSTRAINT fk_ophthalmology_consultations_patient FOREIGN KEY (patient_id) REFERENCES patients (id),
  CONSTRAINT fk_ophthalmology_consultations_created_by FOREIGN KEY (created_by) REFERENCES users (id),
  CONSTRAINT fk_ophthalmology_consultations_completed_by FOREIGN KEY (completed_by) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE ophthalmology_findings (
  consultation_id  BIGINT UNSIGNED NOT NULL,
  finding_id       INT UNSIGNED    NOT NULL,
  eye              ENUM('right','left','both') NOT NULL,
  PRIMARY KEY (consultation_id, finding_id, eye),
  KEY ix_ophthalmology_findings_finding (finding_id),
  CONSTRAINT fk_ophthalmology_findings_consultation FOREIGN KEY (consultation_id) REFERENCES ophthalmology_consultations (id),
  CONSTRAINT fk_ophthalmology_findings_finding FOREIGN KEY (finding_id) REFERENCES exam_findings (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE ophthalmology_section_notes (
  consultation_id  BIGINT UNSIGNED NOT NULL,
  section          VARCHAR(30)     NOT NULL,
  note_enc         VARBINARY(900)  NOT NULL,
  PRIMARY KEY (consultation_id, section),
  CONSTRAINT fk_ophthalmology_section_notes_consultation FOREIGN KEY (consultation_id) REFERENCES ophthalmology_consultations (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─── Diagnoses, prescriptions, follow-ups, orders ───────────────────────

CREATE TABLE clinical_diagnoses (
  id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  patient_id      BIGINT UNSIGNED NOT NULL,
  visit_id        BIGINT UNSIGNED NOT NULL,
  source          ENUM('optometry','ophthalmology') NOT NULL,
  record_id       BIGINT UNSIGNED NOT NULL,
  kind            ENUM('provisional','final') NOT NULL,
  icd10_code_id   INT UNSIGNED    NOT NULL,
  created_at      DATETIME(3)     NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  created_by      INT UNSIGNED    NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_clinical_diagnoses (source, record_id, kind, icd10_code_id),
  KEY ix_clinical_diagnoses_code (icd10_code_id, created_at),
  KEY ix_clinical_diagnoses_patient (patient_id),
  CONSTRAINT fk_clinical_diagnoses_patient FOREIGN KEY (patient_id) REFERENCES patients (id),
  CONSTRAINT fk_clinical_diagnoses_visit FOREIGN KEY (visit_id) REFERENCES visits (id),
  CONSTRAINT fk_clinical_diagnoses_code FOREIGN KEY (icd10_code_id) REFERENCES icd10_codes (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE prescriptions (
  id                         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  prescription_no            VARCHAR(24)     NULL COMMENT 'assigned when issued',
  patient_id                 BIGINT UNSIGNED NOT NULL,
  visit_id                   BIGINT UNSIGNED NOT NULL,
  source                     ENUM('optometry','ophthalmology') NOT NULL,
  record_id                  BIGINT UNSIGNED NOT NULL,
  status                     ENUM('draft','issued','cancelled') NOT NULL DEFAULT 'draft',
  pharmacy_instructions_enc  VARBINARY(2100) NULL,
  issued_at                  DATETIME(3)     NULL,
  issued_by                  INT UNSIGNED    NULL,
  row_version                INT UNSIGNED    NOT NULL DEFAULT 1,
  created_at                 DATETIME(3)     NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  created_by                 INT UNSIGNED    NOT NULL,
  updated_at                 DATETIME(3)     NULL,
  updated_by                 INT UNSIGNED    NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_prescriptions_no (prescription_no),
  UNIQUE KEY uq_prescriptions_source (source, record_id),
  KEY ix_prescriptions_status (status, issued_at),
  KEY ix_prescriptions_patient (patient_id),
  CONSTRAINT fk_prescriptions_patient FOREIGN KEY (patient_id) REFERENCES patients (id),
  CONSTRAINT fk_prescriptions_visit FOREIGN KEY (visit_id) REFERENCES visits (id),
  CONSTRAINT fk_prescriptions_issued_by FOREIGN KEY (issued_by) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE prescription_items (
  id                BIGINT UNSIGNED   NOT NULL AUTO_INCREMENT,
  prescription_id   BIGINT UNSIGNED   NOT NULL,
  drug_id           INT UNSIGNED      NOT NULL,
  quantity          SMALLINT UNSIGNED NOT NULL DEFAULT 1,
  dose              VARCHAR(40)       NULL,
  frequency         VARCHAR(20)       NULL,
  duration          VARCHAR(40)       NULL,
  instructions_enc  VARBINARY(900)    NULL,
  sort_order        SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (id),
  KEY ix_prescription_items_prescription (prescription_id),
  CONSTRAINT fk_prescription_items_prescription FOREIGN KEY (prescription_id) REFERENCES prescriptions (id),
  CONSTRAINT fk_prescription_items_drug FOREIGN KEY (drug_id) REFERENCES drugs (id),
  CONSTRAINT ck_prescription_items_quantity CHECK (quantity > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE follow_ups (
  id                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  patient_id          BIGINT UNSIGNED NOT NULL,
  source_visit_id     BIGINT UNSIGNED NOT NULL,
  source              ENUM('optometry','ophthalmology') NOT NULL,
  record_id           BIGINT UNSIGNED NOT NULL,
  due_date            DATE            NULL,
  priority            ENUM('routine','important','urgent') NOT NULL DEFAULT 'routine',
  reason              VARCHAR(100)    NULL,
  notes_enc           VARBINARY(2100) NULL,
  status              ENUM('draft','due','booked','cancelled') NOT NULL DEFAULT 'draft',
  booked_visit_id     BIGINT UNSIGNED NULL,
  cancel_reason_enc   VARBINARY(2100) NULL,
  row_version         INT UNSIGNED    NOT NULL DEFAULT 1,
  created_at          DATETIME(3)     NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  created_by          INT UNSIGNED    NOT NULL,
  updated_at          DATETIME(3)     NULL,
  updated_by          INT UNSIGNED    NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_follow_ups_source (source, record_id),
  KEY ix_follow_ups_status (status, due_date),
  KEY ix_follow_ups_patient (patient_id),
  CONSTRAINT fk_follow_ups_patient FOREIGN KEY (patient_id) REFERENCES patients (id),
  CONSTRAINT fk_follow_ups_source_visit FOREIGN KEY (source_visit_id) REFERENCES visits (id),
  CONSTRAINT fk_follow_ups_booked_visit FOREIGN KEY (booked_visit_id) REFERENCES visits (id),
  CONSTRAINT fk_follow_ups_created_by FOREIGN KEY (created_by) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE visits
  ADD CONSTRAINT fk_visits_follow_up FOREIGN KEY (follow_up_id) REFERENCES follow_ups (id);

CREATE TABLE investigation_requests (
  id               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  request_no       VARCHAR(24)     NULL COMMENT 'assigned when submitted',
  patient_id       BIGINT UNSIGNED NOT NULL,
  visit_id         BIGINT UNSIGNED NOT NULL,
  consultation_id  BIGINT UNSIGNED NOT NULL,
  price_item_id    INT UNSIGNED    NOT NULL,
  eye              ENUM('right','left','both') NOT NULL,
  priority         ENUM('routine','urgent') NOT NULL DEFAULT 'routine',
  preferred_date   DATE            NULL,
  reason_enc       VARBINARY(2100) NOT NULL,
  status           ENUM('draft','awaiting_payment','paid','cancelled') NOT NULL DEFAULT 'draft',
  bill_id          BIGINT UNSIGNED NULL,
  requested_at     DATETIME(3)     NULL,
  requested_by     INT UNSIGNED    NULL,
  row_version      INT UNSIGNED    NOT NULL DEFAULT 1,
  created_at       DATETIME(3)     NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  created_by       INT UNSIGNED    NOT NULL,
  updated_at       DATETIME(3)     NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_investigation_requests_no (request_no),
  KEY ix_investigation_requests_status (status, requested_at),
  KEY ix_investigation_requests_consultation (consultation_id),
  KEY ix_investigation_requests_patient (patient_id),
  CONSTRAINT fk_investigation_requests_patient FOREIGN KEY (patient_id) REFERENCES patients (id),
  CONSTRAINT fk_investigation_requests_visit FOREIGN KEY (visit_id) REFERENCES visits (id),
  CONSTRAINT fk_investigation_requests_consultation FOREIGN KEY (consultation_id) REFERENCES ophthalmology_consultations (id),
  CONSTRAINT fk_investigation_requests_price_item FOREIGN KEY (price_item_id) REFERENCES price_items (id),
  CONSTRAINT fk_investigation_requests_bill FOREIGN KEY (bill_id) REFERENCES bills (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE surgery_recommendations (
  id                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  recommendation_no   VARCHAR(24)     NULL COMMENT 'assigned when submitted',
  patient_id          BIGINT UNSIGNED NOT NULL,
  visit_id            BIGINT UNSIGNED NOT NULL,
  consultation_id     BIGINT UNSIGNED NOT NULL,
  price_item_id       INT UNSIGNED    NOT NULL,
  eye                 ENUM('right','left','both') NOT NULL,
  urgency             ENUM('elective','urgent','emergency') NOT NULL DEFAULT 'elective',
  indication_enc      VARBINARY(2100) NOT NULL,
  status              ENUM('draft','recommended','booked','cancelled') NOT NULL DEFAULT 'draft',
  recommended_at      DATETIME(3)     NULL,
  recommended_by      INT UNSIGNED    NULL,
  row_version         INT UNSIGNED    NOT NULL DEFAULT 1,
  created_at          DATETIME(3)     NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  created_by          INT UNSIGNED    NOT NULL,
  updated_at          DATETIME(3)     NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_surgery_recommendations_no (recommendation_no),
  KEY ix_surgery_recommendations_status (status, recommended_at),
  KEY ix_surgery_recommendations_consultation (consultation_id),
  KEY ix_surgery_recommendations_patient (patient_id),
  CONSTRAINT fk_surgery_recommendations_patient FOREIGN KEY (patient_id) REFERENCES patients (id),
  CONSTRAINT fk_surgery_recommendations_visit FOREIGN KEY (visit_id) REFERENCES visits (id),
  CONSTRAINT fk_surgery_recommendations_consultation FOREIGN KEY (consultation_id) REFERENCES ophthalmology_consultations (id),
  CONSTRAINT fk_surgery_recommendations_price_item FOREIGN KEY (price_item_id) REFERENCES price_items (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Corrections after the edit window are added as addenda; the original is never changed.
CREATE TABLE clinical_addenda (
  id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  patient_id  BIGINT UNSIGNED NOT NULL,
  visit_id    BIGINT UNSIGNED NOT NULL,
  source      ENUM('triage','optometry','ophthalmology') NOT NULL,
  record_id   BIGINT UNSIGNED NOT NULL,
  reason_enc  VARBINARY(900)  NOT NULL,
  note_enc    VARBINARY(8100) NOT NULL,
  created_at  DATETIME(3)     NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  created_by  INT UNSIGNED    NOT NULL,
  PRIMARY KEY (id),
  KEY ix_clinical_addenda_record (source, record_id),
  CONSTRAINT fk_clinical_addenda_patient FOREIGN KEY (patient_id) REFERENCES patients (id),
  CONSTRAINT fk_clinical_addenda_visit FOREIGN KEY (visit_id) REFERENCES visits (id),
  CONSTRAINT fk_clinical_addenda_created_by FOREIGN KEY (created_by) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
