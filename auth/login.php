<?php
define('SKIP_CSRF', true);
session_start();
require_once '../config/db.php';
require_once '../includes/auth_check.php';

if (isset($_SESSION['user_id'])) {
    header('Location: /attendance-system/index.php');
    exit;
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $univ_id  = trim($_POST['university_id'] ?? '');
    $password = $_POST['password'] ?? '';
    $ip       = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';

    // Brute-force check: max 5 attempts per IP in 15 minutes
    $attempts = $conn->prepare("SELECT COUNT(*) c FROM login_attempts WHERE ip_address=? AND attempted_at > DATE_SUB(NOW(), INTERVAL 15 MINUTE)");
    $attempts->bind_param('s', $ip);
    $attempts->execute();
    $count = $attempts->get_result()->fetch_assoc()['c'];

    if ($count >= 5) {
        $error = 'Too many failed attempts. Please wait 15 minutes before trying again.';
    } else {
        $stmt = $conn->prepare("SELECT id, name, password, role, is_active FROM users WHERE university_id = ?");
        if (!$stmt) {
            $error = 'Database error: ' . $conn->error;
        } else {
            $stmt->bind_param('s', $univ_id);
            $stmt->execute();
            $user = $stmt->get_result()->fetch_assoc();

            if ($user && password_verify($password, $user['password'])) {
                if (!$user['is_active']) {
                    $error = 'Your account has been deactivated. Please contact the administrator.';
                } else {
                    $_SESSION['user_id'] = $user['id'];
                    $_SESSION['name']    = $user['name'];
                    $_SESSION['role']    = $user['role'];

                    $redirects = [
                        'admin'   => '/attendance-system/admin/dashboard.php',
                        'teacher' => '/attendance-system/teacher/dashboard.php',
                        'student' => '/attendance-system/student/dashboard.php',
                    ];
                    header('Location: ' . $redirects[$user['role']]);
                    exit;
                }
            } else {
                $error = 'Invalid University ID or password.';
                $log = $conn->prepare("INSERT INTO login_attempts (ip_address, university_id) VALUES (?,?)");
                $log->bind_param('ss', $ip, $univ_id);
                $log->execute();
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - SmartAttend</title>
    <link rel="stylesheet" href="/attendance-system/assets/style.css">
</head>
<body class="auth-page">
<div class="auth-card">
    <div class="auth-logo">
        <div class="logo-icon">📋</div>
        <h2>SmartAttend</h2>
        <p class="subtitle">Smart Attendance System</p>
    </div>

    <?php if ($error): ?>
        <div class="alert alert-error"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>
    <?php if (isset($_GET['error']) && $_GET['error'] === 'unauthorized'): ?>
        <div class="alert alert-error">Access denied.</div>
    <?php endif; ?>

    <form method="POST" autocomplete="off">
        <?= csrfField() ?>
        <div class="form-group">
            <label>University ID</label>
            <input type="text" name="university_id" required
                   placeholder="e.g. WU/URR/1234/15"
                   autocomplete="off" value="">
        </div>
        <div class="form-group">
            <label>Password</label>
            <input type="password" name="password" required
                   placeholder="Enter your password"
                   autocomplete="new-password">
        </div>
        <button type="submit" class="btn btn-primary btn-full">Login</button>
    </form>
</div>
</body>
</html>
