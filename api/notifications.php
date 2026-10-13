<?php
/**
 * Notifications API
 * GET ?action=count   → { unread: N }
 * GET ?action=recent  → { unread: N, items: [...] }
 * POST action=mark_read id=X
 * POST action=mark_all_read
 */
session_start();
require_once '../config/db.php';
require_once '../includes/auth_check.php';

header('Content-Type: application/json');
header('Cache-Control: no-cache');

requireLogin();
$userId = (int)$_SESSION['user_id'];

$action = $_GET['action'] ?? $_POST['action'] ?? 'count';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_once '../includes/functions.php';
    verifyCsrf();

    if ($action === 'mark_read') {
        $id = (int)($_POST['id'] ?? 0);
        $s  = $conn->prepare("UPDATE notifications SET is_read=1 WHERE id=? AND user_id=?");
        $s->bind_param('ii', $id, $userId);
        $s->execute();
        echo json_encode(['success' => true]);
        exit;
    }

    if ($action === 'mark_all_read') {
        $conn->query("UPDATE notifications SET is_read=1 WHERE user_id=$userId AND is_read=0");
        echo json_encode(['success' => true]);
        exit;
    }
}

// GET actions
$unread = (int)$conn->query("SELECT COUNT(*) c FROM notifications WHERE user_id=$userId AND is_read=0")->fetch_assoc()['c'];

if ($action === 'count') {
    echo json_encode(['unread' => $unread]);
    exit;
}

// recent: last 8 notifications
$items = $conn->query("
    SELECT id, message, is_read, created_at
    FROM notifications
    WHERE user_id=$userId
    ORDER BY created_at DESC
    LIMIT 8
")->fetch_all(MYSQLI_ASSOC);

echo json_encode(['unread' => $unread, 'items' => $items]);
