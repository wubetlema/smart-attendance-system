-- Alert System Migration
-- Run this after migration_academic.sql

USE attendance_system;

-- Alert Rules Configuration
CREATE TABLE IF NOT EXISTS alert_rules (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    name            VARCHAR(200) NOT NULL,
    description     TEXT DEFAULT '',
    rule_type       ENUM('attendance_low','absence_streak','late_streak','no_checkin','custom') DEFAULT 'attendance_low',
    threshold_value DECIMAL(5,2) DEFAULT 75.00,      -- e.g. 75% for attendance
    threshold_days  INT DEFAULT 3,                    -- e.g. 3 consecutive absences
    target_role     ENUM('student','teacher','admin','all') DEFAULT 'student',
    is_active       TINYINT(1) DEFAULT 1,
    priority        ENUM('low','medium','high','critical') DEFAULT 'medium',
    
    -- Notification Channels
    notify_email    TINYINT(1) DEFAULT 1,
    notify_sms      TINYINT(1) DEFAULT 0,
    notify_inapp    TINYINT(1) DEFAULT 1,
    
    -- Escalation
    escalate_after_hours INT DEFAULT 24,
    escalate_to_role     ENUM('teacher','admin','none') DEFAULT 'teacher',
    
    created_by      INT NULL,
    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
);

-- Alert Instances (triggered alerts)
CREATE TABLE IF NOT EXISTS alerts (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    rule_id         INT NOT NULL,
    user_id         INT NOT NULL,                     -- Student/user affected
    class_id        INT NULL,                         -- Related class if applicable
    
    title           VARCHAR(200) NOT NULL,
    message         TEXT NOT NULL,
    priority        ENUM('low','medium','high','critical') DEFAULT 'medium',
    
    status          ENUM('pending','sent','read','escalated','resolved') DEFAULT 'pending',
    
    -- Notification tracking
    email_sent      TINYINT(1) DEFAULT 0,
    email_sent_at   TIMESTAMP NULL,
    sms_sent        TINYINT(1) DEFAULT 0,
    sms_sent_at     TIMESTAMP NULL,
    inapp_sent      TINYINT(1) DEFAULT 1,
    
    read_at         TIMESTAMP NULL,
    resolved_at     TIMESTAMP NULL,
    resolved_by     INT NULL,
    
    escalated       TINYINT(1) DEFAULT 0,
    escalated_at    TIMESTAMP NULL,
    escalated_to    INT NULL,                         -- User ID escalated to
    
    metadata        JSON DEFAULT NULL,                -- Additional data (attendance %, days absent, etc.)
    
    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    
    FOREIGN KEY (rule_id) REFERENCES alert_rules(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (class_id) REFERENCES classes(id) ON DELETE SET NULL,
    FOREIGN KEY (resolved_by) REFERENCES users(id) ON DELETE SET NULL,
    FOREIGN KEY (escalated_to) REFERENCES users(id) ON DELETE SET NULL,
    
    INDEX idx_user_status (user_id, status),
    INDEX idx_created (created_at),
    INDEX idx_priority (priority, status)
);

-- Alert Logs (audit trail)
CREATE TABLE IF NOT EXISTS alert_logs (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    alert_id    INT NOT NULL,
    action      VARCHAR(100) NOT NULL,               -- 'created', 'sent', 'read', 'escalated', 'resolved'
    details     TEXT DEFAULT '',
    performed_by INT NULL,
    created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    
    FOREIGN KEY (alert_id) REFERENCES alerts(id) ON DELETE CASCADE,
    FOREIGN KEY (performed_by) REFERENCES users(id) ON DELETE SET NULL
);

-- Insert default alert rules
INSERT INTO alert_rules (name, description, rule_type, threshold_value, target_role, priority, notify_email, notify_inapp) VALUES
('Low Attendance Warning', 'Alert when student attendance falls below 75%', 'attendance_low', 75.00, 'student', 'high', 1, 1),
('Critical Attendance Alert', 'Critical alert when attendance falls below 50%', 'attendance_low', 50.00, 'student', 'critical', 1, 1),
('Consecutive Absences', 'Alert after 3 consecutive absences', 'absence_streak', 0, 'student', 'high', 1, 1),
('Repeated Late Arrivals', 'Alert after 5 late check-ins', 'late_streak', 0, 'student', 'medium', 0, 1);

-- Add SMS phone field to users if not exists
ALTER TABLE users ADD COLUMN IF NOT EXISTS sms_phone VARCHAR(20) DEFAULT '' AFTER phone;
ALTER TABLE users ADD COLUMN IF NOT EXISTS sms_enabled TINYINT(1) DEFAULT 0 AFTER sms_phone;
