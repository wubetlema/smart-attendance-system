<?php
require_once '../includes/auth_check.php';
requireRole('admin');
require_once '../config/db.php';

$msg = '';
if (isset($_POST['action']) && $_POST['action'] === 'clear') {
    $days = (int)($_POST['days'] ?? 30);
    $conn->query("DELETE FROM activity_logs WHERE created_at < DATE_SUB(NOW(), INTERVAL $days DAY)");
    $msg = "Logs older than $days days deleted.";
}

$logs = $conn->query("
    SELECT l.*, u.name AS user_name, u.role
    FROM activity_logs l
    LEFT JOIN users u ON l.user_id=u.id
    ORDER BY l.created_at DESC
    LIMIT 200
")->fetch_all(MYSQLI_ASSOC);

include '../includes/header.php';
?>
<h1>Activity Logs</h1>
<?php if ($msg): ?><div class="alert alert-success"><?= htmlspecialchars($msg) ?></div><?php endif; ?>

<div class="card">
    <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:1rem">
        <h3>System Logs (last 200)</h3>
        <form method="POST" class="form-inline" onsubmit="return confirm('Delete old logs?')">
            <input type="hidden" name="action" value="clear">
            <select name="days">
                <option value="7">Older than 7 days</option>
                <option value="30" selected>Older than 30 days</option>
                <option value="90">Older than 90 days</option>
            </select>
            <button type="submit" class="btn btn-danger">Clear Logs</button>
        </form>
    </div>
    <?php if (empty($logs)): ?>
        <p class="text-muted" style="margin-top:1rem">No logs yet.</p>
    <?php else: ?>
    <div style="overflow-x:auto;margin-top:1rem">
    <table class="table">
        <thead><tr><th>Time</th><th>User</th><th>Role</th><th>Action</th><th>Details</th><th>IP</th></tr></thead>
        <tbody>
        <?php foreach ($logs as $l): ?>
        <tr>
            <td style="white-space:nowrap"><?= date('d M Y H:i', strtotime($l['created_at'])) ?></td>
            <td><?= htmlspecialchars($l['user_name'] ?? 'System') ?></td>
            <td><?php if($l['role']): ?><span class="badge badge-<?= $l['role'] ?>"><?= $l['role'] ?></span><?php endif; ?></td>
            <td><?= htmlspecialchars($l['action']) ?></td>
            <td class="text-muted"><?= htmlspecialchars($l['details'] ?? '') ?></td>
            <td><code><?= htmlspecialchars($l['ip_address'] ?? '') ?></code></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
    <?php endif; ?>
</div>
<?php include '../includes/footer.php'; ?>
