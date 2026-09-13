-- =====================================================================
-- Krishna Printer — shop self-registration
--
-- A shop owner signs up on the public site, an operator approves them, and
-- approval is what creates their location and their print agent. Nothing a
-- stranger types on a public form becomes a live location on its own: the
-- estate is the operator's, and the queue below is how it stays that way.
--
-- Partners are deliberately NOT rows in `admins`. An admin account carries
-- permissions over the whole estate, and the difference between "can see the
-- panel" and "can see their own shop" is not a thing to express as a role on
-- the same table — one forgotten check and a shop owner is reading another
-- shop's takings. A separate table means a partner has no admin identity to
-- widen in the first place.
-- =====================================================================

CREATE TABLE IF NOT EXISTS partners (
    id                  INT UNSIGNED NOT NULL AUTO_INCREMENT,
    shop_name           VARCHAR(150) NOT NULL,
    contact_name        VARCHAR(120) NOT NULL,
    email               VARCHAR(190) NOT NULL,
    phone               VARCHAR(32) NOT NULL,
    password_hash       VARCHAR(255) NOT NULL,
    address_line1       VARCHAR(190) DEFAULT NULL,
    city                VARCHAR(90) DEFAULT NULL,
    state               VARCHAR(90) DEFAULT NULL,
    postal_code         VARCHAR(20) DEFAULT NULL,

    -- pending  — registered, waiting for an operator to look at it
    -- approved — live; location_id and device_id are set
    -- rejected — turned down, with the reason in review_note
    -- suspended— was live, switched off without deleting the history
    status              ENUM('pending','approved','rejected','suspended') NOT NULL DEFAULT 'pending',

    -- Both filled in at approval, not at registration.
    location_id         INT UNSIGNED DEFAULT NULL,
    device_id           INT UNSIGNED DEFAULT NULL,

    review_note         TEXT DEFAULT NULL,
    reviewed_by         INT UNSIGNED DEFAULT NULL,
    reviewed_at         DATETIME DEFAULT NULL,

    registered_ip       VARCHAR(45) DEFAULT NULL,
    -- Same lockout shape as `admins`: a public login form is a public
    -- password-guessing form unless failures cost something.
    failed_attempts     SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    locked_until        DATETIME DEFAULT NULL,
    last_login_at       DATETIME DEFAULT NULL,
    last_login_ip       VARCHAR(45) DEFAULT NULL,

    created_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_partners_email (email),
    KEY idx_partners_status (status, created_at),
    KEY idx_partners_location (location_id),
    -- A location removed from the estate leaves the partner row behind with
    -- nothing attached, rather than deleting the registration and the audit
    -- trail that goes with it.
    CONSTRAINT fk_partners_location FOREIGN KEY (location_id)
        REFERENCES locations (id) ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT fk_partners_device FOREIGN KEY (device_id)
        REFERENCES devices (id) ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT fk_partners_reviewer FOREIGN KEY (reviewed_by)
        REFERENCES admins (id) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
