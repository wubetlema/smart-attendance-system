<?php
/**
 * Student QR Check-in — PRIMARY attendance method
 *
 * Validates against dynamic_qr_tokens (30-second rotating tokens).
 * Also accepts legacy static tokens for backward compatibility.
 *
 * Anti-cheat: token is only valid for 30s, sharing a screenshot is useless.
 * A 1-window grace (up to 60s old) is accepted to handle slow connections.
 */
require_once '../includes/auth_check.php';
requireRole('student');
require_once '../config/db.php';
require_once '../includes/functions.php';
$user = currentUser();

$msg = ''; $msgType = 'info';

$token = trim($_POST['token'] ?? $_GET['token'] ?? '');

if ($token) {
    // ── 1. Look up dynamic token (current window + 1 grace window = up to 60s) ──
    $dynStmt = $conn->prepare("
        SELECT t.session_id, t.valid_until,
               s.id AS sid, s.status, s.present_until, s.late_until, s.expires_at,
               c.name AS class_name, c.subject, c.id AS class_id
        FROM dynamic_qr_tokens t
        JOIN attendance_sessions s ON t.session_id = s.id
        JOIN classes c ON s.class_id = c.id
        WHERE t.token = ?
          AND t.valid_until > DATE_SUB(NOW(), INTERVAL 60 SECOND)
          AND s.status IN ('open','late','not_started')
    ");
    $dynStmt->bind_param('s', $token);
    $dynStmt->execute();
    $dynSession = $dynStmt->get_result()->fetch_assoc();

    // ── 2. Fall back to legacy static token ──────────────────────────────────
    $session = null;
    if ($dynSession) {
        $session = $dynSession;
        $session['id'] = $dynSession['sid'];
    } else {
        $legStmt = $conn->prepare("
            SELECT s.id, s.status, s.present_until, s.late_until, s.expires_at,
                   c.name AS class_name, c.subject, c.id AS class_id
            FROM attendance_sessions s
            JOIN classes c ON s.class_id = c.id
            WHERE s.qr_token = ?
              AND s.expires_at > NOW()
              AND s.status IN ('open','late','not_started')
        ");
        $legStmt->bind_param('s', $token);
        $legStmt->execute();
        $session = $legStmt->get_result()->fetch_assoc();
    }

    if (!$session) {
        // Token expired or invalid — give helpful message
        $msg     = 'This QR code has expired. Ask your teacher to show the current QR code on screen.';
        $msgType = 'error';
    } else {
        // ── 3. Enrollment check ───────────────────────────────────────────────
        $enrolled = $conn->prepare("
            SELECT cs.id FROM class_students cs WHERE cs.class_id=? AND cs.student_id=?
        ");
        $enrolled->bind_param('ii', $session['class_id'], $user['id']);
        $enrolled->execute();
        if (!$enrolled->get_result()->fetch_assoc()) {
            $msg     = 'You are not enrolled in this class.';
            $msgType = 'error';
        } else {
            // ── 4. Determine present vs late based on time window ─────────────
            $now          = time();
            $lateUntil    = strtotime($session['late_until']    ?: $session['expires_at']);
            $presentUntil = strtotime($session['present_until'] ?: $session['late_until'] ?: $session['expires_at']);
            $attendanceStatus = ($now <= $presentUntil) ? 'present' : 'late';

            // ── 5. Mark attendance ────────────────────────────────────────────
            $result  = markAttendanceWithPriority($session['id'], $user['id'], $attendanceStatus, 'qr');
            $msg     = $result['message'];
            $msgType = $result['success'] ? 'success' : ($result['conflict'] ?? false ? 'error' : 'error');

            if ($result['success']) {
                $statusLabel = $attendanceStatus === 'late' ? ' (Late)' : ' (Present)';
                $msg = "✅ Attendance recorded for {$session['class_name']}{$statusLabel}.";

                // In-app notification
                $notifMsg = "Attendance marked for {$session['class_name']} ({$session['subject']}) — " . date('d M Y, H:i') . $statusLabel;
                $ns = $conn->prepare("INSERT INTO notifications (user_id, message) VALUES (?,?)");
                $ns->bind_param('is', $user['id'], $notifMsg);
                $ns->execute();

                // Low attendance warning
                $check = $conn->prepare("
                    SELECT COUNT(*) AS total,
                           SUM(a.status IN ('present','late')) AS present_count
                    FROM attendance a
                    JOIN attendance_sessions s ON a.session_id = s.id
                    WHERE a.student_id=? AND s.class_id=?
                ");
                $check->bind_param('ii', $user['id'], $session['class_id']);
                $check->execute();
                $stat = $check->get_result()->fetch_assoc();
                if ($stat['total'] > 2) {
                    $pct = round(($stat['present_count'] / $stat['total']) * 100);
                    if ($pct < 75) {
                        $warn = "⚠️ Low attendance warning: Your attendance in {$session['class_name']} is {$pct}%. Minimum required is 75%.";
                        $ws = $conn->prepare("INSERT INTO notifications (user_id, message) VALUES (?,?)");
                        $ws->bind_param('is', $user['id'], $warn);
                        $ws->execute();
                    }
                }
            }
        }
    }
}

include '../includes/header.php';
?>

<h1>📷 QR Check-in</h1>
<p class="text-muted">This is the primary attendance method. Scan the QR code shown by your teacher.</p>

<div id="syncMessages"></div>

<?php if ($msg): ?>
<div class="alert alert-<?= $msgType ?>">
    <?= htmlspecialchars($msg) ?>
</div>
<?php endif; ?>

<!-- Expiry notice when token is invalid -->
<?php if ($token && $msgType === 'error' && str_contains($msg, 'expired')): ?>
<div class="card" style="border-left:4px solid #d97706;background:#fffbeb">
    <h3>⏱️ QR Code Expired</h3>
    <p>The QR code rotates every <strong>30 seconds</strong> to prevent sharing.</p>
    <p>Ask your teacher to show the QR code on screen and scan the <strong>current</strong> one.</p>
    <p class="text-muted" style="font-size:.85rem">
        Alternatively, your teacher can share the session code for manual entry below.
    </p>
</div>
<?php endif; ?>

<!-- Camera scan panel -->
<div class="card">
    <h3>Scan QR Code</h3>
    <p class="text-muted">Point your camera at the rotating QR code shown by your teacher.</p>

    <div style="position:relative;max-width:420px;margin:0 auto">
        <div id="reader" style="width:100%;border-radius:10px;overflow:hidden"></div>
        <div style="position:absolute;inset:0;pointer-events:none;display:flex;align-items:center;justify-content:center">
            <div style="width:200px;height:200px;border:3px solid #6366f1;border-radius:12px;box-shadow:0 0 0 9999px rgba(0,0,0,.35)">
                <div style="height:3px;background:linear-gradient(90deg,transparent,#6366f1,transparent);animation:scan 2s linear infinite;border-radius:2px"></div>
            </div>
        </div>
    </div>
    <style>@keyframes scan{0%{margin-top:0}50%{margin-top:194px}100%{margin-top:0}}</style>

    <div id="scanStatus" class="alert alert-info" style="margin-top:1rem;display:none"></div>
</div>

<!-- Manual token entry -->
<div class="card">
    <h3>Or Enter Code Manually</h3>
    <p class="text-muted" style="font-size:.88rem">Your teacher can share the short session code if the QR camera doesn't work.</p>
    <form method="POST" style="display:flex;gap:.75rem;flex-wrap:wrap;align-items:flex-end">
        <div class="form-group" style="margin:0;flex:1;min-width:200px">
            <label>Session Code</label>
            <input type="text" name="token" placeholder="Paste or type session code"
                   value="<?= htmlspecialchars($token ?? '') ?>" style="width:100%">
        </div>
        <button type="submit" class="btn btn-primary">Check In</button>
    </form>
</div>

<!-- My attendance summary (current class) -->
<?php
$myAttendance = $conn->prepare("
    SELECT c.name AS class_name, c.subject,
           COUNT(*) AS total,
           SUM(a.status IN ('present','late')) AS attended,
           SUM(a.status='late') AS late_count,
           MAX(a.marked_at) AS last_seen
    FROM attendance a
    JOIN attendance_sessions s ON a.session_id=s.id
    JOIN classes c ON s.class_id=c.id
    JOIN class_students cs ON cs.class_id=c.id AND cs.student_id=a.student_id
    WHERE a.student_id=?
    GROUP BY c.id
    ORDER BY last_seen DESC
    LIMIT 5
");
$myAttendance->bind_param('i', $user['id']);
$myAttendance->execute();
$attendanceRows = $myAttendance->get_result()->fetch_all(MYSQLI_ASSOC);
?>
<?php if (!empty($attendanceRows)): ?>
<div class="card">
    <h3>My Attendance Summary</h3>
    <div style="display:grid;gap:.75rem">
    <?php foreach ($attendanceRows as $ar):
        $pct = $ar['total'] > 0 ? round(($ar['attended'] / $ar['total']) * 100) : 0;
        $barColor = $pct >= 75 ? '#16a34a' : ($pct >= 60 ? '#d97706' : '#dc2626');
    ?>
    <div style="padding:.75rem 1rem;border-radius:8px;background:#f8fafc;border:1px solid #e2e8f0">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:.4rem">
            <div>
                <strong><?= htmlspecialchars($ar['class_name']) ?></strong>
                <span class="text-muted"> — <?= htmlspecialchars($ar['subject']) ?></span>
            </div>
            <span style="font-weight:700;color:<?= $barColor ?>;font-size:1.1rem"><?= $pct ?>%</span>
        </div>
        <div style="height:6px;background:#e2e8f0;border-radius:3px;overflow:hidden">
            <div style="height:100%;width:<?= $pct ?>%;background:<?= $barColor ?>;border-radius:3px;transition:width .5s"></div>
        </div>
        <small class="text-muted">
            <?= $ar['attended'] ?>/<?= $ar['total'] ?> classes attended
            <?= $ar['late_count'] > 0 ? " · {$ar['late_count']} late" : '' ?>
            <?php if ($pct < 75): ?> · <span style="color:#dc2626">⚠️ Below minimum</span><?php endif; ?>
        </small>
    </div>
    <?php endforeach; ?>
    </div>
    <a href="/attendance-system/student/my_attendance.php" class="btn btn-secondary btn-sm" style="margin-top:.75rem">View Full Record →</a>
</div>
<?php endif; ?>

<script src="https://unpkg.com/html5-qrcode@2.3.8/html5-qrcode.min.js"></script>
<script>
// Service worker sync messages
if ('serviceWorker' in navigator) {
    navigator.serviceWorker.addEventListener('message', function(event) {
        if (event.data && event.data.type === 'SYNC_COMPLETE') {
            const data = event.data;
            let html = '';
            if (data.synced > 0) html += `<div class="alert alert-success">✅ ${data.synced} offline record(s) synced.</div>`;
            if (data.conflicts > 0) {
                data.results.filter(r => r.conflict).forEach(r => {
                    html += `<div class="alert alert-error">⚠️ Conflict: ${r.message}</div>`;
                });
            }
            if (data.failed > 0) html += `<div class="alert alert-error">❌ ${data.failed} record(s) failed to sync.</div>`;
            if (html) document.getElementById('syncMessages').innerHTML = html;
        }
    });
}

// QR scanner
const scanner = new Html5Qrcode('reader');
const status  = document.getElementById('scanStatus');
let scanning  = false;

scanner.start(
    { facingMode: 'environment' },
    { fps: 10, qrbox: { width: 200, height: 200 } },
    (decodedText) => {
        if (scanning) return;
        scanning = true;
        scanner.stop().catch(() => {});

        status.style.display = 'block';
        status.textContent   = '⏳ Processing...';
        status.className     = 'alert alert-info';

        // Extract token from URL or use raw value
        let token = decodedText;
        try {
            const url = new URL(decodedText);
            token = url.searchParams.get('token') || decodedText;
        } catch(e) {}

        // Submit as POST to avoid token in URL history
        const form = document.createElement('form');
        form.method = 'POST';
        form.action = '';
        const inp = document.createElement('input');
        inp.type = 'hidden'; inp.name = 'token'; inp.value = token;
        form.appendChild(inp);
        document.body.appendChild(form);
        form.submit();
    },
    () => {} // ignore frame errors
).catch((err) => {
    const errMsg = (err && err.message) ? err.message.toLowerCase() : '';
    let helpMsg  = '';

    if (errMsg.includes('permission') || errMsg.includes('denied') || errMsg.includes('notallowed')) {
        helpMsg = `
            <div style="text-align:center;padding:1.5rem">
                <div style="font-size:2.5rem;margin-bottom:.5rem">📵</div>
                <strong>Camera permission denied</strong>
                <p style="color:#64748b;font-size:.88rem;margin:.5rem 0">
                    To use QR scan, allow camera access:<br>
                    Click the 🔒 lock icon in your browser address bar → Camera → Allow
                </p>
                <p style="color:#64748b;font-size:.85rem">
                    Or use the <strong>manual code entry</strong> below — ask your teacher for the session code.
                </p>
            </div>`;
    } else if (errMsg.includes('notfound') || errMsg.includes('no camera')) {
        helpMsg = `
            <div style="text-align:center;padding:1.5rem">
                <div style="font-size:2.5rem;margin-bottom:.5rem">📷</div>
                <strong>No camera found</strong>
                <p style="color:#64748b;font-size:.88rem;margin:.5rem 0">
                    Your device doesn't have an accessible camera.
                    Use the <strong>manual code entry</strong> below.
                </p>
            </div>`;
    } else {
        helpMsg = `
            <div style="text-align:center;padding:1.5rem">
                <div style="font-size:2.5rem;margin-bottom:.5rem">⚠️</div>
                <strong>Camera unavailable</strong>
                <p style="color:#64748b;font-size:.88rem;margin:.5rem 0">
                    Could not start the camera. Use the <strong>manual code entry</strong> below.
                </p>
            </div>`;
    }
    document.getElementById('reader').innerHTML = helpMsg;
});
</script>

<?php include '../includes/footer.php'; ?>
