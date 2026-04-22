<?php
require_once '../includes/auth_check.php';
requireRole('admin');
require_once '../config/db.php';

$students    = $conn->query("SELECT COUNT(*) c FROM users WHERE role='student'")->fetch_assoc()['c'];
$teachers    = $conn->query("SELECT COUNT(*) c FROM users WHERE role='teacher'")->fetch_assoc()['c'];
$courses     = $conn->query("SELECT COUNT(*) c FROM classes")->fetch_assoc()['c'];
$departments = $conn->query("SELECT COUNT(*) c FROM departments")->fetch_assoc()['c'];
$activeUsers = $conn->query("SELECT COUNT(*) c FROM users WHERE is_active=1")->fetch_assoc()['c'];
$liveSessions= $conn->query("SELECT COUNT(*) c FROM attendance_sessions WHERE expires_at > NOW()")->fetch_assoc()['c'];

$todayPresent = $conn->query("SELECT COUNT(*) c FROM attendance WHERE status='present' AND DATE(marked_at)=CURDATE()")->fetch_assoc()['c'];
$todayAbsent  = $conn->query("SELECT COUNT(*) c FROM attendance WHERE status='absent'  AND DATE(marked_at)=CURDATE()")->fetch_assoc()['c'];
$todayLate    = $conn->query("SELECT COUNT(*) c FROM attendance WHERE status='late'    AND DATE(marked_at)=CURDATE()")->fetch_assoc()['c'];
$todayTotal   = $todayPresent + $todayAbsent + $todayLate;
$todayPct     = $todayTotal > 0 ? round(($todayPresent/$todayTotal)*100) : 0;

