-- Smart Attendance System Database Schema

CREATE DATABASE IF NOT EXISTS attendance_system;
USE attendance_system;

CREATE TABLE users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    password VARCHAR(255) NOT NULL,
    role ENUM('admin', 'teacher', 'student') NOT NULL,
    university_id VARCHAR(30) UNIQUE NULL COMMENT 'e.g. WU/URR/1234/15',
    email VARCHAR(100) DEFAULT '',
    phone VARCHAR(20) DEFAULT '',
    face_descriptor TEXT NULL,
    is_first_login TINYINT(1) DEFAULT 1 COMMENT 'Force password change on first login',
    is_active TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE classes (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    subject VARCHAR(100) NOT NULL,
    teacher_id INT NOT NULL,
    schedule VARCHAR(100),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (teacher_id) REFERENCES users(id) ON DELETE CASCADE
);

CREATE TABLE class_students (
    id INT AUTO_INCREMENT PRIMARY KEY,
    class_id INT NOT NULL,
    student_id INT NOT NULL,
    FOREIGN KEY (class_id) REFERENCES classes(id) ON DELETE CASCADE,
    FOREIGN KEY (student_id) REFERENCES users(id) ON DELETE CASCADE,
    UNIQUE KEY unique_enrollment (class_id, student_id)
);

CREATE TABLE attendance_sessions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    class_id INT NOT NULL,
    teacher_id INT NOT NULL,
    qr_token VARCHAR(64) UNIQUE NOT NULL,
    method ENUM('manual', 'qr', 'face') DEFAULT 'manual',
    -- State machine
    status ENUM('scheduled','not_started','open','late','closed') DEFAULT 'not_started',
    -- Time windows
    open_at DATETIME NOT NULL COMMENT 'When attendance window opens (students can scan)',
    present_until DATETIME NOT NULL COMMENT 'After this time students are marked late',
    late_until DATETIME NOT NULL COMMENT 'After this time session is closed, no check-in allowed',
    expires_at DATETIME NOT NULL COMMENT 'Legacy: same as late_until for backward compat',
    -- Auto-session flag
    auto_created TINYINT(1) DEFAULT 0 COMMENT 'Created automatically from timetable',
    schedule_id INT NULL COMMENT 'Source timetable schedule',
    room VARCHAR(100) DEFAULT '',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (class_id) REFERENCES classes(id) ON DELETE CASCADE,
    FOREIGN KEY (teacher_id) REFERENCES users(id) ON DELETE CASCADE
);

CREATE TABLE dynamic_qr_tokens (
    id INT AUTO_INCREMENT PRIMARY KEY,
    session_id INT NOT NULL,
    token VARCHAR(128) NOT NULL UNIQUE,
    valid_from DATETIME NOT NULL,
    valid_until DATETIME NOT NULL,
    is_used TINYINT(1) DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (session_id) REFERENCES attendance_sessions(id) ON DELETE CASCADE,
    INDEX idx_session_valid (session_id, valid_until),
    INDEX idx_token (token)
);

CREATE TABLE attendance (
    id INT AUTO_INCREMENT PRIMARY KEY,
    session_id INT NOT NULL,
    student_id INT NOT NULL,
    status ENUM('present', 'absent', 'late') DEFAULT 'present',
    method ENUM('manual', 'qr', 'face') DEFAULT 'manual',
    marked_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (session_id) REFERENCES attendance_sessions(id) ON DELETE CASCADE,
    FOREIGN KEY (student_id) REFERENCES users(id) ON DELETE CASCADE,
    UNIQUE KEY unique_attendance (session_id, student_id)
);

-- ⚠️ SECURITY: No default admin account in production schema
-- Run install.php on first deployment to create admin account securely
