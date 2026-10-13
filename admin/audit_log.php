<?php
require_once '../includes/auth_check.php';
requireRole('admin');
require_once '../config/db.php';

// ── Export CSV ────────────────────────────────────────────────────────────────
if (isset($_GET['export'])) {
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="audit_log_' . date('Ymd_His') . '.csv"');
    $where = buildWhere();
    $rows  = $conn->query("SELECT id, user_name, user_role, action, category, target_type, target_id, description, reason, ip_address, browser, device_type, severity, created_at FROM audit_log $where ORDER BY created_at DESC LIMIT 10000")->fetch_all(MYSQLI_ASSOC);
    $out = fopen('php://output','w');
    fputcsv($out, ['ID','User','Role','Action','Category','Target','Target ID','Description','Reason','IP','Browser','Device','Severity','Time']);
    foreach ($rows as $r) fputcsv($out, array_values($r));
    fclose($out);
    exit;
}

// ── Build WHERE clause from filters ──────────────────────────────────────────
function buildWhere(): string {
    global $conn;
    $conds = ['1=1'];

    if (!empty($_GET['action']))    $conds[] = "action='".$conn->real_escape_string($_GET['action'])."'";
    if (!empty($_GET['category']))  $conds[] = "category='".$conn->real_escape_string($_GET['category'])."'";
    if (!empty($_GET['severity']))  $conds[] = "severity='".$conn->real_escape_string($_GET['severity'])."'";
    if (!empty($_GET['user_id']))   $conds[] = "user_id=".(int)$_GET['user_id'];
    if (!empty($_GET['ip']))        $conds[] = "ip_address LIKE '%".$conn->real_escape_string($_GET['ip'])."%'";
    if (!empty($_GET['search']))    $conds[] = "(user_name LIKE '%".$conn->real_escape_string($_GET['search'])."%' OR description LIKE '%".$conn->real_escape_string($_GET['search'])."%' OR action LIKE '%".$conn->real_escape_string($_GET['search'])."%')";
    if (!empty($_GET['date_from'])) $conds[] = "DATE(created_at) >= '".$conn->real_escape_string($_GET['date_from'])."'";
    if (!empty($_GET['date_to']))   $conds[] = "DATE(created_at) <= '".$conn->real_escape_string($_GET['date_to'])."'";

    return 'WHERE ' . implode(' AND ', $conds);
}

$where    = buildWhere();
$page     = max(1, (int)($_GET['page'] ?? 1));
$perPage  = 50;
$offset   = ($page - 1) * $perPage;
$total    = (int)$conn->query("SELECT COUNT(*) c FROM audit_log $where")->fetch_assoc()['c'];
$pages    = max(1, ceil($total / $perPage));
$logs     = $conn->query("SELECT * FROM audit_log $where ORDER BY created_at DESC LIMIT $perPage OFFSET $offset")->fetch_all(MYSQLI_ASSOC);

// Distinct actions for filter dropdown
$actions    = $conn->query("SELECT DISTINCT action FROM audit_log ORDER BY action")->fetch_all(MYSQLI_ASSOC);
$categories = ['auth','attendance','correction','user_management','class_management','session_management','notification','system','security','report'];

// Summary stats for quick overview
$stats = $conn->query("
    SELECT
        COUNT(*) AS total,
        SUM(severity='critical') AS critical,
        SUM(severity='error')    AS errors,
        SUM(severity='warning')  AS warnings,
        SUM(action='LOGIN')      AS logins,
        SUM(action='LOGIN_FAILED') AS login_fails,
        SUM(action LIKE '%CORRECTION%') AS corrections,
        SUM(action LIKE '%ATTENDANCE%') AS attendance_events
    FROM audit_log
    WHERE created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
")->fetch_assoc();

include '../includes/header.php';
?>

<h1>🔍 Audit Trail</h1>
<p class="text-muted">Complete tamper-evident record of all system actions. Every entry is permanent.</p>

<!-- 24h Stats -->
<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(130px,1fr));gap:.75rem;margin-bottom:1.5rem">
    <div class="stat-card"><div class="stat-number"><?= $stats['total'] ?></div><div class="stat-label">Events (24h)</div></div>
    <div class="stat-card" style="border-color:#dc2626"><div class="stat-number" style="color:#dc2626"><?= $stats['critical'] ?></div><div class="stat-label">Critical</div></div>
    <div class="stat-card" style="border-color:#f59e0b"><div class="stat-number" style="color:#f59e0b"><?= $stats['errors'] ?></div><div class="stat-label">Errors</div></div>
    <div class="stat-card" style="border-color:#d97706"><div class="stat-number" style="color:#d97706"><?= $stats['login_fails'] ?></div><div class="stat-label">Login Failures</div></div>
    <div class="stat-card"><div class="stat-number"><?= $stats['logins'] ?></div><div class="stat-label">Logins</div></div>
    <div class="stat-card"><div class="stat-number"><?= $stats['corrections'] ?></div><div class="stat-label">Corrections</div></div>
    <div class="stat-card"><div class="stat-number"><?= $stats['attendance_events'] ?></div><div class="stat-label">Attendance</div></div>
