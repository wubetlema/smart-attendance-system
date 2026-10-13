<?php
require_once '../config/db.php';
require_once '../includes/auth_check.php';
requireLogin();
require_once '../includes/session_manager.php';

$user = currentUser();
$msg  = ''; $msgType = 'success';

// Validate session on every load
validateSession();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {

    if ($_POST['action'] === 'force_logout_one') {
        $sid = (int)$_POST['session_id'];
        // Security: only allow user to kill their own sessions (or admin kills anyone's)
        $check = $conn->query("SELECT user_id FROM user_sessions WHERE id=$sid")->fetch_assoc();
        if ($check && ($check['user_id'] == $user['id'] || $_SESSION['role'] === 'admin')) {
            forceLogoutSession($sid, $user['id']);
            $msg = 'Session terminated.';
        }

    } elseif ($_POST['action'] === 'force_logout_all') {
        $token = $_SESSION['session_token'] ?? '';
        $count = forceLogoutAllOther($user['id'], $token);
        $msg = "Logged out from $count other device(s).";

    } elseif ($_POST['action'] === 'continue') {
        // User chose to keep all sessions — just redirect
        $redirects = ['admin'=>'/attendance-system/admin/dashboard.php','teacher'=>'/attendance-system/teacher/dashboard.php','student'=>'/attendance-system/student/dashboard.php'];
        header('Location: ' . ($redirects[$_SESSION['role']] ?? '/attendance-system/'));
        exit;
    }
}

$sessions = getActiveSessions($user['id']);
$history  = getDeviceHistory($user['id'], 20);
$isMultiWarning = isset($_GET['multi']);

include '../includes/header.php';
?>
<h1>Active Sessions</h1>
<?php if ($msg): ?><div class="alert alert-<?= $msgType ?>"><?= htmlspecialchars($msg) ?></div><?php endif; ?>

<?php if ($isMultiWarning): ?>
<div class="card" style="border-left:4px solid #d97706;background:#fffbeb">
    <div style="display:flex;gap:1rem;align-items:flex-start;flex-wrap:wrap">
        <div style="font-size:2.5rem">⚠️</div>
        <div style="flex:1">
            <h3 style="margin:0 0 .4rem">Multiple Active Sessions Detected</h3>
            <p style="margin:0;color:var(--text-muted)">Your account is currently logged in on <strong><?= count($sessions) ?> device(s)</strong>. If this wasn't you, log out all other devices immediately.</p>
        </div>
    </div>
    <div style="display:flex;gap:.75rem;margin-top:1rem;flex-wrap:wrap">
        <form method="POST">
            <input type="hidden" name="action" value="force_logout_all">
            <button class="btn btn-primary" style="background:#d97706">Log Out All Other Devices</button>
        </form>
        <form method="POST">
            <input type="hidden" name="action" value="continue">
            <button class="btn btn-secondary">Continue with All Sessions</button>
        </form>
    </div>
</div>
<?php endif; ?>

<!-- Active Sessions -->
<div class="card">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:1rem;flex-wrap:wrap;gap:.5rem">
        <h3>Active Sessions (<?= count($sessions) ?>)</h3>
        <?php if (count($sessions) > 1): ?>
        <form method="POST">
            <input type="hidden" name="action" value="force_logout_all">
            <button class="btn btn-sm" style="background:#dc2626;color:#fff">Log Out All Other Devices</button>
        </form>
        <?php endif; ?>
    </div>

    <div style="display:grid;gap:.75rem">
    <?php foreach ($sessions as $s):
        $isCurrent = (bool)$s['is_current'];
        $lastActive = strtotime($s['last_activity']);
        $minutesAgo = round((time() - $lastActive) / 60);
        $timeLabel = $minutesAgo < 1 ? 'Just now' : ($minutesAgo < 60 ? "{$minutesAgo}m ago" : round($minutesAgo/60).'h ago');
    ?>
    <div style="border:1px solid <?= $isCurrent?'#6366f1':'var(--border)' ?>;border-radius:10px;padding:1rem;background:<?= $isCurrent?'var(--hover-bg)':'var(--card-bg)' ?>">
        <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:1rem;flex-wrap:wrap">
            <div style="display:flex;gap:.75rem;align-items:flex-start">
                <div style="font-size:2rem;line-height:1"><?= deviceIcon($s['device_type']) ?></div>
                <div>
                    <div style="font-weight:600;display:flex;align-items:center;gap:.5rem;flex-wrap:wrap">
                        <?= htmlspecialchars($s['browser']) ?> on <?= htmlspecialchars($s['os']) ?>
                        <?php if ($isCurrent): ?>
                        <span class="badge" style="background:#ede9fe;color:#6d28d9">This Device</span>
                        <?php endif; ?>
                    </div>
                    <div class="text-muted" style="font-size:.85rem;margin-top:.25rem">
                        <?= ucfirst($s['device_type']) ?>
                        &nbsp;|&nbsp; IP: <?= htmlspecialchars($s['ip_address']) ?>
                        &nbsp;|&nbsp; Last active: <?= $timeLabel ?>
                    </div>
                    <div class="text-muted" style="font-size:.8rem;margin-top:.2rem">
                        Logged in: <?= date('d M Y H:i', strtotime($s['created_at'])) ?>
                    </div>
                </div>
            </div>
            <?php if (!$isCurrent): ?>
            <form method="POST">
                <input type="hidden" name="action" value="force_logout_one">
                <input type="hidden" name="session_id" value="<?= $s['id'] ?>">
                <button class="btn btn-sm" style="background:#dc2626;color:#fff" onclick="return confirm('Log out this device?')">
                    Log Out
                </button>
            </form>
            <?php endif; ?>
        </div>
    </div>
    <?php endforeach; ?>
    <?php if (empty($sessions)): ?>
    <p class="text-muted">No active sessions found.</p>
    <?php endif; ?>
    </div>
</div>

<!-- Device History -->
<div class="card">
    <h3>Device History (Last 20 Events)</h3>
    <div style="overflow-x:auto">
    <table class="table">
        <thead><tr><th>Device</th><th>Browser / OS</th><th>IP Address</th><th>Event</th><th>Time</th></tr></thead>
        <tbody>
        <?php foreach ($history as $h):
            $eventColors = [
                'login'           => ['#dcfce7','#15803d'],
                'logout'          => ['#f1f5f9','#64748b'],
                'force_logout'    => ['#fee2e2','#b91c1c'],
                'expired'         => ['#fef3c7','#b45309'],
                'password_change' => ['#dbeafe','#1e40af'],
            ];
            $ec = $eventColors[$h['event_type']] ?? $eventColors['logout'];
        ?>
        <tr>
            <td><?= deviceIcon($h['device_type']) ?> <?= ucfirst($h['device_type']) ?></td>
            <td><?= htmlspecialchars($h['browser']) ?> / <?= htmlspecialchars($h['os']) ?></td>
            <td><code><?= htmlspecialchars($h['ip_address']) ?></code></td>
            <td><span class="badge" style="background:<?= $ec[0] ?>;color:<?= $ec[1] ?>"><?= ucfirst(str_replace('_',' ',$h['event_type'])) ?></span></td>
            <td><small class="text-muted"><?= date('d M Y H:i', strtotime($h['created_at'])) ?></small></td>
        </tr>
        <?php endforeach; ?>
        <?php if (empty($history)): ?>
        <tr><td colspan="5" class="text-muted" style="text-align:center;padding:2rem">No history yet.</td></tr>
        <?php endif; ?>
        </tbody>
    </table>
    </div>
</div>
<?php include '../includes/footer.php'; ?>
