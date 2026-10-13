-- ============================================================
-- Password Reset Tokens + Email Verification Migration
-- Run after all prior migrations.
-- ============================================================

USE attendance_system;

-- ── Password reset tokens ─────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS password_reset_tokens (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    user_id     INT NOT NULL,
    token       VARCHAR(128) NOT NULL UNIQUE,
    expires_at  DATETIME NOT NULL,
    used        TINYINT(1) DEFAULT 0,
    ip_address  VARCHAR(45) DEFAULT '',
    created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_token   (token),
    INDEX idx_user    (user_id),
    INDEX idx_expires (expires_at)
);

-- ── Add force_password_reset flag to users ───────────────────────────────────
-- Admin can set this to 1 to force a user to change password on next login
ALTER TABLE users
    ADD COLUMN IF NOT EXISTS force_password_reset TINYINT(1) DEFAULT 0
        COMMENT 'Admin-forced password reset on next login',
    ADD COLUMN IF NOT EXISTS email_verified       TINYINT(1) DEFAULT 1
        COMMENT '1=verified (admin-created accounts are pre-verified)',
    ADD COLUMN IF NOT EXISTS last_password_change TIMESTAMP NULL
        COMMENT 'Timestamp of last password change';

-- Admin-created accounts are pre-verified (no email verification step needed)
-- Only set email_verified=0 if you add a self-registration flow in future
UPDATE users SET email_verified=1 WHERE email_verified IS NULL OR email_verified=0;
