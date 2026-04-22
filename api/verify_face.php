<?php
/**
 * Server-side face descriptor verification endpoint.
 * The client sends the captured face descriptor (128-float array).
 * This endpoint loads the stored descriptor from DB and computes
 * Euclidean distance server-side — browser JS cannot tamper with this.
 */
session_start();
require_once '../config/db.php';
require_once '../includes/functions.php';
require_once '../includes/auth_check.php';
header('Content-Type: application/json');

requireLogin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
    exit;
}

$data       = json_decode(file_get_contents('php://input'), true);
$descriptor = $data['descriptor'] ?? null;
$session_id = (int)($data['session_id'] ?? 0);
$student_id = $_SESSION['user_id'];

if (!$descriptor || !is_array($descriptor) || count($descriptor) !== 128) {
    echo json_encode(['success' => false, 'message' => 'Invalid face descriptor. Expected 128-float array.']);
    exit;
}

if (!$session_id) {
    echo json_encode(['success' => false, 'message' => 'No session specified.']);
    exit;
}

// Load stored face descriptor for this student
$stmt = $conn->prepare("SELECT face_descriptor FROM users WHERE id=? AND role='student'");
$stmt->bind_param('i', $student_id);
$stmt->execute();
$row = $stmt->get_result()->fetch_assoc();

if (!$row || !$row['face_descriptor']) {
    echo json_encode(['success' => false, 'message' => 'No registered face found. Please register your face first.']);
    exit;
}

$stored = json_decode($row['face_descriptor'], true);
if (!$stored || count($stored) !== 128) {
    echo json_encode(['success' => false, 'message' => 'Stored face data is corrupted. Please re-register.']);
    exit;
}

// Compute Euclidean distance server-side
function euclideanDistance(array $a, array $b): float {
    $sum = 0.0;
    for ($i = 0; $i < 128; $i++) {
        $diff = ($a[$i] ?? 0) - ($b[$i] ?? 0);
        $sum += $diff * $diff;
    }
    return sqrt($sum);
}

$distance  = euclideanDistance($descriptor, $stored);
$threshold = 0.5; // Standard face-api.js threshold

if ($distance > $threshold) {
    // Log failed attempt
    logActivity('face_verify_failed', "Distance: $distance, Student: $student_id, Session: $session_id", $student_id);
    echo json_encode([
        'success'  => false,
        'message'  => 'Face not recognized. Please try again or use QR check-in.',
        'distance' => round($distance, 4)
    ]);
    exit;
}

// Face verified — now mark attendance with priority rules
$result = markAttendanceWithPriority($session_id, $student_id, 'present', 'face', null, $student_id);

if ($result['success']) {
    logActivity('face_checkin_success', "Distance: $distance, Session: $session_id", $student_id);
}

echo json_encode(array_merge($result, [
    'distance' => round($distance, 4),
    'verified' => true
]));
