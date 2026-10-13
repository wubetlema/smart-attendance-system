<?php
require_once '../includes/auth_check.php';
requireRole('admin');
require_once '../config/db.php';
require_once '../includes/functions.php';

$msg = ''; $msgType = 'success';
$tab = $_GET['tab'] ?? 'attendance';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $action = $_POST['action'] ?? 'save';

    if ($action === 'test_smtp') {
        // Test SMTP connection
        require_once '../includes/mailer.php';
        $testEmail = trim($_POST['test_email'] ?? $_SESSION['email'] ?? '');
        if (!$testEmail || !filter_var($testEmail, FILTER_VALIDATE_EMAIL)) {
            $msg = 'Enter a valid email address to test.'; $msgType = 'error';
        } else {
            $result = sendMail($testEmail, 'Admin', 'SmartAttend SMTP Test', '<p>SMTP is working correctly.</p><p>Sent at ' . date('d M Y H:i:s') . '</p>');
            $msg = $result['success'] ? "✅ Test email sent to $testEmail. Check your inbox." : "❌ SMTP failed: " . ($result['message'] ?? 'Unknown error');
            $msgType = $result['success'] ? 'success' : 'error';
        }
        $tab = 'email';

    } elseif ($action === 'generate_face_key') {
        // Generate a secure random encryption key
        $newKey = bin2hex(random_bytes(32)); // 64-char hex = 256-bit
        $stmt = $conn->prepare("INSERT INTO settings (setting_key, setting_value) VALUES ('face_encryption_key',?) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)");
        $stmt->bind_param('s', $newKey);
        $stmt->execute();
        $msg = '✅ New encryption key generated and saved. Existing face registrations (if any) should be re-enrolled.';
        $tab = 'biometric';

    } elseif ($action === 'auto_setup') {
        // Auto-configure: generate face key if missing
        $changes = [];
        $faceKey = getSetting('face_encryption_key', '');
        if (empty($faceKey)) {
            $newKey = bin2hex(random_bytes(32));
            $s = $conn->prepare("INSERT INTO settings (setting_key,setting_value) VALUES ('face_encryption_key',?) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)");
            $s->bind_param('s', $newKey); $s->execute();
            $changes[] = '🔐 Face encryption key generated';
        }
        $msg = empty($changes) ? 'All settings already configured.' : 'Auto-setup complete: ' . implode(', ', $changes) . '.';
        $tab = 'biometric';

    } else {
        // Save settings based on tab
        $allKeys = [
            // Attendance
            'late_minutes','min_attendance_pct','max_absence_pct',
            'attendance_threshold_good','attendance_threshold_warning','attendance_late_counts',
            // Check-in methods
            'enable_face','enable_qr','enable_geolocation','enable_ip_filter','allowed_ips',
            // Session/security
            'session_timeout',
            // Email
            'email_notifications','smtp_host','smtp_port','smtp_user','smtp_from_name',
            'notify_student_attendance','notify_student_late','notify_student_absent',
            'notify_student_low_attendance','notify_student_correction',
            'notify_teacher_correction','notify_teacher_session_reminder','notify_teacher_low_attendance',
            'notify_admin_suspicious_login','notify_admin_backup_status','notify_admin_face_fail_streak',
            // Biometric
            'face_require_admin_approval','face_min_samples','face_max_registrations_per_hour',
            // University
            'university_name','university_short','university_city','current_academic_year','current_semester','id_format',
            // Auto-session
            'auto_session_enabled','auto_session_window_min','auto_present_minutes','auto_late_minutes','auto_session_method',
        ];

        // Handle password separately (only save if provided)
        if (!empty($_POST['smtp_pass'])) {
            $v = trim($_POST['smtp_pass']);
            $stmt = $conn->prepare("INSERT INTO settings (setting_key, setting_value) VALUES ('smtp_pass',?) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)");
            $stmt->bind_param('s', $v); $stmt->execute();
        }
        // Handle face encryption key separately (only save if provided)
        if (!empty($_POST['face_encryption_key']) && strlen($_POST['face_encryption_key']) >= 16) {
            $v = trim($_POST['face_encryption_key']);
            $stmt = $conn->prepare("INSERT INTO settings (setting_key, setting_value) VALUES ('face_encryption_key',?) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)");
            $stmt->bind_param('s', $v); $stmt->execute();
        }

        foreach ($allKeys as $k) {
            $v = isset($_POST[$k]) ? trim($_POST[$k]) : '0';
            // Checkboxes: if not in POST, value is 0
            if (in_array($k, ['enable_face','enable_qr','enable_geolocation','enable_ip_filter',
                               'email_notifications','face_require_admin_approval','auto_session_enabled',
                               'notify_student_attendance','notify_student_late','notify_student_absent',
                               'notify_student_low_attendance','notify_student_correction',
                               'notify_teacher_correction','notify_teacher_session_reminder','notify_teacher_low_attendance',
                               'notify_admin_suspicious_login','notify_admin_backup_status','notify_admin_face_fail_streak'])) {
                $v = isset($_POST[$k]) ? '1' : '0';
            }
            $stmt = $conn->prepare("INSERT INTO settings (setting_key, setting_value) VALUES (?,?) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)");
            $stmt->bind_param('ss', $k, $v); $stmt->execute();
        }
        $msg = 'Settings saved successfully.';
        $tab = $_POST['tab'] ?? $tab;
    }
}

