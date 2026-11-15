-- ═══════════════════════════════════════════════════════════════════════
-- 010_stores — the store room: what is held, what departments ask for and
-- receive, and how it is bought (quotations → purchase order → goods in).
--
-- Stock is a running balance per item, held as batches so an expiry date
-- belongs to the delivery it came with. Every change leaves a movement row,
-- so any quantity can be explained. Issuing medicine to the pharmacy moves
-- it onto the pharmacy's own shelf.
-- ═══════════════════════════════════════════════════════════════════════

CREATE TABLE suppliers (
  id            INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  name          VARCHAR(150)  NOT NULL,
  contact_name  VARCHAR(120)  NULL,
  phone         VARCHAR(40)   NULL,
  email         VARCHAR(190)  NULL,
  address       VARCHAR(255)  NULL,
  is_preferred  TINYINT(1)    NOT NULL DEFAULT 0,
  is_active     TINYINT(1)    NOT NULL DEFAULT 1,
  row_version   INT UNSIGNED  NOT NULL DEFAULT 1,
  created_at    DATETIME(3)   NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  created_by    INT UNSIGNED  NOT NULL,
  updated_at    DATETIME(3)   NULL,
  updated_by    INT UNSIGNED  NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_suppliers_name (name),
  CONSTRAINT fk_suppliers_created_by FOREIGN KEY (created_by) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─── What the store holds ───────────────────────────────────────────────

CREATE TABLE store_items (
  id             INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  code           VARCHAR(24)   NOT NULL,
  name           VARCHAR(150)  NOT NULL,
  category       VARCHAR(60)   NOT NULL COMMENT 'lookup store_category',
  unit           VARCHAR(40)   NOT NULL COMMENT 'how it is counted, e.g. Box (100pcs)',
  drug_id        INT UNSIGNED  NULL COMMENT 'set when this is a medicine the pharmacy dispenses',
  supplier_id    INT UNSIGNED  NULL COMMENT 'usual supplier',
  reorder_level  INT UNSIGNED  NOT NULL DEFAULT 0,
  quantity       INT           NOT NULL DEFAULT 0 COMMENT 'running balance, sum of the batches',
  cost_price     DECIMAL(12,2) NOT NULL DEFAULT 0,
  is_active      TINYINT(1)    NOT NULL DEFAULT 1,
  row_version    INT UNSIGNED  NOT NULL DEFAULT 1,
  created_at     DATETIME(3)   NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  created_by     INT UNSIGNED  NOT NULL,
  updated_at     DATETIME(3)   NULL,
  updated_by     INT UNSIGNED  NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_store_items_code (code),
  UNIQUE KEY uq_store_items_name (name),
  KEY ix_store_items_category (category, is_active),
  CONSTRAINT fk_store_items_drug FOREIGN KEY (drug_id) REFERENCES drugs (id),
  CONSTRAINT fk_store_items_supplier FOREIGN KEY (supplier_id) REFERENCES suppliers (id),
  CONSTRAINT fk_store_items_created_by FOREIGN KEY (created_by) REFERENCES users (id),
  CONSTRAINT ck_store_items CHECK (quantity >= 0 AND cost_price >= 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- A delivery of one item: its own batch number and expiry. Issues take from
-- the batch that expires first.
CREATE TABLE store_item_batches (
  id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  item_id      INT UNSIGNED    NOT NULL,
  batch_no     VARCHAR(60)     NULL,
  expiry_date  DATE            NULL,
  quantity     INT             NOT NULL DEFAULT 0,
  received_at  DATETIME(3)     NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  grn_id       BIGINT UNSIGNED NULL,
  PRIMARY KEY (id),
  KEY ix_store_batches_item (item_id, expiry_date),
  CONSTRAINT fk_store_batches_item FOREIGN KEY (item_id) REFERENCES store_items (id),
  CONSTRAINT ck_store_batches CHECK (quantity >= 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Append-only: every movement explains the balance.
CREATE TABLE store_stock_movements (
  id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  item_id        INT UNSIGNED    NOT NULL,
  batch_id       BIGINT UNSIGNED NULL,
  change_qty     INT             NOT NULL COMMENT 'positive in, negative out',
  balance_after  INT             NOT NULL,
  reason         ENUM('received','issued','adjusted','returned','written_off') NOT NULL,
  reference      VARCHAR(40)     NULL COMMENT 'the document behind it',
  note           VARCHAR(190)    NULL,
  created_at     DATETIME(3)     NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  created_by     INT UNSIGNED    NOT NULL,
  PRIMARY KEY (id),
  KEY ix_store_movements_item (item_id, created_at),
  CONSTRAINT fk_store_movements_item FOREIGN KEY (item_id) REFERENCES store_items (id),
  CONSTRAINT fk_store_movements_batch FOREIGN KEY (batch_id) REFERENCES store_item_batches (id),
  CONSTRAINT fk_store_movements_created_by FOREIGN KEY (created_by) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─── What departments ask for ───────────────────────────────────────────

CREATE TABLE store_requisitions (
  id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  requisition_no  VARCHAR(24)     NOT NULL,
  department_id   SMALLINT UNSIGNED NOT NULL,
  priority        ENUM('normal','urgent','emergency') NOT NULL DEFAULT 'normal',
  status          ENUM('draft','submitted','approved','rejected','partly_issued','issued','cancelled')
                  NOT NULL DEFAULT 'draft',
  remarks         VARCHAR(500)    NULL,
  submitted_at    DATETIME(3)     NULL,
  decided_at      DATETIME(3)     NULL,
  decided_by      INT UNSIGNED    NULL,
  decision_reason VARCHAR(500)    NULL COMMENT 'why it was cut back or refused',
  row_version     INT UNSIGNED    NOT NULL DEFAULT 1,
  created_at      DATETIME(3)     NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  created_by      INT UNSIGNED    NOT NULL,
  updated_at      DATETIME(3)     NULL,
  updated_by      INT UNSIGNED    NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_store_requisitions_no (requisition_no),
  KEY ix_store_requisitions_status (status, created_at),
  KEY ix_store_requisitions_department (department_id, created_at),
  CONSTRAINT fk_store_requisitions_department FOREIGN KEY (department_id) REFERENCES departments (id),
  CONSTRAINT fk_store_requisitions_created_by FOREIGN KEY (created_by) REFERENCES users (id),
  CONSTRAINT fk_store_requisitions_decided_by FOREIGN KEY (decided_by) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE store_requisition_items (
  id               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  requisition_id   BIGINT UNSIGNED NOT NULL,
  item_id          INT UNSIGNED    NOT NULL,
  qty_requested    INT UNSIGNED    NOT NULL,
  qty_approved     INT UNSIGNED    NULL COMMENT 'what the approver allowed',
  qty_issued       INT UNSIGNED    NOT NULL DEFAULT 0,
  remarks          VARCHAR(190)    NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_store_requisition_items (requisition_id, item_id),
  CONSTRAINT fk_store_req_items_requisition FOREIGN KEY (requisition_id) REFERENCES store_requisitions (id),
  CONSTRAINT fk_store_req_items_item FOREIGN KEY (item_id) REFERENCES store_items (id),
  CONSTRAINT ck_store_req_items CHECK (qty_requested > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Each hand-over is its own note, so a part-filled requisition can be
-- finished later and you can see who issued what and when.
CREATE TABLE store_issues (
  id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  issue_no        VARCHAR(24)     NOT NULL,
  requisition_id  BIGINT UNSIGNED NOT NULL,
  remarks         VARCHAR(500)    NULL,
  issued_at       DATETIME(3)     NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  issued_by       INT UNSIGNED    NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_store_issues_no (issue_no),
  KEY ix_store_issues_requisition (requisition_id, issued_at),
  CONSTRAINT fk_store_issues_requisition FOREIGN KEY (requisition_id) REFERENCES store_requisitions (id),
  CONSTRAINT fk_store_issues_by FOREIGN KEY (issued_by) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE store_issue_items (
  id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  issue_id   BIGINT UNSIGNED NOT NULL,
  item_id    INT UNSIGNED    NOT NULL,
  quantity   INT UNSIGNED    NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_store_issue_items (issue_id, item_id),
  CONSTRAINT fk_store_issue_items_issue FOREIGN KEY (issue_id) REFERENCES store_issues (id),
  CONSTRAINT fk_store_issue_items_item FOREIGN KEY (item_id) REFERENCES store_items (id),
  CONSTRAINT ck_store_issue_items CHECK (quantity > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─── Buying ─────────────────────────────────────────────────────────────

CREATE TABLE store_quotations (
  id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  quotation_no   VARCHAR(24)     NOT NULL,
  supplier_id    INT UNSIGNED    NOT NULL,
  quoted_on      DATE            NULL,
  valid_until    DATE            NULL,
  status         ENUM('draft','under_review','selected','rejected') NOT NULL DEFAULT 'draft',
  total_amount   DECIMAL(12,2)   NOT NULL DEFAULT 0,
  remarks        VARCHAR(500)    NULL,
  row_version    INT UNSIGNED    NOT NULL DEFAULT 1,
  created_at     DATETIME(3)     NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  created_by     INT UNSIGNED    NOT NULL,
  updated_at     DATETIME(3)     NULL,
  updated_by     INT UNSIGNED    NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_store_quotations_no (quotation_no),
  KEY ix_store_quotations_status (status, created_at),
  CONSTRAINT fk_store_quotations_supplier FOREIGN KEY (supplier_id) REFERENCES suppliers (id),
  CONSTRAINT fk_store_quotations_created_by FOREIGN KEY (created_by) REFERENCES users (id),
  CONSTRAINT ck_store_quotations_total CHECK (total_amount >= 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE store_quotation_items (
  id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  quotation_id   BIGINT UNSIGNED NOT NULL,
  item_id        INT UNSIGNED    NOT NULL,
  quantity       INT UNSIGNED    NOT NULL,
  unit_price     DECIMAL(12,2)   NOT NULL,
  tax_percent    DECIMAL(5,2)    NOT NULL DEFAULT 0,
  line_total     DECIMAL(12,2)   AS (ROUND(quantity * unit_price * (1 + tax_percent / 100), 2)) PERSISTENT,
  remarks        VARCHAR(190)    NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_store_quotation_items (quotation_id, item_id),
  CONSTRAINT fk_store_quote_items_quotation FOREIGN KEY (quotation_id) REFERENCES store_quotations (id),
  CONSTRAINT fk_store_quote_items_item FOREIGN KEY (item_id) REFERENCES store_items (id),
  CONSTRAINT ck_store_quote_items CHECK (quantity > 0 AND unit_price >= 0 AND tax_percent >= 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE store_purchase_orders (
  id                 BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  order_no           VARCHAR(24)     NOT NULL,
  quotation_id       BIGINT UNSIGNED NULL,
  supplier_id        INT UNSIGNED    NOT NULL,
  expected_delivery  DATE            NULL,
  status             ENUM('draft','pending_admin','pending_store','approved','sent','closed','cancelled')
                     NOT NULL DEFAULT 'draft',
  total_amount       DECIMAL(12,2)   NOT NULL DEFAULT 0,
  remarks            VARCHAR(500)    NULL,
  admin_approved_at  DATETIME(3)     NULL,
  admin_approved_by  INT UNSIGNED    NULL,
  store_approved_at  DATETIME(3)     NULL,
  store_approved_by  INT UNSIGNED    NULL,
  sent_at            DATETIME(3)     NULL,
  closed_at          DATETIME(3)     NULL,
  row_version        INT UNSIGNED    NOT NULL DEFAULT 1,
  created_at         DATETIME(3)     NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  created_by         INT UNSIGNED    NOT NULL,
  updated_at         DATETIME(3)     NULL,
  updated_by         INT UNSIGNED    NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_store_orders_no (order_no),
  KEY ix_store_orders_status (status, created_at),
  CONSTRAINT fk_store_orders_quotation FOREIGN KEY (quotation_id) REFERENCES store_quotations (id),
  CONSTRAINT fk_store_orders_supplier FOREIGN KEY (supplier_id) REFERENCES suppliers (id),
  CONSTRAINT fk_store_orders_admin_by FOREIGN KEY (admin_approved_by) REFERENCES users (id),
  CONSTRAINT fk_store_orders_store_by FOREIGN KEY (store_approved_by) REFERENCES users (id),
  CONSTRAINT fk_store_orders_created_by FOREIGN KEY (created_by) REFERENCES users (id),
  CONSTRAINT ck_store_orders_total CHECK (total_amount >= 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE store_purchase_order_items (
  id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  order_id     BIGINT UNSIGNED NOT NULL,
  item_id      INT UNSIGNED    NOT NULL,
  quantity     INT UNSIGNED    NOT NULL,
  unit_price   DECIMAL(12,2)   NOT NULL,
  tax_percent  DECIMAL(5,2)    NOT NULL DEFAULT 0 COMMENT 'carried from the quotation',
  qty_received INT UNSIGNED    NOT NULL DEFAULT 0,
  line_total   DECIMAL(12,2)   AS (ROUND(quantity * unit_price * (1 + tax_percent / 100), 2)) PERSISTENT,
  remarks      VARCHAR(190)    NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_store_order_items (order_id, item_id),
  CONSTRAINT fk_store_order_items_order FOREIGN KEY (order_id) REFERENCES store_purchase_orders (id),
  CONSTRAINT fk_store_order_items_item FOREIGN KEY (item_id) REFERENCES store_items (id),
  CONSTRAINT ck_store_order_items CHECK (quantity > 0 AND unit_price >= 0 AND tax_percent >= 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─── Goods in ───────────────────────────────────────────────────────────

CREATE TABLE store_goods_received (
  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  grn_no        VARCHAR(24)     NOT NULL,
  order_id      BIGINT UNSIGNED NOT NULL,
  received_on   DATE            NOT NULL,
  status        ENUM('recorded','verified','approved','cancelled') NOT NULL DEFAULT 'recorded',
  delivery_note VARCHAR(60)     NULL COMMENT "the supplier's own note number",
  remarks       VARCHAR(500)    NULL,
  verified_at   DATETIME(3)     NULL,
  verified_by   INT UNSIGNED    NULL,
  approved_at   DATETIME(3)     NULL,
  approved_by   INT UNSIGNED    NULL,
  row_version   INT UNSIGNED    NOT NULL DEFAULT 1,
  created_at    DATETIME(3)     NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  created_by    INT UNSIGNED    NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_store_grn_no (grn_no),
  KEY ix_store_grn_status (status, received_on),
  CONSTRAINT fk_store_grn_order FOREIGN KEY (order_id) REFERENCES store_purchase_orders (id),
  CONSTRAINT fk_store_grn_verified_by FOREIGN KEY (verified_by) REFERENCES users (id),
  CONSTRAINT fk_store_grn_approved_by FOREIGN KEY (approved_by) REFERENCES users (id),
  CONSTRAINT fk_store_grn_created_by FOREIGN KEY (created_by) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE store_goods_received_items (
  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  grn_id        BIGINT UNSIGNED NOT NULL,
  item_id       INT UNSIGNED    NOT NULL,
  quantity      INT UNSIGNED    NOT NULL COMMENT 'accepted into stock',
  rejected_qty  INT UNSIGNED    NOT NULL DEFAULT 0 COMMENT 'damaged or refused',
  batch_no      VARCHAR(60)     NULL,
  expiry_date   DATE            NULL,
  unit_price    DECIMAL(12,2)   NOT NULL DEFAULT 0 COMMENT 'what was actually charged',
  remarks       VARCHAR(190)    NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_store_grn_items (grn_id, item_id, batch_no),
  CONSTRAINT fk_store_grn_items_grn FOREIGN KEY (grn_id) REFERENCES store_goods_received (id),
  CONSTRAINT fk_store_grn_items_item FOREIGN KEY (item_id) REFERENCES store_items (id),
  CONSTRAINT ck_store_grn_items CHECK (quantity > 0 AND rejected_qty >= 0 AND unit_price >= 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE store_item_batches
  ADD CONSTRAINT fk_store_batches_grn FOREIGN KEY (grn_id) REFERENCES store_goods_received (id);
