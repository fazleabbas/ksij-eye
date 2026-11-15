-- An optometrist may write more than one spectacle prescription in a visit, for
-- example bifocals and reading glasses: each is a final refraction with its own
-- lenses and a label. The first is the visit's final refraction as before (set 1);
-- the others are sets 2, 3, … The Optical Room makes them all on one order.

ALTER TABLE optometry_refractions
  ADD COLUMN set_no SMALLINT UNSIGNED NOT NULL DEFAULT 1 COMMENT 'final refraction: which prescription' AFTER kind,
  ADD COLUMN label  VARCHAR(60) NULL COMMENT 'final refraction: e.g. Bifocal, Reading' AFTER set_no,
  DROP PRIMARY KEY,
  ADD PRIMARY KEY (assessment_id, kind, set_no);

ALTER TABLE optometry_lens_items
  ADD COLUMN set_no SMALLINT UNSIGNED NOT NULL DEFAULT 1 AFTER assessment_id,
  DROP PRIMARY KEY,
  ADD PRIMARY KEY (assessment_id, set_no, price_item_id);

-- Two pairs may use the same lens type, so a lens can appear on an order more than once.
ALTER TABLE optical_order_items
  ADD KEY ix_optical_order_items_order (order_id);
ALTER TABLE optical_order_items
  DROP INDEX uq_optical_order_items,
  ADD COLUMN set_no   SMALLINT UNSIGNED NULL COMMENT 'which prescription this line belongs to' AFTER kind,
  ADD COLUMN rx_label VARCHAR(60) NULL AFTER set_no;