// Load all settings
$raw = $conn->query("SELECT setting_key, setting_value FROM settings")->fetch_all(MYSQLI_ASSOC);
$s = [];
foreach ($raw as $r) $s[$r['setting_key']] = $r['setting_value'];

// Warning flags
$smtpConfigured  = !empty($s['smtp_host']) && !empty($s['smtp_user']) && !empty($s['smtp_pass']);
$faceKeySet      = !empty($s['face_encryption_key']);
$emailEnabled    = ($s['email_notifications'] ?? '0') === '1';

include '../includes/header.php';
?>

<h1>⚙️ System Settings</h1>

<?php if ($msg): ?>
<div class="alert alert-<?= $msgType ?>"><?= htmlspecialchars($msg) ?></div>
<?php endif; ?>

<!-- Critical warnings -->
<?php if (!$faceKeySet): ?>
<div class="alert alert-error" style="border-left:4px solid #dc2626">
    🔐 <strong>Critical:</strong> Face encryption key is not set. Face descriptors are stored as plain text.
    <a href="?tab=biometric" class="btn btn-sm btn-danger" style="margin-left:.75rem">Fix Now →</a>
</div>
<?php endif; ?>
<?php if (!$smtpConfigured): ?>
<div class="alert alert-warning" style="border-left:4px solid #d97706">
    📧 <strong>Warning:</strong> SMTP is not configured. Email notifications will not be sent.
    <a href="?tab=email" class="btn btn-sm btn-warning" style="margin-left:.75rem">Configure SMTP →</a>
</div>
<?php endif; ?>

<!-- Tabs -->
<div style="display:flex;gap:.3rem;flex-wrap:wrap;margin-bottom:1.25rem;border-bottom:2px solid var(--border);padding-bottom:.5rem">
    <?php
    $tabs = [
        'attendance' => '📊 Attendance',
        'checkin'    => '📷 Check-in',
        'email'      => ($smtpConfigured ? '📧' : '⚠️') . ' Email / SMTP',
        'biometric'  => ($faceKeySet ? '🔒' : '🔴') . ' Biometric',
        'autosession'=> '🤖 Auto-Session',
        'university' => '🏛️ University',
        'cron'       => '⏰ Cron / Scheduler',
    ];
    foreach ($tabs as $k => $l):
    ?>
    <a href="?tab=<?= $k ?>"
       style="padding:.5rem 1rem;border-radius:8px 8px 0 0;text-decoration:none;font-weight:600;font-size:.88rem;
              background:<?= $tab===$k?'var(--primary)':'var(--hover-bg)' ?>;
              color:<?= $tab===$k?'#fff':'var(--text)' ?>">
        <?= $l ?>
    </a>
    <?php endforeach; ?>
