-- =====================================================================
-- Krishna Printer — core schema
--
-- Conventions used throughout:
--   * All money is stored as an unsigned integer number of paise. Storing
--     currency as DECIMAL/FLOAT invites rounding drift across the pricing
--     engine, the gateway and the reports; paise is also the unit Razorpay
--     itself uses, so no conversion happens at the payment boundary.
--   * All timestamps are UTC DATETIME. The session sets time_zone='+00:00'
--     so NOW() is UTC regardless of server locale; display conversion to the
--     configured timezone happens in PHP.
--   * InnoDB + utf8mb4 everywhere so foreign keys and emoji-safe text work.
-- =====================================================================

-- ---------------------------------------------------------------------
-- admins — staff accounts for the management panel
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS admins (
    id                  INT UNSIGNED NOT NULL AUTO_INCREMENT,
    name                VARCHAR(120) NOT NULL,
    email               VARCHAR(190) NOT NULL,
    password_hash       VARCHAR(255) NOT NULL,
    role                ENUM('super_admin','admin','operator','viewer') NOT NULL DEFAULT 'operator',
    phone               VARCHAR(32) DEFAULT NULL,
    is_active           TINYINT(1) NOT NULL DEFAULT 1,
    must_change_password TINYINT(1) NOT NULL DEFAULT 0,
    failed_attempts     SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    locked_until        DATETIME DEFAULT NULL,
    last_login_at       DATETIME DEFAULT NULL,
    last_login_ip       VARCHAR(45) DEFAULT NULL,
    password_changed_at DATETIME DEFAULT NULL,
    remember_token_hash CHAR(64) DEFAULT NULL,
    created_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_admins_email (email),
    KEY idx_admins_active (is_active),
    KEY idx_admins_remember (remember_token_hash)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- users — optional customer accounts. The customer flow is deliberately
-- account-free; this table exists for repeat customers who opt in and for
-- B2B accounts that need invoicing.
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS users (
    id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
    name            VARCHAR(120) DEFAULT NULL,
    email           VARCHAR(190) DEFAULT NULL,
    phone           VARCHAR(32) DEFAULT NULL,
    password_hash   VARCHAR(255) DEFAULT NULL,
    is_active       TINYINT(1) NOT NULL DEFAULT 1,
    email_verified_at DATETIME DEFAULT NULL,
    last_login_at   DATETIME DEFAULT NULL,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_users_email (email),
    UNIQUE KEY uq_users_phone (phone)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- locations — one physical shop/branch. Each carries its own QR token.
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS locations (
    id                  INT UNSIGNED NOT NULL AUTO_INCREMENT,
    code                VARCHAR(40) NOT NULL COMMENT 'URL-safe public slug used in the QR link',
    name                VARCHAR(150) NOT NULL,
    address_line1       VARCHAR(190) DEFAULT NULL,
    address_line2       VARCHAR(190) DEFAULT NULL,
    city                VARCHAR(90) DEFAULT NULL,
    state               VARCHAR(90) DEFAULT NULL,
    postal_code         VARCHAR(20) DEFAULT NULL,
    country             VARCHAR(2) NOT NULL DEFAULT 'IN',
    contact_name        VARCHAR(120) DEFAULT NULL,
    contact_phone       VARCHAR(32) DEFAULT NULL,
    contact_email       VARCHAR(190) DEFAULT NULL,
    timezone            VARCHAR(64) NOT NULL DEFAULT 'Asia/Kolkata',
    -- The QR token is stored only as a hash. A stolen database dump therefore
    -- does not yield working QR links; the plaintext exists solely in the
    -- printed QR code. qr_token_hint holds the first 8 chars so the admin UI
    -- can identify which token a printout corresponds to.
    qr_token_hash       CHAR(64) NOT NULL,
    qr_token_hint       VARCHAR(12) NOT NULL,
    qr_token_issued_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    qr_token_rotated_by INT UNSIGNED DEFAULT NULL,
    status              ENUM('active','inactive','maintenance') NOT NULL DEFAULT 'active',
    notes               TEXT DEFAULT NULL,
    created_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    deleted_at          DATETIME DEFAULT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_locations_code (code),
    UNIQUE KEY uq_locations_qr_token (qr_token_hash),
    KEY idx_locations_status (status, deleted_at),
    KEY idx_locations_city (city),
    CONSTRAINT fk_locations_rotated_by FOREIGN KEY (qr_token_rotated_by)
        REFERENCES admins (id) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- devices — the small print agent (Raspberry Pi class) installed at a
-- location. It polls the cloud outbound over HTTPS, so no inbound port and
-- no VPN is required, and printer ports are never exposed to the internet.
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS devices (
    id                  INT UNSIGNED NOT NULL AUTO_INCREMENT,
    location_id         INT UNSIGNED NOT NULL,
    name                VARCHAR(120) NOT NULL,
    device_uid          CHAR(36) NOT NULL COMMENT 'Stable identifier the agent reports',
    agent_version       VARCHAR(40) DEFAULT NULL,
    platform            VARCHAR(80) DEFAULT NULL,
    status              ENUM('pending','online','offline','disabled') NOT NULL DEFAULT 'pending',
    last_seen_at        DATETIME DEFAULT NULL,
    last_ip             VARCHAR(45) DEFAULT NULL,
    poll_interval_secs  SMALLINT UNSIGNED NOT NULL DEFAULT 10,
    created_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_devices_uid (device_uid),
    KEY idx_devices_location (location_id, status),
    KEY idx_devices_last_seen (last_seen_at),
    CONSTRAINT fk_devices_location FOREIGN KEY (location_id)
        REFERENCES locations (id) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- printers
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS printers (
    id                      INT UNSIGNED NOT NULL AUTO_INCREMENT,
    location_id             INT UNSIGNED NOT NULL,
    device_id               INT UNSIGNED DEFAULT NULL COMMENT 'Agent that owns this printer, when driver=agent',
    name                    VARCHAR(150) NOT NULL,
    code                    VARCHAR(40) NOT NULL,
    manufacturer            VARCHAR(80) DEFAULT NULL,
    model                   VARCHAR(120) DEFAULT NULL,
    -- capability_profile names a built-in profile (e.g. canon_gm4070) that
    -- seeds the DECLARED capability set. Declared capabilities are never shown
    -- to customers until a probe verifies them; see printer_capabilities.
    capability_profile      VARCHAR(60) NOT NULL DEFAULT 'generic',
    queue_name              VARCHAR(120) DEFAULT NULL COMMENT 'CUPS/LPD queue name on the agent',
    status                  ENUM('online','offline','unknown','error','maintenance') NOT NULL DEFAULT 'unknown',
    is_enabled              TINYINT(1) NOT NULL DEFAULT 1,
    is_default              TINYINT(1) NOT NULL DEFAULT 0,
    supports_color          TINYINT(1) NOT NULL DEFAULT 0,
    supports_duplex         TINYINT(1) NOT NULL DEFAULT 0,
    capabilities_verified_at DATETIME DEFAULT NULL,
    last_seen_at            DATETIME DEFAULT NULL,
    last_success_at         DATETIME DEFAULT NULL,
    last_error_at           DATETIME DEFAULT NULL,
    last_error               TEXT DEFAULT NULL,
    consecutive_failures    SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    max_queue_depth         SMALLINT UNSIGNED NOT NULL DEFAULT 25,
    notes                   TEXT DEFAULT NULL,
    created_at              DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at              DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    deleted_at              DATETIME DEFAULT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_printers_code (code),
    KEY idx_printers_location (location_id, is_enabled, deleted_at),
    KEY idx_printers_status (status),
    KEY idx_printers_device (device_id),
    KEY idx_printers_last_seen (last_seen_at),
    CONSTRAINT fk_printers_location FOREIGN KEY (location_id)
        REFERENCES locations (id) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_printers_device FOREIGN KEY (device_id)
        REFERENCES devices (id) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- printer_connections — transport details, kept separate from `printers`
-- so credentials live in one narrow table that the admin UI reads with a
-- deliberate extra step, and so a printer can hold a primary plus a fallback
-- transport without duplicating the printer row.
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS printer_connections (
    id                  INT UNSIGNED NOT NULL AUTO_INCREMENT,
    printer_id          INT UNSIGNED NOT NULL,
    driver              ENUM('agent','ipp','raw9100','lpd','null') NOT NULL DEFAULT 'agent',
    priority            TINYINT UNSIGNED NOT NULL DEFAULT 1 COMMENT 'Lower value tried first',
    host                VARCHAR(190) DEFAULT NULL,
    port                SMALLINT UNSIGNED DEFAULT NULL,
    -- Transport-level security for the printer link itself.
    use_tls             TINYINT(1) NOT NULL DEFAULT 0,
    verify_tls          TINYINT(1) NOT NULL DEFAULT 1,
    ipp_path            VARCHAR(190) DEFAULT NULL COMMENT 'e.g. /ipp/print',
    lpd_queue           VARCHAR(120) DEFAULT NULL,
    username            VARCHAR(120) DEFAULT NULL,
    -- Encrypted at rest with the application key (XChaCha20-Poly1305 / AES-GCM).
    password_encrypted  TEXT DEFAULT NULL,
    timeout_seconds     SMALLINT UNSIGNED NOT NULL DEFAULT 10,
    options_json        JSON DEFAULT NULL,
    is_enabled          TINYINT(1) NOT NULL DEFAULT 1,
    last_tested_at      DATETIME DEFAULT NULL,
    last_test_result    ENUM('ok','failed','never') NOT NULL DEFAULT 'never',
    last_test_message   TEXT DEFAULT NULL,
    created_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_conn_printer (printer_id, is_enabled, priority),
    CONSTRAINT fk_conn_printer FOREIGN KEY (printer_id)
        REFERENCES printers (id) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- printer_capabilities — the verified capability matrix.
--
-- `source` records HOW we know: 'declared' comes from the built-in model
-- profile and is NOT trusted for customer-facing option lists; 'probed' came
-- back from the printer itself (IPP Get-Printer-Attributes or the agent's
-- CUPS query); 'manual' was confirmed by an operator who physically tested a
-- print. Only 'probed' and 'manual' entries drive the customer UI.
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS printer_capabilities (
    id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
    printer_id      INT UNSIGNED NOT NULL,
    capability      VARCHAR(60) NOT NULL COMMENT 'paper_size | color_mode | duplex | orientation | resolution',
    value           VARCHAR(60) NOT NULL,
    is_supported    TINYINT(1) NOT NULL DEFAULT 1,
    is_default      TINYINT(1) NOT NULL DEFAULT 0,
    source          ENUM('declared','probed','manual') NOT NULL DEFAULT 'declared',
    verified_at     DATETIME DEFAULT NULL,
    verified_by     INT UNSIGNED DEFAULT NULL,
    evidence        TEXT DEFAULT NULL COMMENT 'Raw attribute value / operator note backing this row',
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_cap (printer_id, capability, value),
    KEY idx_cap_lookup (printer_id, capability, is_supported),
    CONSTRAINT fk_cap_printer FOREIGN KEY (printer_id)
        REFERENCES printers (id) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_cap_verified_by FOREIGN KEY (verified_by)
        REFERENCES admins (id) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- printer_status_checks — health-probe history, used for the "last seen /
-- last error" display and for uptime reporting.
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS printer_status_checks (
    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    printer_id      INT UNSIGNED NOT NULL,
    status          ENUM('online','offline','unknown','error') NOT NULL,
    driver          VARCHAR(20) NOT NULL,
    latency_ms      INT UNSIGNED DEFAULT NULL,
    state_reason    VARCHAR(190) DEFAULT NULL COMMENT 'IPP printer-state-reasons, e.g. media-empty',
    message         TEXT DEFAULT NULL,
    checked_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_status_printer_time (printer_id, checked_at),
    CONSTRAINT fk_status_printer FOREIGN KEY (printer_id)
        REFERENCES printers (id) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
