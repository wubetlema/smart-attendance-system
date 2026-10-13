<?php
/**
 * Forgot Password
 *
 * User provides their University ID + registered email.
 * If both match, a secure reset token is emailed.
 * Token expires in 30 minutes and can only be used once.
 *
 * Rate-limited: max 3 requests per 15 minutes per IP.
 */
define('SKIP_CSRF', true);
session_start();
require_once '../config/db.php';
require_once '../includes/auth_check.php';
require_once '../includes/mailer.php';
require_once '../includes/functions.php';

// Redirect if already logged in
if (isset($_SESSION['user_id'])) {
    header('Location: /attendance-system/index.php');
    exit;
}

$msg = ''; $msgType = 'info'; $sent = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();

    $univId = trim($_POST['university_id'] ?? '');
    $email  = strtolower(trim($_POST['email'] ?? ''));
    $ip     = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';

    // ── Rate limiting: max 3 attempts per 15 min per IP ───────────────────────
    $rateCheck = $conn->prepare("
        SELECT COUNT(*) c FROM password_reset_tokens
        WHERE ip_address=? AND created_at > DATE_SUB(NOW(), INTERVAL 15 MINUTE)
    ");
    $rateCheck->bind_param('s', $ip); $rateCheck->execute();
    $rateCnt = $rateCheck->get_result()->fetch_assoc()['c'];

    if ($rateCnt >= 3) {
        $msg = 'Too many reset requests. Please wait 15 minutes before trying again.';
        $msgType = 'error';
    } elseif (empty($univId) || empty($email)) {
        $msg = 'Please fill in all fields.'; $msgType = 'error';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $msg = 'Invalid email address.'; $msgType = 'error';
    } else {
        // Look up user — match BOTH university_id AND email
        $stmt = $conn->prepare("
            SELECT id, name, email, is_active FROM users
            WHERE university_id=? AND LOWER(email)=? AND is_active=1
        ");
        $stmt->bind_param('ss', $univId, $email);
        $stmt->execute();
        $usr = $stmt->get_result()->fetch_assoc();

        // Always show success message to prevent user enumeration
        $sent = true;
        $msg  = "If that University ID and email match our records, a password reset link has been sent. Check your inbox (and spam folder).";
        $msgType = 'success';

        if ($usr) {
            // Invalidate any existing tokens for this user
            $conn->query("UPDATE password_reset_tokens SET used=1 WHERE user_id={$usr['id']} AND used=0");

            // Generate secure token
            $token     = bin2hex(random_bytes(48));
            $expiresAt = date('Y-m-d H:i:s', time() + 30 * 60); // 30 minutes

            $ins = $conn->prepare("
                INSERT INTO password_reset_tokens (user_id, token, expires_at, ip_address)
                VALUES (?,?,?,?)
            ");
            $ins->bind_param('isss', $usr['id'], $token, $expiresAt, $ip);
            $ins->execute();

            // Check if SMTP is configured
            $smtpConfigured = getSetting('smtp_host','') && getSetting('smtp_user','') && getSetting('smtp_pass','');

            // Send email
            $resetUrl = "http://{$_SERVER['HTTP_HOST']}/attendance-system/auth/reset_password.php?token=" . urlencode($token);
            $subject  = '🔐 Password Reset — SmartAttend';
            $body     = "
                <p>Dear <strong>" . htmlspecialchars($usr['name']) . "</strong>,</p>
                <p>We received a request to reset your SmartAttend password.</p>
                <div style='margin:1.5rem 0;text-align:center'>
                    <a href='$resetUrl' style='background:#4f46e5;color:#fff;padding:.8rem 1.8rem;border-radius:8px;text-decoration:none;font-weight:700;font-size:1rem;display:inline-block'>
                        Reset My Password
                    </a>
                </div>
                <p style='color:#64748b;font-size:.9rem'>This link expires in <strong>30 minutes</strong> and can only be used once.</p>
                <p style='color:#64748b;font-size:.9rem'>If you did not request this, you can safely ignore this email. Your password will not change.</p>
                <hr style='margin:1.5rem 0;border:none;border-top:1px solid #e2e8f0'>
                <p style='color:#94a3b8;font-size:.8rem'>For security: this link was requested from IP address <code>$ip</code>.</p>
                <p style='color:#94a3b8;font-size:.8rem'>University ID: <code>" . htmlspecialchars($univId) . "</code></p>
            ";

            $mailResult = sendMail($usr['email'], $usr['name'], $subject, $body);

            // If SMTP not configured, show the reset link directly (development/admin use)
            if (!$smtpConfigured || !$mailResult['success']) {
                $sent = true;
                $msg  = "SMTP is not configured. Since you are on a local/development system, use this link directly to reset your password:";
                $msgType = 'warning';
                // Store reset URL for display below
                $_SESSION['_dev_reset_url'] = $resetUrl;
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
    <title>Forgot Password — SmartAttend</title>
    <link rel="stylesheet" href="/attendance-system/assets/style.css">
</head>
<body class="auth-page">
<div class="auth-card">
    <div class="auth-logo">
        <div class="logo-icon">🔐</div>
        <h2>Forgot Password?</h2>
        <p class="subtitle">Enter your University ID and registered email. We'll send a reset link.</p>
    </div>

    <?php if ($msg): ?>
    <div class="alert alert-<?= $msgType ?>"><?= htmlspecialchars($msg) ?></div>
    <?php if ($msgType === 'warning' && isset($_SESSION['_dev_reset_url'])): ?>
    <div style="margin-top:.75rem;padding:.85rem 1rem;background:#fef3c7;border:1.5px solid #fde68a;border-radius:8px;word-break:break-all">
        <strong>Direct Reset Link:</strong><br>
        <a href="<?= htmlspecialchars($_SESSION['_dev_reset_url']) ?>"
           style="color:#6366f1;font-size:.85rem">
            <?= htmlspecialchars($_SESSION['_dev_reset_url']) ?>
        </a>
        <br><small style="color:#92400e">This link works for 30 minutes. Configure SMTP in Admin → Settings for production use.</small>
    </div>
    <?php unset($_SESSION['_dev_reset_url']); ?>
    <?php endif; ?>
    <?php endif; ?>

    <?php if (!$sent): ?>
    <form method="POST" autocomplete="off">
        <?= csrfField() ?>
        <div class="form-group">
            <label>University ID</label>
            <input type="text" name="university_id" required
                   placeholder="e.g. WU/SE/001/24"
                   value="<?= htmlspecialchars($_POST['university_id'] ?? '') ?>"
                   autocomplete="off">
        </div>
        <div class="form-group">
            <label>Registered Email Address</label>
            <input type="email" name="email" required
                   placeholder="your@email.com"
                   value="<?= htmlspecialchars($_POST['email'] ?? '') ?>"
                   autocomplete="off">
        </div>
        <button type="submit" class="btn btn-primary btn-full">Send Reset Link</button>
    </form>
    <?php else: ?>
    <div style="text-align:center;margin-top:1rem">
        <div style="font-size:3rem;margin-bottom:.75rem">📧</div>
        <p class="text-muted">Check your email inbox. The link expires in 30 minutes.</p>
    </div>
    <?php endif; ?>

    <div style="text-align:center;margin-top:1.25rem">
        <a href="/attendance-system/auth/login.php" style="color:var(--text-muted);font-size:.9rem">← Back to Login</a>
    </div>

    <div class="alert alert-info" style="margin-top:1.25rem;font-size:.82rem">
        <strong>No self-registration in this system.</strong>
        Accounts are created by administrators. If you don't have an account, contact your department office.
    </div>
</div>
</body>
</html>