</div>

<form method="POST">
    <?= csrfField() ?>
    <input type="hidden" name="tab" value="<?= $tab ?>">

<!-- ── TAB: Attendance ───────────────────────────────────────────────────── -->
<?php if ($tab === 'attendance'): ?>
<div style="display:grid;grid-template-columns:1fr 1fr;gap:1.25rem">
<div class="card">
    <h3>Attendance Rules</h3>
    <div class="form-group"><label>Late Mark After (minutes from session start)</label>
        <input type="number" name="late_minutes" value="<?= $s['late_minutes']??'10' ?>" min="0" max="60">
        <small class="text-muted">Students arriving after this window are marked Late, not Present.</small></div>
    <div class="form-group"><label>🟢 Good — Minimum Attendance % <span id="goodPct" style="color:#16a34a;font-weight:700"><?= $s['attendance_threshold_good']??75 ?>%</span></label>
        <input type="range" name="attendance_threshold_good" min="50" max="100" value="<?= $s['attendance_threshold_good']??75 ?>" oninput="document.getElementById('goodPct').textContent=this.value+'%'" style="width:100%;accent-color:#16a34a">
        <small class="text-muted">Students at or above this are shown in green.</small></div>
    <div class="form-group"><label>🟡 Warning — Minimum Attendance % <span id="warnPct" style="color:#d97706;font-weight:700"><?= $s['attendance_threshold_warning']??60 ?>%</span></label>
        <input type="range" name="attendance_threshold_warning" min="30" max="90" value="<?= $s['attendance_threshold_warning']??60 ?>" oninput="document.getElementById('warnPct').textContent=this.value+'%'" style="width:100%;accent-color:#d97706">
        <small class="text-muted">Below this = Critical (red).</small></div>
    <div class="form-group"><label>How Late arrivals count</label>
        <select name="attendance_late_counts">
            <option value="1"   <?= ($s['attendance_late_counts']??'1')==='1'  ?'selected':'' ?>>Full attendance (same as Present)</option>
            <option value="0.5" <?= ($s['attendance_late_counts']??'1')==='0.5'?'selected':'' ?>>Half attendance (0.5 weight)</option>
            <option value="0"   <?= ($s['attendance_late_counts']??'1')==='0'  ?'selected':'' ?>>Not counted (same as Absent)</option>
        </select></div>
    <div class="form-group"><label>Session Timeout (minutes of inactivity)</label>
        <input type="number" name="session_timeout" value="<?= $s['session_timeout']??'60' ?>" min="5" max="480"></div>
</div>
<div class="card">
    <h3>Attendance % Reference</h3>
    <div style="background:#f8fafc;border-radius:10px;padding:1rem">
        <?php
        $examples = [91, 80, 72, 63, 55];
        $good = (int)($s['attendance_threshold_good'] ?? 75);
        $warn = (int)($s['attendance_threshold_warning'] ?? 60);
        foreach ($examples as $pct):
            $color = $pct >= $good ? '#16a34a' : ($pct >= $warn ? '#d97706' : '#dc2626');
            $icon  = $pct >= $good ? '🟢' : ($pct >= $warn ? '🟡' : '🔴');
        ?>
        <div style="display:flex;align-items:center;gap:.75rem;margin-bottom:.5rem">
            <span style="width:60px;text-align:right;font-weight:700;color:<?= $color ?>"><?= $pct ?>%</span>
            <div style="flex:1;height:8px;background:#e2e8f0;border-radius:4px;overflow:hidden">
                <div style="height:100%;width:<?= $pct ?>%;background:<?= $color ?>;border-radius:4px"></div>
            </div>
            <span><?= $icon ?></span>
        </div>
        <?php endforeach; ?>
    </div>
