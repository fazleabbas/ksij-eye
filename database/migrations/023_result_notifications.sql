-- Doctors see test results from the consultation and patient history, and are told when a
-- result is ready. A result counts as seen once its referring doctor has opened it (or, for a
-- test with no referring doctor, once any doctor has).

ALTER TABLE diagnostic_results
  ADD COLUMN seen_at DATETIME(3)  NULL COMMENT 'first opened by the referring doctor',
  ADD COLUMN seen_by INT UNSIGNED NULL,
  ADD KEY ix_diagnostic_results_unseen (seen_at, completed_at),
  ADD CONSTRAINT fk_diagnostic_results_seen_by FOREIGN KEY (seen_by) REFERENCES users (id);
