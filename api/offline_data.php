<?php
/**
 * Offline Data API
 * Returns JSON payload for offline caching: schedule, student list, dashboard stats
 * Called by the service worker and the offline JS module
 */
define('SKIP_CSRF', true);
require_once '../includes/auth_check.php';
requireLogin();
require_once '../config/db.php';

header('Content-Type: application/json');
header('Cache-Control: private, max-age=3600');

$user = currentUser();
$role = $_SESSION['role'];
$uid  = $user['id'];

$payload = ['role' => $role, 'generated_at' => date('c'), 'data' => []];

// ── Student payload ───────────────────────────────────────────────────────────
if ($role === 'student') {
    // Enrolled classes with schedule
    $stmt = $conn->prepare("
        SELECT c.id, c.name, c.subject, c.course_code, c.schedule,
               u.name AS teacher_name,
               r.name AS room_name, r.building,
               t.name AS term_name
        FROM class_students cs
        JOIN classes c ON cs.class_id=c.id
        JOIN users u ON c.teacher_id=u.id
        LEFT JOIN rooms r ON c.room_id=r.id
        LEFT JOIN academic_terms t ON c.term_id=t.id
        WHERE cs.student_id=?
        ORDER BY c.name
    ");
    $stmt->bind_param('i', $uid); $stmt->execute();
    $payload['data']['classes'] = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

    // Weekly schedule
    $classIds = array_column($payload['data']['classes'], 'id');
    if ($classIds) {
        $in = implode(',', array_map('intval', $classIds));
        $payload['data']['schedule'] = $conn->query("
            SELECT s.*, c.name AS class_name, c.subject, c.course_code,
                   u.name AS teacher_name, r.name AS room_name, r.building
            FROM schedules s
            JOIN classes c ON s.class_id=c.id
            JOIN users u ON c.teacher_id=u.id
            LEFT JOIN rooms r ON s.room_id=r.id
            WHERE s.class_id IN ($in)
            ORDER BY FIELD(s.day_of_week,'Monday','Tuesday','Wednesday','Thursday','Friday','Saturday','Sunday'), s.start_time
        ")->fetch_all(MYSQLI_ASSOC);
    } else {
        $payload['data']['schedule'] = [];
    }

    // Attendance summary
    $stmt = $conn->prepare("
        SELECT COUNT(*) AS total,
               SUM(status='present') AS present,
               SUM(status='absent') AS absent,
               SUM(status='late') AS late
        FROM attendance WHERE student_id=?
    ");
    $stmt->bind_param('i', $uid); $stmt->execute();
    $payload['data']['attendance_summary'] = $stmt->get_result()->fetch_assoc();

    // Recent attendance (last 20)
    $stmt = $conn->prepare("
        SELECT a.status, a.method, a.marked_at, c.name AS class_name, c.subject
        FROM attendance a
        JOIN attendance_sessions s ON a.session_id=s.id
        JOIN classes c ON s.class_id=c.id
        WHERE a.student_id=?
        ORDER BY a.marked_at DESC LIMIT 20
    ");
    $stmt->bind_param('i', $uid); $stmt->execute();
    $payload['data']['recent_attendance'] = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

    // Unread notifications
    $stmt = $conn->prepare("SELECT id, message, created_at FROM notifications WHERE user_id=? AND is_read=0 ORDER BY created_at DESC LIMIT 10");
    $stmt->bind_param('i', $uid); $stmt->execute();
    $payload['data']['notifications'] = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
}

// ── Teacher payload ───────────────────────────────────────────────────────────
if ($role === 'teacher') {
    // Assigned classes
    $stmt = $conn->prepare("
        SELECT c.id, c.name, c.subject, c.course_code, c.schedule, c.semester, c.section,
               r.name AS room_name, r.building,
               t.name AS term_name,
               (SELECT COUNT(*) FROM class_students cs WHERE cs.class_id=c.id) AS student_count
        FROM classes c
        LEFT JOIN rooms r ON c.room_id=r.id
        LEFT JOIN academic_terms t ON c.term_id=t.id
        WHERE c.teacher_id=?
        ORDER BY c.name
    ");
    $stmt->bind_param('i', $uid); $stmt->execute();
    $payload['data']['classes'] = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

    // Weekly schedule
    $classIds = array_column($payload['data']['classes'], 'id');
    if ($classIds) {
        $in = implode(',', array_map('intval', $classIds));
        $payload['data']['schedule'] = $conn->query("
            SELECT s.*, c.name AS class_name, c.subject, c.course_code,
                   r.name AS room_name, r.building
            FROM schedules s
            JOIN classes c ON s.class_id=c.id
            LEFT JOIN rooms r ON s.room_id=r.id
            WHERE s.class_id IN ($in)
            ORDER BY FIELD(s.day_of_week,'Monday','Tuesday','Wednesday','Thursday','Friday','Saturday','Sunday'), s.start_time
        ")->fetch_all(MYSQLI_ASSOC);

        // Student lists per class
        $students = [];
        foreach ($payload['data']['classes'] as $cls) {
            $cid = (int)$cls['id'];
            $rows = $conn->query("
                SELECT u.id, u.name, u.university_id, u.photo, u.email,
                       cs.is_blocked
                FROM class_students cs
                JOIN users u ON cs.student_id=u.id
                WHERE cs.class_id=$cid
                ORDER BY u.name
            ")->fetch_all(MYSQLI_ASSOC);
            $students[$cid] = $rows;
        }
        $payload['data']['students'] = $students;
    } else {
        $payload['data']['schedule'] = [];
        $payload['data']['students'] = [];
    }

    // Today's sessions
    $stmt = $conn->prepare("
        SELECT s.id, s.qr_token, s.method, s.expires_at, c.name AS class_name
        FROM attendance_sessions s
        JOIN classes c ON s.class_id=c.id
        WHERE s.teacher_id=? AND DATE(s.created_at)=CURDATE()
        ORDER BY s.created_at DESC
    ");
    $stmt->bind_param('i', $uid); $stmt->execute();
    $payload['data']['today_sessions'] = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
}

// ── Academic calendar (all roles) ─────────────────────────────────────────────
$payload['data']['calendar'] = $conn->query("
    SELECT title, event_date, end_date, event_type, affects_attendance
    FROM academic_calendar
    WHERE event_date >= CURDATE()
    ORDER BY event_date ASC
    LIMIT 20
")->fetch_all(MYSQLI_ASSOC);

echo json_encode($payload);
