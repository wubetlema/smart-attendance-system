<?php
require_once '../includes/auth_check.php';
requireRole('admin');
require_once '../config/db.php';

$msg = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $keys = ['late_minutes','max_absence_pct','enable_face','enable_qr','enable_geolocation',
             'enable_ip_filter','allowed_ips','session_timeout','min_attendance_pct',
             'email_notifications','smtp_host','smtp_user','smtp_pass','smtp_port'];
    foreach ($keys as $k) {
        $v = trim($_POST[$k] ?? '0');
        $stmt = $conn->prepare("INSERT INTO settings (setting_key,setting_value) VALUES (?,?) ON DUPLICATE KEY UPDATE setting_value=?");
        $stmt->bind_param('sss', $k, $v, $v);
        $stmt->execute();
    }
    $msg = 'Settings saved.';
}

$raw = $conn->query("SELECT setting_key, setting_value FROM settings")->fetch_all(MYSQLI_ASSOC);
$s = [];
foreach ($raw as $r) $s[$r['setting_key']] = $r['setting_value'];

include '../includes/header.php';
?>
<h1>System Settings</h1>
<?php if ($msg): ?><div class="alert alert-success"><?= htmlspecialchars($msg) ?></div><?php endif; ?>

<form method="POST">
<div style="display:grid;grid-template-columns:1fr 1fr;gap:1.5rem">

<div class="card">
    <h3>Attendance Rules</h3>
    <div class="form-group"><label>Late Mark After (minutes from start)</label>
        <input type="number" name="late_minutes" value="<?= htmlspecialchars($s['late_minutes']??'5') ?>" min="0" max="60"></div>
    <div class="form-group"><label>Minimum Attendance % Required</label>
        <input type="number" name="min_attendance_pct" value="<?= htmlspecialchars($s['min_attendance_pct']??'75') ?>" min="0" max="100"></div>
    <div class="form-group"><label>Maximum Allowed Absence %</label>
        <input type="number" name="max_absence_pct" value="<?= htmlspecialchars($s['max_absence_pct']??'25') ?>" min="0" max="100"></div>
</div>

<div class="card">
    <h3>Check-in Methods</h3>
    <div style="display:flex;flex-direction:column;gap:.75rem">
        <label style="display:flex;align-items:center;gap:.75rem;cursor:pointer">
            <input type="checkbox" name="enable_face" value="1" <?= ($s['enable_face']??'1')?'checked':'' ?> style="width:auto">
            Enable Face Recognition
        </label>
        <label style="display:flex;align-items:center;gap:.75rem;cursor:pointer">
            <input type="checkbox" name="enable_qr" value="1" <?= ($s['enable_qr']??'1')?'checked':'' ?> style="width:auto">
            Enable QR Code Scanning
        </label>
        <label style="display:flex;align-items:center;gap:.75rem;cursor:pointer">
            <input type="checkbox" name="enable_geolocation" value="1" <?= ($s['enable_geolocation']??'0')?'checked':'' ?> style="width:auto">
            Enable Geolocation Check (GPS)
        </label>
        <label style="display:flex;align-items:center;gap:.75rem;cursor:pointer">
            <input type="checkbox" name="enable_ip_filter" value="1" <?= ($s['enable_ip_filter']??'0')?'checked':'' ?> style="width:auto">
            Enable IP Filtering (Lab Computers)
        </label>
    </div>
    <div class="form-group" style="margin-top:1rem"><label>Allowed IP Addresses (comma separated)</label>
        <input type="text" name="allowed_ips" value="<?= htmlspecialchars($s['allowed_ips']??'') ?>" placeholder="192.168.1.10, 192.168.1.11"></div>
</div>

<div class="card">
    <h3>Session & Security</h3>
    <div class="form-group"><label>Session Timeout (minutes)</label>
        <input type="number" name="session_timeout" value="<?= htmlspecialchars($s['session_timeout']??'60') ?>" min="5" max="480"></div>
</div>

<div class="card">
    <h3>Email Notifications (SMTP)</h3>
    <label style="display:flex;align-items:center;gap:.75rem;cursor:pointer;margin-bottom:1rem">
        <input type="checkbox" name="email_notifications" value="1" <?= ($s['email_notifications']??'0')?'checked':'' ?> style="width:auto">
        Enable Email Notifications
    </label>
    <div class="form-group"><label>SMTP Host</label><input type="text" name="smtp_host" value="<?= htmlspecialchars($s['smtp_host']??'') ?>" placeholder="smtp.gmail.com"></div>
    <div class="form-group"><label>SMTP Port</label><input type="number" name="smtp_port" value="<?= htmlspecialchars($s['smtp_port']??'587') ?>"></div>
    <div class="form-group"><label>SMTP Username</label><input type="text" name="smtp_user" value="<?= htmlspecialchars($s['smtp_user']??'') ?>" placeholder="your@email.com"></div>
    <div class="form-group"><label>SMTP Password</label><input type="password" name="smtp_pass" value="<?= htmlspecialchars($s['smtp_pass']??'') ?>" autocomplete="new-password"></div>
</div>

</div>
<div style="margin-top:1rem">
    <button type="submit" class="btn btn-primary">Save All Settings</button>
</div>
</form>
<?php include '../includes/footer.php'; ?>
