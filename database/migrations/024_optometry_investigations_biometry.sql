-- Optometry may request investigations like the doctor (booked at Diagnostics, billed at
-- check-in) and records cataract biometry per eye; Theatre fills a new cataract booking from
-- the patient's latest biometry for that eye.

ALTER TABLE investigation_requests
  ADD COLUMN source        ENUM('ophthalmology','optometry') NOT NULL DEFAULT 'ophthalmology' AFTER visit_id,
  ADD COLUMN assessment_id BIGINT UNSIGNED NULL COMMENT 'optometry assessment that requested it' AFTER consultation_id,
  MODIFY COLUMN consultation_id BIGINT UNSIGNED NULL,
  ADD KEY ix_investigation_requests_assessment (assessment_id),
  ADD CONSTRAINT fk_investigation_requests_assessment FOREIGN KEY (assessment_id) REFERENCES optometry_assessments (id),
  ADD CONSTRAINT ck_investigation_requests_record CHECK ((consultation_id IS NULL) <> (assessment_id IS NULL));

ALTER TABLE diagnostic_bookings
  MODIFY COLUMN source ENUM('desk','ophthalmology','visit','optometry') NOT NULL DEFAULT 'desk';

-- Measurements are kept plain (like theatre_cataract_biometry); remarks are encrypted.
CREATE TABLE optometry_biometry (
  assessment_id      BIGINT UNSIGNED NOT NULL,
  eye                ENUM('right','left') NOT NULL,
  axial_length       DECIMAL(5,2)    NULL COMMENT 'mm',
  steep_k            DECIMAL(5,2)    NULL COMMENT 'K1, dioptres',
  flat_k             DECIMAL(5,2)    NULL COMMENT 'K2, dioptres',
  acd                DECIMAL(5,2)    NULL COMMENT 'mm',
  corneal_thickness  SMALLINT UNSIGNED NULL COMMENT 'microns',
  lens_thickness     DECIMAL(5,2)    NULL COMMENT 'mm',
  white_to_white     DECIMAL(5,2)    NULL COMMENT 'mm',
  a_constant         DECIMAL(6,2)    NULL,
  iol_model          VARCHAR(60)     NULL,
  iol_power          DECIMAL(5,2)    NULL COMMENT 'dioptres',
  remarks_enc        VARBINARY(1100) NULL,
  PRIMARY KEY (assessment_id, eye),
  CONSTRAINT fk_optometry_biometry_assessment FOREIGN KEY (assessment_id) REFERENCES optometry_assessments (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
