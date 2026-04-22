<?php
session_start();
require_once '../config/db.php';
header('Content-Type: application/json');

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'student') {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$data = json_decode(file_get_contents('php://input'), true);
$descriptor = $data['descriptor'] ?? null;

if (!$descriptor || !is_array($descriptor)) {
    echo json_encode(['success' => false, 'message' => 'Invalid face data']);
    exit;
}

$json = json_encode($descriptor);
$stmt = $conn->prepare("UPDATE users SET face_descriptor=? WHERE id=?");
$stmt->bind_param('si', $json, $_SESSION['user_id']);
$stmt->execute();

echo json_encode(['success' => true, 'message' => 'Face registered successfully']);
