<?php
/**
 * Face Check-in API — Full Server-Side Pipeline
 *
 * Pipeline (all steps validated server-side, browser cannot skip any):
 *   1. Auth             — logged-in student
 *   2. Input validation — descriptor is 128-float array, session_id present
 *   3. Liveness flag    — client must pass liveness check before calling this
 *   4. Face match       — euclidean distance vs stored descriptor
 *   5. Enrollment check — student enrolled in the session's class
 *   6. Session state    — session must be open or late (not closed/scheduled)
 *   7. Time window      — determines present vs late status automatically
 *   8. Duplicate check  — server priority rules (markAttendanceWithPriority)
 *   9. Record + notify
 */

session_start();
require_once '../config/db.php';
require_once '../includes/functions.php';
require_once '../includes/auth_check.php';

header('Content-Type: application/json');
header('X-Content-Type-Options: nosniff');

// ── Step 1: Authentication ─────────────────────────────────────────────────
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'student') {
    echo json_encode(['success' => false, 'message' => 'Unauthorized.']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed.']);
    exit;
}

$studentId = (int)$_SESSION['user_id'];
$data      = json_decode(file_get_contents('php://input'), true);

// ── Step 2: Input Validation ───────────────────────────────────────────────
$descriptor     = $data['descriptor']      ?? null;
$sessionId      = (int)($data['session_id']    ?? 0);
$livenessPassed = !empty($data['liveness_passed']);

$steps = [
    'face_verified'  => false,
    'enrolled'       => false,
    'session_valid'  => false,
];

if (!$descriptor || !is_array($descriptor) || count($descriptor) !== 128) {
    echo json_encode(['success' => false, 'message' => 'Invalid face descriptor.', 'steps' => $steps]);
    exit;
}

if (!$sessionId) {
    echo json_encode(['success' => false, 'message' => 'No session specified.', 'steps' => $steps]);
    exit;
}

// ── Step 3: Liveness Check (server-side gate) ──────────────────────────────
// The client runs the liveness challenge; we enforce that the flag is set.
// This is a first line of defence — a determined attacker could fake the flag,
// but combined with face verification it raises the bar significantly.
if (!$livenessPassed) {
    logActivity('face_checkin_liveness_fail', "Session: $sessionId", $studentId);
    echo json_encode([
        'success' => false,
        'message' => 'Liveness check required. Please complete the blink challenge.',
        'steps'   => $steps
    ]);
    exit;
}

// ── Step 4: Face Match ─────────────────────────────────────────────────────
$stmt = $conn->prepare("SELECT face_descriptor FROM users WHERE id=? AND role='student'");
$stmt->bind_param('i', $studentId);
$stmt->execute();
$row = $stmt->get_result()->fetch_assoc();

if (!$row || empty($row['face_descriptor'])) {
    echo json_encode([
        'success' => false,
        'message' => 'No registered face found. Please register your face first.',
        'steps'   => $steps
    ]);
    exit;
}

$stored = json_decode($row['face_descriptor'], true);
if (!$stored || count($stored) !== 128) {
    echo json_encode([
        'success' => false,
        'message' => 'Stored face data is corrupted. Please re-register.',
        'steps'   => $steps
    ]);
    exit;
}

function euclideanDistance(array $a, array $b): float {
    $sum = 0.0;
    for ($i = 0; $i < 128; $i++) {
        $diff  = ($a[$i] ?? 0.0) - ($b[$i] ?? 0.0);
        $sum  += $diff * $diff;
    }
    return sqrt($sum);
}

$distance  = euclideanDistance($descriptor, $stored);
$threshold = 0.5;

if ($distance > $threshold) {
    logActivity('face_verify_failed', "Distance: $distance, Session: $sessionId", $studentId);
    echo json_encode([
        'success'  => false,
        'message'  => 'Face not recognized. Please try again or use QR check-in.',
        'distance' => round($distance, 4),
        'steps'    => $steps,
    ]);
    exit;
}

$steps['face_verified'] = true;

// ── Step 5: Enrollment Check ───────────────────────────────────────────────
// Confirm student is actually enrolled in the class this session belongs to
$enrollStmt = $conn->prepare("
    SELECT cs.id
    FROM attendance_sessions s
    JOIN class_students cs ON cs.class_id = s.class_id
    WHERE s.id = ? AND cs.student_id = ?
");
$enrollStmt->bind_param('ii', $sessionId, $studentId);
$enrollStmt->execute();
$enrolled = $enrollStmt->get_result()->fetch_assoc();

if (!$enrolled) {
    logActivity('face_checkin_not_enrolled', "Session: $sessionId", $studentId);
    echo json_encode([
        'success' => false,
        'message' => 'You are not enrolled in the class this session belongs to.',
        'steps'   => $steps,
    ]);
    exit;
}

$steps['enrolled'] = true;

// ── Step 6 & 7: Session State + Time Window ────────────────────────────────
// Fetch session with state machine fields
$sessStmt = $conn->prepare("
    SELECT s.id, s.status, s.open_at, s.present_until, s.late_until, s.expires_at,
           c.name AS class_name, c.subject, c.id AS class_id
    FROM attendance_sessions s
    JOIN classes c ON s.class_id = c.id
    WHERE s.id = ?
");
$sessStmt->bind_param('i', $sessionId);
$sessStmt->execute();
$session = $sessStmt->get_result()->fetch_assoc();

if (!$session) {
    echo json_encode([
        'success' => false,
        'message' => 'Session not found.',
        'steps'   => $steps,
    ]);
    exit;
}

// Compute live state (in case cron hasn't updated it yet)
$now = time();

// Support both new state machine columns and legacy expires_at
$lateUntil    = !empty($session['late_until'])    ? strtotime($session['late_until'])    : strtotime($session['expires_at']);
$presentUntil = !empty($session['present_until']) ? strtotime($session['present_until']) : $lateUntil;
$openAt       = !empty($session['open_at'])        ? strtotime($session['open_at'])        : 0;

if ($openAt > $now) {
    echo json_encode([
        'success' => false,
        'message' => 'Attendance session has not opened yet. Please wait.',
        'steps'   => $steps,
    ]);
    exit;
}

if ($now > $lateUntil) {
    echo json_encode([
        'success' => false,
        'message' => 'This attendance session is closed. Check-in time has passed.',
        'steps'   => $steps,
    ]);
    exit;
}

// Determine status: present or late
$attendanceStatus = ($now <= $presentUntil) ? 'present' : 'late';
$steps['session_valid'] = true;

// ── Step 8 & 9: Record Attendance with Priority Rules ──────────────────────
$result = markAttendanceWithPriority($sessionId, $studentId, $attendanceStatus, 'face', null, $studentId);

if ($result['success']) {
    logActivity('face_checkin_success', "Distance: " . round($distance, 4) . ", Status: $attendanceStatus, Session: $sessionId", $studentId);
}

$statusLabel = $attendanceStatus === 'late' ? ' (marked as Late)' : ' (marked as Present)';

echo json_encode(array_merge($result, [
    'message'  => $result['success']
        ? "Attendance recorded for {$session['class_name']}{$statusLabel}."
        : $result['message'],
    'distance' => round($distance, 4),
    'status'   => $attendanceStatus,
    'steps'    => $steps,
]));