</div>
</div>

<!-- ── TAB: Check-in Methods ─────────────────────────────────────────────── -->
<?php elseif ($tab === 'checkin'): ?>
<div class="card" style="max-width:600px">
    <h3>Check-in Methods</h3>
    <?php foreach ([
        'enable_qr'          => ['📷 QR Code Check-in (Primary)',        'Recommended. Dynamic QR rotates every 30 seconds.'],
        'enable_face'        => ['🤳 Face Recognition Check-in',         'Secondary method. Requires camera and liveness detection.'],
        'enable_geolocation' => ['📍 Geolocation Verification',          'Check student is physically present in the building.'],
        'enable_ip_filter'   => ['🖥️ IP Address Filtering (Lab mode)',   'Only allow check-in from specific IP addresses.'],
    ] as $key => [$label, $desc]): ?>
    <label style="display:flex;align-items:flex-start;gap:.75rem;cursor:pointer;padding:.65rem;border:1.5px solid var(--border);border-radius:8px;margin-bottom:.5rem">
        <input type="checkbox" name="<?= $key ?>" value="1" <?= ($s[$key]??'1')==='1'?'checked':'' ?> style="width:auto;margin-top:.15rem">
        <div><strong><?= $label ?></strong><br><small class="text-muted"><?= $desc ?></small></div>
    </label>
    <?php endforeach; ?>
    <div class="form-group" style="margin-top:.75rem"><label>Allowed IP Addresses (comma separated)</label>
        <input type="text" name="allowed_ips" value="<?= htmlspecialchars($s['allowed_ips']??'') ?>" placeholder="192.168.1.10, 192.168.1.0/24">
        <small class="text-muted">Only used when IP Filtering is enabled above.</small></div>
</div>

<!-- ── TAB: Email / SMTP ──────────────────────────────────────────────────── -->
<?php elseif ($tab === 'email'): ?>
<div style="display:grid;grid-template-columns:1fr 1fr;gap:1.25rem">
<div class="card">
    <h3>SMTP Configuration</h3>

    <?php if (!$smtpConfigured): ?>
    <div class="alert alert-warning" style="font-size:.88rem">
        ⚠️ SMTP is not configured. Fill in the fields below to enable email notifications.
        <br><br>
        <strong>Gmail setup:</strong><br>
        Host: <code>smtp.gmail.com</code> · Port: <code>587</code><br>
        Username: your Gmail address<br>
        Password: <a href="https://myaccount.google.com/apppasswords" target="_blank">App Password</a> (not your regular password)
    </div>
    <?php endif; ?>

    <label style="display:flex;align-items:center;gap:.6rem;cursor:pointer;margin-bottom:1rem">
        <input type="checkbox" name="email_notifications" value="1" <?= ($s['email_notifications']??'0')==='1'?'checked':'' ?>>
        Enable Email Notifications Globally
    </label>

    <div class="form-group"><label>SMTP Host</label>
        <input type="text" name="smtp_host" value="<?= htmlspecialchars($s['smtp_host']??'') ?>" placeholder="smtp.gmail.com">
    </div>
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:.75rem">
        <div class="form-group" style="margin:0"><label>SMTP Port</label>
            <select name="smtp_port">
                <option value="587" <?= ($s['smtp_port']??'587')==='587'?'selected':'' ?>>587 (TLS — recommended)</option>
                <option value="465" <?= ($s['smtp_port']??'587')==='465'?'selected':'' ?>>465 (SSL)</option>
                <option value="25"  <?= ($s['smtp_port']??'587')==='25' ?'selected':'' ?>>25 (Plain)</option>
            </select></div>
        <div class="form-group" style="margin:0"><label>Sender Name</label>
            <input type="text" name="smtp_from_name" value="<?= htmlspecialchars($s['smtp_from_name']??'SmartAttend') ?>"></div>
    </div>
    <div class="form-group"><label>SMTP Username / Sender Email</label>
        <input type="email" name="smtp_user" value="<?= htmlspecialchars($s['smtp_user']??'') ?>" placeholder="your@gmail.com">
    </div>
    <div class="form-group"><label>SMTP Password</label>
        <input type="password" name="smtp_pass" placeholder="<?= $smtpConfigured?'(leave blank to keep current)':'Enter SMTP password' ?>" autocomplete="new-password">
        <?php if ($smtpConfigured): ?>
        <small class="text-muted">✅ Password is set. Leave blank to keep it unchanged.</small>
        <?php endif; ?>
    </div>

    <!-- Test SMTP -->
    <div style="background:#f8fafc;border-radius:8px;padding:1rem;margin-top:.75rem">
        <h4 style="margin:0 0 .5rem">Test SMTP Connection</h4>
        <div style="display:flex;gap:.5rem">
            <input type="email" name="test_email" placeholder="Send test to..." style="flex:1"
                   value="<?= htmlspecialchars($s['smtp_user']??'') ?>">
            <button type="submit" name="action" value="test_smtp" class="btn btn-secondary">Send Test</button>
        </div>
    </div>
