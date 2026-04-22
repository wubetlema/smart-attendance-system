<?php
/**
 * Offline attendance sync endpoint.
 * Called when the student comes back online after marking offline.
 * Handles conflict detection — if teacher already marked the student,
 * returns a clear conflict message instead of silently failing.
 */
session_start();
require_once '../config/db.php';
require_once '../includes/functions.php';
header('Content-Type: application/json');

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'student') {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$data       = json_decode(file_get_contents('php://input'), true);
$student_id = $_SESSION['user_id'];
$records    = $data['records'] ?? []; // Support batch sync of multiple offline records

// Single record fallback (legacy)
if (empty($records) && isset($data['token'])) {
    $records = [[
        'token'      => $data['token'],
        'status'     => $data['status'] ?? 'present',
        'offline_at' => $data['offline_at'] ?? date('Y-m-d H:i:s')
    ]];
}

if (empty($records)) {
    echo json_encode(['success' => false, 'message' => 'No records to sync']);
    exit;
}

$results = [];

foreach ($records as $record) {
    $token     = $record['token'] ?? '';
    $status    = in_array($record['status'] ?? '', ['present','absent','late']) ? $record['status'] : 'present';
    $offlineAt = $record['offline_at'] ?? date('Y-m-d H:i:s');

    if (!$token) {
        $results[] = ['token' => $token, 'success' => false, 'message' => 'Missing token'];
        continue;
    }

    // Validate offline timestamp before anything else
    $tsCheck = validateOfflineTimestamp($offlineAt);
    if (!$tsCheck['valid']) {
        $results[] = [
            'token'   => $token,
            'success' => false,
            'message' => $tsCheck['message']
        ];
        continue;
    }

    // Find session with 2hr grace window for offline records
    $stmt = $conn->prepare("
        SELECT id, class_id, expires_at
        FROM attendance_sessions
        WHERE qr_token = ?
        AND expires_at > DATE_SUB(NOW(), INTERVAL 2 HOUR)
    ");
    $stmt->bind_param('s', $token);
    $stmt->execute();
    $session = $stmt->get_result()->fetch_assoc();

    if (!$session) {
        $results[] = [
            'token'   => $token,
            'success' => false,
            'message' => 'Session expired or invalid. This offline record could not be synced.'
        ];
        continue;
    }

    // Use priority-aware marking with timestamp validation
    $result = markAttendanceWithPriority($session['id'], $student_id, $status, 'offline_sync', $offlineAt);
    $results[] = array_merge(['token' => $token], $result);
}

// Summary response
$synced    = count(array_filter($results, fn($r) => $r['success']));
$conflicts = count(array_filter($results, fn($r) => !empty($r['conflict'])));
$failed    = count(array_filter($results, fn($r) => !$r['success'] && empty($r['conflict'])));

echo json_encode([
    'success'   => $synced > 0,
    'synced'    => $synced,
    'conflicts' => $conflicts,
    'failed'    => $failed,
    'message'   => "$synced synced, $conflicts conflicts, $failed failed.",
    'results'   => $results
]);
