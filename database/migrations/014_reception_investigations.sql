-- UAT round 3 — an investigation chosen at reception now becomes a diagnostic
-- booking straight away. Reception may send a patient without a referring doctor
-- (an outside referral, or the patient's own request), so the booking allows none.
ALTER TABLE diagnostic_bookings
  MODIFY COLUMN referring_user_id INT UNSIGNED NULL;