$recent = $conn->query("
    SELECT u.name AS student, c.name AS class, a.status, a.method, a.marked_at
    FROM attendance a
    JOIN users u ON a.student_id=u.id
    JOIN attendance_sessions s ON a.session_id=s.id
    JOIN classes c ON s.class_id=c.id
    ORDER BY a.marked_at DESC LIMIT 10
")->fetch_all(MYSQLI_ASSOC);

include '../includes/header.php';
?>
<h1>Admin Dashboard</h1>
<p class="text-muted" style="margin-top:-.5rem;margin-bottom:1.5rem">
    Welcome, <?= htmlspecialchars($_SESSION['name']) ?> &mdash; <?= date('l, d F Y') ?>
</p>

<div class="stats-grid">
    <div class="stat-card"><div class="stat-number"><?= $students ?></div><div class="stat-label">Students</div></div>
    <div class="stat-card"><div class="stat-number"><?= $teachers ?></div><div class="stat-label">Teachers</div></div>
    <div class="stat-card"><div class="stat-number"><?= $courses ?></div><div class="stat-label">Courses</div></div>
    <div class="stat-card"><div class="stat-number"><?= $departments ?></div><div class="stat-label">Departments</div></div>
    <div class="stat-card"><div class="stat-number"><?= $activeUsers ?></div><div class="stat-label">Active Users</div></div>
    <div class="stat-card" style="border-color:#6366f1"><div class="stat-number" style="color:#6366f1"><?= $liveSessions ?></div><div class="stat-label">Live Sessions</div></div>
</div>

<div style="display:grid;grid-template-columns:1fr 1fr;gap:1.5rem;margin-bottom:1.5rem">

<div class="card">
    <h3>Today's Attendance &mdash; <?= date('d M Y') ?></h3>
    <?php if ($todayTotal === 0): ?>
        <p class="text-muted">No attendance recorded today yet.</p>
    <?php else: ?>
    <div class="stats-grid" style="grid-template-columns:repeat(3,1fr);margin-bottom:1rem">
        <div class="stat-card" style="border-color:#16a34a;padding:.75rem">
            <div class="stat-number" style="color:#16a34a;font-size:1.5rem"><?= $todayPresent ?></div>
            <div class="stat-label">Present</div>
        </div>
        <div class="stat-card" style="border-color:#dc2626;padding:.75rem">
            <div class="stat-number" style="color:#dc2626;font-size:1.5rem"><?= $todayAbsent ?></div>
            <div class="stat-label">Absent</div>
        </div>
        <div class="stat-card" style="border-color:#d97706;padding:.75rem">
            <div class="stat-number" style="color:#d97706;font-size:1.5rem"><?= $todayLate ?></div>
            <div class="stat-label">Late</div>
        </div>
    </div>
    <div class="progress-bar-wrap">
        <div class="progress-bar" style="width:<?= $todayPct ?>%"><?= $todayPct ?>% Present</div>
    </div>
    <?php endif; ?>
</div>

<div class="card">
    <h3>System Status</h3>
    <table style="width:100%;border-collapse:collapse">
        <tr style="border-bottom:1px solid var(--border)">
            <td style="padding:.6rem 0">Web Server (Apache)</td>
            <td style="text-align:right"><span class="badge" style="background:#dcfce7;color:#15803d">Online</span></td>
        </tr>
        <tr style="border-bottom:1px solid var(--border)">
            <td style="padding:.6rem 0">Database (MySQL)</td>
            <td style="text-align:right">
                <?php $dbOk = $conn->ping(); ?>
                <span class="badge" style="background:<?= $dbOk?'#dcfce7':'#fee2e2' ?>;color:<?= $dbOk?'#15803d':'#b91c1c' ?>">
                    <?= $dbOk ? 'Connected' : 'Error' ?>
                </span>
            </td>
        </tr>
        <tr style="border-bottom:1px solid var(--border)">
            <td style="padding:.6rem 0">Camera (Face/QR)</td>
            <td style="text-align:right"><span id="camStatus" class="badge" style="background:#fef3c7;color:#b45309">Checking...</span></td>
        </tr>
        <tr style="border-bottom:1px solid var(--border)">
            <td style="padding:.6rem 0">Location Services</td>
            <td style="text-align:right"><span id="geoStatus" class="badge" style="background:#fef3c7;color:#b45309">Checking...</span></td>
        </tr>
        <tr>
            <td style="padding:.6rem 0">Server Time</td>
            <td style="text-align:right"><span class="badge" style="background:#dbeafe;color:#1d4ed8"><?= date('H:i:s') ?></span></td>
        </tr>
    </table>
</div>
</div>

<div class="quick-links">
    <a href="users.php" class="btn btn-primary">Manage Users</a>
    <a href="classes.php" class="btn btn-secondary">Manage Classes</a>
    <a href="departments.php" class="btn btn-secondary">Departments</a>
    <a href="monitoring.php" class="btn btn-secondary">Monitoring</a>
    <a href="reports.php" class="btn btn-secondary">Reports</a>
    <a href="backup.php" class="btn btn-secondary">Backup</a>
</div>

<div class="card">
    <h3>Recent Activity</h3>
    <?php if (empty($recent)): ?>
        <p class="text-muted">No activity yet.</p>
    <?php else: ?>
    <table class="table">
        <thead><tr><th>Student</th><th>Class</th><th>Status</th><th>Method</th><th>Time</th></tr></thead>
        <tbody>
        <?php foreach ($recent as $r): ?>
        <tr>
            <td><?= htmlspecialchars($r['student']) ?></td>
            <td><?= htmlspecialchars($r['class']) ?></td>
            <td><span class="badge badge-<?= $r['status'] ?>"><?= $r['status'] ?></span></td>
            <td><?= $r['method'] ?></td>
            <td><?= date('d M, H:i', strtotime($r['marked_at'])) ?></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>
</div>

<script>
navigator.mediaDevices && navigator.mediaDevices.getUserMedia({video:true})
    .then(()=>{ let e=document.getElementById('camStatus'); e.textContent='Available'; e.style.cssText='background:#dcfce7;color:#15803d'; })
    .catch(()=>{ let e=document.getElementById('camStatus'); e.textContent='Not Available'; e.style.cssText='background:#fee2e2;color:#b91c1c'; });

navigator.geolocation ? navigator.geolocation.getCurrentPosition(
    ()=>{ let e=document.getElementById('geoStatus'); e.textContent='Enabled'; e.style.cssText='background:#dcfce7;color:#15803d'; },
    ()=>{ let e=document.getElementById('geoStatus'); e.textContent='Denied'; e.style.cssText='background:#fee2e2;color:#b91c1c'; }
) : (document.getElementById('geoStatus').textContent='Not Supported');
</script>
<?php include '../includes/footer.php'; ?>