</div>

<!-- Filters -->
<div class="card">
    <form method="GET" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:.65rem">
        <div class="form-group" style="margin:0">
            <label>Search</label>
            <input type="text" name="search" placeholder="User, action, description..." value="<?= htmlspecialchars($_GET['search'] ?? '') ?>">
        </div>
        <div class="form-group" style="margin:0">
            <label>Action</label>
            <select name="action">
                <option value="">All Actions</option>
                <?php foreach ($actions as $a): ?>
                <option value="<?= htmlspecialchars($a['action']) ?>" <?= ($_GET['action']??'')===$a['action']?'selected':'' ?>>
                    <?= htmlspecialchars($a['action']) ?>
                </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="form-group" style="margin:0">
            <label>Category</label>
            <select name="category">
                <option value="">All</option>
                <?php foreach ($categories as $cat): ?>
                <option value="<?= $cat ?>" <?= ($_GET['category']??'')===$cat?'selected':'' ?>><?= ucfirst(str_replace('_',' ',$cat)) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="form-group" style="margin:0">
            <label>Severity</label>
            <select name="severity">
                <option value="">All</option>
                <?php foreach (['info','warning','error','critical'] as $sv): ?>
                <option value="<?= $sv ?>" <?= ($_GET['severity']??'')===$sv?'selected':'' ?>><?= ucfirst($sv) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="form-group" style="margin:0">
            <label>IP Address</label>
            <input type="text" name="ip" placeholder="e.g. 192.168.1." value="<?= htmlspecialchars($_GET['ip'] ?? '') ?>">
        </div>
        <div class="form-group" style="margin:0">
            <label>From Date</label>
            <input type="date" name="date_from" value="<?= htmlspecialchars($_GET['date_from'] ?? '') ?>">
        </div>
        <div class="form-group" style="margin:0">
            <label>To Date</label>
            <input type="date" name="date_to" value="<?= htmlspecialchars($_GET['date_to'] ?? '') ?>">
        </div>
        <div class="form-group" style="margin:0;display:flex;gap:.4rem;align-items:flex-end">
            <button type="submit" class="btn btn-primary" style="flex:1">Filter</button>
            <a href="audit_log.php" class="btn btn-secondary" style="flex:1;text-align:center">Clear</a>
        </div>
    </form>
</div>

<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:.75rem;flex-wrap:wrap;gap:.5rem">
    <div style="color:#64748b;font-size:.9rem">
        Showing <?= number_format($total) ?> total events · Page <?= $page ?> of <?= $pages ?>
    </div>
    <a href="?<?= http_build_query(array_merge($_GET, ['export'=>1])) ?>" class="btn btn-secondary btn-sm">Export CSV</a>
</div>

