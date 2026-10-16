<?php
/**
 * Face Check-in API — Full Server-Side Pipeline
 *
 * Uses FaceCrypto shared service for decryption (fixes the json_decode-on-ciphertext bug).
 * Requires a server-issued liveness nonce (not a client-supplied flag).
 *
 * Two sub-actions:
 *   GET  ?action=init_liveness  — issue server-side liveness nonce
 *   POST (JSON body)            — full pipeline: nonce → face match → enroll → session → record
 */
session_start();
require_once '../config/db.php';
require_once '../includes/functions.php';
require_once '../includes/auth_check.php';
require_once '../includes/face_crypto.php';

header('Content-Type: application/json');
header('X-Content-Type-Options: nosniff');

// ── Auth: students only ───────────────────────────────────────────────────────
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'student') {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized.']);
    exit;
}

// Rate limit: max 10 face check-ins per minute per IP
if (!rateLimit('face_checkin', 10)) {
    echo json_encode(['success' => false, 'message' => 'Too many requests. Please wait a moment.']);
    exit;
}

$studentId = (int)$_SESSION['user_id'];
$ip        = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';

// ── GET: issue liveness nonce ─────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'GET' && ($_GET['action'] ?? '') === 'init_liveness') {
    $nonce = FaceCrypto::issueLivenessNonce($studentId, $ip, $conn);
    echo json_encode(['success' => true, 'nonce' => $nonce, 'expires_in' => 120]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed.']);
    exit;
}

$data      = json_decode(file_get_contents('php://input'), true);
$steps     = ['face_verified' => false, 'enrolled' => false, 'session_valid' => false];

// ── Step 1: Input validation ──────────────────────────────────────────────────
$descriptor = $data['descriptor']     ?? null;
$sessionId  = (int)($data['session_id'] ?? 0);
$nonce      = trim($data['liveness_nonce'] ?? '');

if (!$descriptor || !is_array($descriptor) || count($descriptor) !== 128) {
    echo json_encode(['success' => false, 'message' => 'Invalid face descriptor.', 'steps' => $steps]);
    exit;
}
if (!FaceCrypto::validate($descriptor)) {
    echo json_encode(['success' => false, 'message' => 'Descriptor contains invalid values.', 'steps' => $steps]);
    exit;
}
if (!$sessionId) {
    echo json_encode(['success' => false, 'message' => 'No session specified.', 'steps' => $steps]);
    exit;
}

// ── Step 2: Liveness nonce (server-side, not client flag) ─────────────────────
if (empty($nonce) || !FaceCrypto::consumeLivenessNonce($nonce, $studentId, $conn)) {
    logActivity('face_checkin_liveness_fail', "Session: $sessionId", $studentId);
    echo json_encode([
        'success'           => false,
        'message'           => 'Liveness verification required. Please complete the blink challenge again.',
        'require_new_nonce' => true,
        'steps'             => $steps,
    ]);
    exit;
}

// ── Step 3: Load + decrypt stored face descriptor ─────────────────────────────
$stmt = $conn->prepare("
    SELECT face_descriptor, face_admin_approved, face_revoked
    FROM users WHERE id=? AND role='student'
");
$stmt->bind_param('i', $studentId);
$stmt->execute();
$row = $stmt->get_result()->fetch_assoc();

if (!$row || empty($row['face_descriptor'])) {
    echo json_encode(['success' => false, 'message' => 'No registered face found.', 'steps' => $steps]);
    exit;
}
if ($row['face_revoked']) {
    echo json_encode(['success' => false, 'message' => 'Your biometric access has been revoked.', 'steps' => $steps]);
    exit;
}
if (!$row['face_admin_approved']) {
    echo json_encode(['success' => false, 'message' => 'Face enrollment pending approval. Use QR check-in.', 'steps' => $steps]);
    exit;
}

$encKey = getSetting('face_encryption_key', '');
$stored = FaceCrypto::decrypt($row['face_descriptor'], $encKey);

if ($stored === null) {
    logActivity('face_descriptor_corrupt', "Student: $studentId", $studentId);
    echo json_encode(['success' => false, 'message' => 'Stored face data is unreadable. Please re-register.', 'steps' => $steps]);
    exit;
}

// ── Step 4: Face match ────────────────────────────────────────────────────────
$distance  = FaceCrypto::distance($descriptor, $stored);
$threshold = 0.5;

if ($distance > $threshold) {
    logActivity('face_verify_failed', "Distance: " . round($distance, 4) . ", Session: $sessionId", $studentId);
    echo json_encode([
        'success'  => false,
        'message'  => 'Face not recognized. Please try again or use QR check-in.',
        'distance' => round($distance, 4),
        'steps'    => $steps,
    ]);
    exit;
}
$steps['face_verified'] = true;

// ── Step 5: Enrollment check ──────────────────────────────────────────────────
$enrollStmt = $conn->prepare("
    SELECT cs.id FROM attendance_sessions s
    JOIN class_students cs ON cs.class_id = s.class_id
    WHERE s.id = ? AND cs.student_id = ? AND cs.status = 'active'
");
$enrollStmt->bind_param('ii', $sessionId, $studentId);
$enrollStmt->execute();
if (!$enrollStmt->get_result()->fetch_assoc()) {
    logActivity('face_checkin_not_enrolled', "Session: $sessionId", $studentId);
    echo json_encode(['success' => false, 'message' => 'You are not enrolled in this class.', 'steps' => $steps]);
    exit;
}
$steps['enrolled'] = true;

// ── Step 6 + 7: Session state + time window ───────────────────────────────────
$sessStmt = $conn->prepare("
    SELECT s.id, s.status, s.open_at, s.present_until, s.late_until, s.expires_at,
           c.name AS class_name, c.subject
    FROM attendance_sessions s JOIN classes c ON s.class_id=c.id
    WHERE s.id=?
");
$sessStmt->bind_param('i', $sessionId);
$sessStmt->execute();
$session = $sessStmt->get_result()->fetch_assoc();

if (!$session) {
    echo json_encode(['success' => false, 'message' => 'Session not found.', 'steps' => $steps]);
    exit;
}

$now          = time();
$lateUntil    = strtotime($session['late_until']    ?: $session['expires_at']);
$presentUntil = strtotime($session['present_until'] ?: $session['late_until'] ?: $session['expires_at']);
$openAt       = $session['open_at'] ? strtotime($session['open_at']) : 0;

if ($openAt && $now < $openAt) {
    echo json_encode(['success' => false, 'message' => 'Attendance window has not opened yet.', 'steps' => $steps]);
    exit;
}
if ($now > $lateUntil) {
    echo json_encode(['success' => false, 'message' => 'This session is closed.', 'steps' => $steps]);
    exit;
}

$attendanceStatus = ($now <= $presentUntil) ? 'present' : 'late';
$steps['session_valid'] = true;

// ── Step 8: Record attendance ─────────────────────────────────────────────────
$result = markAttendanceWithPriority($sessionId, $studentId, $attendanceStatus, 'face', null, $studentId);

if ($result['success']) {
    logActivity('face_checkin_success', "Distance: " . round($distance, 4) . ", Status: $attendanceStatus", $studentId);
}

$statusLabel = $attendanceStatus === 'late' ? ' (Late)' : ' (Present)';
echo json_encode(array_merge($result, [
    'message'  => $result['success']
        ? "Attendance recorded for {$session['class_name']}{$statusLabel}."
        : $result['message'],
    'distance' => round($distance, 4),
    'status'   => $attendanceStatus,
    'steps'    => $steps,
]));
