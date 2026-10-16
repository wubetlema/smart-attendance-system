<?php
/**
 * Secure File Server
 *
 * Serves uploaded evidence files only to authorized users.
 * Files are stored outside web root — this controller is the only access path.
 *
 * Access rules:
 *   - Student: can only view their own evidence files
 *   - Teacher: can view evidence for correction requests in their classes
 *   - Admin:   can view all files
 */
session_start();
require_once '../config/db.php';
require_once '../includes/auth_check.php';

requireLogin();

$path   = trim($_GET['path'] ?? '');
$userId = (int)$_SESSION['user_id'];
$role   = $_SESSION['role'] ?? '';

// ── Sanitise path — prevent directory traversal ───────────────────────────────
$path = str_replace(['..', "\0", '\\'], '', $path);
$path = ltrim($path, '/');

if (empty($path) || !preg_match('/^[a-z0-9_\/]+\.[a-z]{2,4}$/i', $path)) {
    http_response_code(400);
    echo 'Invalid path.';
    exit;
}

$fullPath = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'secure_uploads'
           . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $path);

if (!file_exists($fullPath) || !is_file($fullPath)) {
    http_response_code(404);
    echo 'File not found.';
    exit;
}

// ── Authorization check ───────────────────────────────────────────────────────
$authorized = false;

if ($role === 'admin') {
    $authorized = true;
} elseif ($role === 'teacher') {
    // Teacher can access evidence for corrections in their classes
    $filename = basename($path);
    $stmt = $conn->prepare("
        SELECT cr.id FROM attendance_corrections cr
        JOIN classes c ON cr.class_id = c.id
        WHERE cr.evidence_path LIKE ?
          AND c.teacher_id = ?
        LIMIT 1
    ");
    $like = '%' . $filename;
    $stmt->bind_param('si', $like, $userId);
    $stmt->execute();
    $authorized = (bool)$stmt->get_result()->fetch_assoc();

    // Also check leave request evidence
    if (!$authorized) {
        $stmt2 = $conn->prepare("
            SELECT lr.id FROM leave_requests lr
            JOIN classes c ON lr.class_id = c.id
            WHERE lr.evidence_path LIKE ?
              AND c.teacher_id = ?
            LIMIT 1
        ");
        $stmt2->bind_param('si', $like, $userId);
        $stmt2->execute();
        $authorized = (bool)$stmt2->get_result()->fetch_assoc();
    }
} elseif ($role === 'student') {
    // Student can only access their own uploads
    $filename = basename($path);
    $stmt = $conn->prepare("
        SELECT id FROM attendance_corrections
        WHERE evidence_path LIKE ? AND student_id = ?
        LIMIT 1
    ");
    $like = '%' . $filename;
    $stmt->bind_param('si', $like, $userId);
    $stmt->execute();
    $authorized = (bool)$stmt->get_result()->fetch_assoc();

    if (!$authorized) {
        $stmt2 = $conn->prepare("
            SELECT id FROM leave_requests
            WHERE evidence_path LIKE ? AND student_id = ?
            LIMIT 1
        ");
        $stmt2->bind_param('si', $like, $userId);
        $stmt2->execute();
        $authorized = (bool)$stmt2->get_result()->fetch_assoc();
    }
}

if (!$authorized) {
    http_response_code(403);
    echo 'Access denied.';
    exit;
}

// ── Serve file with correct MIME type ─────────────────────────────────────────
$finfo    = new finfo(FILEINFO_MIME_TYPE);
$mimeType = $finfo->file($fullPath);

// Whitelist MIME types we accept
$safeMimes = [
    'image/jpeg', 'image/png', 'image/webp', 'application/pdf'
];
if (!in_array($mimeType, $safeMimes)) {
    http_response_code(403);
    echo 'File type not allowed.';
    exit;
}

header('Content-Type: ' . $mimeType);
header('Content-Length: ' . filesize($fullPath));
header('Content-Disposition: inline; filename="' . basename($fullPath) . '"');
header('Cache-Control: private, max-age=3600');
header('X-Content-Type-Options: nosniff');
readfile($fullPath);