</div>

<div class="card">
    <h3>Notification Preferences</h3>
    <p class="text-muted" style="font-size:.85rem">Choose which events trigger email notifications.</p>

    <h4 style="font-size:.85rem;text-transform:uppercase;color:var(--text-muted);margin:.75rem 0 .4rem">Student Emails</h4>
    <?php foreach ([
        'notify_student_attendance'     => 'Attendance marked (present)',
        'notify_student_late'           => 'Marked late',
        'notify_student_absent'         => 'Marked absent',
        'notify_student_low_attendance' => 'Low attendance warning',
        'notify_student_correction'     => 'Correction request status changed',
    ] as $k => $l): ?>
    <label style="display:flex;align-items:center;gap:.5rem;cursor:pointer;font-size:.88rem;padding:.25rem 0">
        <input type="checkbox" name="<?= $k ?>" value="1" <?= ($s[$k]??'1')==='1'?'checked':'' ?>>
        <?= $l ?>
    </label>
    <?php endforeach; ?>

    <h4 style="font-size:.85rem;text-transform:uppercase;color:var(--text-muted);margin:.75rem 0 .4rem">Teacher Emails</h4>
    <?php foreach ([
        'notify_teacher_correction'       => 'New correction request',
        'notify_teacher_session_reminder' => 'Session auto-created',
        'notify_teacher_low_attendance'   => 'Student at-risk alert',
    ] as $k => $l): ?>
    <label style="display:flex;align-items:center;gap:.5rem;cursor:pointer;font-size:.88rem;padding:.25rem 0">
        <input type="checkbox" name="<?= $k ?>" value="1" <?= ($s[$k]??'1')==='1'?'checked':'' ?>>
        <?= $l ?>
    </label>
    <?php endforeach; ?>

    <h4 style="font-size:.85rem;text-transform:uppercase;color:var(--text-muted);margin:.75rem 0 .4rem">Admin Emails</h4>
    <?php foreach ([
        'notify_admin_suspicious_login'  => 'Suspicious login attempt',
        'notify_admin_backup_status'     => 'Backup completed/failed',
        'notify_admin_face_fail_streak'  => 'Repeated face verification failures',
    ] as $k => $l): ?>
    <label style="display:flex;align-items:center;gap:.5rem;cursor:pointer;font-size:.88rem;padding:.25rem 0">
        <input type="checkbox" name="<?= $k ?>" value="1" <?= ($s[$k]??'1')==='1'?'checked':'' ?>>
        <?= $l ?>
    </label>
    <?php endforeach; ?>
