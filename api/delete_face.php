<?php
/**
 * Delete Face Data API
 *
 * Allows a student to delete their own biometric data.
 * Requires password confirmation — biometric data cannot be
 * deleted accidentally or by a script without the user's credential.
 *
 * POST body (JSON):
 *   { "password": "current_password" }
 *
 * Steps:
 *   1. Verify student is logged in
 *   2. Verify password
 *   3. Clear face_descriptor + metadata
 *   4. Log to face_registrations audit table
 *   5. Log to activity_logs
 */

session_start();
require_once '../config/db.php';
require_once '../includes/auth_check.php';
require_once '../includes/functions.php';

header('Content-Type: application/json');
header('X-Content-Type-Options: nosniff');

// ── Auth ──────────────────────────────────────────────────────────────────────
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'student') {
    http_response_code(401);
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
$password  = $data['password'] ?? '';
$ip        = $_SERVER['REMOTE_ADDR']     ?? '0.0.0.0';
$ua        = $_SERVER['HTTP_USER_AGENT'] ?? '';

if (empty($password)) {
    echo json_encode(['success' => false, 'message' => 'Password is required to delete biometric data.']);
    exit;
}

// ── Verify password ───────────────────────────────────────────────────────────
$stmt = $conn->prepare("SELECT password, face_descriptor, name FROM users WHERE id=?");
$stmt->bind_param('i', $studentId);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();

if (!$user) {
    echo json_encode(['success' => false, 'message' => 'User not found.']);
    exit;
}

if (!password_verify($password, $user['password'])) {
    // Log failed deletion attempt
    logActivity('face_delete_failed', "Password verification failed. User: $studentId", $studentId);
    echo json_encode(['success' => false, 'message' => 'Incorrect password. Biometric data was not deleted.']);
    exit;
}

// ── Check face data exists ────────────────────────────────────────────────────
if (empty($user['face_descriptor'])) {
    echo json_encode(['success' => false, 'message' => 'No face data registered. Nothing to delete.']);
    exit;
}

// ── Delete face data ──────────────────────────────────────────────────────────
$upd = $conn->prepare("
    UPDATE users
    SET face_descriptor    = NULL,
        face_registered_at = NULL,
        face_registered_by = NULL,
        face_admin_approved = 0,
        face_revoked        = 0
    WHERE id = ?
");
$upd->bind_param('i', $studentId);
$upd->execute();

// ── Audit log ─────────────────────────────────────────────────────────────────
$devType = (stripos($ua, 'mobile') !== false) ? 'mobile' : 'desktop';
$ins = $conn->prepare("
    INSERT INTO face_registrations
        (user_id, action, performed_by, performed_role,
         liveness_passed, ip_address, user_agent, device_type, admin_notes)
    VALUES (?,?,?,?,0,?,?,?,?)
");
$role  = 'student';
$notes = 'Student deleted own biometric data after password verification';
$ins->bind_param('iiisssss', $studentId, $studentId, $role, $ip, $ua, $devType, $notes);
// Use correct bind — action is string
$ins2 = $conn->prepare("
    INSERT INTO face_registrations
        (user_id, action, performed_by, performed_role,
         liveness_passed, ip_address, user_agent, device_type, admin_notes)
    VALUES (?, 'deleted_by_user', ?, 'student', 0, ?, ?, ?, ?)
");
$ins2->bind_param('iissss', $studentId, $studentId, $ip, $ua, $devType, $notes);
$ins2->execute();

// Activity log
logActivity('face_deleted', "Student deleted own face data. IP: $ip", $studentId);

echo json_encode([
    'success' => true,
    'message' => 'Your face data has been permanently deleted. You can register again at any time.',
]);
