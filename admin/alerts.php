<?php
require_once '../includes/auth_check.php';
requireRole('admin');
require_once '../config/db.php';
require_once '../includes/alert_engine.php';

$msg = ''; $msgType = 'success';
$user = currentUser();

// Manual trigger
if (isset($_GET['run_engine'])) {
    runAlertEngine();
    $msg = 'Alert engine executed. New alerts generated if conditions met.';
}

// Actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];

    if ($action === 'resolve') {
        $id = (int)$_POST['id'];
        $conn->query("UPDATE alerts SET status='resolved', resolved_at=NOW(), resolved_by={$user['id']} WHERE id=$id");
        logAlertAction($id, 'resolved', 'Manually resolved by admin', $user['id']);
        $msg = 'Alert resolved.';

    } elseif ($action === 'bulk_resolve') {
        $ids = array_map('intval', $_POST['alert_ids'] ?? []);
        if (!empty($ids)) {
            $in = implode(',', $ids);
            $conn->query("UPDATE alerts SET status='resolved', resolved_at=NOW(), resolved_by={$user['id']} WHERE id IN ($in)");
            foreach ($ids as $id) logAlertAction($id, 'resolved', 'Bulk resolved by admin', $user['id']);
            $msg = count($ids) . ' alerts resolved.';
        }
    }
}

// Filters
$filterStatus   = $_GET['status'] ?? '';
$filterPriority = $_GET['priority'] ?? '';
$filterUser     = isset($_GET['user']) ? (int)$_GET['user'] : 0;

