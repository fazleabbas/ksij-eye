-- Operation templates: for each surgery or procedure on the price list, the usual operation
-- notes, the post-op medicines and instructions, and the first review day. A new operation
-- record and the first post-op review start from them; the surgeon edits freely. Templates are
-- reference text written by the clinic (no patient data), so they are stored plain.

CREATE TABLE operation_templates (
  id                  INT UNSIGNED    NOT NULL AUTO_INCREMENT,
  price_item_id       INT UNSIGNED    NOT NULL,
  operation_notes     TEXT            NULL,
  postop_instructions TEXT            NULL,
  review_days         TINYINT UNSIGNED NULL COMMENT 'first review this many days after surgery',
  is_active           TINYINT(1)      NOT NULL DEFAULT 1,
  created_at          DATETIME(3)     NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  updated_at          DATETIME(3)     NULL,
  updated_by          INT UNSIGNED    NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_operation_templates_item (price_item_id),
  CONSTRAINT fk_operation_templates_item FOREIGN KEY (price_item_id) REFERENCES price_items (id),
  CONSTRAINT fk_operation_templates_by FOREIGN KEY (updated_by) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE operation_template_medicines (
  template_id  INT UNSIGNED      NOT NULL,
  drug_id      INT UNSIGNED      NOT NULL,
  quantity     SMALLINT UNSIGNED NOT NULL DEFAULT 1,
  dose         VARCHAR(40)       NULL,
  frequency    VARCHAR(20)       NULL,
  duration     VARCHAR(40)       NULL,
  sort_order   SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (template_id, drug_id),
  CONSTRAINT fk_operation_template_medicines_template FOREIGN KEY (template_id) REFERENCES operation_templates (id),
  CONSTRAINT fk_operation_template_medicines_drug FOREIGN KEY (drug_id) REFERENCES drugs (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