</div>
</div>

<!-- ── TAB: Biometric ─────────────────────────────────────────────────────── -->
<?php elseif ($tab === 'biometric'): ?>
<div style="display:grid;grid-template-columns:1fr 1fr;gap:1.25rem">
<div class="card">
    <h3>Face Descriptor Encryption</h3>

    <?php if (!$faceKeySet): ?>
    <div class="alert alert-error">
        🔴 <strong>No encryption key set.</strong> Face descriptors are stored as plain JSON.
        Generate a key now — do this before any student registers their face.
    </div>
    <?php else: ?>
    <div class="alert alert-success">✅ Encryption key is set. Face data is encrypted at rest (AES-256-CBC).</div>
    <?php endif; ?>

    <!-- Generate key button -->
    <form method="POST" style="margin-bottom:1rem" onsubmit="return confirm('Generate a new key? Existing face registrations will need to be re-enrolled.')">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="generate_face_key">
        <input type="hidden" name="tab" value="biometric">
        <button type="submit" class="btn <?= $faceKeySet?'btn-secondary':'btn-primary' ?>">
            <?= $faceKeySet ? '🔄 Regenerate Key' : '🔑 Generate Encryption Key' ?>
        </button>
        <small class="text-muted" style="display:block;margin-top:.3rem">
            A secure 256-bit key is generated automatically. You never need to remember it.
        </small>
    </form>

    <div class="form-group"><label>Or Enter Your Own Key (min 16 characters)</label>
        <input type="password" name="face_encryption_key" autocomplete="off"
               placeholder="<?= $faceKeySet?'(key is set — leave blank to keep)':'Enter 32+ char random key' ?>">
        <small class="text-muted">⚠️ Never change this after face data has been enrolled.</small></div>
</div>

<div class="card">
    <h3>Registration Settings</h3>
    <div class="form-group"><label>Minimum Samples per Registration</label>
        <input type="number" name="face_min_samples" value="<?= $s['face_min_samples']??'3' ?>" min="1" max="10">
        <small class="text-muted">More samples = better accuracy. Recommended: 3.</small></div>
    <div class="form-group"><label>Max Registration Attempts per Hour</label>
        <input type="number" name="face_max_registrations_per_hour" value="<?= $s['face_max_registrations_per_hour']??'3' ?>" min="1" max="20"></div>
    <label style="display:flex;align-items:flex-start;gap:.6rem;cursor:pointer">
        <input type="checkbox" name="face_require_admin_approval" value="1" <?= ($s['face_require_admin_approval']??'0')==='1'?'checked':'' ?> style="width:auto;margin-top:.15rem">
        <div><strong>Require admin approval before face check-in is active</strong><br>
        <small class="text-muted">New enrollments will show as "Pending" until an admin approves them.</small></div>
    </label>
    <div style="margin-top:1rem">
        <a href="biometric.php" class="btn btn-secondary btn-sm">Open Biometric Management →</a>
    </div>
</div>
</div>

