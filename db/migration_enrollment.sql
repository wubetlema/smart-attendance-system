-- Enrollment Management Migration
-- Run after schema.sql and all prior migrations.

USE attendance_system;

-- ── Extend class_students with section/year metadata ─────────────────────────
ALTER TABLE class_students
    ADD COLUMN IF NOT EXISTS enrolled_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    ADD COLUMN IF NOT EXISTS enrolled_by   INT NULL COMMENT 'Admin user who enrolled',
    ADD COLUMN IF NOT EXISTS section       VARCHAR(10) DEFAULT '' COMMENT 'Section within the class',
    ADD COLUMN IF NOT EXISTS academic_year VARCHAR(20) DEFAULT '',
    ADD COLUMN IF NOT EXISTS status        ENUM('active','dropped','transferred','completed') DEFAULT 'active',
    ADD COLUMN IF NOT EXISTS notes         VARCHAR(255) DEFAULT '';

ALTER TABLE class_students
    ADD COLUMN IF NOT EXISTS transferred_from INT NULL COMMENT 'Original class_id if transferred',
    ADD COLUMN IF NOT EXISTS transferred_at   TIMESTAMP NULL;

-- ── Enrollment History (full audit trail) ─────────────────────────────────────
CREATE TABLE IF NOT EXISTS enrollment_history (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    student_id      INT NOT NULL,
    class_id        INT NOT NULL,
    action          ENUM('enrolled','dropped','transferred_in','transferred_out','reactivated','completed') NOT NULL,
    performed_by    INT NULL COMMENT 'Admin/teacher who performed the action',
    from_class_id   INT NULL COMMENT 'Source class for transfer actions',
    to_class_id     INT NULL COMMENT 'Destination class for transfer actions',
    notes           VARCHAR(255) DEFAULT '',
    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    FOREIGN KEY (student_id)   REFERENCES users(id)   ON DELETE CASCADE,
    FOREIGN KEY (class_id)     REFERENCES classes(id)  ON DELETE CASCADE,
    FOREIGN KEY (performed_by) REFERENCES users(id)    ON DELETE SET NULL,
    FOREIGN KEY (from_class_id) REFERENCES classes(id) ON DELETE SET NULL,
    FOREIGN KEY (to_class_id)   REFERENCES classes(id) ON DELETE SET NULL,

    INDEX idx_student  (student_id),
    INDEX idx_class    (class_id),
    INDEX idx_action   (action),
    INDEX idx_created  (created_at)
);

-- ── CSV Import Batches (track who imported what and when) ─────────────────────
CREATE TABLE IF NOT EXISTS csv_import_batches (
    id           INT AUTO_INCREMENT PRIMARY KEY,
    type         ENUM('students','enrollments') NOT NULL,
    filename     VARCHAR(255) DEFAULT '',
    total_rows   INT DEFAULT 0,
    added        INT DEFAULT 0,
    skipped      INT DEFAULT 0,
    errors       INT DEFAULT 0,
    performed_by INT NULL,
    notes        TEXT DEFAULT '',
    created_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (performed_by) REFERENCES users(id) ON DELETE SET NULL
);

-- ── Notifications table (if not already created) ──────────────────────────────
CREATE TABLE IF NOT EXISTS notifications (
    id         INT AUTO_INCREMENT PRIMARY KEY,
    user_id    INT NOT NULL,
    message    TEXT NOT NULL,
    is_read    TINYINT(1) DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_user_read (user_id, is_read)
);

-- ── Security migration: is_first_login, dynamic QR, session state ─────────────
-- Users
ALTER TABLE users
    ADD COLUMN IF NOT EXISTS is_first_login TINYINT(1) DEFAULT 1 COMMENT 'Force password change on first login',
    ADD COLUMN IF NOT EXISTS email          VARCHAR(100) DEFAULT '' AFTER password,
    ADD COLUMN IF NOT EXISTS phone          VARCHAR(20)  DEFAULT '' AFTER email,
    ADD COLUMN IF NOT EXISTS is_active      TINYINT(1)   DEFAULT 1 AFTER phone,
    ADD COLUMN IF NOT EXISTS sms_phone      VARCHAR(20)  DEFAULT '' AFTER phone,
    ADD COLUMN IF NOT EXISTS sms_enabled    TINYINT(1)   DEFAULT 0 AFTER sms_phone;

