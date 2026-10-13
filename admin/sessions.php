<?php
require_once '../includes/auth_check.php';
requireRole('admin');
require_once '../config/db.php';
require_once '../includes/session_manager.php';

$msg = ''; $msgType = 'success';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {

    if ($_POST['action'] === 'force_logout_one') {
        $sid = (int)$_POST['session_id'];
        forceLogoutSession($sid, $_SESSION['user_id']);
        $msg = 'Session terminated.';

    } elseif ($_POST['action'] === 'force_logout_user') {
        $uid   = (int)$_POST['user_id'];
        $token = ''; // empty = kill all
        $sessions = $conn->query("SELECT id FROM user_sessions WHERE user_id=$uid AND is_active=1")->fetch_all(MYSQLI_ASSOC);
        foreach ($sessions as $s) forceLogoutSession($s['id'], $_SESSION['user_id']);
        $msg = 'All sessions for user terminated.';

    } elseif ($_POST['action'] === 'force_logout_all_users') {
        $myId = (int)$_SESSION['user_id'];
        $sessions = $conn->query("SELECT id FROM user_sessions WHERE user_id!=$myId AND is_active=1")->fetch_all(MYSQLI_ASSOC);
        foreach ($sessions as $s) forceLogoutSession($s['id'], $myId);
        $msg = count($sessions) . ' session(s) terminated across all users.';
    }
}

// Filters
$filterRole = $_GET['role'] ?? '';
$filterUser = isset($_GET['user_id']) ? (int)$_GET['user_id'] : 0;
$roleWhere  = $filterRole ? "AND u.role='" . $conn->real_escape_string($filterRole) . "'" : '';
$userWhere  = $filterUser ? "AND s.user_id=$filterUser" : '';