<!-- ── TAB: Auto-Session ─────────────────────────────────────────────────── -->
<?php elseif ($tab === 'autosession'): ?>
<div class="card" style="max-width:600px">
    <h3>🤖 Timetable Auto-Session</h3>
    <p class="text-muted">When enabled, the system automatically creates attendance sessions from the timetable. Teachers are notified and QR is ready before class starts.</p>

    <label style="display:flex;align-items:flex-start;gap:.6rem;cursor:pointer;margin-bottom:1rem">
        <input type="checkbox" name="auto_session_enabled" value="1" <?= ($s['auto_session_enabled']??'1')==='1'?'checked':'' ?> style="width:auto;margin-top:.15rem">
        <div><strong>Enable Auto-Session Creation</strong><br>
        <small class="text-muted">Requires a cron job or Task Scheduler running api/auto_session.php every minute.</small></div>
    </label>

    <div class="form-group"><label>Create Session N Minutes Before Class</label>
        <input type="number" name="auto_session_window_min" value="<?= $s['auto_session_window_min']??'5' ?>" min="1" max="30">
        <small class="text-muted">e.g. 5 = session is ready 5 minutes before the scheduled start.</small></div>
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:.75rem">
        <div class="form-group" style="margin:0"><label>Present Window (minutes)</label>
            <input type="number" name="auto_present_minutes" value="<?= $s['auto_present_minutes']??'10' ?>" min="1" max="120">
            <small class="text-muted">Marked Present if scanned within this time.</small></div>
        <div class="form-group" style="margin:0"><label>Session Closes After (minutes)</label>
            <input type="number" name="auto_late_minutes" value="<?= $s['auto_late_minutes']??'20' ?>" min="2" max="180">
            <small class="text-muted">Session closes, late period ends.</small></div>
    </div>
    <div class="form-group"><label>Default Check-in Method</label>
        <select name="auto_session_method">
            <option value="qr"     <?= ($s['auto_session_method']??'qr')==='qr'    ?'selected':'' ?>>QR Code (recommended)</option>
            <option value="face"   <?= ($s['auto_session_method']??'qr')==='face'  ?'selected':'' ?>>Face Recognition</option>
            <option value="manual" <?= ($s['auto_session_method']??'qr')==='manual'?'selected':'' ?>>Manual</option>
        </select></div>
</div>

<!-- ── TAB: University ───────────────────────────────────────────────────── -->
<?php elseif ($tab === 'university'): ?>
<div class="card" style="max-width:600px">
    <h3>🏛️ University Information</h3>
    <div class="form-group"><label>University Name</label><input type="text" name="university_name" value="<?= htmlspecialchars($s['university_name']??'Wollo University') ?>"></div>
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:.75rem">
        <div class="form-group" style="margin:0"><label>Abbreviation</label><input type="text" name="university_short" value="<?= htmlspecialchars($s['university_short']??'WU') ?>"></div>
        <div class="form-group" style="margin:0"><label>City / Location</label><input type="text" name="university_city" value="<?= htmlspecialchars($s['university_city']??'Dessie, Ethiopia') ?>"></div>
    </div>
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:.75rem;margin-top:.75rem">
        <div class="form-group" style="margin:0"><label>Current Academic Year</label><input type="text" name="current_academic_year" value="<?= htmlspecialchars($s['current_academic_year']??'2024/25') ?>" placeholder="2024/25"></div>
        <div class="form-group" style="margin:0"><label>Current Semester</label>
            <select name="current_semester">
                <option value="Semester 1" <?= ($s['current_semester']??'')==='Semester 1'?'selected':'' ?>>Semester 1</option>
                <option value="Semester 2" <?= ($s['current_semester']??'')==='Semester 2'?'selected':'' ?>>Semester 2</option>
            </select></div>
    </div>
    <div class="form-group"><label>Student ID Format</label>
        <input type="text" name="id_format" value="<?= htmlspecialchars($s['id_format']??'WU/{DEPT}/{NUM}/{YEAR}') ?>">
        <small class="text-muted">Tokens: {DEPT} = dept code, {NUM} = sequence, {YEAR} = entry year (2 digits)</small></div>
    <div style="margin-top:.75rem"><a href="academic_structure.php" class="btn btn-secondary btn-sm">Manage Full Hierarchy →</a></div>
</div>

