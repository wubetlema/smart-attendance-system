-- ============================================================
-- Integrity Fixes Migration
-- Adds UNIQUE constraints and security hardening at DB level.
-- Run after all prior migrations.
-- ============================================================

USE attendance_system;

-- ── Prevent duplicate attendance for same student + session ──────────────────
-- This is the DB-level safety net that makes INSERT IGNORE work correctly
-- and eliminates TOCTOU race conditions.
ALTER TABLE attendance
    ADD UNIQUE KEY IF NOT EXISTS uq_session_student (session_id, student_id);

-- ── Prevent duplicate correction requests for same attendance record ─────────
ALTER TABLE attendance_corrections
    ADD UNIQUE KEY IF NOT EXISTS uq_attendance_pending
        (attendance_id, status);
-- Note: allows multiple requests per attendance record as long as they are in
-- different statuses (e.g. one rejected then one pending is fine).

-- ── Prevent duplicate enrollment (already exists but confirm) ────────────────
-- class_students should not allow same student in same class twice
ALTER TABLE class_students
    ADD UNIQUE KEY IF NOT EXISTS uq_class_student (class_id, student_id);

-- ── Prevent duplicate dynamic QR tokens ──────────────────────────────────────
ALTER TABLE dynamic_qr_tokens
    ADD UNIQUE KEY IF NOT EXISTS uq_token (token);

-- ── Index improvements for performance ───────────────────────────────────────
ALTER TABLE attendance
    ADD INDEX IF NOT EXISTS idx_student_session (student_id, session_id),
    ADD INDEX IF NOT EXISTS idx_marked_at (marked_at);

ALTER TABLE attendance_sessions
    ADD INDEX IF NOT EXISTS idx_class_status (class_id, status),
    ADD INDEX IF NOT EXISTS idx_open_at (open_at);

-- ── Ensure attendance status is always valid ──────────────────────────────────
ALTER TABLE attendance
    MODIFY COLUMN status ENUM('present','absent','late') NOT NULL DEFAULT 'present';

-- ── Rate limiting table index ──────────────────────────────────────────────────
ALTER TABLE login_attempts
    ADD INDEX IF NOT EXISTS idx_ip_time (ip_address, attempted_at);
