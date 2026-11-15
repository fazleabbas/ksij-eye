-- UAT round 4 (live testing).
-- 1. Reception may send a patient straight to Optometry (children skip Triage), into a room
--    chosen from the administrator's list "Optometry rooms" (seeded by the seed step).
-- 2. The doctor gets a Clinical History box at the top of the consultation and may refer the
--    patient to Optometry for spectacles; the visit then goes to the Optometry queue, and an
--    optometry record already completed in the same visit is reopened for the optometrist.

ALTER TABLE visits
  MODIFY COLUMN destination ENUM('doctor_room','triage','investigation','optometry') NOT NULL;

ALTER TABLE ophthalmology_consultations
  ADD COLUMN clinical_history_enc BLOB       NULL AFTER pupil_consensual_left,
  ADD COLUMN refer_optometry      TINYINT(1) NOT NULL DEFAULT 0 AFTER referral_facility_enc;

ALTER TABLE optometry_assessments
  ADD COLUMN reopened_at DATETIME(3)  NULL COMMENT 'last time a doctor sent the patient back for spectacles',
  ADD COLUMN reopened_by INT UNSIGNED NULL,
  ADD CONSTRAINT fk_optometry_assessments_reopened_by FOREIGN KEY (reopened_by) REFERENCES users (id);