// Active sessions across all users
$activeSessions = $conn->query("
    SELECT s.*, u.name AS user_name, u.role, u.university_id
    FROM user_sessions s
    JOIN users u ON s.user_id=u.id
    WHERE s.is_active=1 $roleWhere $userWhere
    ORDER BY s.last_activity DESC
")->fetch_all(MYSQLI_ASSOC);

// Users with multiple sessions
$multiSessions = $conn->query("
    SELECT u.id, u.name, u.role, u.university_id, COUNT(s.id) AS session_count
    FROM user_sessions s
    JOIN users u ON s.user_id=u.id
    WHERE s.is_active=1
    GROUP BY s.user_id
    HAVING session_count > 1
    ORDER BY session_count DESC
")->fetch_all(MYSQLI_ASSOC);

// Recent device history (all users)
$recentHistory = $conn->query("
    SELECT h.*, u.name AS user_name, u.role
    FROM device_history h
    JOIN users u ON h.user_id=u.id
    ORDER BY h.created_at DESC
    LIMIT 50
")->fetch_all(MYSQLI_ASSOC);

include '../includes/header.php';
?>
<h1>Session Control Center</h1>
<?php if ($msg): ?><div class="alert alert-<?= $msgType ?>"><?= htmlspecialchars($msg) ?></div><?php endif; ?>

<!-- Stats -->
<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:1rem;margin-bottom:1.5rem">
    <?php
    $totalActive = count($activeSessions);
    $totalMulti  = count($multiSessions);
    $totalUsers  = count(array_unique(array_column($activeSessions, 'user_id')));
    ?>
    <div class="stat-card">
        <div class="stat-number"><?= $totalActive ?></div>
        <div class="stat-label">Active Sessions</div>
    </div>
    <div class="stat-card">
        <div class="stat-number"><?= $totalUsers ?></div>
        <div class="stat-label">Online Users</div>
    </div>
    <div class="stat-card" style="border-color:<?= $totalMulti>0?'#d97706':'#16a34a' ?>">
        <div class="stat-number" style="color:<?= $totalMulti>0?'#d97706':'#16a34a' ?>"><?= $totalMulti ?></div>
        <div class="stat-label">Multi-Login Users</div>
    </div>
</div>

<!-- Multi-login alerts -->
<?php if (!empty($multiSessions)): ?>
<div class="card" style="border-left:4px solid #d97706">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:.75rem;flex-wrap:wrap;gap:.5rem">
        <h3>⚠️ Users with Multiple Active Sessions</h3>
        <form method="POST" onsubmit="return confirm('Force logout ALL users (except yourself)?')">
            <input type="hidden" name="action" value="force_logout_all_users">
            <button class="btn btn-sm" style="background:#dc2626;color:#fff">Kill All Sessions</button>
        </form>
    </div>
    <div style="display:grid;gap:.5rem">
    <?php foreach ($multiSessions as $m): ?>
    <div style="display:flex;align-items:center;justify-content:space-between;padding:.6rem .75rem;background:#fffbeb;border-radius:8px;flex-wrap:wrap;gap:.5rem">
        <div>
            <strong><?= htmlspecialchars($m['name']) ?></strong>
            <span class="badge badge-<?= $m['role'] ?>" style="margin-left:.4rem"><?= $m['role'] ?></span>
            <small class="text-muted"> — <?= htmlspecialchars($m['university_id'] ?? '') ?></small>
        </div>
        <div style="display:flex;align-items:center;gap:.75rem">
            <span style="background:#fef3c7;color:#b45309;padding:.2rem .6rem;border-radius:12px;font-size:.85rem;font-weight:600">
                <?= $m['session_count'] ?> sessions
            </span>
            <form method="POST" style="display:inline">
                <input type="hidden" name="action" value="force_logout_user">
                <input type="hidden" name="user_id" value="<?= $m['id'] ?>">
                <button class="btn btn-sm" style="background:#dc2626;color:#fff" onclick="return confirm('Log out all sessions for <?= htmlspecialchars($m['name'],ENT_QUOTES) ?>?')">
                    Force Logout All
                </button>
            </form>
        </div>
    </div>
    <?php endforeach; ?>
    </div>
</div>
<?php endif; ?>

<!-- Active Sessions Table -->
<div class="card">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:1rem;flex-wrap:wrap;gap:.5rem">
        <h3>All Active Sessions (<?= $totalActive ?>)</h3>
        <form method="GET" style="display:flex;gap:.5rem;flex-wrap:wrap">
            <select name="role" onchange="this.form.submit()">
                <option value="">All Roles</option>
                <?php foreach (['admin','teacher','student'] as $r): ?>
                <option value="<?= $r ?>" <?= $filterRole===$r?'selected':'' ?>><?= ucfirst($r) ?></option>
                <?php endforeach; ?>
            </select>
            <a href="sessions.php" class="btn btn-secondary btn-sm">Clear</a>
        </form>
    </div>
    <div style="overflow-x:auto">
    <table class="table">
        <thead><tr><th>User</th><th>Device</th><th>Browser / OS</th><th>IP</th><th>Last Active</th><th>Logged In</th><th>Action</th></tr></thead>
        <tbody>
        <?php foreach ($activeSessions as $s):
            $lastActive = strtotime($s['last_activity']);
            $minsAgo = round((time() - $lastActive) / 60);
            $timeLabel = $minsAgo < 1 ? 'Just now' : ($minsAgo < 60 ? "{$minsAgo}m ago" : round($minsAgo/60).'h ago');
            $isMe = $s['user_id'] == $_SESSION['user_id'];
        ?>
        <tr>
            <td>
                <strong><?= htmlspecialchars($s['user_name']) ?></strong>
                <br><span class="badge badge-<?= $s['role'] ?>"><?= $s['role'] ?></span>
                <small class="text-muted"> <?= htmlspecialchars($s['university_id'] ?? '') ?></small>
            </td>
            <td><?= deviceIcon($s['device_type']) ?> <?= ucfirst($s['device_type']) ?></td>
            <td><?= htmlspecialchars($s['browser']) ?> / <?= htmlspecialchars($s['os']) ?></td>
            <td><code><?= htmlspecialchars($s['ip_address']) ?></code></td>
            <td>
                <span style="color:<?= $minsAgo < 5 ? '#16a34a' : ($minsAgo < 30 ? '#d97706' : '#64748b') ?>">
                    <?= $timeLabel ?>
                </span>
            </td>
            <td><small class="text-muted"><?= date('d M Y H:i', strtotime($s['created_at'])) ?></small></td>
            <td>
                <?php if ($isMe): ?>
                <span class="badge" style="background:#ede9fe;color:#6d28d9">You</span>
                <?php else: ?>
                <form method="POST" style="display:inline" onsubmit="return confirm('Terminate this session?')">
                    <input type="hidden" name="action" value="force_logout_one">
                    <input type="hidden" name="session_id" value="<?= $s['id'] ?>">
                    <button class="btn btn-danger btn-sm">Terminate</button>
                </form>
                <?php endif; ?>
            </td>
        </tr>
        <?php endforeach; ?>
        <?php if (empty($activeSessions)): ?>
        <tr><td colspan="7" class="text-muted" style="text-align:center;padding:2rem">No active sessions.</td></tr>
        <?php endif; ?>
        </tbody>
    </table>
    </div>
</div>

<!-- Device History Log -->
<div class="card">
    <h3>Device History Log (Last 50 Events)</h3>
    <div style="overflow-x:auto">
    <table class="table">
        <thead><tr><th>User</th><th>Device</th><th>Browser / OS</th><th>IP</th><th>Event</th><th>Time</th></tr></thead>
        <tbody>
        <?php
        $eventColors = [
            'login'           => ['#dcfce7','#15803d'],
            'logout'          => ['#f1f5f9','#64748b'],
            'force_logout'    => ['#fee2e2','#b91c1c'],
            'expired'         => ['#fef3c7','#b45309'],
            'password_change' => ['#dbeafe','#1e40af'],
        ];
        foreach ($recentHistory as $h):
            $ec = $eventColors[$h['event_type']] ?? $eventColors['logout'];
        ?>
        <tr>
            <td>
                <?= htmlspecialchars($h['user_name']) ?>
                <br><span class="badge badge-<?= $h['role'] ?>"><?= $h['role'] ?></span>
            </td>
            <td><?= deviceIcon($h['device_type']) ?> <?= ucfirst($h['device_type']) ?></td>
            <td><?= htmlspecialchars($h['browser']) ?> / <?= htmlspecialchars($h['os']) ?></td>
            <td><code><?= htmlspecialchars($h['ip_address']) ?></code></td>
            <td><span class="badge" style="background:<?= $ec[0] ?>;color:<?= $ec[1] ?>"><?= ucfirst(str_replace('_',' ',$h['event_type'])) ?></span></td>
            <td><small class="text-muted"><?= date('d M Y H:i', strtotime($h['created_at'])) ?></small></td>
        </tr>
        <?php endforeach; ?>
        <?php if (empty($recentHistory)): ?>
        <tr><td colspan="6" class="text-muted" style="text-align:center;padding:2rem">No history yet.</td></tr>
        <?php endif; ?>
        </tbody>
    </table>
    </div>
</div>
<?php include '../includes/footer.php'; ?>
