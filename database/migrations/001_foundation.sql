-- ═══════════════════════════════════════════════════════════════════════
-- 001_foundation — identity, access control, audit and reference data
-- MariaDB 10.4 · InnoDB · utf8mb4_unicode_ci · every timestamp is UTC
-- created_by / updated_by hold users.id (not enforced by FK to keep
-- reference tables independent of the users table).
-- ═══════════════════════════════════════════════════════════════════════

CREATE TABLE departments (
  id          SMALLINT UNSIGNED NOT NULL AUTO_INCREMENT,
  name        VARCHAR(100)      NOT NULL,
  is_active   TINYINT(1)        NOT NULL DEFAULT 1,
  created_at  DATETIME(3)       NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  created_by  INT UNSIGNED      NULL,
  updated_at  DATETIME(3)       NULL,
  updated_by  INT UNSIGNED      NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_departments_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE designations (
  id          SMALLINT UNSIGNED NOT NULL AUTO_INCREMENT,
  code        VARCHAR(40)       NOT NULL,
  name        VARCHAR(100)      NOT NULL,
  is_active   TINYINT(1)        NOT NULL DEFAULT 1,
  created_at  DATETIME(3)       NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  created_by  INT UNSIGNED      NULL,
  updated_at  DATETIME(3)       NULL,
  updated_by  INT UNSIGNED      NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_designations_code (code),
  UNIQUE KEY uq_designations_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE users (
  id                    INT UNSIGNED      NOT NULL AUTO_INCREMENT,
  staff_no              VARCHAR(20)       NOT NULL,
  username              VARCHAR(50)       NOT NULL,
  password_hash         VARCHAR(255)      NOT NULL,
  first_name            VARCHAR(80)       NOT NULL,
  middle_name           VARCHAR(80)       NULL,
  last_name             VARCHAR(80)       NOT NULL,
  phone                 VARCHAR(20)       NULL,
  email                 VARCHAR(190)      NULL,
  department_id         SMALLINT UNSIGNED NULL,
  status                ENUM('active','inactive') NOT NULL DEFAULT 'active',
  must_change_password  TINYINT(1)        NOT NULL DEFAULT 1,
  password_changed_at   DATETIME(3)       NULL,
  failed_login_count    SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  locked_until          DATETIME(3)       NULL,
  last_login_at         DATETIME(3)       NULL,
  last_login_ip         VARCHAR(45)       NULL,
  row_version           INT UNSIGNED      NOT NULL DEFAULT 1,
  created_at            DATETIME(3)       NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  created_by            INT UNSIGNED      NULL,
  updated_at            DATETIME(3)       NULL,
  updated_by            INT UNSIGNED      NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_users_username (username),
  UNIQUE KEY uq_users_staff_no (staff_no),
  KEY ix_users_department (department_id),
  KEY ix_users_status (status),
  CONSTRAINT fk_users_department FOREIGN KEY (department_id) REFERENCES departments (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE roles (
  id           SMALLINT UNSIGNED NOT NULL AUTO_INCREMENT,
  code         VARCHAR(50)       NOT NULL,
  name         VARCHAR(80)       NOT NULL,
  description  VARCHAR(255)      NULL,
  color        VARCHAR(20)       NOT NULL DEFAULT 'teal',
  is_system    TINYINT(1)        NOT NULL DEFAULT 0 COMMENT 'cannot be renamed or deleted',
  is_locked    TINYINT(1)        NOT NULL DEFAULT 0 COMMENT 'permissions cannot be edited (Admin)',
  row_version  INT UNSIGNED      NOT NULL DEFAULT 1,
  created_at   DATETIME(3)       NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  created_by   INT UNSIGNED      NULL,
  updated_at   DATETIME(3)       NULL,
  updated_by   INT UNSIGNED      NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_roles_code (code),
  UNIQUE KEY uq_roles_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE permissions (
  id           SMALLINT UNSIGNED NOT NULL AUTO_INCREMENT,
  code         VARCHAR(80)       NOT NULL,
  category     VARCHAR(50)       NOT NULL,
  label        VARCHAR(120)      NOT NULL,
  description  VARCHAR(255)      NULL,
  sort_order   SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (id),
  UNIQUE KEY uq_permissions_code (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE role_permissions (
  role_id        SMALLINT UNSIGNED NOT NULL,
  permission_id  SMALLINT UNSIGNED NOT NULL,
  granted_at     DATETIME(3)       NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  granted_by     INT UNSIGNED      NULL,
  PRIMARY KEY (role_id, permission_id),
  KEY ix_role_permissions_permission (permission_id),
  CONSTRAINT fk_role_permissions_role FOREIGN KEY (role_id) REFERENCES roles (id) ON DELETE CASCADE,
  CONSTRAINT fk_role_permissions_permission FOREIGN KEY (permission_id) REFERENCES permissions (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE user_roles (
  user_id      INT UNSIGNED      NOT NULL,
  role_id      SMALLINT UNSIGNED NOT NULL,
  assigned_at  DATETIME(3)       NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  assigned_by  INT UNSIGNED      NULL,
  PRIMARY KEY (user_id, role_id),
  KEY ix_user_roles_role (role_id),
  CONSTRAINT fk_user_roles_user FOREIGN KEY (user_id) REFERENCES users (id),
  CONSTRAINT fk_user_roles_role FOREIGN KEY (role_id) REFERENCES roles (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE user_designations (
  user_id         INT UNSIGNED      NOT NULL,
  designation_id  SMALLINT UNSIGNED NOT NULL,
  assigned_at     DATETIME(3)       NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  assigned_by     INT UNSIGNED      NULL,
  PRIMARY KEY (user_id, designation_id),
  KEY ix_user_designations_designation (designation_id),
  CONSTRAINT fk_user_designations_user FOREIGN KEY (user_id) REFERENCES users (id),
  CONSTRAINT fk_user_designations_designation FOREIGN KEY (designation_id) REFERENCES designations (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE user_sessions (
  id                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  token_hash        BINARY(32)      NOT NULL COMMENT 'SHA-256 of the cookie token',
  user_id           INT UNSIGNED    NOT NULL,
  ip_address        VARCHAR(45)     NOT NULL,
  user_agent        VARCHAR(255)    NULL,
  created_at        DATETIME(3)     NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  last_activity_at  DATETIME(3)     NOT NULL,
  expires_at        DATETIME(3)     NOT NULL COMMENT 'absolute limit',
  revoked_at        DATETIME(3)     NULL,
  revoked_reason    VARCHAR(40)     NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_user_sessions_token (token_hash),
  KEY ix_user_sessions_user (user_id, revoked_at),
  CONSTRAINT fk_user_sessions_user FOREIGN KEY (user_id) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE login_attempts (
  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  username      VARCHAR(50)     NOT NULL,
  user_id       INT UNSIGNED    NULL,
  ip_address    VARCHAR(45)     NOT NULL,
  success       TINYINT(1)      NOT NULL,
  reason        VARCHAR(40)     NOT NULL,
  attempted_at  DATETIME(3)     NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  PRIMARY KEY (id),
  KEY ix_login_attempts_ip (ip_address, attempted_at),
  KEY ix_login_attempts_user (user_id, attempted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE audit_log (
  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  occurred_at   DATETIME(3)     NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  user_id       INT UNSIGNED    NULL,
  username      VARCHAR(50)     NULL,
  user_display  VARCHAR(200)    NULL,
  ip_address    VARCHAR(45)     NULL,
  module        VARCHAR(50)     NOT NULL,
  action        VARCHAR(80)     NOT NULL,
  entity_type   VARCHAR(50)     NULL,
  entity_id     VARCHAR(50)     NULL,
  summary       VARCHAR(255)    NULL,
  changes       LONGTEXT        NULL,
  request_id    CHAR(16)        NULL,
  PRIMARY KEY (id),
  KEY ix_audit_log_time (occurred_at),
  KEY ix_audit_log_user (user_id, occurred_at),
  KEY ix_audit_log_module (module, occurred_at),
  KEY ix_audit_log_entity (entity_type, entity_id),
  CONSTRAINT ck_audit_log_changes CHECK (changes IS NULL OR JSON_VALID(changes))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE record_access_log (
  id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  occurred_at  DATETIME(3)     NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  user_id      INT UNSIGNED    NOT NULL,
  patient_id   BIGINT UNSIGNED NOT NULL COMMENT 'FK added with the patients table',
  module       VARCHAR(50)     NOT NULL,
  action       VARCHAR(30)     NOT NULL COMMENT 'view, print, export, download',
  entity_type  VARCHAR(50)     NULL,
  entity_id    VARCHAR(50)     NULL,
  ip_address   VARCHAR(45)     NULL,
  request_id   CHAR(16)        NULL,
  PRIMARY KEY (id),
  KEY ix_record_access_patient (patient_id, occurred_at),
  KEY ix_record_access_user (user_id, occurred_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE sequences (
  seq_key     VARCHAR(60)  NOT NULL,
  last_value  INT UNSIGNED NOT NULL,
  updated_at  DATETIME(3)  NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  PRIMARY KEY (seq_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE settings (
  setting_key    VARCHAR(80)  NOT NULL,
  setting_value  VARCHAR(500) NOT NULL,
  value_type     ENUM('int','string','bool','json') NOT NULL DEFAULT 'string',
  description    VARCHAR(255) NULL,
  updated_at     DATETIME(3)  NULL,
  updated_by     INT UNSIGNED NULL,
  PRIMARY KEY (setting_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE price_items (
  id            INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  code          VARCHAR(40)   NOT NULL,
  category      ENUM('consultation','investigation','surgery','procedure','lens','other') NOT NULL,
  name          VARCHAR(150)  NOT NULL,
  unit_price    DECIMAL(12,2) NOT NULL,
  currency      CHAR(3)       NOT NULL DEFAULT 'TZS',
  is_confirmed  TINYINT(1)    NOT NULL DEFAULT 0 COMMENT 'confirmed by finance',
  is_active     TINYINT(1)    NOT NULL DEFAULT 1,
  row_version   INT UNSIGNED  NOT NULL DEFAULT 1,
  created_at    DATETIME(3)   NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  created_by    INT UNSIGNED  NULL,
  updated_at    DATETIME(3)   NULL,
  updated_by    INT UNSIGNED  NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_price_items_code (code),
  UNIQUE KEY uq_price_items_name (category, name),
  CONSTRAINT ck_price_items_price CHECK (unit_price >= 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE price_history (
  id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  price_item_id  INT UNSIGNED    NOT NULL,
  old_price      DECIMAL(12,2)   NULL,
  new_price      DECIMAL(12,2)   NOT NULL,
  changed_at     DATETIME(3)     NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  changed_by     INT UNSIGNED    NULL,
  reason         VARCHAR(255)    NULL,
  PRIMARY KEY (id),
  KEY ix_price_history_item (price_item_id, changed_at),
  CONSTRAINT fk_price_history_item FOREIGN KEY (price_item_id) REFERENCES price_items (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE lookup_values (
  id          INT UNSIGNED      NOT NULL AUTO_INCREMENT,
  list_code   VARCHAR(50)       NOT NULL,
  value       VARCHAR(100)      NOT NULL,
  label       VARCHAR(150)      NOT NULL,
  sort_order  SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  is_active   TINYINT(1)        NOT NULL DEFAULT 1,
  created_at  DATETIME(3)       NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  created_by  INT UNSIGNED      NULL,
  updated_at  DATETIME(3)       NULL,
  updated_by  INT UNSIGNED      NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_lookup_values (list_code, value)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
