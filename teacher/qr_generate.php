<?php
/**
 * Teacher QR Generation Page
 * - QR is the PRIMARY attendance method
 * - Token rotates every 30 seconds (dynamic QR) to prevent screenshot sharing
 * - Session has configurable open / present-until / close times
 * - Teacher also configures late threshold and attendance method
 */
require_once '../includes/auth_check.php';
requireRole('teacher');
require_once '../config/db.php';
require_once '../includes/functions.php';
$user = currentUser();

$msg = ''; $msgType = 'success';
$class_id = (int)($_GET['class_id'] ?? 0);
$session  = null;

// ── Create New Session ────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'create') {
    verifyCsrf();

    $class_id       = (int)$_POST['class_id'];
    $openAt         = $_POST['open_at']          ?? date('Y-m-d H:i:s');
    $presentMinutes = max(1, (int)($_POST['present_minutes'] ?? 10));
    $lateMinutes    = max(1, (int)($_POST['late_minutes']    ?? 20));
    $method         = in_array($_POST['method'] ?? 'qr', ['qr','face','manual']) ? $_POST['method'] : 'qr';

    // Parse open_at — if just a time like "08:00", make it today
    if (strlen($openAt) <= 5) $openAt = date('Y-m-d ') . $openAt . ':00';
    $openTs       = strtotime($openAt);
    $presentUntil = date('Y-m-d H:i:s', $openTs + $presentMinutes * 60);
    $lateUntil    = date('Y-m-d H:i:s', $openTs + $lateMinutes    * 60);

    // Verify class belongs to this teacher
    $cls = $conn->prepare("SELECT id, name, subject FROM classes WHERE id=? AND teacher_id=?");
    $cls->bind_param('ii', $class_id, $user['id']);
    $cls->execute();
    $classRow = $cls->get_result()->fetch_assoc();

    if (!$classRow) {
        $msg = 'Invalid class.'; $msgType = 'error';
    } else {
        // Create attendance session with state machine fields
        $token = bin2hex(random_bytes(32));
        $stmt  = $conn->prepare("
            INSERT INTO attendance_sessions
                (class_id, teacher_id, qr_token, method, status, open_at, present_until, late_until, expires_at)
            VALUES (?,?,?,?,'not_started',?,?,?,?)
        ");
        $stmt->bind_param('iissssss', $class_id, $user['id'], $token, $method, $openAt, $presentUntil, $lateUntil, $lateUntil);
        $stmt->execute();
        $sessionId = $conn->insert_id;

        // Generate first dynamic QR token (30-second window)
        generateNextQrToken($sessionId);

        // Auto-open if open_at is now or in the past
        if ($openTs <= time()) {
            $conn->query("UPDATE attendance_sessions SET status='open' WHERE id=$sessionId");
        }

        header("Location: ?class_id=$class_id&session_id=$sessionId&created=1");
        exit;
    }
}

// ── Close Session ─────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'close') {
    verifyCsrf();
    $sid = (int)$_POST['session_id'];
    $conn->prepare("UPDATE attendance_sessions SET status='closed' WHERE id=? AND teacher_id=?")
         ->bind_param('ii', $sid, $user['id']);
    // Use execute via stmt
    $upd = $conn->prepare("UPDATE attendance_sessions SET status='closed' WHERE id=? AND teacher_id=?");
    $upd->bind_param('ii', $sid, $user['id']);
    $upd->execute();
    header("Location: ?class_id=$class_id&closed=1");
    exit;
}

