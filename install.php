<?php
/**
 * SmartAttend — First-Run Installation Wizard
 *
 * This file creates the first admin account securely.
 * It LOCKS ITSELF after setup is complete by writing a lock file.
 *
 * HOW TO USE:
 *   1. Deploy the project to your web server
 *   2. Import db/schema.sql and all migration files
 *   3. Open http://localhost/attendance-system/install.php
 *   4. Fill in the admin details and submit
 *   5. The lock file (config/.installed) is created — this page then refuses all access
 *
 * SECURITY: Delete this file from the server after installation.
 */

define('LOCK_FILE', __DIR__ . '/config/.installed');

// ── Already installed? Block immediately ─────────────────────────────────────
if (file_exists(LOCK_FILE)) {
    http_response_code(403);
    die('<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8">
    <title>Already Installed</title>
    <style>body{font-family:sans-serif;display:flex;justify-content:center;align-items:center;min-height:100vh;margin:0;background:#f1f5f9}
    .box{background:#fff;padding:2.5rem;border-radius:12px;box-shadow:0 4px 20px rgba(0,0,0,.1);max-width:480px;text-align:center}
    h2{color:#dc2626;margin-top:0}p{color:#475569}a{color:#6366f1}</style></head>
    <body><div class="box">
    <h2>⛔ Already Installed</h2>
    <p>SmartAttend has already been set up. This installer is locked.</p>
    <p>If you need to reset, delete <code>config/.installed</code> from the server — then delete this file entirely after re-setup.</p>
    <p><a href="/attendance-system/auth/login.php">Go to Login →</a></p>
    </div></body></html>');
}

session_start();
require_once __DIR__ . '/config/db.php';

$errors  = [];
$success = false;

// ── Check DB connectivity ─────────────────────────────────────────────────────
$dbOk = !$conn->connect_error;
$tableExists = false;
if ($dbOk) {
    $tableExists = $conn->query("SHOW TABLES LIKE 'users'")->num_rows > 0;
}

// ── Process Setup Form ────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $dbOk && $tableExists) {

    $name      = trim($_POST['name']      ?? '');
    $univ_id   = trim($_POST['university_id'] ?? '');
    $email     = trim($_POST['email']     ?? '');
    $password  = $_POST['password']       ?? '';
    $password2 = $_POST['password2']      ?? '';

    // Validate
    if (strlen($name) < 2)         $errors[] = 'Full name must be at least 2 characters.';
    if (strlen($univ_id) < 3)      $errors[] = 'University ID must be at least 3 characters.';
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'Valid email address is required.';
    if (strlen($password) < 10)    $errors[] = 'Password must be at least 10 characters.';
    if (!preg_match('/[A-Z]/', $password)) $errors[] = 'Password must contain at least one uppercase letter.';
    if (!preg_match('/[0-9]/', $password)) $errors[] = 'Password must contain at least one number.';
    if (!preg_match('/[^A-Za-z0-9]/', $password)) $errors[] = 'Password must contain at least one special character (!@#$%^&*).';
    if ($password !== $password2)  $errors[] = 'Passwords do not match.';

    // Check university_id not taken
    if (empty($errors)) {
        $check = $conn->prepare("SELECT id FROM users WHERE university_id=?");
        $check->bind_param('s', $univ_id);
        $check->execute();
        if ($check->get_result()->num_rows > 0) {
            $errors[] = 'That University ID is already registered.';
        }
    }

    if (empty($errors)) {
        $hash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);

        $stmt = $conn->prepare("
            INSERT INTO users (name, university_id, email, password, role, is_first_login, is_active)
            VALUES (?, ?, ?, ?, 'admin', 0, 1)
        ");
        $stmt->bind_param('ssss', $name, $univ_id, $email, $hash);

        if ($stmt->execute()) {
            // Write lock file
            file_put_contents(LOCK_FILE, date('Y-m-d H:i:s') . " — installed by $univ_id\n");
            $success = true;
        } else {
            $errors[] = 'Database error: ' . $conn->error;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Install SmartAttend</title>
    <style>
        *, *::before, *::after { box-sizing: border-box; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 1.5rem;
            margin: 0;
        }
        .card {
            background: #fff;
            border-radius: 16px;
            box-shadow: 0 20px 60px rgba(0,0,0,.2);
            padding: 2.5rem;
            width: 100%;
            max-width: 520px;
        }
        .logo { text-align: center; margin-bottom: 2rem; }
        .logo-icon { font-size: 3rem; }
        h1 { margin: .5rem 0 .25rem; font-size: 1.6rem; color: #1e293b; }
        .subtitle { color: #64748b; font-size: .95rem; margin: 0; }
        .step-badge {
            display: inline-block;
            background: #ede9fe;
            color: #6d28d9;
            font-size: .78rem;
            font-weight: 700;
            padding: .2rem .7rem;
            border-radius: 20px;
            margin-bottom: 1.5rem;
        }
        .form-group { margin-bottom: 1.1rem; }
        label { display: block; font-weight: 600; color: #334155; margin-bottom: .4rem; font-size: .9rem; }
        input[type=text], input[type=email], input[type=password] {
            width: 100%;
            padding: .65rem .9rem;
            border: 1.5px solid #cbd5e1;
            border-radius: 8px;
            font-size: .95rem;
            color: #1e293b;
            transition: border-color .2s;
            outline: none;
        }
        input:focus { border-color: #6366f1; }
        .hint { font-size: .78rem; color: #94a3b8; margin-top: .3rem; }
        .btn {
            width: 100%;
            padding: .8rem;
            background: #6366f1;
            color: #fff;
            font-size: 1rem;
            font-weight: 600;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            margin-top: .5rem;
            transition: background .2s;
        }
        .btn:hover { background: #4f46e5; }
        .alert-error {
            background: #fee2e2;
            color: #b91c1c;
            border-left: 4px solid #dc2626;
            border-radius: 0 8px 8px 0;
            padding: .75rem 1rem;
            margin-bottom: 1.25rem;
            font-size: .9rem;
        }
        .alert-error ul { margin: .4rem 0 0 1.2rem; padding: 0; }
        .alert-success {
            background: #dcfce7;
            color: #15803d;
            border-left: 4px solid #16a34a;
            border-radius: 0 8px 8px 0;
            padding: 1.25rem 1.5rem;
            margin-bottom: 1rem;
        }
        .alert-success h3 { margin: 0 0 .5rem; }
        .checklist { list-style: none; padding: 0; margin: .75rem 0; }
        .checklist li::before { content: '✅ '; }
        .db-status {
            padding: .6rem 1rem;
            border-radius: 8px;
            font-size: .88rem;
            margin-bottom: 1.25rem;
        }
        .db-ok  { background: #dcfce7; color: #15803d; }
        .db-err { background: #fee2e2; color: #b91c1c; }
        .warning-box {
            background: #fef3c7;
            border-left: 4px solid #d97706;
            border-radius: 0 8px 8px 0;
            padding: .75rem 1rem;
            margin-top: 1.5rem;
            font-size: .85rem;
            color: #92400e;
        }
        .strength-bar { height: 4px; border-radius: 4px; margin-top: .4rem; background: #e2e8f0; overflow: hidden; }
        .strength-fill { height: 100%; width: 0; border-radius: 4px; transition: width .3s, background .3s; }
        #strengthText { font-size: .78rem; color: #64748b; margin-top: .2rem; }
    </style>
</head>
<body>
<div class="card">
    <div class="logo">
        <div class="logo-icon">📋</div>
        <h1>SmartAttend</h1>
        <p class="subtitle">First-Run Setup Wizard</p>
    </div>

    <?php if ($success): ?>

    <div class="alert-success">
        <h3>✅ Installation Complete!</h3>
        <p>Your admin account has been created and the installer is now locked.</p>
        <ul class="checklist">
            <li>Admin account created</li>
            <li>Installer locked (config/.installed)</li>
            <li>No default credentials in database</li>
        </ul>
    </div>

    <div class="warning-box">
        <strong>⚠️ Security Reminder:</strong> Delete <code>install.php</code> from your server now.
        It cannot be accessed again, but removing it is best practice.
    </div>

    <a href="/attendance-system/auth/login.php" class="btn" style="display:block;text-align:center;text-decoration:none;margin-top:1.5rem">
        Go to Login →
    </a>

    <?php else: ?>

    <span class="step-badge">Step 1 of 1 — Create Admin Account</span>

    <!-- DB Status -->
    <?php if (!$dbOk): ?>
    <div class="db-status db-err">
        ❌ Database connection failed. Check <code>config/db.php</code> before continuing.
    </div>
    <?php elseif (!$tableExists): ?>
    <div class="db-status db-err">
        ❌ Database tables not found. Please import <code>db/schema.sql</code> first, then reload this page.
    </div>
    <?php else: ?>
    <div class="db-status db-ok">
        ✅ Database connected — ready to set up.
    </div>
    <?php endif; ?>

    <!-- Errors -->
    <?php if (!empty($errors)): ?>
    <div class="alert-error">
        <strong>Please fix the following:</strong>
        <ul>
            <?php foreach ($errors as $e): ?>
            <li><?= htmlspecialchars($e) ?></li>
            <?php endforeach; ?>
        </ul>
    </div>
    <?php endif; ?>

    <form method="POST" autocomplete="off" id="setupForm">

        <div class="form-group">
            <label for="name">Full Name</label>
            <input type="text" id="name" name="name" required
                   value="<?= htmlspecialchars($_POST['name'] ?? '') ?>"
                   placeholder="e.g. System Administrator">
        </div>

        <div class="form-group">
            <label for="university_id">University ID (Login ID)</label>
            <input type="text" id="university_id" name="university_id" required
                   value="<?= htmlspecialchars($_POST['university_id'] ?? '') ?>"
                   placeholder="e.g. WU/ADMIN/0001/00" autocomplete="off">
            <p class="hint">This is the ID used to log in. Make it unique and memorable.</p>
        </div>

        <div class="form-group">
            <label for="email">Email Address</label>
            <input type="email" id="email" name="email" required
                   value="<?= htmlspecialchars($_POST['email'] ?? '') ?>"
                   placeholder="admin@university.edu">
        </div>

        <div class="form-group">
            <label for="password">Password</label>
            <input type="password" id="password" name="password" required
                   placeholder="Min 10 chars, uppercase, number, symbol"
                   autocomplete="new-password" oninput="checkStrength(this.value)">
            <div class="strength-bar"><div class="strength-fill" id="strengthFill"></div></div>
            <div id="strengthText">Enter a password</div>
        </div>

        <div class="form-group">
            <label for="password2">Confirm Password</label>
            <input type="password" id="password2" name="password2" required
                   placeholder="Re-enter your password" autocomplete="new-password">
        </div>

        <button type="submit" class="btn" <?= (!$dbOk || !$tableExists) ? 'disabled' : '' ?>>
            Create Admin Account & Complete Setup
        </button>
    </form>

    <div class="warning-box">
        <strong>🔒 Security Notes:</strong><br>
        • This page will be permanently locked after setup.<br>
        • Delete <code>install.php</code> from the server after installation.<br>
        • No credentials are stored in the database schema — only what you enter here.
    </div>

    <?php endif; ?>
</div>

<script>
function checkStrength(pw) {
    let score = 0;
    if (pw.length >= 10) score++;
    if (pw.length >= 14) score++;
    if (/[A-Z]/.test(pw)) score++;
    if (/[0-9]/.test(pw)) score++;
    if (/[^A-Za-z0-9]/.test(pw)) score++;

    const fill  = document.getElementById('strengthFill');
    const label = document.getElementById('strengthText');
    const levels = [
        { pct: '20%',  bg: '#dc2626', text: 'Very Weak' },
        { pct: '40%',  bg: '#f97316', text: 'Weak' },
        { pct: '60%',  bg: '#eab308', text: 'Fair' },
        { pct: '80%',  bg: '#22c55e', text: 'Strong' },
        { pct: '100%', bg: '#16a34a', text: 'Very Strong ✓' },
    ];
    const l = levels[Math.min(score, 4)];
    fill.style.width  = pw.length ? l.pct  : '0';
    fill.style.background = l.bg;
    label.textContent = pw.length ? l.text : 'Enter a password';
    label.style.color = pw.length ? l.bg   : '#64748b';
}
</script>
</body>
</html>
