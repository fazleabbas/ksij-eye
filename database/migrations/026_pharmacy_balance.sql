-- A patient who cannot afford everything takes part of the prescription now and collects the
-- rest (the balance) on a later day, straight from the pharmacy, at the price locked at the
-- first visit. Each later part is its own bill. The balance never expires; the pharmacist may
-- close it with a reason. The balance does not hold stock aside.

ALTER TABLE pharmacy_order_items
  ADD COLUMN prescribed_qty INT UNSIGNED NULL COMMENT 'what the prescription asked for; quantity = billed so far' AFTER quantity;
UPDATE pharmacy_order_items SET prescribed_qty = quantity;
ALTER TABLE pharmacy_order_items MODIFY COLUMN prescribed_qty INT UNSIGNED NOT NULL;
-- A line may now be billed 0 for the time being (all of it taken later).
ALTER TABLE pharmacy_order_items DROP CONSTRAINT ck_pharmacy_order_items;
ALTER TABLE pharmacy_order_items
  ADD CONSTRAINT ck_pharmacy_order_items CHECK (quantity >= 0 AND prescribed_qty > 0 AND dispensed_qty >= 0 AND unit_price >= 0 AND catalogue_price >= 0);

ALTER TABLE pharmacy_orders
  ADD COLUMN balance_closed_at        DATETIME(3)    NULL,
  ADD COLUMN balance_closed_by        INT UNSIGNED   NULL,
  ADD COLUMN balance_close_reason_enc VARBINARY(900) NULL,
  ADD CONSTRAINT fk_pharmacy_orders_balance_closed_by FOREIGN KEY (balance_closed_by) REFERENCES users (id);

-- Each later part: what was taken from the balance and its bill.
CREATE TABLE pharmacy_balance_supplies (
  id               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  order_id         BIGINT UNSIGNED NOT NULL,
  bill_id          BIGINT UNSIGNED NOT NULL,
  previous_bill_id BIGINT UNSIGNED NULL COMMENT 'the order''s bill before this part',
  status           ENUM('billed','cancelled') NOT NULL DEFAULT 'billed',
  total_amount     DECIMAL(12,2)   NOT NULL,
  created_at       DATETIME(3)     NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  created_by       INT UNSIGNED    NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_pharmacy_balance_supplies_bill (bill_id),
  KEY ix_pharmacy_balance_supplies_order (order_id),
  CONSTRAINT fk_pharmacy_balance_supplies_order FOREIGN KEY (order_id) REFERENCES pharmacy_orders (id),
  CONSTRAINT fk_pharmacy_balance_supplies_bill FOREIGN KEY (bill_id) REFERENCES bills (id),
  CONSTRAINT fk_pharmacy_balance_supplies_prev FOREIGN KEY (previous_bill_id) REFERENCES bills (id),
  CONSTRAINT fk_pharmacy_balance_supplies_by FOREIGN KEY (created_by) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE pharmacy_balance_supply_items (
  supply_id  BIGINT UNSIGNED NOT NULL,
  item_id    BIGINT UNSIGNED NOT NULL,
  quantity   INT UNSIGNED    NOT NULL,
  PRIMARY KEY (supply_id, item_id),
  CONSTRAINT fk_pharmacy_balance_supply_items_supply FOREIGN KEY (supply_id) REFERENCES pharmacy_balance_supplies (id),
  CONSTRAINT fk_pharmacy_balance_supply_items_item FOREIGN KEY (item_id) REFERENCES pharmacy_order_items (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