<!-- ── TAB: Cron / Scheduler ─────────────────────────────────────────────── -->
<?php elseif ($tab === 'cron'): ?>
<div class="card">
    <h3>⏰ Automated Tasks Setup</h3>
    <p class="text-muted">These tasks need to run automatically. On Windows (XAMPP), use <strong>Task Scheduler</strong>. On Linux, use <strong>crontab</strong>.</p>

    <?php
    $autoEnabled = ($s['auto_session_enabled']??'1') === '1';
    $cronFile = 'C:\xampp\htdocs\attendance-system\api\auto_session.php';
    ?>

    <!-- Auto-session task -->
    <div style="padding:1rem;border-radius:10px;border:1.5px solid <?= $autoEnabled?'#86efac':'#fde68a' ?>;background:<?= $autoEnabled?'#f0fdf4':'#fffbeb' ?>;margin-bottom:1rem">
        <div style="display:flex;justify-content:space-between;align-items:flex-start;flex-wrap:wrap;gap:.5rem">
            <div>
                <strong>Auto-Session Creator</strong>
                <span class="badge" style="background:<?= $autoEnabled?'#dcfce7':'#fef3c7' ?>;color:<?= $autoEnabled?'#15803d':'#b45309' ?>;margin-left:.4rem">
                    <?= $autoEnabled ? '✅ Enabled' : '⚠️ Disabled' ?>
                </span>
                <br><small class="text-muted">Checks timetable and creates attendance sessions before class starts.</small>
            </div>
            <a href="?tab=autosession" class="btn btn-secondary btn-sm">Configure →</a>
        </div>
    </div>

    <!-- Windows Task Scheduler -->
    <div class="card" style="margin-bottom:0">
        <h4>Windows Task Scheduler Setup</h4>
        <ol style="line-height:2">
            <li>Open <strong>Task Scheduler</strong> (search in Start Menu)</li>
            <li>Click <strong>Create Basic Task...</strong></li>
            <li>Name: <code>SmartAttend Auto-Session</code></li>
            <li>Trigger: <strong>Daily</strong> → repeat every <strong>1 minute</strong> for a duration of <strong>1 day</strong></li>
            <li>Action: <strong>Start a program</strong></li>
            <li>Program: <code>C:\xampp\php\php.exe</code></li>
            <li>Arguments: <code>C:\xampp\htdocs\attendance-system\api\auto_session.php --cron</code></li>
            <li>Click <strong>Finish</strong></li>
        </ol>

        <div style="background:#1e293b;color:#e2e8f0;padding:1rem;border-radius:8px;font-family:monospace;font-size:.85rem;margin-top:.75rem">
            <div style="color:#94a3b8;margin-bottom:.4rem"># Or run manually to test:</div>
            <div>"C:\xampp\php\php.exe" "C:\xampp\htdocs\attendance-system\api\auto_session.php" --cron</div>
        </div>
    </div>

    <!-- Linux cron -->
    <div class="card" style="margin-top:1rem;margin-bottom:0">
        <h4>Linux / cPanel Cron Setup</h4>
        <p class="text-muted" style="font-size:.88rem">Add this line to your crontab (<code>crontab -e</code>):</p>
        <div style="background:#1e293b;color:#e2e8f0;padding:1rem;border-radius:8px;font-family:monospace;font-size:.85rem">
            * * * * * /usr/bin/php /var/www/html/attendance-system/api/auto_session.php --cron >> /dev/null 2>&1
        </div>
        <p class="text-muted" style="font-size:.82rem;margin-top:.5rem">In cPanel: Cron Jobs → Every minute → command above.</p>
    </div>

    <!-- Manual trigger -->
    <div style="margin-top:1rem;padding:.75rem 1rem;background:#f8fafc;border-radius:8px;border:1px solid var(--border)">
        <strong>Test now:</strong> <a href="/attendance-system/api/auto_session.php" target="_blank" class="btn btn-secondary btn-sm" style="margin-left:.5rem">Run Auto-Session Check →</a>
        <small class="text-muted" style="display:block;margin-top:.3rem">Opens in new tab and shows what sessions were created.</small>
    </div>
</div>
<?php endif; ?>

    <div style="margin-top:1.25rem">
        <button type="submit" class="btn btn-primary">💾 Save Settings</button>
    </div>
</form>

<?php include '../includes/footer.php'; ?>
