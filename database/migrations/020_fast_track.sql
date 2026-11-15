-- Fast track: a patient may pay to be seen first, at registration or later at the cashier.
-- A fast-track visit goes ahead of the normal queue everywhere (emergencies still come first).
-- is_fast_track is the single flag every queue and report reads; sub_type keeps what was
-- chosen at registration.

ALTER TABLE visits
  ADD COLUMN is_fast_track    TINYINT(1)      NOT NULL DEFAULT 0 AFTER is_emergency,
  ADD COLUMN fast_track_bill_id BIGINT UNSIGNED NULL COMMENT 'the bill that pays for the upgrade; fast track starts once it is paid' AFTER is_fast_track,
  ADD COLUMN fast_tracked_at  DATETIME(3)     NULL AFTER fast_track_bill_id,
  ADD COLUMN fast_tracked_by  INT UNSIGNED    NULL AFTER fast_tracked_at,
  ADD KEY ix_visits_fast_track_bill (fast_track_bill_id);

-- Visits registered as fast-track consultations stay fast track.
UPDATE visits SET is_fast_track = 1, fast_tracked_at = created_at, fast_tracked_by = created_by WHERE sub_type = 'fast_track';

-- One fast-track price for doctor and surgery follow-ups; editable in the Price List.
INSERT IGNORE INTO price_items (code, category, name, unit_price, currency, is_confirmed, is_active, created_at, updated_at)
VALUES ('CONS-FOLLOWUP-FAST', 'consultation', 'Follow-Up — Fast Track', 20000.00, 'TZS', 1, 1, UTC_TIMESTAMP(3), UTC_TIMESTAMP(3));
