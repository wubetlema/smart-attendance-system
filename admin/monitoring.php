<?php
require_once '../includes/auth_check.php';
requireRole('admin');
require_once '../config/db.php';
include '../includes/header.php';

// Overall stats
$totalUsers    = $conn->query("SELECT COUNT(*) c FROM users")->fetch_assoc()['c'];
$activeUsers   = $conn->query("SELECT COUNT(*) c FROM users WHERE is_active=1")->fetch_assoc()['c'];
$totalSessions = $conn->query("SELECT COUNT(*) c FROM attendance_sessions")->fetch_assoc()['c'];
$totalRecords  = $conn->query("SELECT COUNT(*) c FROM attendance")->fetch_assoc()['c'];
$todayRecords  = $conn->query("SELECT COUNT(*) c FROM attendance WHERE DATE(marked_at)=CURDATE()")->fetch_assoc()['c'];
$totalClasses  = $conn->query("SELECT COUNT(*) c FROM classes")->fetch_assoc()['c'];

// Method breakdown
$methods = $conn->query("SELECT method, COUNT(*) as c FROM attendance GROUP BY method")->fetch_all(MYSQLI_ASSOC);

// Recent activity (last 20 attendance records)
$recent = $conn->query("
    SELECT u.name AS student, c.name AS class, c.subject,
           a.status, a.method, a.marked_at
    FROM attendance a
    JOIN users u ON a.student_id=u.id
    JOIN attendance_sessions s ON a.session_id=s.id
    JOIN classes c ON s.class_id=c.id
    ORDER BY a.marked_at DESC LIMIT 20
")->fetch_all(MYSQLI_ASSOC);

// Daily attendance last 7 days
$daily = $conn->query("
    SELECT DATE(marked_at) AS day, COUNT(*) AS c
    FROM attendance
    WHERE marked_at >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)
    GROUP BY DATE(marked_at)
    ORDER BY day ASC
")->fetch_all(MYSQLI_ASSOC);
?>
<h1>System Monitoring</h1>

<div class="stats-grid">
    <div class="stat-card"><div class="stat-number"><?= $totalUsers ?></div><div class="stat-label">Total Users</div></div>
    <div class="stat-card"><div class="stat-number"><?= $activeUsers ?></div><div class="stat-label">Active Users</div></div>
    <div class="stat-card"><div class="stat-number"><?= $totalClasses ?></div><div class="stat-label">Classes</div></div>
    <div class="stat-card"><div class="stat-number"><?= $totalSessions ?></div><div class="stat-label">Total Sessions</div></div>
    <div class="stat-card"><div class="stat-number"><?= $totalRecords ?></div><div class="stat-label">Total Attendance</div></div>
    <div class="stat-card" style="border-color:#16a34a"><div class="stat-number" style="color:#16a34a"><?= $todayRecords ?></div><div class="stat-label">Today's Check-ins</div></div>
</div>

<!-- Method breakdown -->
<div class="card">
    <h3>Attendance by Method</h3>
    <div style="display:flex;gap:1rem;flex-wrap:wrap">
    <?php foreach ($methods as $m):
        $icons = ['manual'=>'✏️','qr'=>'📱','face'=>'👤'];
    ?>
    <div class="stat-card" style="flex:1;min-width:120px">
        <div class="stat-number"><?= $m['c'] ?></div>
        <div class="stat-label"><?= ($icons[$m['method']] ?? '') . ' ' . ucfirst($m['method']) ?></div>
    </div>
    <?php endforeach; ?>
    </div>
</div>

<!-- Daily chart (last 7 days) -->
<div class="card">
    <h3>Daily Attendance (Last 7 Days)</h3>
    <div style="display:flex;align-items:flex-end;gap:.5rem;height:120px;padding:.5rem 0">
    <?php
    $maxVal = max(array_column($daily, 'c') ?: [1]);
    foreach ($daily as $d):
        $h = round(($d['c'] / $maxVal) * 100);
    ?>
    <div style="flex:1;display:flex;flex-direction:column;align-items:center;gap:.25rem">
        <span style="font-size:.75rem;font-weight:600"><?= $d['c'] ?></span>
        <div style="width:100%;background:var(--primary);border-radius:4px 4px 0 0;height:<?= $h ?>px"></div>
        <span style="font-size:.7rem;color:var(--text-muted)"><?= date('d M', strtotime($d['day'])) ?></span>
    </div>
    <?php endforeach; ?>
    </div>
</div>

<!-- Recent activity -->
<div class="card">
    <h3>Recent Activity</h3>
    <?php if (empty($recent)): ?>
        <p class="text-muted">No activity yet.</p>
    <?php else: ?>
    <table class="table">
        <thead><tr><th>Student</th><th>Class</th><th>Subject</th><th>Status</th><th>Method</th><th>Time</th></tr></thead>
        <tbody>
        <?php foreach ($recent as $r): ?>
        <tr>
            <td><?= htmlspecialchars($r['student']) ?></td>
            <td><?= htmlspecialchars($r['class']) ?></td>
            <td><?= htmlspecialchars($r['subject']) ?></td>
            <td><span class="badge badge-<?= $r['status'] ?>"><?= $r['status'] ?></span></td>
            <td><?= $r['method'] ?></td>
            <td><?= date('d M, H:i', strtotime($r['marked_at'])) ?></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>
</div>
<?php include '../includes/footer.php'; ?>