$where = [];
if ($filterStatus) $where[] = "a.status='" . $conn->real_escape_string($filterStatus) . "'";
if ($filterPriority) $where[] = "a.priority='" . $conn->real_escape_string($filterPriority) . "'";
if ($filterUser) $where[] = "a.user_id=$filterUser";
$whereSQL = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$alerts = $conn->query("
    SELECT a.*, u.name AS user_name, u.email, c.name AS class_name, r.name AS rule_name
    FROM alerts a
    JOIN users u ON a.user_id=u.id
    LEFT JOIN classes c ON a.class_id=c.id
    JOIN alert_rules r ON a.rule_id=r.id
    $whereSQL
    ORDER BY a.created_at DESC
    LIMIT 100
")->fetch_all(MYSQLI_ASSOC);

include '../includes/header.php';
?>
<h1>Alert Dashboard</h1>
<?php if ($msg): ?><div class="alert alert-<?= $msgType ?>"><?= htmlspecialchars($msg) ?></div><?php endif; ?>

<!-- Stats -->
<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:1rem;margin-bottom:1.5rem">
    <?php
    $stats = [
        'pending' => $conn->query("SELECT COUNT(*) c FROM alerts WHERE status='pending'")->fetch_assoc()['c'],
        'sent' => $conn->query("SELECT COUNT(*) c FROM alerts WHERE status='sent'")->fetch_assoc()['c'],
        'escalated' => $conn->query("SELECT COUNT(*) c FROM alerts WHERE status='escalated'")->fetch_assoc()['c'],
        'resolved' => $conn->query("SELECT COUNT(*) c FROM alerts WHERE status='resolved' AND DATE(resolved_at)=CURDATE()")->fetch_assoc()['c'],
    ];
    ?>
    <div class="stat-card" style="border-color:#d97706">
        <div class="stat-number" style="color:#d97706"><?= $stats['pending'] ?></div>
        <div class="stat-label">Pending</div>
    </div>
    <div class="stat-card" style="border-color:#6366f1">
        <div class="stat-number" style="color:#6366f1"><?= $stats['sent'] ?></div>
        <div class="stat-label">Sent</div>
    </div>
    <div class="stat-card" style="border-color:#dc2626">
        <div class="stat-number" style="color:#dc2626"><?= $stats['escalated'] ?></div>
        <div class="stat-label">Escalated</div>
    </div>
    <div class="stat-card" style="border-color:#16a34a">
        <div class="stat-number" style="color:#16a34a"><?= $stats['resolved'] ?></div>
        <div class="stat-label">Resolved Today</div>
    </div>
</div>

<!-- Actions -->
<div class="card">
    <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:.75rem">
        <div>
            <a href="?run_engine=1" class="btn btn-primary" onclick="return confirm('Run alert engine now?')">🔄 Run Alert Engine</a>
            <a href="alert_rules.php" class="btn btn-secondary">⚙️ Configure Rules</a>
        </div>
        <form method="GET" style="display:flex;gap:.5rem;flex-wrap:wrap">
            <select name="status" onchange="this.form.submit()">
                <option value="">All Status</option>
                <?php foreach (['pending','sent','escalated','resolved'] as $s): ?>
                <option value="<?= $s ?>" <?= $filterStatus===$s?'selected':'' ?>><?= ucfirst($s) ?></option>
                <?php endforeach; ?>
            </select>
            <select name="priority" onchange="this.form.submit()">
                <option value="">All Priority</option>
                <?php foreach (['low','medium','high','critical'] as $p): ?>
                <option value="<?= $p ?>" <?= $filterPriority===$p?'selected':'' ?>><?= ucfirst($p) ?></option>
                <?php endforeach; ?>
            </select>
            <a href="alerts.php" class="btn btn-secondary btn-sm">Clear</a>
        </form>
    </div>
</div>

<!-- Alerts List -->
<div class="card">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:1rem">
        <h3>Alerts (<?= count($alerts) ?>)</h3>
        <div style="display:flex;gap:.5rem">
            <button class="btn btn-secondary btn-sm" onclick="selectAllAlerts(true)">Select All</button>
            <button class="btn btn-secondary btn-sm" onclick="selectAllAlerts(false)">Deselect</button>
            <button class="btn btn-primary btn-sm" onclick="bulkResolve()">Resolve Selected</button>
        </div>
    </div>

    <?php if (empty($alerts)): ?>
        <p class="text-muted">No alerts match the current filters.</p>
    <?php else: ?>
    <form method="POST" id="bulkForm">
        <input type="hidden" name="action" value="bulk_resolve">
        <div style="display:grid;gap:.75rem">
        <?php
        $priorityColors = ['low'=>['#f1f5f9','#64748b'],'medium'=>['#fef3c7','#b45309'],'high'=>['#fed7aa','#c2410c'],'critical'=>['#fee2e2','#b91c1c']];
        $statusColors = ['pending'=>['#fef3c7','#b45309'],'sent'=>['#dbeafe','#1e40af'],'escalated'=>['#fee2e2','#b91c1c'],'resolved'=>['#dcfce7','#15803d']];
        foreach ($alerts as $a):
            $pc = $priorityColors[$a['priority']] ?? $priorityColors['medium'];
            $sc = $statusColors[$a['status']] ?? $statusColors['sent'];
        ?>
        <div style="border-left:4px solid <?= $pc[1] ?>;padding:.75rem 1rem;background:var(--card-bg);border-radius:0 8px 8px 0;<?= $a['status']==='resolved'?'opacity:.6':'' ?>">
            <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:1rem;flex-wrap:wrap">
                <div style="flex:1">
                    <div style="display:flex;align-items:center;gap:.5rem;margin-bottom:.3rem;flex-wrap:wrap">
                        <?php if ($a['status'] !== 'resolved'): ?>
                        <input type="checkbox" name="alert_ids[]" value="<?= $a['id'] ?>" style="margin-right:.3rem">
                        <?php endif; ?>
                        <span class="badge" style="background:<?= $pc[0] ?>;color:<?= $pc[1] ?>"><?= ucfirst($a['priority']) ?></span>
                        <span class="badge" style="background:<?= $sc[0] ?>;color:<?= $sc[1] ?>"><?= ucfirst($a['status']) ?></span>
                        <?php if ($a['escalated']): ?>
                        <span class="badge" style="background:#fee2e2;color:#b91c1c">🔴 Escalated</span>
                        <?php endif; ?>
                        <small class="text-muted"><?= htmlspecialchars($a['rule_name']) ?></small>
                    </div>
                    <h4 style="margin:.2rem 0"><?= htmlspecialchars($a['title']) ?></h4>
                    <p style="margin:.3rem 0"><?= htmlspecialchars($a['message']) ?></p>
                    <div class="text-muted" style="font-size:.85rem;margin-top:.5rem">
                        👤 <?= htmlspecialchars($a['user_name']) ?>
                        <?php if ($a['class_name']): ?> &nbsp;|&nbsp; 📚 <?= htmlspecialchars($a['class_name']) ?><?php endif; ?>
                        &nbsp;|&nbsp; 📅 <?= date('d M Y H:i', strtotime($a['created_at'])) ?>
                        <?php if ($a['email_sent']): ?> &nbsp;|&nbsp; 📧 Email<?php endif; ?>
                        <?php if ($a['sms_sent']): ?> &nbsp;|&nbsp; 📲 SMS<?php endif; ?>
                    </div>
                </div>
                <?php if ($a['status'] !== 'resolved'): ?>
                <form method="POST" style="display:inline">
                    <input type="hidden" name="action" value="resolve">
                    <input type="hidden" name="id" value="<?= $a['id'] ?>">
                    <button class="btn btn-sm" style="background:#16a34a;color:#fff">✓ Resolve</button>
                </form>
                <?php endif; ?>
            </div>
        </div>
        <?php endforeach; ?>
        </div>
    </form>
    <?php endif; ?>
</div>

<script>
function selectAllAlerts(checked) {
    document.querySelectorAll('input[name="alert_ids[]"]').forEach(cb => cb.checked = checked);
}
function bulkResolve() {
    const checked = document.querySelectorAll('input[name="alert_ids[]"]:checked').length;
    if (checked === 0) { alert('Select at least one alert.'); return; }
    if (confirm(`Resolve ${checked} alert(s)?`)) document.getElementById('bulkForm').submit();
}
</script>
<?php include '../includes/footer.php'; ?>
