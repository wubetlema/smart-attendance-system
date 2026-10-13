-- ============================================================
-- Class Events: Cancellations & Reschedules Migration
-- Run after all prior migrations.
-- ============================================================

USE attendance_system;

-- ── Class events (cancellation / reschedule / makeup) ─────────────────────────
CREATE TABLE IF NOT EXISTS class_events (
    id              INT AUTO_INCREMENT PRIMARY KEY,

    -- Which scheduled class this applies to
    schedule_id     INT NOT NULL          COMMENT 'The recurring schedule entry',
    class_id        INT NOT NULL,

    -- When does this event apply?
    -- For recurring schedules: the specific date of the affected occurrence
    event_date      DATE NOT NULL         COMMENT 'Date of the affected class occurrence',
    original_day    VARCHAR(20) NOT NULL  COMMENT 'Original day_of_week',
    original_start  TIME NOT NULL,
    original_end    TIME NOT NULL,
    original_room   VARCHAR(100) DEFAULT '',

    event_type      ENUM('cancelled','rescheduled','makeup','room_change') NOT NULL,

    -- For reschedules: new time/location
    new_date        DATE NULL,
    new_start       TIME NULL,
    new_end         TIME NULL,
    new_room        VARCHAR(100) DEFAULT '',
    new_room_id     INT NULL,

    -- Reason / message to students
    reason          TEXT DEFAULT '',
    notify_students TINYINT(1) DEFAULT 1,
    notification_sent TINYINT(1) DEFAULT 0,

    -- Who created it
    created_by      INT NOT NULL,
    created_by_role VARCHAR(30) DEFAULT '',

    status          ENUM('active','cancelled_by_admin','reversed') DEFAULT 'active',

    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    FOREIGN KEY (schedule_id) REFERENCES schedules(id) ON DELETE CASCADE,
    FOREIGN KEY (class_id)    REFERENCES classes(id)   ON DELETE CASCADE,
    FOREIGN KEY (created_by)  REFERENCES users(id)     ON DELETE CASCADE,

    INDEX idx_class_date  (class_id, event_date),
    INDEX idx_schedule    (schedule_id),
    INDEX idx_event_type  (event_type),
    INDEX idx_event_date  (event_date)
);

-- ── Conflict log (persistent record of detected conflicts) ────────────────────
CREATE TABLE IF NOT EXISTS schedule_conflicts (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    conflict_type   ENUM('teacher','room','student_group') NOT NULL,
    schedule_id_1   INT NOT NULL,
    schedule_id_2   INT NOT NULL,
    day_of_week     VARCHAR(20) NOT NULL,
    start_time      TIME NOT NULL,
    end_time        TIME NOT NULL,
    description     TEXT DEFAULT '',
    resolved        TINYINT(1) DEFAULT 0,
    resolved_by     INT NULL,
    resolved_at     TIMESTAMP NULL,
    detected_at     TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    FOREIGN KEY (schedule_id_1) REFERENCES schedules(id) ON DELETE CASCADE,
    FOREIGN KEY (schedule_id_2) REFERENCES schedules(id) ON DELETE CASCADE,

    UNIQUE KEY unique_conflict (schedule_id_1, schedule_id_2, conflict_type),
    INDEX idx_type      (conflict_type, resolved),
    INDEX idx_detected  (detected_at)
);
