<?php
require_once '../includes/auth_check.php';
requireRole('teacher');
require_once '../config/db.php';
require_once '../includes/functions.php';
$user = currentUser();

$class_id = (int)($_GET['class_id'] ?? 0);
$session  = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $class_id) {
    $minutes = (int)($_POST['minutes'] ?? 15);
    $session = createSession($class_id, $user['id'], 'qr', $minutes);
}

$stmt = $conn->prepare("SELECT * FROM classes WHERE teacher_id=? ORDER BY name");
$stmt->bind_param('i', $user['id']);
$stmt->execute();
$classes = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

include '../includes/header.php';
?>
<h1>Generate QR Code</h1>

<div class="card">
    <form method="POST">
        <div class="form-inline">
            <select name="class_id" id="classSelect" required onchange="window.location.href='?class_id='+this.value">
                <option value="">Select Class</option>
                <?php foreach ($classes as $c): ?>
                <option value="<?= $c['id'] ?>" <?= $class_id == $c['id'] ? 'selected' : '' ?>>
                    <?= htmlspecialchars($c['name']) ?> - <?= htmlspecialchars($c['subject']) ?>
                </option>
                <?php endforeach; ?>
            </select>
            <?php if ($class_id): ?>
            <select name="minutes">
                <option value="10">10 minutes</option>
                <option value="15" selected>15 minutes</option>
                <option value="30">30 minutes</option>
                <option value="60">60 minutes</option>
            </select>
            <button type="submit" class="btn btn-primary">Generate QR</button>
            <?php endif; ?>
        </div>
    </form>
</div>

<?php if ($session): ?>
<div class="card text-center">
    <h3>Scan to Mark Attendance</h3>
    <p>Session expires at: <strong><?= $session['expires_at'] ?></strong></p>
    <div id="qrcode" style="display:flex;justify-content:center;margin:1.5rem 0"></div>
    <p class="text-muted">Token: <code><?= $session['token'] ?></code></p>
    <p>Students can also go to: <strong>QR Check-in</strong> and enter the token manually.</p>
</div>

<script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"></script>
<script>
const host = window.location.hostname;
const checkinUrl = `http://${host}/attendance-system/student/qr_checkin.php?token=<?= $session['token'] ?>`;
new QRCode(document.getElementById('qrcode'), {
    text: checkinUrl,
    width: 256,
    height: 256,
    colorDark: '#1a1a2e',
    colorLight: '#ffffff'
});
</script>
<?php endif; ?>
<?php include '../includes/footer.php'; ?>
