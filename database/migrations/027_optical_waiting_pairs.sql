-- With several spectacle prescriptions, the patient may order some pairs now and the rest
-- later. The pairs left over wait in Incoming Prescriptions, with the lens price locked on the
-- day the first pair was ordered, until ordered or closed by hand (no expiry).

CREATE TABLE optical_waiting_pairs (
  id                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  assessment_id     BIGINT UNSIGNED NOT NULL,
  set_no            SMALLINT UNSIGNED NOT NULL,
  rx_label          VARCHAR(60)     NULL,
  status            ENUM('waiting','ordered','closed') NOT NULL DEFAULT 'waiting',
  first_order_id    BIGINT UNSIGNED NOT NULL COMMENT 'the order that left this pair for later',
  order_id          BIGINT UNSIGNED NULL COMMENT 'the order that took it later',
  closed_at         DATETIME(3)     NULL,
  closed_by         INT UNSIGNED    NULL,
  close_reason_enc  VARBINARY(900)  NULL,
  created_at        DATETIME(3)     NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  created_by        INT UNSIGNED    NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_optical_waiting_pairs (assessment_id, set_no),
  KEY ix_optical_waiting_pairs_status (status, created_at),
  CONSTRAINT fk_optical_waiting_pairs_assessment FOREIGN KEY (assessment_id) REFERENCES optometry_assessments (id),
  CONSTRAINT fk_optical_waiting_pairs_first FOREIGN KEY (first_order_id) REFERENCES optical_orders (id),
  CONSTRAINT fk_optical_waiting_pairs_order FOREIGN KEY (order_id) REFERENCES optical_orders (id),
  CONSTRAINT fk_optical_waiting_pairs_closed_by FOREIGN KEY (closed_by) REFERENCES users (id),
  CONSTRAINT fk_optical_waiting_pairs_by FOREIGN KEY (created_by) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- The lenses of a waiting pair at the price locked when the first pair was ordered.
CREATE TABLE optical_waiting_pair_lenses (
  pair_id        BIGINT UNSIGNED NOT NULL,
  price_item_id  INT UNSIGNED    NOT NULL,
  name           VARCHAR(190)    NOT NULL,
  unit_price     DECIMAL(12,2)   NOT NULL,
  PRIMARY KEY (pair_id, price_item_id),
  CONSTRAINT fk_optical_waiting_pair_lenses_pair FOREIGN KEY (pair_id) REFERENCES optical_waiting_pairs (id),
  CONSTRAINT fk_optical_waiting_pair_lenses_item FOREIGN KEY (price_item_id) REFERENCES price_items (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
