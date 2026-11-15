-- Patient numbers continue the clinic's paper file series. A patient who already has
-- a paper file keeps that number; a new patient gets the next number above the
-- highest paper number. Numbers are shown plainly, as on the files ("3127").

-- REG-000123 becomes 123.
UPDATE patients SET reg_no = CAST(SUBSTRING(reg_no, 5) AS UNSIGNED) WHERE reg_no LIKE 'REG-%';

ALTER TABLE patients
  ADD COLUMN paper_file TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'registered under the number of an existing paper file' AFTER reg_no;

-- The highest number used on paper before the system: paper numbers are at or
-- below it, numbers the system gives out are above it. Set once at go-live.
INSERT IGNORE INTO settings (setting_key, setting_value, value_type, description)
VALUES ('patients.paper_max', '0', 'int', 'Highest patient file number used on paper before the system');
