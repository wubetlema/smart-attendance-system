<?php
/**
 * Face Verification — Server-Side Pipeline
 *
 * Decrypts the stored face descriptor using the shared FaceCrypto service,
 * validates the liveness nonce issued by GET ?action=init_liveness,
 * then computes Euclidean distance server-side.
 *
 * Two sub-actions:
 *   GET  ?action=init_liveness   — issue a server-side liveness nonce
 *   POST (JSON body)             — verify face + consume nonce + mark attendance
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

$studentId = (int)$_SESSION['user_id'];
$ip        = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';

// ── GET: issue server-side liveness nonce ─────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'GET' && ($_GET['action'] ?? '') === 'init_liveness') {
    $nonce = FaceCrypto::issueLivenessNonce($studentId, $ip, $conn);
    echo json_encode([
        'success' => true,
        'nonce'   => $nonce,
        'expires_in' => 120,
        'message' => 'Complete the blink challenge, then POST with this nonce.',
    ]);
    exit;
}

// ── POST: verify face descriptor ──────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed.']);
    exit;
}

$data       = json_decode(file_get_contents('php://input'), true);
$descriptor = $data['descriptor']  ?? null;
$session_id = (int)($data['session_id'] ?? 0);
$nonce      = trim($data['liveness_nonce'] ?? '');

// Input validation
if (!$descriptor || !is_array($descriptor) || count($descriptor) !== 128) {
    echo json_encode(['success' => false, 'message' => 'Invalid face descriptor.']);
    exit;
}
if (!FaceCrypto::validate($descriptor)) {
    echo json_encode(['success' => false, 'message' => 'Descriptor contains invalid values.']);
    exit;
}
if (!$session_id) {
    echo json_encode(['success' => false, 'message' => 'No session specified.']);
    exit;
}

// ── Liveness nonce validation (server-side, not client-supplied flag) ─────────
if (empty($nonce) || !FaceCrypto::consumeLivenessNonce($nonce, $studentId, $conn)) {
    logActivity('face_checkin_liveness_fail', "Session: $session_id", $studentId);
    echo json_encode([
        'success' => false,
        'message' => 'Liveness verification failed or expired. Please start again.',
        'require_new_nonce' => true,
    ]);
    exit;
}

// ── Load and decrypt stored face descriptor ───────────────────────────────────
$stmt = $conn->prepare("
    SELECT face_descriptor, face_admin_approved, face_revoked
    FROM users WHERE id=? AND role='student'
");
$stmt->bind_param('i', $studentId);
$stmt->execute();
$row = $stmt->get_result()->fetch_assoc();

if (!$row || empty($row['face_descriptor'])) {
    echo json_encode(['success' => false, 'message' => 'No registered face found.']);
    exit;
}
if ($row['face_revoked']) {
    echo json_encode(['success' => false, 'message' => 'Your biometric access has been revoked. Contact the department.']);
    exit;
}
if (!$row['face_admin_approved']) {
    echo json_encode(['success' => false, 'message' => 'Your face enrollment is pending admin approval. Use QR check-in.']);
    exit;
}

$encKey = getSetting('face_encryption_key', '');
$stored = FaceCrypto::decrypt($row['face_descriptor'], $encKey);

if ($stored === null) {
    logActivity('face_descriptor_corrupt', "Student: $studentId", $studentId);
    echo json_encode(['success' => false, 'message' => 'Stored face data is unreadable. Please re-register your face.']);
    exit;
}

// ── Compute distance server-side ──────────────────────────────────────────────
$distance  = FaceCrypto::distance($descriptor, $stored);
$threshold = 0.5;

if ($distance > $threshold) {
    logActivity('face_verify_failed', "Distance: " . round($distance, 4) . ", Session: $session_id", $studentId);
    echo json_encode([
        'success'  => false,
        'message'  => 'Face not recognized. Please try again or use QR check-in.',
        'distance' => round($distance, 4),
    ]);
    exit;
}

// ── Mark attendance ───────────────────────────────────────────────────────────
$result = markAttendanceWithPriority($session_id, $studentId, 'present', 'face', null, $studentId);

if ($result['success']) {
    logActivity('face_checkin_success', "Distance: " . round($distance, 4) . ", Session: $session_id", $studentId);
}

echo json_encode(array_merge($result, [
    'distance' => round($distance, 4),
    'verified' => true,
]));
