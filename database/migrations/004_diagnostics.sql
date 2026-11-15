-- ═══════════════════════════════════════════════════════════════════════
-- 004_diagnostics — diagnostic bookings, check-in, results and report
-- files. Bookings carry no bill: the charge is raised when the patient
-- arrives on the test day, and a test is never blocked by payment.
-- Free text (clinical reason, result, comments) is encrypted (_enc).
-- Cancellations go through the existing approval queue; "no charge" is a
-- full discount request on the bill, so it reuses the same approval path.
-- ═══════════════════════════════════════════════════════════════════════

-- A laser procedure asks for its laser type (and Retina sub-types) when booked.
ALTER TABLE price_items
  ADD COLUMN is_laser TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'booking asks for laser type and sub-type' AFTER is_active;

-- ─── Bookings ───────────────────────────────────────────────────────────

CREATE TABLE diagnostic_bookings (
  id                    BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  booking_no            VARCHAR(24)     NOT NULL,
  patient_id            BIGINT UNSIGNED NOT NULL,
  visit_id              BIGINT UNSIGNED NULL COMMENT 'set when booked from a visit; desk bookings have none',
  source                ENUM('desk','ophthalmology','visit') NOT NULL DEFAULT 'desk',
  request_id            BIGINT UNSIGNED NULL COMMENT 'ophthalmology investigation request',
  price_item_id         INT UNSIGNED    NOT NULL COMMENT 'the procedure, from the price list',
  laser_type_id         INT UNSIGNED    NULL COMMENT 'lookup diagnostic_laser_type',
  eye                   ENUM('right','left','both') NOT NULL,
  referring_user_id     INT UNSIGNED    NOT NULL,
  scheduled_for         DATETIME        NOT NULL,
  timing                ENUM('now','later') NOT NULL DEFAULT 'now',
  remark_enc            VARBINARY(2100) NULL COMMENT 'clinical reason / instructions',
  status                ENUM('scheduled','arrived','completed','cancellation_requested','cancelled')
                        NOT NULL DEFAULT 'scheduled',
  bill_id               BIGINT UNSIGNED NULL COMMENT 'raised at check-in',
  arrived_at            DATETIME(3)     NULL,
  arrived_by            INT UNSIGNED    NULL,
  completed_at          DATETIME(3)     NULL,
  cancel_approval_id    BIGINT UNSIGNED NULL,
  cancelled_at          DATETIME(3)     NULL,
  row_version           INT UNSIGNED    NOT NULL DEFAULT 1,
  created_at            DATETIME(3)     NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  created_by            INT UNSIGNED    NOT NULL,
  updated_at            DATETIME(3)     NULL,
  updated_by            INT UNSIGNED    NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_diagnostic_bookings_no (booking_no),
  UNIQUE KEY uq_diagnostic_bookings_request (request_id),
  KEY ix_diagnostic_bookings_status (status, scheduled_for),
  KEY ix_diagnostic_bookings_patient (patient_id, scheduled_for),
  KEY ix_diagnostic_bookings_bill (bill_id),
  CONSTRAINT fk_diagnostic_bookings_patient FOREIGN KEY (patient_id) REFERENCES patients (id),
  CONSTRAINT fk_diagnostic_bookings_visit FOREIGN KEY (visit_id) REFERENCES visits (id),
  CONSTRAINT fk_diagnostic_bookings_request FOREIGN KEY (request_id) REFERENCES investigation_requests (id),
  CONSTRAINT fk_diagnostic_bookings_price_item FOREIGN KEY (price_item_id) REFERENCES price_items (id),
  CONSTRAINT fk_diagnostic_bookings_laser_type FOREIGN KEY (laser_type_id) REFERENCES lookup_values (id),
  CONSTRAINT fk_diagnostic_bookings_referring FOREIGN KEY (referring_user_id) REFERENCES users (id),
  CONSTRAINT fk_diagnostic_bookings_bill FOREIGN KEY (bill_id) REFERENCES bills (id),
  CONSTRAINT fk_diagnostic_bookings_created_by FOREIGN KEY (created_by) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Retina laser sub-types (PRO, Focal/Grid, Barrage); several per booking.
CREATE TABLE diagnostic_booking_laser_subtypes (
  booking_id       BIGINT UNSIGNED NOT NULL,
  lookup_value_id  INT UNSIGNED    NOT NULL COMMENT 'lookup diagnostic_laser_subtype',
  PRIMARY KEY (booking_id, lookup_value_id),
  CONSTRAINT fk_diag_laser_subtypes_booking FOREIGN KEY (booking_id) REFERENCES diagnostic_bookings (id),
  CONSTRAINT fk_diag_laser_subtypes_value FOREIGN KEY (lookup_value_id) REFERENCES lookup_values (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─── Results ────────────────────────────────────────────────────────────

CREATE TABLE diagnostic_results (
  id                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  booking_id          BIGINT UNSIGNED NOT NULL,
  patient_id          BIGINT UNSIGNED NOT NULL,
  technician_user_id  INT UNSIGNED    NULL,
  reporting_user_id   INT UNSIGNED    NOT NULL,
  result_enc          VARBINARY(16400) NULL,
  comments_enc        VARBINARY(8100) NULL,
  completed_at        DATETIME(3)     NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  completed_by        INT UNSIGNED    NOT NULL,
  row_version         INT UNSIGNED    NOT NULL DEFAULT 1,
  updated_at          DATETIME(3)     NULL,
  updated_by          INT UNSIGNED    NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_diagnostic_results_booking (booking_id),
  KEY ix_diagnostic_results_patient (patient_id, completed_at),
  CONSTRAINT fk_diagnostic_results_booking FOREIGN KEY (booking_id) REFERENCES diagnostic_bookings (id),
  CONSTRAINT fk_diagnostic_results_patient FOREIGN KEY (patient_id) REFERENCES patients (id),
  CONSTRAINT fk_diagnostic_results_technician FOREIGN KEY (technician_user_id) REFERENCES users (id),
  CONSTRAINT fk_diagnostic_results_reporting FOREIGN KEY (reporting_user_id) REFERENCES users (id),
  CONSTRAINT fk_diagnostic_results_completed_by FOREIGN KEY (completed_by) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Scans / printouts attached to a report; the file itself is encrypted on disk.
CREATE TABLE diagnostic_result_files (
  result_id  BIGINT UNSIGNED NOT NULL,
  file_id    BIGINT UNSIGNED NOT NULL,
  PRIMARY KEY (result_id, file_id),
  UNIQUE KEY uq_diagnostic_result_files_file (file_id),
  CONSTRAINT fk_diagnostic_result_files_result FOREIGN KEY (result_id) REFERENCES diagnostic_results (id),
  CONSTRAINT fk_diagnostic_result_files_file FOREIGN KEY (file_id) REFERENCES stored_files (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─── Existing tables ────────────────────────────────────────────────────

-- Ophthalmology investigation requests no longer raise a bill: submitting one
-- now creates an unbilled diagnostic booking, billed when the patient arrives.
ALTER TABLE investigation_requests
  DROP FOREIGN KEY fk_investigation_requests_bill;

ALTER TABLE investigation_requests
  DROP COLUMN bill_id,
  MODIFY COLUMN status ENUM('draft','requested','booked','cancelled') NOT NULL DEFAULT 'draft';

-- Booking cancellations are decided in the approval queue, like discounts.
ALTER TABLE approval_requests
  MODIFY COLUMN type ENUM('discount','bill_cancellation','refund','diagnostic_cancellation') NOT NULL,
  ADD COLUMN booking_id BIGINT UNSIGNED NULL AFTER refund_id,
  ADD KEY ix_approval_requests_booking (booking_id),
  ADD CONSTRAINT fk_approval_requests_booking FOREIGN KEY (booking_id) REFERENCES diagnostic_bookings (id);

ALTER TABLE diagnostic_bookings
  ADD CONSTRAINT fk_diagnostic_bookings_cancel_approval FOREIGN KEY (cancel_approval_id) REFERENCES approval_requests (id);

-- A diagnostic report is a clinical record: the same correction window and
-- addendum rule apply. Desk bookings have no visit, so visit_id may be null.
ALTER TABLE clinical_addenda
  MODIFY COLUMN visit_id BIGINT UNSIGNED NULL,
  MODIFY COLUMN source ENUM('triage','optometry','ophthalmology','diagnostic') NOT NULL;
