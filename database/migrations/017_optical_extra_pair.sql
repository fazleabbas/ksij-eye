-- A patient may order another pair of spectacles on the same prescription: same
-- lenses, a frame of their choice, at full price. The extra pair is its own order,
-- tracked and handed over separately, and points back to the first one.
-- A prescription can therefore have more than one order.
ALTER TABLE optical_orders
  ADD KEY ix_optical_orders_assessment (assessment_id);

ALTER TABLE optical_orders
  DROP INDEX uq_optical_orders_assessment,
  MODIFY COLUMN order_type ENUM('referral','external','frame_change','extra_pair') NOT NULL DEFAULT 'referral',
  MODIFY COLUMN original_order_id BIGINT UNSIGNED NULL COMMENT 'frame change or extra pair: the earlier spectacles';