// ── Load Active or Recently Created Session ───────────────────────────────────
$activeSessionId = (int)($_GET['session_id'] ?? 0);
if ($activeSessionId) {
    $ss = $conn->prepare("
        SELECT s.*, c.name AS class_name, c.subject
        FROM attendance_sessions s JOIN classes c ON s.class_id=c.id
        WHERE s.id=? AND s.teacher_id=?
    ");
    $ss->bind_param('ii', $activeSessionId, $user['id']);
    $ss->execute();
    $session = $ss->get_result()->fetch_assoc();
}

// ── Teacher's Classes ──────────────────────────────────────────────────────────
$stmt = $conn->prepare("SELECT c.*, COUNT(cs.id) AS enrolled FROM classes c LEFT JOIN class_students cs ON cs.class_id=c.id WHERE c.teacher_id=? GROUP BY c.id ORDER BY c.name");
$stmt->bind_param('i', $user['id']);
$stmt->execute();
$classes = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// ── Recent Sessions for this teacher ─────────────────────────────────────────
$recentSessions = $conn->query("
    SELECT s.*, c.name AS class_name, c.subject,
           COUNT(a.id) AS attendance_count
    FROM attendance_sessions s
    JOIN classes c ON s.class_id=c.id
    LEFT JOIN attendance a ON a.session_id=s.id
    WHERE s.teacher_id={$user['id']}
    GROUP BY s.id
    ORDER BY s.created_at DESC
    LIMIT 10
")->fetch_all(MYSQLI_ASSOC);

include '../includes/header.php';
?>
<h1>📷 QR Attendance — Primary Method</h1>

<?php if (isset($_GET['created'])): ?>
<div class="alert alert-success">✅ Session created! QR code is now rotating every 30 seconds.</div>
<?php endif; ?>
<?php if (isset($_GET['closed'])): ?>
<div class="alert alert-info">Session closed. Students can no longer check in.</div>
<?php endif; ?>

<!-- Explainer banner -->
<div class="card" style="background:linear-gradient(135deg,#ede9fe,#dbeafe);border:none;margin-bottom:1.5rem">
    <div style="display:flex;align-items:flex-start;gap:1rem;flex-wrap:wrap">
        <div style="font-size:2.5rem">📋</div>
        <div>
            <h3 style="margin:0 0 .4rem">How QR Attendance Works</h3>
            <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:.5rem;font-size:.88rem;color:#334155">
                <div>1️⃣ Create a session with time windows</div>
                <div>2️⃣ Show the QR on your screen or projector</div>
                <div>3️⃣ QR refreshes <strong>every 30 seconds</strong></div>
                <div>4️⃣ Students scan using their phone camera</div>
                <div>5️⃣ Late arrivals are marked automatically</div>
                <div>6️⃣ Session closes at your set time</div>
            </div>
        </div>
    </div>
</div>

<div style="display:grid;grid-template-columns:1fr 1fr;gap:1.5rem;align-items:start">

<!-- Left: Create Session Form -->
<div class="card">
    <h3>Create New Attendance Session</h3>
    <form method="POST" id="createForm">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="create">

        <div class="form-group">
            <label>Class</label>
            <select name="class_id" required onchange="this.form.class_id_display.value=this.options[this.selectedIndex].text">
                <option value="">Select class...</option>
                <?php foreach ($classes as $c): ?>
                <option value="<?= $c['id'] ?>" <?= $class_id==$c['id']?'selected':'' ?>>
                    <?= htmlspecialchars($c['name']) ?> — <?= htmlspecialchars($c['subject']) ?>
                    (<?= $c['enrolled'] ?> students)
                </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="form-group">
            <label>Attendance Opens At</label>
            <input type="datetime-local" name="open_at"
                   value="<?= date('Y-m-d\TH:i') ?>" required>
            <small class="text-muted">Students can start scanning from this time.</small>
        </div>

        <div style="display:grid;grid-template-columns:1fr 1fr;gap:.75rem">
            <div class="form-group">
                <label>Present Window (min)</label>
                <input type="number" name="present_minutes" value="10" min="1" max="120" required>
                <small class="text-muted">Marked <strong>Present</strong> if scanned within this time.</small>
            </div>
            <div class="form-group">
                <label>Close After (min)</label>
                <input type="number" name="late_minutes" value="20" min="2" max="180" required>
                <small class="text-muted">Marked <strong>Late</strong> after present window; session closes here.</small>
            </div>
        </div>

        <div class="form-group">
            <label>Check-in Method</label>
            <select name="method">
                <option value="qr" selected>QR Code (recommended)</option>
                <option value="face">Face Recognition (advanced)</option>
                <option value="manual">Manual (teacher marks)</option>
            </select>
        </div>

        <!-- Visual time preview -->
        <div id="timelinePreview" style="background:#f8fafc;border-radius:8px;padding:.75rem;font-size:.85rem;margin-bottom:1rem">
            <strong>Session timeline:</strong>
            <div id="timelineText" style="color:#475569;margin-top:.3rem"></div>
        </div>

        <button type="submit" class="btn btn-primary btn-full">🚀 Create Session & Generate QR</button>
    </form>
</div>

<!-- Right: Live QR Display (shown when session active) -->
<div>
<?php if ($session && in_array($session['status'], ['not_started','open','late'])): ?>
<div class="card" style="text-align:center" id="qrCard">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:.75rem">
        <h3 style="margin:0"><?= htmlspecialchars($session['class_name']) ?></h3>
        <span id="sessionStatusBadge" class="badge">...</span>
    </div>
    <p class="text-muted" style="margin:.25rem 0"><?= htmlspecialchars($session['subject']) ?></p>

    <!-- Countdown ring -->
    <div style="position:relative;display:inline-flex;align-items:center;justify-content:center;margin:.75rem 0">
        <svg width="60" height="60" style="transform:rotate(-90deg)">
            <circle cx="30" cy="30" r="26" fill="none" stroke="#e2e8f0" stroke-width="5"/>
            <circle id="countdownRing" cx="30" cy="30" r="26" fill="none" stroke="#6366f1"
                    stroke-width="5" stroke-dasharray="163" stroke-dashoffset="0"
                    style="transition:stroke-dashoffset .9s linear"/>
        </svg>
        <span id="countdownNum" style="position:absolute;font-weight:700;font-size:1.1rem;color:#6366f1">30</span>
    </div>
    <p class="text-muted" style="font-size:.8rem;margin:0 0 .75rem">QR refreshes every 30 seconds</p>

    <!-- QR code container -->
    <div id="qrContainer" style="display:flex;justify-content:center;margin:0 auto 1rem;min-height:260px;align-items:center">
        <div style="color:#94a3b8">Loading QR...</div>
    </div>

    <div id="qrTokenCode" style="font-family:monospace;font-size:.75rem;color:#94a3b8;word-break:break-all"></div>

    <!-- Session time info -->
    <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:.5rem;margin-top:1rem;font-size:.8rem">
        <div style="background:#dcfce7;border-radius:6px;padding:.4rem">
            <div style="font-weight:700;color:#15803d">Present</div>
            <div style="color:#166534">until <?= date('H:i', strtotime($session['present_until'])) ?></div>
        </div>
        <div style="background:#fef3c7;border-radius:6px;padding:.4rem">
            <div style="font-weight:700;color:#b45309">Late</div>
            <div style="color:#92400e">until <?= date('H:i', strtotime($session['late_until'])) ?></div>
        </div>
        <div style="background:#fee2e2;border-radius:6px;padding:.4rem">
            <div style="font-weight:700;color:#b91c1c">Closes</div>
            <div style="color:#991b1b"><?= date('H:i', strtotime($session['late_until'])) ?></div>
        </div>
    </div>

    <!-- Attendance counter (live) -->
    <div style="margin-top:1rem;padding:.75rem;background:#f8fafc;border-radius:8px">
        <span id="attendanceCount" style="font-size:1.4rem;font-weight:700;color:#6366f1">–</span>
        <span style="color:#475569;font-size:.9rem"> students checked in</span>
    </div>

    <!-- Close session button -->
    <form method="POST" style="margin-top:1rem" onsubmit="return confirm('Close this session? Students will no longer be able to check in.')">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="close">
        <input type="hidden" name="session_id" value="<?= $session['id'] ?>">
        <input type="hidden" name="class_id" value="<?= $class_id ?>">
        <button class="btn btn-secondary btn-sm" style="background:#fee2e2;color:#b91c1c;border:none">
            🔒 Close Session Manually
        </button>
    </form>
</div>

<script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"></script>
<script>
const SESSION_ID  = <?= $session['id'] ?>;
const HOST        = window.location.hostname;
let qrInstance    = null;
let countdown     = 30;
let countdownTimer = null;

async function fetchAndRenderQR() {
    try {
        const res  = await fetch(`/attendance-system/api/qr_refresh.php?session_id=${SESSION_ID}`);
        const data = await res.json();

        if (!data.success) {
            // Session closed or expired
            document.getElementById('qrCard').innerHTML = `
                <div class="alert alert-error" style="text-align:center">
                    <h3>Session Closed</h3><p>${data.message}</p>
                </div>`;
            clearInterval(countdownTimer);
            return;
        }

        const token = data.token;
        const url   = `http://${HOST}/attendance-system/student/qr_checkin.php?token=${encodeURIComponent(token)}`;

        // Re-render QR
        const container = document.getElementById('qrContainer');
        container.innerHTML = '';
        qrInstance = new QRCode(container, {
            text:       url,
            width:      260,
            height:     260,
            colorDark:  '#1a1a2e',
            colorLight: '#ffffff',
            correctLevel: QRCode.CorrectLevel.H
        });

        document.getElementById('qrTokenCode').textContent = 'Token: ' + token.substring(0, 16) + '...';

        // Update attendance count
        if (data.attendance_count !== undefined) {
            document.getElementById('attendanceCount').textContent = data.attendance_count;
        }

        // Update session status badge
        const badge    = document.getElementById('sessionStatusBadge');
        const statuses = {
            open:        ['Open',        '#dcfce7', '#15803d'],
            late:        ['Late Period',  '#fef3c7', '#b45309'],
            not_started: ['Not Started', '#f1f5f9', '#475569'],
            closed:      ['Closed',      '#fee2e2', '#b91c1c'],
        };
        const [label, bg, color] = statuses[data.status] || ['Unknown', '#f1f5f9', '#475569'];
        badge.textContent       = label;
        badge.style.background  = bg;
        badge.style.color       = color;

        // Reset countdown
        countdown = 30;
    } catch (e) {
        console.error('QR refresh failed:', e);
    }
}

function tickCountdown() {
    countdown--;
    if (countdown <= 0) {
        countdown = 30;
        fetchAndRenderQR();
    }

    const num  = document.getElementById('countdownNum');
    const ring = document.getElementById('countdownRing');
    if (num)  num.textContent = countdown;
    // 163 = 2π*26 (circumference). Fill proportionally
    if (ring) ring.style.strokeDashoffset = 163 * (1 - countdown / 30);
}

// Initial load
fetchAndRenderQR();
countdownTimer = setInterval(tickCountdown, 1000);
</script>

<?php elseif ($session && $session['status'] === 'closed'): ?>
<div class="card" style="text-align:center">
    <div style="font-size:3rem">🔒</div>
    <h3>Session Closed</h3>
    <p class="text-muted">This attendance session is closed.</p>
    <a href="?class_id=<?= $class_id ?>" class="btn btn-primary">Create New Session</a>
</div>
<?php else: ?>
<div class="card" style="text-align:center;background:#f8fafc">
    <div style="font-size:3rem;margin-bottom:.75rem">📋</div>
    <h3>No Active Session</h3>
    <p class="text-muted">Create a session on the left to generate a QR code.</p>
</div>
<?php endif; ?>
</div><!-- end right col -->

</div><!-- end grid -->

<!-- Recent Sessions -->
<?php if (!empty($recentSessions)): ?>
<div class="card">
    <h3>Recent Sessions</h3>
    <div style="overflow-x:auto">
    <table class="table">
        <thead>
            <tr><th>Class</th><th>Status</th><th>Opens</th><th>Present Until</th><th>Closes</th><th>Method</th><th>Check-ins</th></tr>
        </thead>
        <tbody>
        <?php foreach ($recentSessions as $rs):
            $statusColors = [
                'open'        => ['#dcfce7','#15803d','Open'],
                'late'        => ['#fef3c7','#b45309','Late Period'],
                'closed'      => ['#fee2e2','#b91c1c','Closed'],
                'not_started' => ['#f1f5f9','#475569','Not Started'],
                'scheduled'   => ['#dbeafe','#1e40af','Scheduled'],
            ];
            [$bg,$fg,$label] = $statusColors[$rs['status']] ?? ['#f1f5f9','#475569','Unknown'];
        ?>
        <tr>
            <td>
                <strong><?= htmlspecialchars($rs['class_name']) ?></strong>
                <br><small class="text-muted"><?= htmlspecialchars($rs['subject']) ?></small>
            </td>
            <td><span class="badge" style="background:<?= $bg ?>;color:<?= $fg ?>"><?= $label ?></span></td>
            <td><small><?= $rs['open_at'] ? date('d M H:i', strtotime($rs['open_at'])) : '–' ?></small></td>
            <td><small><?= $rs['present_until'] ? date('H:i', strtotime($rs['present_until'])) : '–' ?></small></td>
            <td><small><?= date('H:i', strtotime($rs['late_until'] ?: $rs['expires_at'])) ?></small></td>
            <td><span class="badge"><?= htmlspecialchars($rs['method']) ?></span></td>
            <td>
                <strong><?= $rs['attendance_count'] ?></strong>
                <?php if ($rs['status'] !== 'closed'): ?>
                <a href="?class_id=<?= $rs['class_id'] ?>&session_id=<?= $rs['id'] ?>" class="btn btn-sm btn-secondary" style="margin-left:.4rem">View</a>
                <?php endif; ?>
            </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
</div>
<?php endif; ?>

<script>
// ── Timeline preview ──────────────────────────────────────────────────────────
function updatePreview() {
    const openAt  = document.querySelector('input[name=open_at]')?.value;
    const pMins   = parseInt(document.querySelector('input[name=present_minutes]')?.value || 10);
    const lMins   = parseInt(document.querySelector('input[name=late_minutes]')?.value    || 20);
    const preview = document.getElementById('timelineText');
    if (!openAt || !preview) return;

    const base = new Date(openAt);
    const fmt  = d => d.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
    const presentEnd = new Date(base.getTime() + pMins * 60000);
    const closeTime  = new Date(base.getTime() + lMins * 60000);

    preview.innerHTML =
        `<span style="color:#15803d">✅ Present</span>: ${fmt(base)} – ${fmt(presentEnd)} &nbsp;|&nbsp; ` +
        `<span style="color:#b45309">⏰ Late</span>: ${fmt(presentEnd)} – ${fmt(closeTime)} &nbsp;|&nbsp; ` +
        `<span style="color:#b91c1c">🔒 Closed</span>: after ${fmt(closeTime)}`;
}
document.querySelectorAll('input[name=open_at],input[name=present_minutes],input[name=late_minutes]')
        .forEach(el => el.addEventListener('input', updatePreview));
updatePreview();
</script>

<?php include '../includes/footer.php'; ?>

<?php
// ── Helper ────────────────────────────────────────────────────────────────────
function generateNextQrToken($sessionId) {
    global $conn;
    $token     = bin2hex(random_bytes(32));
    $validFrom = date('Y-m-d H:i:s');
    $validUntil= date('Y-m-d H:i:s', time() + 30);
    $stmt = $conn->prepare("INSERT INTO dynamic_qr_tokens (session_id, token, valid_from, valid_until) VALUES (?,?,?,?)");
    $stmt->bind_param('isss', $sessionId, $token, $validFrom, $validUntil);
    $stmt->execute();
    return $token;
}
?>
