-- =====================================================================
-- Pricing engine, API auth, auditing and platform-management tables.
-- =====================================================================

-- ---------------------------------------------------------------------
-- pricing_rules — a resolution ladder rather than a flat price list.
--
-- A rule may be scoped to a specific printer, a specific location, or be
-- global (both NULL). Matching picks the most specific applicable rule:
--   printer-specific  >  location-specific  >  global
-- Within the same specificity, the higher `priority` wins, then the newest.
-- This is what lets one location charge more for A4 colour than another
-- without duplicating the whole price list for all 50 sites.
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS pricing_rules (
    id                  INT UNSIGNED NOT NULL AUTO_INCREMENT,
    name                VARCHAR(120) NOT NULL,
    location_id         INT UNSIGNED DEFAULT NULL COMMENT 'NULL = applies to every location',
    printer_id          INT UNSIGNED DEFAULT NULL COMMENT 'NULL = applies to every printer in scope',
    paper_size          VARCHAR(20) DEFAULT NULL COMMENT 'NULL = any paper size',
    color_mode          ENUM('bw','color') DEFAULT NULL COMMENT 'NULL = any colour mode',
    duplex              ENUM('single','double') DEFAULT NULL COMMENT 'NULL = any duplex mode',
    price_per_page_paise INT UNSIGNED NOT NULL,
    -- Optional volume tiering: the rule only applies at or above min_pages,
    -- so "first 50 pages at ₹2, beyond that ₹1.50" is expressible.
    min_pages           INT UNSIGNED NOT NULL DEFAULT 1,
    max_pages           INT UNSIGNED DEFAULT NULL,
    setup_fee_paise     INT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Flat per-job charge added once',
    minimum_charge_paise INT UNSIGNED NOT NULL DEFAULT 0,
    priority            SMALLINT NOT NULL DEFAULT 0,
    is_active           TINYINT(1) NOT NULL DEFAULT 1,
    valid_from          DATETIME DEFAULT NULL,
    valid_until         DATETIME DEFAULT NULL,
    created_by          INT UNSIGNED DEFAULT NULL,
    created_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_pricing_lookup (is_active, location_id, printer_id, paper_size, color_mode, duplex),
    KEY idx_pricing_priority (priority),
    CONSTRAINT fk_pricing_location FOREIGN KEY (location_id)
        REFERENCES locations (id) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_pricing_printer FOREIGN KEY (printer_id)
        REFERENCES printers (id) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_pricing_admin FOREIGN KEY (created_by)
        REFERENCES admins (id) ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT chk_pricing_pages CHECK (max_pages IS NULL OR max_pages >= min_pages)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- api_tokens — bearer credentials for print agents and integrations.
-- Only the hash is stored; the plaintext is shown once at creation.
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS api_tokens (
    id                  INT UNSIGNED NOT NULL AUTO_INCREMENT,
    name                VARCHAR(120) NOT NULL,
    token_hash          CHAR(64) NOT NULL COMMENT 'SHA-256 of the bearer token',
    token_prefix        VARCHAR(16) NOT NULL COMMENT 'Lookup shortcut + admin-visible identifier',
    abilities           VARCHAR(255) NOT NULL DEFAULT 'agent' COMMENT 'Comma-separated scope list',
    device_id           INT UNSIGNED DEFAULT NULL,
    location_id         INT UNSIGNED DEFAULT NULL,
    created_by          INT UNSIGNED DEFAULT NULL,
    last_used_at        DATETIME DEFAULT NULL,
    last_used_ip        VARCHAR(45) DEFAULT NULL,
    expires_at          DATETIME DEFAULT NULL,
    revoked_at          DATETIME DEFAULT NULL,
    -- Token rotation: a rotated token stays valid for a short grace window so
    -- an agent mid-poll is not locked out, then stops working.
    rotated_from        INT UNSIGNED DEFAULT NULL,
    grace_until         DATETIME DEFAULT NULL,
    created_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_token_hash (token_hash),
    KEY idx_token_prefix (token_prefix),
    KEY idx_token_device (device_id),
    KEY idx_token_active (revoked_at, expires_at),
    CONSTRAINT fk_token_device FOREIGN KEY (device_id)
        REFERENCES devices (id) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_token_location FOREIGN KEY (location_id)
        REFERENCES locations (id) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_token_admin FOREIGN KEY (created_by)
        REFERENCES admins (id) ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT fk_token_rotated FOREIGN KEY (rotated_from)
        REFERENCES api_tokens (id) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- activity_logs — audit trail. Append-only by convention; the application
-- exposes no update or delete path for these rows.
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS activity_logs (
    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    actor_type      ENUM('admin','customer','agent','system','gateway') NOT NULL DEFAULT 'system',
    actor_id        VARCHAR(64) DEFAULT NULL,
    actor_label     VARCHAR(190) DEFAULT NULL,
    action          VARCHAR(80) NOT NULL,
    subject_type    VARCHAR(60) DEFAULT NULL,
    subject_id      VARCHAR(64) DEFAULT NULL,
    description     VARCHAR(500) DEFAULT NULL,
    ip_address      VARCHAR(45) DEFAULT NULL,
    user_agent      VARCHAR(255) DEFAULT NULL,
    context         JSON DEFAULT NULL,
    severity        ENUM('info','notice','warning','critical') NOT NULL DEFAULT 'info',
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_audit_action (action, created_at),
    KEY idx_audit_actor (actor_type, actor_id),
    KEY idx_audit_subject (subject_type, subject_id),
    KEY idx_audit_created (created_at),
    KEY idx_audit_severity (severity, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- system_settings — runtime-editable configuration.
-- Secrets (gateway keys, GitHub token) are stored encrypted with
-- is_encrypted=1 and are never returned to the browser in plaintext.
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS system_settings (
    id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
    setting_key     VARCHAR(80) NOT NULL,
    setting_value   TEXT DEFAULT NULL,
    value_type      ENUM('string','int','bool','json','secret') NOT NULL DEFAULT 'string',
    is_encrypted    TINYINT(1) NOT NULL DEFAULT 0,
    group_name      VARCHAR(40) NOT NULL DEFAULT 'general',
    label           VARCHAR(190) DEFAULT NULL,
    description     VARCHAR(500) DEFAULT NULL,
    is_public       TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Safe to expose to the customer front-end',
    updated_by      INT UNSIGNED DEFAULT NULL,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_setting_key (setting_key),
    KEY idx_setting_group (group_name),
    CONSTRAINT fk_setting_admin FOREIGN KEY (updated_by)
        REFERENCES admins (id) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- backups — database and application snapshots taken before updates and
-- on schedule. Files are written under /backups (outside the web root).
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS backups (
    id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
    backup_type     ENUM('database','application','full') NOT NULL,
    trigger_source  ENUM('manual','scheduled','pre_update','pre_rollback') NOT NULL DEFAULT 'manual',
    filename        VARCHAR(190) NOT NULL,
    relative_path   VARCHAR(255) NOT NULL,
    size_bytes      BIGINT UNSIGNED NOT NULL DEFAULT 0,
    sha256          CHAR(64) DEFAULT NULL,
    app_version     VARCHAR(40) DEFAULT NULL,
    commit_sha      VARCHAR(40) DEFAULT NULL,
    status          ENUM('running','completed','failed','restored','deleted') NOT NULL DEFAULT 'running',
    error_message   TEXT DEFAULT NULL,
    created_by      INT UNSIGNED DEFAULT NULL,
    started_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    completed_at    DATETIME DEFAULT NULL,
    restored_at     DATETIME DEFAULT NULL,
    expires_at      DATETIME DEFAULT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_backup_file (filename),
    KEY idx_backup_type (backup_type, status, started_at),
    KEY idx_backup_expiry (expires_at),
    CONSTRAINT fk_backup_admin FOREIGN KEY (created_by)
        REFERENCES admins (id) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- update_logs — one row per GitHub update attempt, with a per-step trace
-- so a failed update can be explained precisely and audited later.
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS update_logs (
    id                  INT UNSIGNED NOT NULL AUTO_INCREMENT,
    from_version        VARCHAR(40) DEFAULT NULL,
    to_version          VARCHAR(40) DEFAULT NULL,
    from_commit         VARCHAR(40) DEFAULT NULL,
    to_commit           VARCHAR(40) DEFAULT NULL,
    commit_message      TEXT DEFAULT NULL,
    commit_author       VARCHAR(190) DEFAULT NULL,
    commit_date         DATETIME DEFAULT NULL,
    changed_files       JSON DEFAULT NULL,
    repository          VARCHAR(190) DEFAULT NULL,
    branch              VARCHAR(120) DEFAULT NULL,
    status              ENUM('checking','downloading','backing_up','applying','migrating','verifying','completed','failed','rolled_back')
                        NOT NULL DEFAULT 'checking',
    steps               JSON DEFAULT NULL COMMENT 'Ordered per-step results with timings',
    migrations_run      JSON DEFAULT NULL,
    database_backup_id  INT UNSIGNED DEFAULT NULL,
    application_backup_id INT UNSIGNED DEFAULT NULL,
    rollback_performed  TINYINT(1) NOT NULL DEFAULT 0,
    rollback_reason     TEXT DEFAULT NULL,
    error_message       TEXT DEFAULT NULL,
    duration_ms         INT UNSIGNED DEFAULT NULL,
    triggered_by        INT UNSIGNED DEFAULT NULL,
    started_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    completed_at        DATETIME DEFAULT NULL,
    PRIMARY KEY (id),
    KEY idx_update_status (status, started_at),
    CONSTRAINT fk_update_db_backup FOREIGN KEY (database_backup_id)
        REFERENCES backups (id) ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT fk_update_app_backup FOREIGN KEY (application_backup_id)
        REFERENCES backups (id) ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT fk_update_admin FOREIGN KEY (triggered_by)
        REFERENCES admins (id) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- rate_limits — shared fixed-window counters. Lives in the database rather
-- than a per-process cache so limits hold across every PHP worker and every
-- app server behind the load balancer.
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS rate_limits (
    bucket_key      CHAR(64) NOT NULL,
    window_start    DATETIME NOT NULL,
    attempts        INT UNSIGNED NOT NULL DEFAULT 0,
    expires_at      DATETIME NOT NULL,
    PRIMARY KEY (bucket_key, window_start),
    KEY idx_rate_expiry (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- migrations — applied-migration ledger used by the installer and by the
-- GitHub updater's migration step.
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS migrations (
    id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    migration   VARCHAR(190) NOT NULL,
    checksum    CHAR(64) NOT NULL COMMENT 'Detects a migration edited after it was applied',
    batch       INT UNSIGNED NOT NULL,
    applied_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    duration_ms INT UNSIGNED DEFAULT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_migration (migration)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
