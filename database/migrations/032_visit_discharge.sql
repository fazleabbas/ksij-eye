-- UAT: discharge (End Visit) and re-admission.
-- 1. A visit is discharged automatically once every service booked for the day is done: the
--    clinical stages, tests booked for today, medicines to collect and spectacles to order. When
--    the clinician finishes but something is still outstanding, the visit waits in
--    'awaiting_services' and is discharged ('completed') when the last of them is done.
-- 2. Reception may end (discharge) an open visit by hand, with a reason — e.g. the patient left.
-- 3. Reception may re-admit a discharged visit: the same visit goes back to Triage, Optometry
--    or the Doctor without a new fee, and that stage's completed record reopens as a draft.

ALTER TABLE visits
  MODIFY COLUMN status ENUM('pending_payment','waiting_triage','waiting_doctor','waiting_investigation',
                            'waiting_optometry','waiting_ophthalmology','in_progress','awaiting_services',
                            'completed','cancelled') NOT NULL,
  ADD COLUMN ended_by          INT UNSIGNED    NULL COMMENT 'set when reception ended the visit by hand' AFTER completed_at,
  ADD COLUMN end_reason_enc    VARBINARY(2100) NULL AFTER ended_by,
  ADD COLUMN readmit_count     INT UNSIGNED    NOT NULL DEFAULT 0 AFTER end_reason_enc,
  ADD COLUMN readmitted_at     DATETIME(3)     NULL AFTER readmit_count,
  ADD COLUMN readmitted_by     INT UNSIGNED    NULL AFTER readmitted_at,
  ADD COLUMN readmit_reason_enc VARBINARY(2100) NULL AFTER readmitted_by,
  ADD CONSTRAINT fk_visits_ended_by FOREIGN KEY (ended_by) REFERENCES users (id),
  ADD CONSTRAINT fk_visits_readmitted_by FOREIGN KEY (readmitted_by) REFERENCES users (id);

-- Like optometry already has: when and by whom a completed record was reopened.
ALTER TABLE triage_assessments
  ADD COLUMN reopened_at DATETIME(3)  NULL COMMENT 'last time the visit was re-admitted to Triage',
  ADD COLUMN reopened_by INT UNSIGNED NULL,
  ADD CONSTRAINT fk_triage_assessments_reopened_by FOREIGN KEY (reopened_by) REFERENCES users (id);

ALTER TABLE ophthalmology_consultations
  ADD COLUMN reopened_at DATETIME(3)  NULL COMMENT 'last time the visit was re-admitted to the Doctor',
  ADD COLUMN reopened_by INT UNSIGNED NULL,
  ADD CONSTRAINT fk_ophthalmology_consultations_reopened_by FOREIGN KEY (reopened_by) REFERENCES users (id);
