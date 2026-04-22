<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header('Location: /attendance-system/auth/login.php');
    exit;
}
$redirects = [
    'admin'   => '/attendance-system/admin/dashboard.php',
    'teacher' => '/attendance-system/teacher/dashboard.php',
    'student' => '/attendance-system/student/dashboard.php',
];
header('Location: ' . ($redirects[$_SESSION['role']] ?? '/attendance-system/auth/login.php'));
exit;