<!-- Audit Table -->
<div class="card" style="padding:0">
    <div style="overflow-x:auto">
    <table class="table" style="margin:0">
        <thead>
            <tr>
                <th style="width:140px">Time</th>
                <th>User</th>
                <th>Action</th>
                <th>Description</th>
                <th>Changes</th>
                <th>Device / IP</th>
                <th style="width:80px">Severity</th>
            </tr>
        </thead>
        <tbody>
        <?php if (empty($logs)): ?>
        <tr><td colspan="7" style="text-align:center;padding:2.5rem;color:#94a3b8">No audit events found for the selected filters.</td></tr>
        <?php endif; ?>
        <?php
        $sevColors = [
            'info'     => ['#f0f9ff','#0369a1'],
            'warning'  => ['#fef3c7','#b45309'],
            'error'    => ['#fee2e2','#b91c1c'],
            'critical' => ['#fdf2f8','#7e22ce'],
        ];
        $catColors = [
            'auth'             => '#6366f1',
            'attendance'       => '#16a34a',
            'correction'       => '#d97706',
            'user_management'  => '#0891b2',
            'class_management' => '#7c3aed',
            'session_management'=> '#059669',
            'security'         => '#dc2626',
            'system'           => '#475569',
            'report'           => '#b45309',
            'notification'     => '#0ea5e9',
        ];

        foreach ($logs as $log):
            [$sevBg, $sevFg] = $sevColors[$log['severity']] ?? $sevColors['info'];
            $catColor = $catColors[$log['category']] ?? '#6366f1';
            $hasChanges = $log['old_value'] || $log['new_value'];
        ?>
        <tr style="background:<?= $sevBg ?>18;vertical-align:top">
            <td>
                <div style="font-size:.78rem;font-weight:600;color:#1e293b"><?= date('d M Y', strtotime($log['created_at'])) ?></div>
                <div style="font-size:.75rem;color:#64748b"><?= date('H:i:s', strtotime($log['created_at'])) ?></div>
            </td>
            <td>
                <?php if ($log['user_name']): ?>
                <div style="font-weight:600;font-size:.88rem"><?= htmlspecialchars($log['user_name']) ?></div>
                <span class="badge badge-<?= $log['user_role'] ?>" style="font-size:.72rem"><?= $log['user_role'] ?></span>
                <?php else: ?>
                <span style="color:#94a3b8;font-size:.82rem">System</span>
                <?php endif; ?>
            </td>
            <td>
                <div>
                    <span style="display:inline-block;font-size:.75rem;font-weight:700;color:<?= $catColor ?>;background:<?= $catColor ?>18;padding:.15rem .5rem;border-radius:5px;margin-bottom:.2rem">
                        <?= str_replace('_',' ', $log['category']) ?>
                    </span>
                </div>
                <span style="font-family:monospace;font-size:.78rem;font-weight:700;color:#1e293b">
                    <?= htmlspecialchars($log['action']) ?>
                </span>
                <?php if ($log['target_type'] && $log['target_id']): ?>
                <div style="font-size:.73rem;color:#94a3b8"><?= $log['target_type'] ?> #<?= $log['target_id'] ?></div>
                <?php endif; ?>
            </td>
            <td style="font-size:.85rem;max-width:250px">
                <?= htmlspecialchars($log['description']) ?>
                <?php if ($log['reason']): ?>
                <div style="margin-top:.2rem;font-size:.78rem;color:#64748b"><em>Reason: <?= htmlspecialchars(substr($log['reason'],0,80)) ?></em></div>
                <?php endif; ?>
            </td>
            <td>
                <?php if ($hasChanges): ?>
                <div style="font-size:.75rem;max-width:180px">
                    <?php if ($log['old_value']): ?>
                    <div style="color:#dc2626">
                        <strong>Before:</strong>
                        <?php
                        $old = json_decode($log['old_value'], true);
                        if (is_array($old)) {
                            foreach ($old as $k => $v) echo htmlspecialchars("$k: $v") . ' ';
                        } else { echo htmlspecialchars($log['old_value']); }
                        ?>
                    </div>
                    <?php endif; ?>
                    <?php if ($log['new_value']): ?>
                    <div style="color:#16a34a">
                        <strong>After:</strong>
                        <?php
                        $new = json_decode($log['new_value'], true);
                        if (is_array($new)) {
                            foreach ($new as $k => $v) echo htmlspecialchars("$k: $v") . ' ';
                        } else { echo htmlspecialchars($log['new_value']); }
                        ?>
                    </div>
                    <?php endif; ?>
                </div>
                <?php else: ?>
                <span style="color:#94a3b8;font-size:.78rem">–</span>
                <?php endif; ?>
            </td>
            <td style="font-size:.75rem">
                <div><code><?= htmlspecialchars($log['ip_address']) ?></code></div>
                <div style="color:#64748b"><?= htmlspecialchars($log['browser']) ?></div>
                <div style="color:#94a3b8"><?= $log['device_type'] ?></div>
            </td>
            <td>
                <span style="display:inline-block;padding:.2rem .55rem;border-radius:10px;font-size:.72rem;font-weight:700;background:<?= $sevBg ?>;color:<?= $sevFg ?>">
                    <?= strtoupper($log['severity']) ?>
                </span>
            </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
</div>

<!-- Pagination -->
<?php if ($pages > 1): ?>
<div style="display:flex;justify-content:center;gap:.4rem;margin-top:1rem;flex-wrap:wrap">
    <?php
    $queryBase = array_diff_key($_GET, ['page' => '']);
    $range = range(max(1, $page - 3), min($pages, $page + 3));
    if (!in_array(1, $range)) { echo '<a href="?'.http_build_query(array_merge($queryBase, ['page'=>1])).'" class="btn btn-secondary btn-sm">1</a><span style="padding:.4rem">…</span>'; }
    foreach ($range as $p):
        $active = $p === $page;
    ?>
    <a href="?<?= http_build_query(array_merge($queryBase, ['page'=>$p])) ?>"
       class="btn btn-sm <?= $active?'btn-primary':'btn-secondary' ?>">
       <?= $p ?>
    </a>
    <?php endforeach; ?>
    <?php if (!in_array($pages, $range)): ?>
    <span style="padding:.4rem">…</span>
    <a href="?<?= http_build_query(array_merge($queryBase, ['page'=>$pages])) ?>" class="btn btn-secondary btn-sm"><?= $pages ?></a>
    <?php endif; ?>
</div>
<?php endif; ?>

<?php include '../includes/footer.php'; ?>
