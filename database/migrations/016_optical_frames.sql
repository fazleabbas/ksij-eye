-- ═══════════════════════════════════════════════════════════════════════
-- 016_optical_frames — spectacle frames, counted in the Optical Room.
--
-- Frames come from the store: issuing a store item linked to a frame to the
-- Optical Room adds it to the frame stock. A frame leaves the stock when the
-- spectacles are handed over; until then a frame on an open order is set
-- aside, so the same frame cannot be sold twice.
--
-- Optical orders now come in three kinds: a referral from optometry (as
-- before), an external sale to someone who is not a patient, and a frame
-- change on an earlier order. Every frame costs the same: one price for
-- patients, one for external customers, both kept in the Price List.
-- ═══════════════════════════════════════════════════════════════════════

CREATE TABLE frames (
  id           INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  code         VARCHAR(24)   NOT NULL,
  name         VARCHAR(150)  NOT NULL COMMENT 'model',
  brand        VARCHAR(80)   NULL,
  colour       VARCHAR(40)   NULL,
  size         VARCHAR(20)   NULL,
  is_active    TINYINT(1)    NOT NULL DEFAULT 1,
  row_version  INT UNSIGNED  NOT NULL DEFAULT 1,
  created_at   DATETIME(3)   NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  created_by   INT UNSIGNED  NULL,
  updated_at   DATETIME(3)   NULL,
  updated_by   INT UNSIGNED  NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_frames_code (code),
  KEY ix_frames_name (name),
  CONSTRAINT fk_frames_created_by FOREIGN KEY (created_by) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE optical_frame_stock (
  frame_id       INT UNSIGNED  NOT NULL,
  quantity       INT           NOT NULL DEFAULT 0,
  reorder_level  INT UNSIGNED  NOT NULL DEFAULT 0,
  row_version    INT UNSIGNED  NOT NULL DEFAULT 1,
  updated_at     DATETIME(3)   NULL,
  updated_by     INT UNSIGNED  NULL,
  PRIMARY KEY (frame_id),
  CONSTRAINT fk_optical_frame_stock_frame FOREIGN KEY (frame_id) REFERENCES frames (id),
  CONSTRAINT ck_optical_frame_stock CHECK (quantity >= 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Every change to the frame count, with who, why and the balance after it.
CREATE TABLE optical_frame_movements (
  id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  frame_id       INT UNSIGNED    NOT NULL,
  change_qty     INT             NOT NULL,
  balance_after  INT             NOT NULL,
  reason         ENUM('received','sold','adjusted','returned') NOT NULL,
  reference      VARCHAR(40)     NULL COMMENT 'issue note or order number',
  note           VARCHAR(190)    NULL,
  created_at     DATETIME(3)     NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  created_by     INT UNSIGNED    NOT NULL,
  PRIMARY KEY (id),
  KEY ix_optical_frame_movements_frame (frame_id, created_at),
  CONSTRAINT fk_optical_frame_movements_frame FOREIGN KEY (frame_id) REFERENCES frames (id),
  CONSTRAINT fk_optical_frame_movements_by FOREIGN KEY (created_by) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- A store item can be a frame, like it can be a pharmacy medicine.
ALTER TABLE store_items
  ADD COLUMN frame_id INT UNSIGNED NULL COMMENT 'set when this is a frame the optical room sells' AFTER drug_id,
  ADD CONSTRAINT fk_store_items_frame FOREIGN KEY (frame_id) REFERENCES frames (id);

-- Orders: a patient or an external customer, and three kinds.
ALTER TABLE optical_orders
  ADD COLUMN order_type ENUM('referral','external','frame_change') NOT NULL DEFAULT 'referral' AFTER order_no,
  MODIFY COLUMN patient_id BIGINT UNSIGNED NULL,
  ADD COLUMN walkin_id BIGINT UNSIGNED NULL COMMENT 'external customer' AFTER patient_id,
  MODIFY COLUMN visit_id BIGINT UNSIGNED NULL,
  MODIFY COLUMN assessment_id BIGINT UNSIGNED NULL COMMENT 'the optometry assessment that referred the patient',
  ADD COLUMN original_order_id BIGINT UNSIGNED NULL COMMENT 'frame change: the spectacles being repaired' AFTER assessment_id,
  ADD COLUMN old_frame VARCHAR(190) NULL COMMENT 'frame change: the frame taken off' AFTER original_order_id,
  ADD COLUMN change_reason_enc VARBINARY(1100) NULL AFTER old_frame,
  ADD KEY ix_optical_orders_walkin (walkin_id),
  ADD CONSTRAINT fk_optical_orders_walkin FOREIGN KEY (walkin_id) REFERENCES pharmacy_walkins (id),
  ADD CONSTRAINT fk_optical_orders_original FOREIGN KEY (original_order_id) REFERENCES optical_orders (id),
  ADD CONSTRAINT ck_optical_orders_owner CHECK ((patient_id IS NULL) <> (walkin_id IS NULL));

-- An order line is a lens (from the price list) or a frame.
ALTER TABLE optical_order_items
  ADD COLUMN kind ENUM('lens','frame') NOT NULL DEFAULT 'lens' AFTER order_id,
  MODIFY COLUMN price_item_id INT UNSIGNED NULL,
  ADD COLUMN frame_id INT UNSIGNED NULL AFTER price_item_id,
  ADD CONSTRAINT fk_optical_order_items_frame FOREIGN KEY (frame_id) REFERENCES frames (id),
  ADD CONSTRAINT ck_optical_order_items_kind CHECK (
    (kind = 'lens' AND price_item_id IS NOT NULL) OR (kind = 'frame' AND frame_id IS NOT NULL));

-- The two frame prices, edited in the Price List like every other price.
INSERT IGNORE INTO price_items (code, category, name, unit_price, currency, is_confirmed, is_active, created_at, updated_at)
VALUES ('FRAME-EXTERNAL', 'other', 'Spectacle frame — external customer', 25000, 'TZS', 1, 1, UTC_TIMESTAMP(3), UTC_TIMESTAMP(3)),
       ('FRAME-INTERNAL', 'other', 'Spectacle frame — patient', 1000, 'TZS', 1, 1, UTC_TIMESTAMP(3), UTC_TIMESTAMP(3));