-- Attendance sessions state machine
ALTER TABLE attendance_sessions
    ADD COLUMN IF NOT EXISTS status        ENUM('scheduled','not_started','open','late','closed') DEFAULT 'not_started' AFTER method,
    ADD COLUMN IF NOT EXISTS open_at       DATETIME NULL AFTER status,
    ADD COLUMN IF NOT EXISTS present_until DATETIME NULL AFTER open_at,
    ADD COLUMN IF NOT EXISTS late_until    DATETIME NULL AFTER present_until,
    ADD COLUMN IF NOT EXISTS auto_created  TINYINT(1) DEFAULT 0 AFTER late_until,
    ADD COLUMN IF NOT EXISTS schedule_id   INT NULL AFTER auto_created,
    ADD COLUMN IF NOT EXISTS room          VARCHAR(100) DEFAULT '' AFTER schedule_id;

-- Back-fill existing sessions: set open_at/present_until/late_until from expires_at
UPDATE attendance_sessions
SET
    open_at       = created_at,
    present_until = DATE_SUB(expires_at, INTERVAL 10 MINUTE),
    late_until    = expires_at,
    status        = CASE
                        WHEN expires_at < NOW() THEN 'closed'
                        WHEN expires_at > NOW() THEN 'open'
                        ELSE 'not_started'
                    END
WHERE open_at IS NULL;

-- Dynamic QR tokens table
CREATE TABLE IF NOT EXISTS dynamic_qr_tokens (
    id           INT AUTO_INCREMENT PRIMARY KEY,
    session_id   INT NOT NULL,
    token        VARCHAR(128) NOT NULL UNIQUE,
    valid_from   DATETIME NOT NULL,
    valid_until  DATETIME NOT NULL,
    is_used      TINYINT(1) DEFAULT 0,
    created_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (session_id) REFERENCES attendance_sessions(id) ON DELETE CASCADE,
    INDEX idx_session_valid (session_id, valid_until),
    INDEX idx_token         (token)
);

-- Activity logs (if not exists)
CREATE TABLE IF NOT EXISTS activity_logs (
    id         INT AUTO_INCREMENT PRIMARY KEY,
    user_id    INT NULL,
    action     VARCHAR(100) NOT NULL,
    details    TEXT DEFAULT '',
    ip_address VARCHAR(45) DEFAULT '',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_user   (user_id),
    INDEX idx_action (action),
    INDEX idx_created(created_at)
);

-- Login attempts (brute-force protection)
CREATE TABLE IF NOT EXISTS login_attempts (
    id           INT AUTO_INCREMENT PRIMARY KEY,
    ip_address   VARCHAR(45) NOT NULL,
    university_id VARCHAR(30) DEFAULT '',
    attempted_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_ip (ip_address, attempted_at)
);

-- Settings table (for auto-session config etc.)
CREATE TABLE IF NOT EXISTS settings (
    id            INT AUTO_INCREMENT PRIMARY KEY,
    setting_key   VARCHAR(100) NOT NULL UNIQUE,
    setting_value TEXT DEFAULT '',
    description   VARCHAR(255) DEFAULT '',
    updated_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

-- Default settings
INSERT IGNORE INTO settings (setting_key, setting_value, description) VALUES
('auto_session_enabled',    '1',   'Enable automatic attendance session creation from timetable'),
('auto_session_window_min', '5',   'Create session N minutes before class starts'),
('auto_present_minutes',    '10',  'Minutes from start where students are marked Present'),
('auto_late_minutes',       '20',  'Minutes from start where session closes (Late threshold)'),
('auto_session_method',     'qr',  'Default check-in method for auto-created sessions');
