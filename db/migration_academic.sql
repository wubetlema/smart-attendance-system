-- Academic Management Migration
-- Run this after the base schema.sql

USE attendance_system;

-- Rooms / Classrooms
CREATE TABLE IF NOT EXISTS rooms (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    name        VARCHAR(100) NOT NULL,
    building    VARCHAR(100) DEFAULT '',
    capacity    INT DEFAULT 0,
    type        ENUM('classroom','lab','lecture_hall','seminar','online') DEFAULT 'classroom',
    facilities  VARCHAR(255) DEFAULT '',
    is_active   TINYINT(1) DEFAULT 1,
    created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY unique_room (name, building)
);

-- Academic Terms (Semesters / Terms / Years)
CREATE TABLE IF NOT EXISTS academic_terms (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    name        VARCHAR(100) NOT NULL,          -- e.g. "Semester 1 2024/25"
    term_type   ENUM('semester','trimester','quarter','year') DEFAULT 'semester',
    academic_year VARCHAR(20) NOT NULL,          -- e.g. "2024/25"
    start_date  DATE NOT NULL,
    end_date    DATE NOT NULL,
    is_current  TINYINT(1) DEFAULT 0,
    status      ENUM('upcoming','active','completed') DEFAULT 'upcoming',
    created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Academic Calendar Events
CREATE TABLE IF NOT EXISTS academic_calendar (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    term_id     INT NULL,
    title       VARCHAR(200) NOT NULL,
    description TEXT DEFAULT '',
    event_date  DATE NOT NULL,
    end_date    DATE NULL,
    event_type  ENUM('holiday','exam','registration','orientation','break','deadline','other') DEFAULT 'other',
    affects_attendance TINYINT(1) DEFAULT 0,
    created_by  INT NULL,
    created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (term_id) REFERENCES academic_terms(id) ON DELETE SET NULL,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
);

-- Add room_id to schedules if not present
ALTER TABLE schedules ADD COLUMN IF NOT EXISTS room_id INT NULL AFTER room;
ALTER TABLE schedules ADD COLUMN IF NOT EXISTS term_id INT NULL AFTER room_id;

-- Add term_id to classes if not present
ALTER TABLE classes ADD COLUMN IF NOT EXISTS term_id INT NULL AFTER batch_year;
ALTER TABLE classes ADD COLUMN IF NOT EXISTS room_id INT NULL AFTER term_id;
ALTER TABLE classes ADD COLUMN IF NOT EXISTS department VARCHAR(100) DEFAULT '' AFTER room_id;
ALTER TABLE classes ADD COLUMN IF NOT EXISTS max_students INT DEFAULT 50 AFTER department;
ALTER TABLE classes ADD COLUMN IF NOT EXISTS is_active TINYINT(1) DEFAULT 1 AFTER max_students;
