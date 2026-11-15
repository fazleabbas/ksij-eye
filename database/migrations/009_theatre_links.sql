-- ═══════════════════════════════════════════════════════════════════════
-- 009_theatre_links — a post-op review becomes a real follow-up at
-- reception, so a surgical case can raise one without a clinic visit.
-- ═══════════════════════════════════════════════════════════════════════

ALTER TABLE follow_ups
  MODIFY COLUMN source ENUM('optometry','ophthalmology','theatre_postop') NOT NULL,
  MODIFY COLUMN source_visit_id BIGINT UNSIGNED NULL COMMENT 'null for a follow-up raised after surgery';
