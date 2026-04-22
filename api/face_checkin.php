<?php
session_start();
require_once '../config/db.php';
require_once '../includes/functions.php';
header('Content-Type: application/json');

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'student') {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$data = json_decode(file_get_contents('php://input'), true);
$session_id = (int)($data['session_id'] ?? 0);

if (!$session_id) {
    echo json_encode(['success' => false, 'message' => 'Invalid session']);
    exit;
}

$result = markAttendance($session_id, $_SESSION['user_id'], 'present', 'face');
echo json_encode($result);
