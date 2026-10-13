<?php
/**
 * Forced password change page.
 * Triggered automatically when is_first_login = 1.
 * Also accessible voluntarily from profile pages.
 */
if (session_status() === PHP_SESSION_NONE) session_start();

// Must be logged in to change password
if (!isset($_SESSION['user_id'])) {
    header('Location: /attendance-system/auth/login.php');
    exit;
}

require_once '../config/db.php';
require_once '../includes/auth_check.php';

$isForced   = isset($_GET['first']) || !empty($_SESSION['is_first_login']);
$userId     = (int)$_SESSION['user_id'];
$errors     = [];
$success    = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();

    $current  = $_POST['current_password']  ?? '';
    $new      = $_POST['new_password']       ?? '';
    $confirm  = $_POST['confirm_password']   ?? '';

    // Fetch current hash
    $stmt = $conn->prepare("SELECT password FROM users WHERE id=?");
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();

    if (!$row || !password_verify($current, $row['password'])) {
        $errors[] = 'Current password is incorrect.';
    }

    if (strlen($new) < 10)                          $errors[] = 'New password must be at least 10 characters.';
    if (!preg_match('/[A-Z]/', $new))               $errors[] = 'Must contain at least one uppercase letter.';
    if (!preg_match('/[0-9]/', $new))               $errors[] = 'Must contain at least one number.';
    if (!preg_match('/[^A-Za-z0-9]/', $new))        $errors[] = 'Must contain at least one special character.';
    if ($new === $current)                          $errors[] = 'New password must be different from the current one.';
    if ($new !== $confirm)                          $errors[] = 'Passwords do not match.';

    if (empty($errors)) {
        $hash = password_hash($new, PASSWORD_BCRYPT, ['cost' => 12]);
        $upd  = $conn->prepare("UPDATE users SET password=?, is_first_login=0 WHERE id=?");
        $upd->bind_param('si', $hash, $userId);
        $upd->execute();

        // Clear first-login flag from session
        $_SESSION['is_first_login'] = false;

        // Log the event for device history
        if (function_exists('logDeviceEvent')) {
            $token = $_SESSION['session_token'] ?? '';
            $ip    = $_SERVER['REMOTE_ADDR']    ?? '';
            $ua    = $_SERVER['HTTP_USER_AGENT'] ?? '';
            require_once '../includes/session_manager.php';
            logDeviceEvent($userId, 'password_change', $token, $ip, $ua, detectDevice($ua));
        }

        // Redirect to login with success banner
        session_destroy();
        setcookie('sa_session_token', '', time() - 3600, '/');
        header('Location: /attendance-system/auth/login.php?changed=1');
        exit;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $isForced ? 'Set New Password' : 'Change Password' ?> — SmartAttend</title>
    <link rel="stylesheet" href="/attendance-system/assets/style.css">
    <style>
        .strength-bar { height: 5px; border-radius: 4px; background: #e2e8f0; margin-top: .4rem; overflow: hidden; }
        .strength-fill { height: 100%; width: 0; border-radius: 4px; transition: width .3s, background .3s; }
        #strengthText { font-size: .78rem; color: #94a3b8; margin-top: .25rem; }
        .requirement { font-size: .82rem; color: #94a3b8; padding: .15rem 0; }
        .requirement.met { color: #16a34a; }
        .requirement.met::before { content: '✓ '; font-weight: 700; }
        .requirement:not(.met)::before { content: '○ '; }
        .forced-banner {
            background: #fef3c7;
            border-left: 4px solid #d97706;
            border-radius: 0 8px 8px 0;
            padding: .85rem 1rem;
            margin-bottom: 1.5rem;
            font-size: .9rem;
            color: #92400e;
        }
    </style>
</head>
<body class="auth-page">
<div class="auth-card" style="max-width:460px">

    <div class="auth-logo">
        <div class="logo-icon">🔐</div>
        <h2><?= $isForced ? 'Set Your Password' : 'Change Password' ?></h2>
        <p class="subtitle">
            <?= $isForced
                ? 'Your account requires a new password before you can continue.'
                : 'Update your account password.' ?>
        </p>
    </div>

    <?php if ($isForced): ?>
    <div class="forced-banner">
        ⚠️ For security, you must set a personal password before accessing the system.
        This replaces any default or temporary password.
    </div>
    <?php endif; ?>

    <?php if (!empty($errors)): ?>
    <div class="alert alert-error">
        <ul style="margin:.25rem 0 0 1.2rem;padding:0">
            <?php foreach ($errors as $e): ?>
            <li><?= htmlspecialchars($e) ?></li>
            <?php endforeach; ?>
        </ul>
    </div>
    <?php endif; ?>

    <form method="POST" autocomplete="off" id="pwForm">
        <?= csrfField() ?>

        <div class="form-group">
            <label>Current Password</label>
            <input type="password" name="current_password" required
                   placeholder="Enter your current password"
                   autocomplete="current-password">
        </div>

        <div class="form-group">
            <label>New Password</label>
            <input type="password" name="new_password" id="newPw" required
                   placeholder="Min 10 chars, uppercase, number, symbol"
                   autocomplete="new-password"
                   oninput="checkPw(this.value)">
            <div class="strength-bar"><div class="strength-fill" id="sFill"></div></div>
            <div id="strengthText">Enter a password</div>
            <div style="margin-top:.5rem">
                <div class="requirement" id="req-len">At least 10 characters</div>
                <div class="requirement" id="req-upper">At least one uppercase letter</div>
                <div class="requirement" id="req-num">At least one number</div>
                <div class="requirement" id="req-sym">At least one special character</div>
            </div>
        </div>

        <div class="form-group">
            <label>Confirm New Password</label>
            <input type="password" name="confirm_password" id="confirmPw" required
                   placeholder="Re-enter new password"
                   autocomplete="new-password"
                   oninput="checkMatch()">
            <div id="matchText" style="font-size:.82rem;margin-top:.25rem"></div>
        </div>

        <button type="submit" class="btn btn-primary btn-full" id="submitBtn">
            <?= $isForced ? 'Set Password & Continue' : 'Change Password' ?>
        </button>

        <?php if (!$isForced): ?>
        <div style="text-align:center;margin-top:1rem">
            <a href="javascript:history.back()" style="color:#64748b;font-size:.9rem">← Cancel</a>
        </div>
        <?php endif; ?>
    </form>
</div>

<script>
function checkPw(pw) {
    const reqs = {
        'req-len':   pw.length >= 10,
        'req-upper': /[A-Z]/.test(pw),
        'req-num':   /[0-9]/.test(pw),
        'req-sym':   /[^A-Za-z0-9]/.test(pw),
    };
    let score = Object.values(reqs).filter(Boolean).length;
    Object.entries(reqs).forEach(([id, met]) => {
        document.getElementById(id).classList.toggle('met', met);
    });

    const levels = [
        { pct: '25%', bg: '#dc2626', text: 'Weak' },
        { pct: '50%', bg: '#f97316', text: 'Fair' },
        { pct: '75%', bg: '#eab308', text: 'Good' },
        { pct: '100%',bg: '#16a34a', text: 'Strong ✓' },
    ];
    const l = levels[Math.min(score - 1, 3)];
    const fill  = document.getElementById('sFill');
    const label = document.getElementById('strengthText');
    fill.style.width      = pw.length ? (l?.pct  ?? '0') : '0';
    fill.style.background = l?.bg ?? '#e2e8f0';
    label.textContent     = pw.length ? (l?.text ?? '') : 'Enter a password';
    label.style.color     = l?.bg ?? '#94a3b8';
    checkMatch();
}

function checkMatch() {
    const pw  = document.getElementById('newPw').value;
    const cfw = document.getElementById('confirmPw').value;
    const el  = document.getElementById('matchText');
    if (!cfw) { el.textContent = ''; return; }
    if (pw === cfw) {
        el.textContent = '✓ Passwords match';
        el.style.color = '#16a34a';
    } else {
        el.textContent = '✗ Passwords do not match';
        el.style.color = '#dc2626';
    }
}
</script>
</body>
</html>
