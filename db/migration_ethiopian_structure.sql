-- ============================================================
-- Ethiopian University Academic Structure Migration
-- Run after all prior migrations.
--
-- Hierarchy:
--   University
--     └── Campus
--           └── College / Institute
--                 └── Department
--                       └── Program
--                             └── Batch
--                                   └── Year → Semester → Section → Course/Class
-- ============================================================

USE attendance_system;

-- ── University (top-level, typically one row) ─────────────────────────────────
CREATE TABLE IF NOT EXISTS university (
    id           INT AUTO_INCREMENT PRIMARY KEY,
    name         VARCHAR(200) NOT NULL DEFAULT 'Wollo University',
    short_name   VARCHAR(50)  DEFAULT 'WU',
    country      VARCHAR(100) DEFAULT 'Ethiopia',
    city         VARCHAR(100) DEFAULT 'Dessie',
    website      VARCHAR(200) DEFAULT '',
    logo_path    VARCHAR(255) DEFAULT '',
    established  YEAR         DEFAULT NULL,
    created_at   TIMESTAMP    DEFAULT CURRENT_TIMESTAMP
);

INSERT IGNORE INTO university (id, name, short_name, city) VALUES
(1, 'Wollo University', 'WU', 'Dessie');

-- ── Campuses ──────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS campuses (
    id           INT AUTO_INCREMENT PRIMARY KEY,
    university_id INT NOT NULL DEFAULT 1,
    name         VARCHAR(200) NOT NULL,
    short_name   VARCHAR(50)  DEFAULT '',
    city         VARCHAR(100) DEFAULT '',
    address      VARCHAR(255) DEFAULT '',
    is_main      TINYINT(1)   DEFAULT 0,
    is_active    TINYINT(1)   DEFAULT 1,
    created_at   TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (university_id) REFERENCES university(id) ON DELETE CASCADE,
    UNIQUE KEY unique_campus (university_id, name)
);

INSERT IGNORE INTO campuses (id, university_id, name, short_name, city, is_main) VALUES
(1, 1, 'Main Campus',                     'MC',   'Dessie',    1),
(2, 1, 'Kombolcha Institute of Technology','KIoT', 'Kombolcha', 0),
(3, 1, 'Woldia Campus',                   'WC',   'Woldia',    0);

-- ── Colleges / Institutes / Schools ──────────────────────────────────────────
CREATE TABLE IF NOT EXISTS colleges (
    id           INT AUTO_INCREMENT PRIMARY KEY,
    campus_id    INT NOT NULL,
    name         VARCHAR(200) NOT NULL,
    short_name   VARCHAR(50)  DEFAULT '',
    college_type ENUM('college','institute','school','faculty','center') DEFAULT 'college',
    dean_name    VARCHAR(100) DEFAULT '',
    is_active    TINYINT(1)   DEFAULT 1,
    created_at   TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (campus_id) REFERENCES campuses(id) ON DELETE CASCADE,
    UNIQUE KEY unique_college (campus_id, name)
);

INSERT IGNORE INTO colleges (id, campus_id, name, short_name, college_type) VALUES
(1, 2, 'Kombolcha Institute of Technology',      'KIoT',   'institute'),
(2, 1, 'College of Natural and Computational Sciences', 'CNCS', 'college'),
(3, 1, 'College of Business and Economics',      'CBE',    'college'),
(4, 1, 'College of Medicine and Health Sciences','CMHS',   'college'),
(5, 1, 'College of Social Sciences and Humanities','CSSH', 'college');

-- ── Departments ───────────────────────────────────────────────────────────────
-- Extends the simple departments table with full hierarchy
DROP TABLE IF EXISTS departments;
CREATE TABLE IF NOT EXISTS departments (
    id           INT AUTO_INCREMENT PRIMARY KEY,
    college_id   INT NOT NULL,
    name         VARCHAR(200) NOT NULL,
    short_name   VARCHAR(50)  DEFAULT '',
    head_name    VARCHAR(100) DEFAULT '',
    is_active    TINYINT(1)   DEFAULT 1,
    created_at   TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (college_id) REFERENCES colleges(id) ON DELETE CASCADE,
    UNIQUE KEY unique_dept (college_id, name)
);

INSERT IGNORE INTO departments (id, college_id, name, short_name) VALUES
(1, 1, 'Software Engineering',          'SE'),
(2, 1, 'Computer Science',              'CS'),
(3, 1, 'Information Technology',        'IT'),
(4, 1, 'Electrical Engineering',        'EE'),
(5, 1, 'Civil Engineering',             'CE'),
(6, 1, 'Mechanical Engineering',        'ME'),
(7, 2, 'Mathematics',                   'MATH'),
(8, 2, 'Physics',                       'PHYS'),
(9, 3, 'Accounting and Finance',        'ACCT'),
(10,3, 'Management',                    'MGMT');

-- ── Programs ──────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS programs (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    department_id   INT NOT NULL,
    name            VARCHAR(200) NOT NULL,
    short_name      VARCHAR(50)  DEFAULT '',
    degree_type     ENUM('BSc','BA','MSc','MA','PhD','Diploma','Certificate') DEFAULT 'BSc',
    duration_years  TINYINT      DEFAULT 4,
    total_semesters TINYINT      DEFAULT 8,
    is_active       TINYINT(1)   DEFAULT 1,
    created_at      TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (department_id) REFERENCES departments(id) ON DELETE CASCADE,
    UNIQUE KEY unique_program (department_id, name, degree_type)
);

