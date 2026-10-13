<?php
/**
 * Session Status API
 *
 * Returns the live computed state of one or more attendance sessions.
 * Used by teacher dashboard and student pages to show real-time state.
 *
 * GET ?session_id=X          → single session
 * GET ?class_id=X            → all active sessions for a class
 * GET ?teacher_id=X (admin)  → all sessions for a teacher
 */
session_start();
require_once '../config/db.php';
require_once '../includes/auth_check.php';

header('Content-Type: application/json');
header('Cache-Control: no-cache, no-store, must-revalidate');

requireLogin();

$sessionId = (int)($_GET['session_id'] ?? 0);
$classId   = (int)($_GET['class_id']   ?? 0);

function computeSessionState(array $s, $conn): array {
    $now          = time();
    $openAt       = $s['open_at']       ? strtotime($s['open_at'])        : 0;
    $presentUntil = $s['present_until'] ? strtotime($s['present_until'])  : 0;
    $lateUntil    = strtotime($s['late_until'] ?: $s['expires_at']);

    // Compute live status
    $status = $s['status'];
    if ($status !== 'closed') {
        if ($now > $lateUntil)                                     $status = 'closed';
        elseif ($presentUntil && $now > $presentUntil)             $status = 'late';
        elseif ($openAt && $now >= $openAt && $status === 'not_started') $status = 'open';
    }

    // Persist if changed
    if ($status !== $s['status']) {
        $conn->query("UPDATE attendance_sessions SET status='$status' WHERE id={$s['id']}");
    }

    $secondsRemaining = max(0, $lateUntil - $now);
    $presentRemaining = $presentUntil ? max(0, $presentUntil - $now) : 0;

    $count = (int)$conn->query("SELECT COUNT(*) c FROM attendance WHERE session_id={$s['id']}")->fetch_assoc()['c'];
    $presentCount = (int)$conn->query("SELECT COUNT(*) c FROM attendance WHERE session_id={$s['id']} AND status='present'")->fetch_assoc()['c'];
    $lateCount    = (int)$conn->query("SELECT COUNT(*) c FROM attendance WHERE session_id={$s['id']} AND status='late'")->fetch_assoc()['c'];

    return [
        'id'                => (int)$s['id'],
        'status'            => $status,
        'open_at'           => $s['open_at'],
        'present_until'     => $s['present_until'],
        'late_until'        => $s['late_until'] ?: $s['expires_at'],
        'seconds_remaining' => $secondsRemaining,
        'present_remaining' => $presentRemaining,
        'class_name'        => $s['class_name'] ?? '',
        'subject'           => $s['subject']    ?? '',
        'method'            => $s['method']     ?? 'qr',
        'attendance'        => [
            'total'   => $count,
            'present' => $presentCount,
            'late'    => $lateCount,
            'absent'  => 0,   // computed separately if needed
        ],
    ];
}

// ── Single session ────────────────────────────────────────────────────────────
if ($sessionId) {
    $stmt = $conn->prepare("
        SELECT s.*, c.name AS class_name, c.subject
        FROM attendance_sessions s
        JOIN classes c ON s.class_id = c.id
        WHERE s.id = ?
    ");
    $stmt->bind_param('i', $sessionId);
    $stmt->execute();
    $s = $stmt->get_result()->fetch_assoc();

    if (!$s) {
        echo json_encode(['success' => false, 'message' => 'Session not found.']);
        exit;
    }

    // Access control: teacher sees own; admin sees all; student sees enrolled
    if ($_SESSION['role'] === 'teacher' && $s['teacher_id'] != $_SESSION['user_id']) {
        echo json_encode(['success' => false, 'message' => 'Access denied.']);
        exit;
    }

    echo json_encode(['success' => true, 'session' => computeSessionState($s, $conn)]);
    exit;
}

// ── All active sessions for a class ───────────────────────────────────────────
if ($classId) {
    $rows = $conn->query("
        SELECT s.*, c.name AS class_name, c.subject
        FROM attendance_sessions s
        JOIN classes c ON s.class_id = c.id
        WHERE s.class_id = $classId
          AND s.status NOT IN ('closed')
        ORDER BY s.created_at DESC
        LIMIT 5
    ")->fetch_all(MYSQLI_ASSOC);

    $sessions = array_map(fn($s) => computeSessionState($s, $conn), $rows);
    echo json_encode(['success' => true, 'sessions' => $sessions]);
    exit;
}

// ── Teacher's open sessions ───────────────────────────────────────────────────
if ($_SESSION['role'] === 'teacher') {
    $tid  = (int)$_SESSION['user_id'];
    $rows = $conn->query("
        SELECT s.*, c.name AS class_name, c.subject
        FROM attendance_sessions s
        JOIN classes c ON s.class_id=c.id
        WHERE s.teacher_id=$tid
          AND s.status NOT IN ('closed')
        ORDER BY s.open_at ASC
    ")->fetch_all(MYSQLI_ASSOC);

    $sessions = array_map(fn($s) => computeSessionState($s, $conn), $rows);
    echo json_encode(['success' => true, 'sessions' => $sessions]);
    exit;
}

echo json_encode(['success' => false, 'message' => 'Specify session_id or class_id.']);
