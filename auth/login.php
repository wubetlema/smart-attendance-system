<?php
define('SKIP_CSRF', true);
session_start();
require_once '../config/db.php';
require_once '../includes/auth_check.php';
require_once '../includes/session_manager.php';

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

    // Brute-force check
    $attempts = $conn->prepare("SELECT COUNT(*) c FROM login_attempts WHERE ip_address=? AND attempted_at > DATE_SUB(NOW(), INTERVAL 15 MINUTE)");
    $attempts->bind_param('s', $ip); $attempts->execute();
    $count = $attempts->get_result()->fetch_assoc()['c'];

    if ($count >= 5) {
        $error = 'Too many failed attempts. Please wait 15 minutes before trying again.';
    } else {
        $stmt = $conn->prepare("SELECT id, name, password, role, is_active, is_first_login, force_password_reset FROM users WHERE university_id=?");
        $stmt->bind_param('s', $univ_id); $stmt->execute();
        $user = $stmt->get_result()->fetch_assoc();

        if ($user && password_verify($password, $user['password'])) {
            if (!$user['is_active']) {
                $error = 'Your account has been deactivated. Please contact the administrator.';
            } else {
                $_SESSION['user_id']      = $user['id'];
                $_SESSION['name']         = $user['name'];
                $_SESSION['role']         = $user['role'];
                $_SESSION['is_first_login'] = (bool)$user['is_first_login'];

                // Create session record & detect concurrent sessions
                $token          = createSessionRecord($user['id']);
                $activeSessions = countActiveSessions($user['id']);

                // Force password change: first login OR admin-forced reset
                if ($user['is_first_login'] || $user['force_password_reset']) {
                    header('Location: /attendance-system/auth/change_password.php?first=1');
                    exit;
                }

                $redirects = [
                    'admin'   => '/attendance-system/admin/dashboard.php',
                    'teacher' => '/attendance-system/teacher/dashboard.php',
                    'student' => '/attendance-system/student/dashboard.php',
                ];

                // If more than 1 active session, redirect to session warning page
                if ($activeSessions > 1) {
                    header('Location: /attendance-system/auth/sessions.php?multi=1');
                } else {
                    header('Location: ' . $redirects[$user['role']]);
                }
                exit;
            }
        } else {
            $error = 'Invalid University ID or password.';
            $log = $conn->prepare("INSERT INTO login_attempts (ip_address, university_id) VALUES (?,?)");
            $log->bind_param('ss', $ip, $univ_id); $log->execute();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login — SmartAttend</title>
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
    <?php if (isset($_GET['error'])): ?>
        <?php if ($_GET['error'] === 'unauthorized'): ?>
        <div class="alert alert-error">Access denied.</div>
        <?php elseif ($_GET['error'] === 'force_logout'): ?>
        <div class="alert alert-error">⚠️ You were logged out by an administrator or from another device.</div>
        <?php endif; ?>
    <?php endif; ?>
    <?php if (isset($_GET['changed'])): ?>
        <div class="alert alert-success">✅ Password changed successfully. Please log in with your new password.</div>
    <?php endif; ?>

    <form method="POST" autocomplete="off">
        <?= csrfField() ?>
        <div class="form-group">
            <label>University ID</label>
            <input type="text" name="university_id" required placeholder="e.g. WU/URR/1234/15" autocomplete="off">
        </div>
        <div class="form-group">
            <label>Password</label>
            <input type="password" name="password" required placeholder="Enter your password" autocomplete="new-password">
        </div>
        <button type="submit" class="btn btn-primary btn-full">Login</button>
    </form>
    <div style="text-align:center;margin-top:1rem">
        <a href="/attendance-system/auth/forgot_password.php" style="color:var(--text-muted);font-size:.88rem">
            Forgot your password?
        </a>
    </div>
</div>
</body>
</html>
