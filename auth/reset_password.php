<?php
/**
 * Reset Password
 * Validates the token from the email link, then allows setting a new password.
 */
define('SKIP_CSRF', true);
session_start();
require_once '../config/db.php';
require_once '../includes/auth_check.php';
require_once '../includes/functions.php';

if (isset($_SESSION['user_id'])) {
    header('Location: /attendance-system/index.php');
    exit;
}

$token    = trim($_GET['token'] ?? $_POST['token'] ?? '');
$msg      = ''; $msgType = 'error';
$tokenRow = null;
$done     = false;

// ── Validate token ────────────────────────────────────────────────────────────
if ($token) {
    $stmt = $conn->prepare("
        SELECT t.*, u.name, u.email, u.university_id
        FROM password_reset_tokens t
        JOIN users u ON t.user_id=u.id
        WHERE t.token=? AND t.used=0 AND t.expires_at > NOW()
    ");
    $stmt->bind_param('s', $token);
    $stmt->execute();
    $tokenRow = $stmt->get_result()->fetch_assoc();
}

// ── Process new password ──────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $tokenRow) {
    verifyCsrf();

    $new     = $_POST['new_password']     ?? '';
    $confirm = $_POST['confirm_password'] ?? '';

    $errors = [];
    if (strlen($new) < 10)                           $errors[] = 'Password must be at least 10 characters.';
    if (!preg_match('/[A-Z]/', $new))                $errors[] = 'Must contain at least one uppercase letter.';
    if (!preg_match('/[0-9]/', $new))                $errors[] = 'Must contain at least one number.';
    if (!preg_match('/[^A-Za-z0-9]/', $new))         $errors[] = 'Must contain at least one special character.';
    if ($new !== $confirm)                           $errors[] = 'Passwords do not match.';

    if ($errors) {
        $msg = implode(' ', $errors);
    } else {
        $hash = password_hash($new, PASSWORD_BCRYPT, ['cost' => 12]);
        $uid  = (int)$tokenRow['user_id'];

        // Update password + clear first-login and force-reset flags
        $upd = $conn->prepare("
            UPDATE users
            SET password=?, is_first_login=0, force_password_reset=0, last_password_change=NOW()
            WHERE id=?
        ");
        $upd->bind_param('si', $hash, $uid);
        $upd->execute();

        // Mark token as used
        $conn->query("UPDATE password_reset_tokens SET used=1 WHERE id={$tokenRow['id']}");

        // Invalidate all active sessions for this user (security)
        $conn->query("UPDATE user_sessions SET is_active=0, force_logout=1 WHERE user_id=$uid");

        $done = true;
        $msg  = 'Password reset successfully. You can now log in with your new password.';
        $msgType = 'success';
    }
}

// Token invalid or expired
if (!$token || (!$tokenRow && !$done)) {
    $msg = 'This password reset link is invalid or has expired. Please request a new one.';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reset Password — SmartAttend</title>
    <link rel="stylesheet" href="/attendance-system/assets/style.css">
</head>
<body class="auth-page">
<div class="auth-card">
    <div class="auth-logo">
        <div class="logo-icon">🔑</div>
        <h2>Set New Password</h2>
        <?php if ($tokenRow && !$done): ?>
        <p class="subtitle">Setting new password for <strong><?= htmlspecialchars($tokenRow['university_id']) ?></strong></p>
        <?php endif; ?>
    </div>

    <?php if ($msg): ?>
    <div class="alert alert-<?= $msgType ?>"><?= htmlspecialchars($msg) ?></div>
    <?php endif; ?>

    <?php if ($done): ?>
    <div style="text-align:center;margin-top:1rem">
        <div style="font-size:3rem;margin-bottom:.75rem">✅</div>
        <p class="text-muted">Your password has been changed. All other sessions have been signed out for security.</p>
        <a href="/attendance-system/auth/login.php" class="btn btn-primary btn-full" style="margin-top:1rem">Go to Login →</a>
    </div>

    <?php elseif ($tokenRow): ?>
    <form method="POST" autocomplete="off" id="resetForm">
        <?= csrfField() ?>
        <input type="hidden" name="token" value="<?= htmlspecialchars($token) ?>">

        <div class="form-group">
            <label>New Password</label>
            <input type="password" name="new_password" id="newPw" required
                   placeholder="Min 10 chars, uppercase, number, symbol"
                   autocomplete="new-password"
                   oninput="checkPw(this.value)">
            <div style="height:5px;background:var(--border);border-radius:4px;margin-top:.4rem;overflow:hidden">
                <div id="strengthBar" style="height:100%;width:0;border-radius:4px;transition:width .3s,background .3s"></div>
            </div>
            <div id="strengthText" style="font-size:.78rem;color:var(--text-muted);margin-top:.2rem">Enter a password</div>
            <div style="margin-top:.5rem;display:grid;grid-template-columns:1fr 1fr;gap:.1rem">
                <div id="req-len"   style="font-size:.75rem;color:var(--text-muted)">○ 10+ characters</div>
                <div id="req-upper" style="font-size:.75rem;color:var(--text-muted)">○ Uppercase letter</div>
                <div id="req-num"   style="font-size:.75rem;color:var(--text-muted)">○ Number</div>
                <div id="req-sym"   style="font-size:.75rem;color:var(--text-muted)">○ Special character</div>
            </div>
        </div>

        <div class="form-group">
            <label>Confirm New Password</label>
            <input type="password" name="confirm_password" id="confirmPw" required
                   autocomplete="new-password"
                   oninput="checkMatch()">
            <div id="matchText" style="font-size:.78rem;margin-top:.2rem"></div>
        </div>

        <button type="submit" class="btn btn-primary btn-full" id="submitBtn">Set New Password</button>
    </form>

    <?php else: ?>
    <!-- Token invalid -->
    <div style="text-align:center;margin-top:1rem">
        <div style="font-size:3rem;margin-bottom:.75rem">❌</div>
        <a href="/attendance-system/auth/forgot_password.php" class="btn btn-primary btn-full">Request New Reset Link</a>
    </div>
    <?php endif; ?>

    <div style="text-align:center;margin-top:1.25rem">
        <a href="/attendance-system/auth/login.php" style="color:var(--text-muted);font-size:.9rem">← Back to Login</a>
    </div>
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
        const el = document.getElementById(id);
        if (el) { el.textContent = (met?'✓ ':'○ ') + el.textContent.replace(/^[✓○] /,''); el.style.color = met?'#16a34a':'var(--text-muted)'; }
    });
    const levels = [{pct:'25%',bg:'#dc2626',text:'Weak'},{pct:'50%',bg:'#f97316',text:'Fair'},{pct:'75%',bg:'#eab308',text:'Good'},{pct:'100%',bg:'#16a34a',text:'Strong ✓'}];
    const l = levels[Math.min(score-1,3)];
    document.getElementById('strengthBar').style.width      = pw.length ? (l?.pct??'0') : '0';
    document.getElementById('strengthBar').style.background = l?.bg ?? '#e2e8f0';
    document.getElementById('strengthText').textContent     = pw.length ? (l?.text??'') : 'Enter a password';
    checkMatch();
}
function checkMatch() {
    const pw  = document.getElementById('newPw')?.value;
    const cfw = document.getElementById('confirmPw')?.value;
    const el  = document.getElementById('matchText');
    if (!el || !cfw) return;
    el.textContent = pw===cfw ? '✓ Passwords match' : '✗ Passwords do not match';
    el.style.color = pw===cfw ? '#16a34a' : '#dc2626';
}
</script>
</body>
</html>
