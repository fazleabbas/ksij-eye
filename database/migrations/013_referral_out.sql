-- MTUHA register — the doctor can record that a patient was referred to another
-- facility, so the register's Outcome column can show Referral. Where the patient
-- was sent is free text, so it is encrypted like the rest of the consultation.
ALTER TABLE ophthalmology_consultations
  ADD COLUMN referred_out          TINYINT(1)     NOT NULL DEFAULT 0 AFTER clinical_notes_enc,
  ADD COLUMN referral_facility_enc VARBINARY(700) NULL AFTER referred_out;
