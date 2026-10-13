-- Multi-Device Session Control Migration
USE attendance_system;

-- Active sessions table (one row per logged-in device)
CREATE TABLE IF NOT EXISTS user_sessions (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    user_id         INT NOT NULL,
    session_token   VARCHAR(128) NOT NULL UNIQUE,  -- stored in cookie, maps to PHP session
    php_session_id  VARCHAR(128) NOT NULL,
    ip_address      VARCHAR(45)  NOT NULL DEFAULT '',
    user_agent      TEXT         DEFAULT '',
    device_type     VARCHAR(30)  DEFAULT 'unknown', -- desktop|mobile|tablet
    browser         VARCHAR(80)  DEFAULT '',
    os              VARCHAR(80)  DEFAULT '',
    location        VARCHAR(120) DEFAULT '',
    is_active       TINYINT(1)   DEFAULT 1,
    last_activity   TIMESTAMP    DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    created_at      TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
    force_logout    TINYINT(1)   DEFAULT 0,         -- set to 1 to kick this session
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_user_active (user_id, is_active),
    INDEX idx_token (session_token)
);

-- Device history log (permanent record, never deleted on logout)
CREATE TABLE IF NOT EXISTS device_history (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    user_id         INT NOT NULL,
    ip_address      VARCHAR(45)  NOT NULL DEFAULT '',
    user_agent      TEXT         DEFAULT '',
    device_type     VARCHAR(30)  DEFAULT 'unknown',
    browser         VARCHAR(80)  DEFAULT '',
    os              VARCHAR(80)  DEFAULT '',
    location        VARCHAR(120) DEFAULT '',
    event_type      ENUM('login','logout','force_logout','expired','password_change') DEFAULT 'login',
    session_token   VARCHAR(128) DEFAULT '',
    created_at      TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_user (user_id),
    INDEX idx_created (created_at)
);
