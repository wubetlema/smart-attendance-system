<?php
session_start();
require_once '../config/db.php';
require_once '../includes/session_manager.php';

// Terminate this device's session record
terminateCurrentSession();

// Clear remember token
if (isset($_COOKIE['remember_token'])) {
    $token = $_COOKIE['remember_token'];
    $stmt  = $conn->prepare("DELETE FROM remember_tokens WHERE token=?");
    $stmt->bind_param('s', $token); $stmt->execute();
    setcookie('remember_token', '', time() - 3600, '/');
}

session_destroy();
header('Location: /attendance-system/auth/login.php');
exit;
