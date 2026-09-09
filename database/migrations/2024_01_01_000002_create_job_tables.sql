-- =====================================================================
-- Print jobs, files, per-job settings and payments.
-- =====================================================================

-- ---------------------------------------------------------------------
-- print_sessions — a customer's working basket between "QR scanned" and
-- "job submitted". Guests are identified by an opaque session token, so no
-- account is required anywhere in the customer flow.
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS print_sessions (
    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    session_token   CHAR(64) NOT NULL,
    location_id     INT UNSIGNED NOT NULL,
    printer_id      INT UNSIGNED DEFAULT NULL,
    user_id         INT UNSIGNED DEFAULT NULL,
    ip_hash         CHAR(64) DEFAULT NULL COMMENT 'HMAC of client IP; raw IP is not retained here',
    user_agent      VARCHAR(255) DEFAULT NULL,
    expires_at      DATETIME NOT NULL,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_psession_token (session_token),
    KEY idx_psession_expiry (expires_at),
    KEY idx_psession_location (location_id),
    CONSTRAINT fk_psession_location FOREIGN KEY (location_id)
        REFERENCES locations (id) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_psession_printer FOREIGN KEY (printer_id)
        REFERENCES printers (id) ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT fk_psession_user FOREIGN KEY (user_id)
        REFERENCES users (id) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- print_files — uploaded documents.
--
-- stored_name is a server-generated random name; original_name is retained
-- for display only and is never used to build a filesystem path. Files live
-- under /uploads, which sits OUTSIDE the web root.
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS print_files (
    id                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    session_id          BIGINT UNSIGNED DEFAULT NULL,
    location_id         INT UNSIGNED NOT NULL,
    original_name       VARCHAR(255) NOT NULL,
    stored_name         VARCHAR(120) NOT NULL COMMENT 'Random server-side filename',
    relative_path       VARCHAR(255) NOT NULL COMMENT 'Path under the private uploads root',
    extension           VARCHAR(10) NOT NULL,
    mime_type           VARCHAR(120) NOT NULL,
    size_bytes          BIGINT UNSIGNED NOT NULL,
    sha256              CHAR(64) NOT NULL,
    page_count          INT UNSIGNED DEFAULT NULL,
    page_count_source   ENUM('parsed','estimated','declared','unknown') NOT NULL DEFAULT 'unknown',
    scan_status         ENUM('pending','clean','infected','skipped','error') NOT NULL DEFAULT 'pending',
    scan_message        VARCHAR(255) DEFAULT NULL,
    is_deleted          TINYINT(1) NOT NULL DEFAULT 0,
    deleted_at          DATETIME DEFAULT NULL,
    purge_after         DATETIME NOT NULL COMMENT 'Retention deadline for automatic cleanup',
    created_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_files_stored (stored_name),
    KEY idx_files_session (session_id),
    KEY idx_files_purge (is_deleted, purge_after),
    KEY idx_files_location (location_id),
    KEY idx_files_hash (sha256),
    CONSTRAINT fk_files_session FOREIGN KEY (session_id)
        REFERENCES print_sessions (id) ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT fk_files_location FOREIGN KEY (location_id)
        REFERENCES locations (id) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- print_jobs — one row per document sent to one printer.
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS print_jobs (
    id                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    job_number          VARCHAR(24) NOT NULL COMMENT 'Human-facing reference, e.g. AK102548',
    batch_id            CHAR(32) DEFAULT NULL COMMENT 'Groups jobs submitted together in one basket',
    location_id         INT UNSIGNED NOT NULL,
    printer_id          INT UNSIGNED NOT NULL,
    file_id             BIGINT UNSIGNED DEFAULT NULL,
    session_id          BIGINT UNSIGNED DEFAULT NULL,
    user_id             INT UNSIGNED DEFAULT NULL,
    device_id           INT UNSIGNED DEFAULT NULL COMMENT 'Agent that claimed the job',

    copies              SMALLINT UNSIGNED NOT NULL DEFAULT 1,
    color_mode          ENUM('bw','color') NOT NULL DEFAULT 'bw',
    paper_size          VARCHAR(20) NOT NULL DEFAULT 'A4',
    orientation         ENUM('portrait','landscape') NOT NULL DEFAULT 'portrait',
    duplex              ENUM('single','double') NOT NULL DEFAULT 'single',
    page_range          VARCHAR(120) NOT NULL DEFAULT 'all',
    document_pages      INT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Pages in the source document',
    selected_pages      INT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Pages after the range filter',
    billable_pages      INT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'selected_pages * copies',
    sheets              INT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Physical sheets (halved for duplex)',

    subtotal_paise      INT UNSIGNED NOT NULL DEFAULT 0,
    tax_paise           INT UNSIGNED NOT NULL DEFAULT 0,
    gateway_fee_paise   INT UNSIGNED NOT NULL DEFAULT 0,
    discount_paise      INT UNSIGNED NOT NULL DEFAULT 0,
    total_paise         INT UNSIGNED NOT NULL DEFAULT 0,
    currency            CHAR(3) NOT NULL DEFAULT 'INR',
    price_breakdown     JSON DEFAULT NULL COMMENT 'Frozen copy of the pricing calculation',

    payment_status      ENUM('not_required','pending','paid','failed','refunded') NOT NULL DEFAULT 'not_required',
    status              ENUM('pending','payment_pending','paid','queued','processing','printing','completed','failed','cancelled')
                        NOT NULL DEFAULT 'pending',

    attempts            TINYINT UNSIGNED NOT NULL DEFAULT 0,
    max_attempts        TINYINT UNSIGNED NOT NULL DEFAULT 3,
    -- Optimistic lease: a worker/agent claims a job by writing its own token
    -- and a deadline. A crashed worker's lease simply expires and the job is
    -- re-offered, so no job can be lost or double-printed.
    lease_token         CHAR(32) DEFAULT NULL,
    lease_expires_at    DATETIME DEFAULT NULL,
    available_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'Retry backoff gate',

    remote_job_id       VARCHAR(64) DEFAULT NULL COMMENT 'IPP/CUPS job id reported by the printer',
    error_message       TEXT DEFAULT NULL,
    error_code          VARCHAR(60) DEFAULT NULL,
    cancelled_reason    VARCHAR(190) DEFAULT NULL,

    created_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    queued_at           DATETIME DEFAULT NULL,
    started_at          DATETIME DEFAULT NULL,
    completed_at        DATETIME DEFAULT NULL,
    updated_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    UNIQUE KEY uq_jobs_number (job_number),
    KEY idx_jobs_status (status, available_at),
    KEY idx_jobs_printer_status (printer_id, status),
    KEY idx_jobs_location_created (location_id, created_at),
    KEY idx_jobs_created (created_at),
    KEY idx_jobs_batch (batch_id),
    KEY idx_jobs_session (session_id),
    KEY idx_jobs_payment (payment_status),
    KEY idx_jobs_lease (lease_expires_at),
    KEY idx_jobs_reporting (created_at, location_id, color_mode, paper_size),
    CONSTRAINT fk_jobs_location FOREIGN KEY (location_id)
        REFERENCES locations (id) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_jobs_printer FOREIGN KEY (printer_id)
        REFERENCES printers (id) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_jobs_file FOREIGN KEY (file_id)
        REFERENCES print_files (id) ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT fk_jobs_session FOREIGN KEY (session_id)
        REFERENCES print_sessions (id) ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT fk_jobs_user FOREIGN KEY (user_id)
        REFERENCES users (id) ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT fk_jobs_device FOREIGN KEY (device_id)
        REFERENCES devices (id) ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT chk_jobs_copies CHECK (copies BETWEEN 1 AND 999)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- print_settings — the per-job option set in normalised key/value form.
--
-- print_jobs holds the settings that are queried and reported on; this table
-- carries driver-specific extras (IPP attributes, CUPS -o options, agent
-- hints) without forcing a schema change for every new printer feature.
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS print_settings (
    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    job_id          BIGINT UNSIGNED NOT NULL,
    setting_key     VARCHAR(60) NOT NULL,
    setting_value   VARCHAR(255) NOT NULL,
    applied         TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Set once the driver confirms it accepted the option',
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_setting (job_id, setting_key),
    CONSTRAINT fk_setting_job FOREIGN KEY (job_id)
        REFERENCES print_jobs (id) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- job_events — append-only status transition log (who/what/when/why).
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS job_events (
    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    job_id          BIGINT UNSIGNED NOT NULL,
    from_status     VARCHAR(24) DEFAULT NULL,
    to_status       VARCHAR(24) NOT NULL,
    actor_type      ENUM('customer','admin','agent','system','gateway') NOT NULL DEFAULT 'system',
    actor_id        VARCHAR(64) DEFAULT NULL,
    message         TEXT DEFAULT NULL,
    context         JSON DEFAULT NULL,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_events_job (job_id, created_at),
    CONSTRAINT fk_events_job FOREIGN KEY (job_id)
        REFERENCES print_jobs (id) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- payments — one row per gateway order. `verified_server_side` is the ONLY
-- flag the queue trusts; a client-side success callback never sets it.
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS payments (
    id                      BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    batch_id                CHAR(32) NOT NULL COMMENT 'Payment covers every job in this basket',
    location_id             INT UNSIGNED NOT NULL,
    session_id              BIGINT UNSIGNED DEFAULT NULL,
    gateway                 VARCHAR(40) NOT NULL DEFAULT 'razorpay',
    gateway_order_id        VARCHAR(120) DEFAULT NULL,
    gateway_payment_id      VARCHAR(120) DEFAULT NULL,
    gateway_signature       VARCHAR(255) DEFAULT NULL,
    amount_paise            INT UNSIGNED NOT NULL,
    currency                CHAR(3) NOT NULL DEFAULT 'INR',
    status                  ENUM('created','pending','paid','failed','refunded','cancelled') NOT NULL DEFAULT 'created',
    verified_server_side    TINYINT(1) NOT NULL DEFAULT 0,
    verification_method     ENUM('none','signature','api_fetch','webhook') NOT NULL DEFAULT 'none',
    verified_at             DATETIME DEFAULT NULL,
    method                  VARCHAR(40) DEFAULT NULL COMMENT 'upi / card / netbanking as reported by the gateway',
    contact_hash            CHAR(64) DEFAULT NULL,
    failure_reason          VARCHAR(255) DEFAULT NULL,
    refund_id               VARCHAR(120) DEFAULT NULL,
    refunded_paise          INT UNSIGNED NOT NULL DEFAULT 0,
    gateway_response        JSON DEFAULT NULL COMMENT 'Redacted gateway payload for reconciliation',
    created_at              DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at              DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_payment_order (gateway, gateway_order_id),
    UNIQUE KEY uq_payment_batch (batch_id),
    KEY idx_payment_status (status, created_at),
    KEY idx_payment_gateway_payment (gateway_payment_id),
    KEY idx_payment_location (location_id, created_at),
    CONSTRAINT fk_payment_location FOREIGN KEY (location_id)
        REFERENCES locations (id) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_payment_session FOREIGN KEY (session_id)
        REFERENCES print_sessions (id) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Link jobs to the payment that covers them (added after both tables exist).
ALTER TABLE print_jobs
    ADD COLUMN payment_id BIGINT UNSIGNED DEFAULT NULL AFTER payment_status,
    ADD KEY idx_jobs_payment_id (payment_id),
    ADD CONSTRAINT fk_jobs_payment FOREIGN KEY (payment_id)
        REFERENCES payments (id) ON DELETE SET NULL ON UPDATE CASCADE;
