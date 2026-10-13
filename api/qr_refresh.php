<?php
/**
 * Dynamic QR Token Refresh API
 *
 * Called by the teacher's QR display page every ~1 second (for the countdown),
 * and regenerates a new token automatically every 30 seconds.
 *
 * Response:
 *   { success, token, status, expires_in, attendance_count, message? }
 */
session_start();
require_once '../config/db.php';
require_once '../includes/auth_check.php';

header('Content-Type: application/json');
header('Cache-Control: no-cache, no-store, must-revalidate');

requireRole('teacher');

$sessionId = (int)($_GET['session_id'] ?? 0);
if (!$sessionId) {
    echo json_encode(['success' => false, 'message' => 'Missing session_id.']);
    exit;
}

// Verify this session belongs to the requesting teacher
$stmt = $conn->prepare("
    SELECT s.id, s.status, s.open_at, s.present_until, s.late_until, s.expires_at, s.teacher_id
    FROM attendance_sessions s
    WHERE s.id = ? AND s.teacher_id = ?
");
$stmt->bind_param('ii', $sessionId, $_SESSION['user_id']);
$stmt->execute();
$session = $stmt->get_result()->fetch_assoc();

if (!$session) {
    echo json_encode(['success' => false, 'message' => 'Session not found or access denied.']);
    exit;
}

// ── Compute live state ────────────────────────────────────────────────────────
$now          = time();
$lateUntil    = strtotime($session['late_until'] ?: $session['expires_at']);
$presentUntil = strtotime($session['present_until'] ?: $session['late_until'] ?: $session['expires_at']);
$openAt       = strtotime($session['open_at'] ?? '');

// Auto-advance state
$computedStatus = $session['status'];
if ($computedStatus !== 'closed') {
    if ($now > $lateUntil) {
        $computedStatus = 'closed';
        $conn->query("UPDATE attendance_sessions SET status='closed' WHERE id=$sessionId");
    } elseif ($now > $presentUntil) {
        $computedStatus = 'late';
        $conn->query("UPDATE attendance_sessions SET status='late' WHERE id=$sessionId AND status='open'");
    } elseif ($openAt && $now >= $openAt && $computedStatus === 'not_started') {
        $computedStatus = 'open';
        $conn->query("UPDATE attendance_sessions SET status='open' WHERE id=$sessionId AND status='not_started'");
    }
}

// ── Closed / expired — no token ───────────────────────────────────────────────
if ($computedStatus === 'closed') {
    echo json_encode([
        'success'          => false,
        'status'           => 'closed',
        'message'          => 'Session is closed.',
        'attendance_count' => (int)$conn->query("SELECT COUNT(*) c FROM attendance WHERE session_id=$sessionId")->fetch_assoc()['c'],
    ]);
    exit;
}

// ── Get or generate current dynamic token ─────────────────────────────────────
// A token is "current" if valid_until > NOW()
$tok = $conn->query("
    SELECT token, valid_until
    FROM dynamic_qr_tokens
    WHERE session_id = $sessionId
      AND valid_until > NOW()
    ORDER BY valid_until DESC
    LIMIT 1
")->fetch_assoc();

if (!$tok) {
    // Generate a fresh token
    $token      = bin2hex(random_bytes(32));
    $validFrom  = date('Y-m-d H:i:s');
    $validUntil = date('Y-m-d H:i:s', $now + 30);

    $ins = $conn->prepare("
        INSERT INTO dynamic_qr_tokens (session_id, token, valid_from, valid_until)
        VALUES (?, ?, ?, ?)
    ");
    $ins->bind_param('isss', $sessionId, $token, $validFrom, $validUntil);
    $ins->execute();

    $expiresIn = 30;
} else {
    $token     = $tok['token'];
    $expiresIn = max(0, strtotime($tok['valid_until']) - $now);

    // Pre-generate next token when < 5 seconds remain, so checkin never hits a gap
    if ($expiresIn < 5) {
        $nextToken      = bin2hex(random_bytes(32));
        $nextValidFrom  = $tok['valid_until'];                                           // starts exactly when current ends
        $nextValidUntil = date('Y-m-d H:i:s', strtotime($tok['valid_until']) + 30);

        // Only insert if a future token doesn't already exist
        $futureTok = $conn->query("
            SELECT id FROM dynamic_qr_tokens
            WHERE session_id=$sessionId AND valid_from >= '{$tok['valid_until']}'
            LIMIT 1
        ")->fetch_assoc();

        if (!$futureTok) {
            $ins = $conn->prepare("
                INSERT INTO dynamic_qr_tokens (session_id, token, valid_from, valid_until)
                VALUES (?, ?, ?, ?)
            ");
            $ins->bind_param('isss', $sessionId, $nextToken, $nextValidFrom, $nextValidUntil);
            $ins->execute();
        }
    }
}

// Clean up tokens older than 2 minutes (keep a short history for offline grace)
$conn->query("
    DELETE FROM dynamic_qr_tokens
    WHERE session_id = $sessionId
      AND valid_until < DATE_SUB(NOW(), INTERVAL 2 MINUTE)
");

// ── Attendance count ──────────────────────────────────────────────────────────
$count = (int)$conn->query("SELECT COUNT(*) c FROM attendance WHERE session_id=$sessionId")->fetch_assoc()['c'];

echo json_encode([
    'success'          => true,
    'token'            => $token,
    'status'           => $computedStatus,
    'expires_in'       => $expiresIn,
    'attendance_count' => $count,
]);
