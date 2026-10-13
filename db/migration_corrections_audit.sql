-- ============================================================
-- Attendance Correction Workflow + Expanded Audit Trail
-- Run after all prior migrations.
-- ============================================================

USE attendance_system;

-- ── Attendance Correction Requests ───────────────────────────────────────────
CREATE TABLE IF NOT EXISTS attendance_corrections (
    id               INT AUTO_INCREMENT PRIMARY KEY,
    attendance_id    INT NOT NULL         COMMENT 'The attendance record being disputed',
    session_id       INT NOT NULL,
    student_id       INT NOT NULL,
    class_id         INT NOT NULL,

    -- Original values (snapshot at time of request)
    original_status  ENUM('present','absent','late') NOT NULL,
    original_method  VARCHAR(30)  DEFAULT '',

    -- What the student is requesting
    requested_status ENUM('present','absent','late') NOT NULL DEFAULT 'present',
    reason           TEXT         NOT NULL,

    -- Supporting evidence (optional file)
    evidence_path    VARCHAR(255) DEFAULT '',

    -- Workflow state
    status           ENUM('pending','approved','rejected','cancelled') DEFAULT 'pending',

    -- Teacher review
    reviewed_by      INT NULL     COMMENT 'Teacher who reviewed',
    reviewed_at      TIMESTAMP NULL,
    reviewer_notes   TEXT         DEFAULT '',

    -- Admin override (optional second level)
    admin_override   TINYINT(1)   DEFAULT 0,
    admin_id         INT NULL,
    admin_notes      TEXT         DEFAULT '',

    created_at       TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
    updated_at       TIMESTAMP    DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    FOREIGN KEY (attendance_id) REFERENCES attendance(id) ON DELETE CASCADE,
    FOREIGN KEY (session_id)    REFERENCES attendance_sessions(id) ON DELETE CASCADE,
    FOREIGN KEY (student_id)    REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (class_id)      REFERENCES classes(id) ON DELETE CASCADE,
    FOREIGN KEY (reviewed_by)   REFERENCES users(id) ON DELETE SET NULL,
    FOREIGN KEY (admin_id)      REFERENCES users(id) ON DELETE SET NULL,

    INDEX idx_student    (student_id, status),
    INDEX idx_class      (class_id, status),
    INDEX idx_session    (session_id),
    INDEX idx_status     (status, created_at)
);

-- ── Expanded Audit Log ────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS audit_log (
    id              BIGINT AUTO_INCREMENT PRIMARY KEY,

    -- Who
    user_id         INT NULL,
    user_name       VARCHAR(100) DEFAULT '',
    user_role       VARCHAR(30)  DEFAULT '',

    -- What
    action          VARCHAR(80)  NOT NULL COMMENT 'e.g. LOGIN, MARK_ATTENDANCE, APPROVE_CORRECTION',
    category        ENUM(
                        'auth',
                        'attendance',
                        'correction',
                        'user_management',
                        'class_management',
                        'session_management',
                        'notification',
                        'system',
                        'security',
                        'report'
                    ) DEFAULT 'system',

    -- Target record
    target_type     VARCHAR(50)  DEFAULT '' COMMENT 'e.g. attendance, user, class',
    target_id       INT NULL     COMMENT 'ID of the affected record',

    -- Change tracking (JSON old/new)
    old_value       JSON         DEFAULT NULL,
    new_value       JSON         DEFAULT NULL,
    reason          TEXT         DEFAULT '',

    -- Context
    description     TEXT         DEFAULT '',
    ip_address      VARCHAR(45)  DEFAULT '',
    user_agent      VARCHAR(255) DEFAULT '',
    device_type     VARCHAR(30)  DEFAULT '',
    browser         VARCHAR(80)  DEFAULT '',

    -- Severity
    severity        ENUM('info','warning','error','critical') DEFAULT 'info',

    created_at      TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,

    INDEX idx_user      (user_id, created_at),
    INDEX idx_action    (action, created_at),
    INDEX idx_category  (category, created_at),
    INDEX idx_target    (target_type, target_id),
    INDEX idx_severity  (severity, created_at),
    INDEX idx_ip        (ip_address, created_at)
);

-- ── Notification Queue (for async sending) ────────────────────────────────────
CREATE TABLE IF NOT EXISTS notification_queue (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    user_id         INT NOT NULL,
    type            VARCHAR(80)  NOT NULL COMMENT 'e.g. ATTENDANCE_MARKED, CORRECTION_APPROVED',
    channel         ENUM('inapp','email','sms','all') DEFAULT 'inapp',
    subject         VARCHAR(255) DEFAULT '',
    message         TEXT         NOT NULL,
    email_body      TEXT         DEFAULT '',
    metadata        JSON         DEFAULT NULL,
    status          ENUM('pending','sent','failed','skipped') DEFAULT 'pending',
    attempts        TINYINT      DEFAULT 0,
    sent_at         TIMESTAMP NULL,
    error_message   VARCHAR(255) DEFAULT '',
    created_at      TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_status  (status, created_at),
    INDEX idx_user    (user_id, type)
);

-- ── Add email_notifications_enabled to settings ───────────────────────────────
INSERT IGNORE INTO settings (setting_key, setting_value, description) VALUES
('email_notifications',            '1',   'Enable email notifications globally (1=yes, 0=no)'),
('notify_student_attendance',      '1',   'Email student on every attendance mark'),
('notify_student_late',            '1',   'Email student when marked late'),
('notify_student_absent',         '1',    'Email student when marked absent'),
('notify_student_low_attendance',  '1',   'Email student when attendance drops below threshold'),
('notify_student_correction',      '1',   'Email student on correction request status change'),
('notify_teacher_correction',      '1',   'Email teacher on new correction request'),
('notify_teacher_session_reminder','1',   'Email teacher before session start'),
('notify_teacher_low_attendance',  '1',   'Email teacher when student is at risk'),
('notify_admin_suspicious_login',  '1',   'Email admin on suspicious login'),
('notify_admin_backup_status',     '1',   'Email admin after database backup'),
('notify_admin_face_fail_streak',  '1',   'Email admin after repeated face verification failures'),
('smtp_host',    '',  'SMTP server hostname'),
('smtp_user',    '',  'SMTP username / sender email'),
('smtp_pass',    '',  'SMTP password'),
('smtp_port',    '587','SMTP port (587=TLS, 465=SSL)'),
('smtp_from_name','SmartAttend','Sender display name');
