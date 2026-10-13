-- ============================================================
-- Face Registration Security Migration
-- Run after all prior migrations.
--
-- Biometric data is sensitive. This migration adds:
--   1. face_registration_sessions  — nonce-based pipeline tokens
--   2. face_registrations          — full audit trail per enrollment
--   3. Encryption key setting      — AES key stored in settings
--   4. Admin approval flag         — admin_approved on users
-- ============================================================

USE attendance_system;

-- ── Face registration pipeline sessions (nonce) ───────────────────────────────
-- Each registration attempt gets a server-issued nonce.
-- The client must present this nonce when submitting descriptors.
-- Prevents replay attacks and ensures the full pipeline was followed.
CREATE TABLE IF NOT EXISTS face_registration_sessions (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    user_id         INT NOT NULL,
    nonce           VARCHAR(128) NOT NULL UNIQUE,
    liveness_passed TINYINT(1)   DEFAULT 0,
    samples_received TINYINT     DEFAULT 0  COMMENT 'How many descriptor samples collected',
    sample_data     MEDIUMTEXT   DEFAULT NULL COMMENT 'JSON array of collected descriptor samples',
    ip_address      VARCHAR(45)  DEFAULT '',
    user_agent      VARCHAR(255) DEFAULT '',
    expires_at      DATETIME     NOT NULL,
    completed       TINYINT(1)   DEFAULT 0,
    created_at      TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_nonce   (nonce),
    INDEX idx_user    (user_id),
    INDEX idx_expires (expires_at)
);

-- ── Face registration audit log ───────────────────────────────────────────────
-- Every enrollment, update, deletion, revocation is permanently recorded.
-- Biometric changes must be traceable.
CREATE TABLE IF NOT EXISTS face_registrations (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    user_id         INT NOT NULL,

    action          ENUM(
                        'registered',       -- new face enrolled
                        'updated',          -- existing face replaced
                        'deleted_by_user',  -- student deleted own face
                        'deleted_by_admin', -- admin removed face
                        'revoked',          -- admin revoked/suspended
                        'reactivated',      -- admin re-enabled
                        'failed_attempt'    -- failed registration attempt
                    ) NOT NULL,

    -- Who performed it
    performed_by    INT NULL     COMMENT 'user_id of actor (student or admin)',
    performed_role  VARCHAR(30)  DEFAULT '',

    -- Technical details
    samples_used    TINYINT      DEFAULT 1,
    liveness_passed TINYINT(1)   DEFAULT 0,
    distance_score  DECIMAL(6,4) DEFAULT NULL COMMENT 'Self-check similarity vs previous (if update)',
    ip_address      VARCHAR(45)  DEFAULT '',
    user_agent      VARCHAR(255) DEFAULT '',
    device_type     VARCHAR(30)  DEFAULT '',

    -- Admin control
    admin_notes     TEXT         DEFAULT '',
    approved_by     INT NULL,
    approved_at     TIMESTAMP    NULL,

    created_at      TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,

    FOREIGN KEY (user_id)      REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (performed_by) REFERENCES users(id) ON DELETE SET NULL,
    FOREIGN KEY (approved_by)  REFERENCES users(id) ON DELETE SET NULL,

    INDEX idx_user    (user_id),
    INDEX idx_action  (action),
    INDEX idx_created (created_at)
);

-- ── Extend users with biometric control fields ────────────────────────────────
ALTER TABLE users
    ADD COLUMN IF NOT EXISTS face_registered_at  TIMESTAMP NULL
        COMMENT 'When face was last enrolled',
    ADD COLUMN IF NOT EXISTS face_registered_by  INT NULL
        COMMENT 'Who enrolled (self or admin)',
    ADD COLUMN IF NOT EXISTS face_admin_approved TINYINT(1) DEFAULT 1
        COMMENT '0=pending admin approval, 1=approved and active',
    ADD COLUMN IF NOT EXISTS face_revoked        TINYINT(1) DEFAULT 0
        COMMENT 'Admin-revoked biometric access';

-- ── AES encryption key for face descriptors ───────────────────────────────────
-- Generate a strong random key before first deployment.
-- NEVER commit the real key to git. Store it outside the web root in production.
-- This default is a placeholder — change it immediately.
INSERT IGNORE INTO settings (setting_key, setting_value, description) VALUES
(
    'face_encryption_key',
    '',
    'AES-256 encryption key for face descriptors. Set a 32-byte random string before deployment. KEEP SECRET.'
),
(
    'face_require_admin_approval',
    '0',
    'Require admin approval before enrolled face is active for check-in (1=yes, 0=no)'
),
(
    'face_min_samples',
    '3',
    'Minimum number of face descriptor samples to capture during registration'
),
(
    'face_max_registrations_per_hour',
    '3',
    'Max registration attempts per student per hour (rate limiting)'
);

-- ── Back-fill existing face registrations ────────────────────────────────────
-- Mark existing face descriptors as registered/approved
UPDATE users
SET face_registered_at  = created_at,
    face_admin_approved = 1,
    face_revoked        = 0
WHERE face_descriptor IS NOT NULL
  AND face_descriptor != ''
  AND face_registered_at IS NULL;
