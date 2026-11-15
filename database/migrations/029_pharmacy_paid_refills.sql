-- Refills are now paid for: a refill is its own order (source 'refill') of the medicines of a
-- completed prescription order, at today's catalogue price, billed to the cashier and handed
-- over once paid. It points back to the order it repeats.

ALTER TABLE pharmacy_orders
  MODIFY COLUMN source ENUM('prescription','walk_in','refill') NOT NULL,
  ADD COLUMN refill_of_order_id BIGINT UNSIGNED NULL COMMENT 'the order this refill repeats' AFTER prescription_id,
  ADD KEY ix_pharmacy_orders_refill_of (refill_of_order_id),
  ADD CONSTRAINT fk_pharmacy_orders_refill_of FOREIGN KEY (refill_of_order_id) REFERENCES pharmacy_orders (id);