INSERT IGNORE INTO programs (id, department_id, name, short_name, degree_type, duration_years, total_semesters) VALUES
(1, 1, 'Software Engineering',   'SE',  'BSc', 4, 8),
(2, 2, 'Computer Science',       'CS',  'BSc', 4, 8),
(3, 3, 'Information Technology', 'IT',  'BSc', 4, 8),
(4, 4, 'Electrical Engineering', 'EE',  'BSc', 5, 10),
(5, 5, 'Civil Engineering',      'CE',  'BSc', 5, 10),
(6, 9, 'Accounting and Finance', 'ACCT','BSc', 4, 8);

-- ── Batches (intake years / cohorts) ─────────────────────────────────────────
CREATE TABLE IF NOT EXISTS batches (
    id            INT AUTO_INCREMENT PRIMARY KEY,
    program_id    INT NOT NULL,
    batch_name    VARCHAR(100) NOT NULL COMMENT 'e.g. 2021/22 Intake',
    entry_year    YEAR         NOT NULL COMMENT 'Year students enrolled',
    expected_graduation_year YEAR DEFAULT NULL,
    total_students INT DEFAULT 0,
    is_active     TINYINT(1)   DEFAULT 1,
    created_at    TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (program_id) REFERENCES programs(id) ON DELETE CASCADE,
    UNIQUE KEY unique_batch (program_id, entry_year)
);

INSERT IGNORE INTO batches (program_id, batch_name, entry_year, expected_graduation_year) VALUES
(1, '2021/22 Intake', 2021, 2025),
(1, '2022/23 Intake', 2022, 2026),
(1, '2023/24 Intake', 2023, 2027),
(2, '2022/23 Intake', 2022, 2026),
(2, '2023/24 Intake', 2023, 2027);

-- ── Extend users with full hierarchy ─────────────────────────────────────────
ALTER TABLE users
    ADD COLUMN IF NOT EXISTS campus_id     INT NULL AFTER role,
    ADD COLUMN IF NOT EXISTS college_id    INT NULL AFTER campus_id,
    ADD COLUMN IF NOT EXISTS department_id INT NULL AFTER college_id,
    ADD COLUMN IF NOT EXISTS program_id    INT NULL AFTER department_id,
    ADD COLUMN IF NOT EXISTS batch_id      INT NULL AFTER program_id,
    ADD COLUMN IF NOT EXISTS year_of_study TINYINT DEFAULT NULL COMMENT '1-5',
    ADD COLUMN IF NOT EXISTS photo         VARCHAR(255) DEFAULT '' AFTER section;

-- ── Extend classes with full hierarchy ───────────────────────────────────────
ALTER TABLE classes
    ADD COLUMN IF NOT EXISTS campus_id     INT NULL AFTER department,
    ADD COLUMN IF NOT EXISTS college_id    INT NULL AFTER campus_id,
    ADD COLUMN IF NOT EXISTS department_id INT NULL AFTER college_id,
    ADD COLUMN IF NOT EXISTS program_id    INT NULL AFTER department_id,
    ADD COLUMN IF NOT EXISTS batch_id      INT NULL AFTER program_id,
    ADD COLUMN IF NOT EXISTS year_of_study TINYINT DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS credits       INT DEFAULT 3 AFTER max_students,
    ADD COLUMN IF NOT EXISTS course_code   VARCHAR(30) DEFAULT '' AFTER credits;

-- ── Configurable attendance thresholds ───────────────────────────────────────
-- These live in the settings table (created by migration_enrollment.sql)
CREATE TABLE IF NOT EXISTS settings (
    id            INT AUTO_INCREMENT PRIMARY KEY,
    setting_key   VARCHAR(100) NOT NULL UNIQUE,
    setting_value TEXT DEFAULT '',
    description   VARCHAR(255) DEFAULT '',
    updated_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

INSERT IGNORE INTO settings (setting_key, setting_value, description) VALUES
-- Attendance thresholds
('attendance_threshold_good',     '75',  'Minimum % for Good (green) attendance status'),
('attendance_threshold_warning',  '60',  'Minimum % for Warning (yellow) attendance status — below this is Critical (red)'),
('attendance_late_counts',        '1',   'Count Late arrivals as attended: 1=yes (full), 0=no (absent), 0.5=half'),
('attendance_warning_email',      '1',   'Send email when student crosses warning threshold'),
('attendance_critical_email',     '1',   'Send email when student crosses critical threshold'),

-- University identity
('university_name',   'Wollo University',                'University display name'),
('university_short',  'WU',                              'University abbreviation'),
('university_city',   'Dessie, Ethiopia',                'University location'),
('university_logo',   '',                                'Path to university logo'),

-- Academic settings
('current_academic_year', '2024/25',   'Current academic year'),
('current_semester',      'Semester 1','Current semester'),
('id_format',             'WU/{DEPT}/{NUM}/{YEAR}', 'University ID format pattern');
