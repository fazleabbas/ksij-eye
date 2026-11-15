-- UAT round 2 — medicine prices live in one place: the pharmacy's medicine list
-- (drugs.unit_price). The Price List's "Medicine" category, added in round 1, was
-- never read by the pharmacy and only confused things, so it goes again.

-- Anything filed under Medicine moves back to Other. A name that already exists
-- under Other keeps its row but is marked so the two can be told apart.
UPDATE price_items p
  JOIN price_items o ON o.category = 'other' AND o.name = p.name
   SET p.name = LEFT(CONCAT(p.name, ' (medicine)'), 150)
 WHERE p.category = 'medicine';

UPDATE price_items SET category = 'other' WHERE category = 'medicine';

ALTER TABLE price_items
  MODIFY COLUMN category ENUM('consultation','investigation','surgery','procedure','lens','other') NOT NULL;
