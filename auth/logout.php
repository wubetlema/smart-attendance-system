<?php
session_start();
require_once '../config/db.php';

// Delete remember token from DB
if (isset($_COOKIE['remember_token'])) {
    $token = $_COOKIE['remember_token'];
    $stmt  = $conn->prepare("DELETE FROM remember_tokens WHERE token=?");
    $stmt->bind_param('s', $token);
    $stmt->execute();
    setcookie('remember_token', '', time() - 3600, '/');
}

// Keep saved_university_id cookie so the ID is pre-filled next time
// but clear the session
session_destroy();
header('Location: /attendance-system/auth/login.php');
exit;
