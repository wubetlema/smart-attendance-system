<?php
require_once '../includes/auth_check.php';
requireRole('student');
require_once '../config/db.php';
require_once '../includes/functions.php';
$user = currentUser();

$msg = '';
$msgType = 'info';

$token = $_POST['token'] ?? $_GET['token'] ?? '';
if ($token) {
    $stmt = $conn->prepare("
        SELECT s.id, c.name AS class_name, c.subject
        FROM attendance_sessions s
        JOIN classes c ON s.class_id = c.id
        WHERE s.qr_token=? AND s.expires_at > NOW()
    ");
    $stmt->bind_param('s', $token);
    $stmt->execute();
    $session = $stmt->get_result()->fetch_assoc();

    if ($session) {
        $result = markAttendance($session['id'], $user['id'], 'present', 'qr');
        $msg = $result['message'];
        $msgType = $result['success'] ? 'success' : 'error';

        // Send confirmation notification
        if ($result['success']) {
            $notifMsg = "Attendance marked for {$session['class_name']} ({$session['subject']}) - " . date('d M Y, H:i');
            $ns = $conn->prepare("INSERT INTO notifications (user_id, message) VALUES (?,?)");
            $ns->bind_param('is', $user['id'], $notifMsg);
            $ns->execute();

            // Check low attendance warning (below 75%)
            $check = $conn->prepare("
                SELECT COUNT(*) AS total, SUM(status='present') AS present
                FROM attendance a
                JOIN attendance_sessions s ON a.session_id = s.id
                WHERE a.student_id=? AND s.class_id=(SELECT class_id FROM attendance_sessions WHERE id=?)
            ");
            $check->bind_param('ii', $user['id'], $session['id']);
            $check->execute();
            $stat = $check->get_result()->fetch_assoc();
            if ($stat['total'] > 0) {
                $pct = round(($stat['present'] / $stat['total']) * 100);
                if ($pct < 75) {
                    $warn = "⚠️ Low attendance warning: Your attendance in {$session['class_name']} is {$pct}%. Minimum required is 75%.";
                    $ws = $conn->prepare("INSERT INTO notifications (user_id, message) VALUES (?,?)");
                    $ws->bind_param('is', $user['id'], $warn);
                    $ws->execute();
                }
            }
        }
    } else {
        $msg = 'Invalid or expired QR code / session token.';
        $msgType = 'error';
    }
}

include '../includes/header.php';
?>
<h1>📷 QR Check-in</h1>

<div id="syncMessages"></div>

<?php if ($msg): ?>
<div class="alert alert-<?= $msgType ?>">
    <?= $msgType === 'success' ? '✅' : '❌' ?> <?= htmlspecialchars($msg) ?>
</div>
<?php endif; ?>

<div class="card text-center">
    <h3>Scan QR Code</h3>
    <p class="text-muted mb-2">Point your camera at the QR code shown by your teacher.</p>
    <div class="qr-container" id="qrContainer">
        <div id="reader" style="width:100%"></div>
        <div class="qr-overlay">
            <div class="qr-frame"><div class="qr-scan-line"></div></div>
        </div>
    </div>
    <div id="scanStatus" class="alert alert-info mt-2" style="display:none"></div>
    <div style="margin-top:1.25rem">
        <p class="text-muted mb-1">Or enter the session code manually:</p>
        <form method="POST" style="display:flex;gap:.75rem;justify-content:center;flex-wrap:wrap">
            <input type="text" name="token" placeholder="Enter session code" required style="width:260px;max-width:100%">
            <button type="submit" class="btn btn-primary">Check In</button>
        </form>
    </div>
</div>

<script src="https://unpkg.com/html5-qrcode@2.3.8/html5-qrcode.min.js"></script>
<script>
// Listen for sync results from service worker
if ('serviceWorker' in navigator) {
    navigator.serviceWorker.addEventListener('message', function(event) {
        if (event.data && event.data.type === 'SYNC_COMPLETE') {
            const data = event.data;
            let html = '';

            if (data.synced > 0) {
                html += `<div class="alert alert-success">✅ ${data.synced} offline record(s) synced successfully.</div>`;
            }

            if (data.conflicts > 0) {
                data.results.filter(r => r.conflict).forEach(r => {
                    html += `<div class="alert alert-error">
                        ⚠️ Conflict: ${r.message}
                        ${r.existing_status ? `<br><small>Recorded status: <strong>${r.existing_status}</strong> (by ${r.existing_method})</small>` : ''}
                    </div>`;
                });
            }

            if (data.failed > 0) {
                html += `<div class="alert alert-error">❌ ${data.failed} record(s) could not be synced (session expired).</div>`;
            }

            if (html) {
                const container = document.getElementById('syncMessages');
                if (container) container.innerHTML = html;
            }
        }
    });
}

const html5QrCode = new Html5Qrcode("reader");
html5QrCode.start(
    { facingMode: "environment" },
    { fps: 10, qrbox: { width: 250, height: 250 } },
    (decodedText) => {
        html5QrCode.stop();
        try {
            const url = new URL(decodedText);
            const token = url.searchParams.get('token');
            if (token) { window.location.href = '?token=' + encodeURIComponent(token); return; }
        } catch(e) {}
        window.location.href = '?token=' + encodeURIComponent(decodedText);
    },
    () => {}
).catch(() => {
    document.getElementById('reader').innerHTML = '<p class="text-muted">Camera not available. Use the code below.</p>';
});
</script>
<?php include '../includes/footer.php'; ?>
