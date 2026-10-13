<?php
require_once '../includes/auth_check.php';
requireRole('admin');
require_once '../config/db.php';
require_once '../includes/attendance_calculator.php';

// Load thresholds early — needed by KPI queries below
$t             = AttendanceCalculator::thresholds();
$thresholdGood = (int)$t['good'];

// ── KPIs ──────────────────────────────────────────────────────────────────────
// Helper: safe query that returns 0 if table/column doesn't exist
function safeCount(mysqli $conn, string $sql): int {
    $r = @$conn->query($sql);
    if (!$r || $r === true) return 0;
    $row = $r->fetch_assoc();
    return (int)($row['c'] ?? 0);
}
function safeQuery(mysqli $conn, string $sql): array {
    $r = @$conn->query($sql);
    if (!$r || $r === true) return [];
    return $r->fetch_all(MYSQLI_ASSOC);
}

$students    = safeCount($conn, "SELECT COUNT(*) c FROM users WHERE role='student' AND is_active=1");
$teachers    = safeCount($conn, "SELECT COUNT(*) c FROM users WHERE role='teacher' AND is_active=1");
$courses     = safeCount($conn, "SELECT COUNT(*) c FROM classes WHERE is_active=1");
$departments = safeCount($conn, "SELECT COUNT(*) c FROM departments");
$liveSessions= safeCount($conn, "SELECT COUNT(*) c FROM attendance_sessions WHERE status IN ('open','late')");
if (!$liveSessions) {
    // Fallback for old schema without status column
    $liveSessions = safeCount($conn, "SELECT COUNT(*) c FROM attendance_sessions WHERE expires_at > NOW()");
}

$todayPresent = safeCount($conn, "SELECT COUNT(*) c FROM attendance WHERE status='present' AND DATE(marked_at)=CURDATE()");
$todayAbsent  = safeCount($conn, "SELECT COUNT(*) c FROM attendance WHERE status='absent'  AND DATE(marked_at)=CURDATE()");
$todayLate    = safeCount($conn, "SELECT COUNT(*) c FROM attendance WHERE status='late'    AND DATE(marked_at)=CURDATE()");
$todayTotal   = $todayPresent + $todayAbsent + $todayLate;
$todayPct     = $todayTotal > 0 ? round(($todayPresent / $todayTotal) * 100) : 0;

$todayClasses = safeCount($conn, "SELECT COUNT(*) c FROM schedules WHERE day_of_week = '" . date('l') . "'");

$avgRow = safeQuery($conn, "SELECT ROUND(SUM(status IN ('present','late')) / NULLIF(COUNT(*),0) * 100, 1) AS avg_pct FROM attendance");
$avgPct = (float)($avgRow[0]['avg_pct'] ?? 0);

$atRisk = 0;
try {
    $atRisk = safeCount($conn, "
        SELECT COUNT(*) c FROM (
            SELECT a.student_id, s.class_id,
                   ROUND(SUM(a.status IN ('present','late')) / COUNT(*) * 100, 1) AS pct
            FROM attendance a
            JOIN attendance_sessions s ON a.session_id=s.id
            GROUP BY a.student_id, s.class_id
            HAVING COUNT(*) >= 3 AND pct < $thresholdGood
        ) sub
    ");
} catch (Throwable $e) { $atRisk = 0; }

$pendingCorrections = safeCount($conn, "SELECT COUNT(*) c FROM attendance_corrections WHERE status='pending'");
$pendingLeaves      = safeCount($conn, "SELECT COUNT(*) c FROM leaves WHERE status='pending'");

// Chart: attendance by department (last 30 days)
$deptChart = safeQuery($conn, "
    SELECT d.name AS dept,
           COUNT(*) AS total,
           SUM(a.status IN ('present','late')) AS attended
    FROM attendance a
    JOIN attendance_sessions s ON a.session_id=s.id
    JOIN classes c ON s.class_id=c.id
    LEFT JOIN departments d ON c.department_id=d.id
    WHERE a.marked_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
      AND d.name IS NOT NULL
    GROUP BY d.name
    ORDER BY attended DESC
    LIMIT 8
");

// Chart: attendance by week (last 8 weeks)
$weekChart = safeQuery($conn, "
    SELECT YEARWEEK(marked_at, 1) AS yw,
           MIN(DATE(marked_at)) AS week_start,
           COUNT(*) AS total,
           SUM(status IN ('present','late')) AS attended
    FROM attendance
    WHERE marked_at >= DATE_SUB(NOW(), INTERVAL 8 WEEK)
    GROUP BY yw
    ORDER BY yw ASC
");

// Chart: attendance by course (top 8)
$courseChart = safeQuery($conn, "
    SELECT c.name AS course, c.subject,
           COUNT(*) AS total,
           SUM(a.status IN ('present','late')) AS attended,
           ROUND(SUM(a.status IN ('present','late')) / NULLIF(COUNT(*),0) * 100, 1) AS pct
    FROM attendance a
    JOIN attendance_sessions s ON a.session_id=s.id
    JOIN classes c ON s.class_id=c.id
    GROUP BY c.id
    HAVING total >= 5
    ORDER BY pct DESC
    LIMIT 8
");

// Recent activity
$recent = safeQuery($conn, "
    SELECT u.name AS student, c.name AS class, a.status, a.method, a.marked_at
    FROM attendance a
    JOIN users u ON a.student_id=u.id
    JOIN attendance_sessions s ON a.session_id=s.id
    JOIN classes c ON s.class_id=c.id
    ORDER BY a.marked_at DESC LIMIT 10
");

include '../includes/header.php';
?>

<h1>Admin Dashboard</h1>
<p class="text-muted" style="margin-top:-.5rem;margin-bottom:1.5rem">
    Welcome, <?= htmlspecialchars($_SESSION['name']) ?> &mdash; <?= date('l, d F Y') ?>
</p>

<!-- ── KPI Stats ───────────────────────────────────────────────────────────── -->
<div class="stats-grid">
    <div class="stat-card">
        <div class="stat-number"><?= number_format($students) ?></div>
        <div class="stat-label">Total Students</div>
    </div>
    <div class="stat-card">
        <div class="stat-number"><?= $teachers ?></div>
        <div class="stat-label">Total Teachers</div>
    </div>
    <div class="stat-card">
        <div class="stat-number"><?= $courses ?></div>
        <div class="stat-label">Total Courses</div>
    </div>
    <div class="stat-card">
        <div class="stat-number"><?= $todayClasses ?></div>
        <div class="stat-label">Today's Classes</div>
    </div>
    <div class="stat-card" style="border-color:#6366f1">
        <div class="stat-number" style="color:#6366f1"><?= $liveSessions ?></div>
        <div class="stat-label">Live Sessions</div>
    </div>
    <div class="stat-card" style="border-color:<?= $avgPct >= $thresholdGood ? '#16a34a' : '#d97706' ?>">
        <div class="stat-number" style="color:<?= $avgPct >= $thresholdGood ? '#16a34a' : '#d97706' ?>"><?= $avgPct ?>%</div>
        <div class="stat-label">Avg Attendance</div>
    </div>
    <div class="stat-card" style="border-color:#dc2626">
        <div class="stat-number" style="color:#dc2626"><?= $atRisk ?></div>
        <div class="stat-label">At-Risk Students</div>
    </div>
    <?php if ($pendingCorrections > 0): ?>
    <div class="stat-card" style="border-color:#d97706">
        <div class="stat-number" style="color:#d97706"><?= $pendingCorrections ?></div>
        <div class="stat-label">Pending Corrections</div>
    </div>
    <?php endif; ?>
</div>

<!-- ── Alert banners ──────────────────────────────────────────────────────── -->
<?php if ($atRisk > 0): ?>
<div class="alert alert-error" style="justify-content:space-between;flex-wrap:wrap;gap:.5rem">
    <span>🚨 <strong><?= $atRisk ?> student(s)</strong> are below the <?= $thresholdGood ?>% attendance threshold.</span>
    <a href="reports.php?type=at_risk" class="btn btn-sm btn-danger">View At-Risk Report</a>
</div>
<?php endif; ?>
<?php if ($pendingCorrections > 0): ?>
<div class="alert alert-warning" style="justify-content:space-between;flex-wrap:wrap;gap:.5rem">
    <span>⏳ <strong><?= $pendingCorrections ?> correction request(s)</strong> are waiting for teacher review.</span>
    <a href="../teacher/correction_requests.php" class="btn btn-sm btn-warning">View Requests</a>
</div>
<?php endif; ?>

<div style="display:grid;grid-template-columns:1fr 1fr;gap:1.5rem;margin-bottom:1.5rem">

<!-- Today's attendance -->
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
    <div class="progress-label"><span>Today's attendance rate</span><span><?= $todayPct ?>%</span></div>
    <div class="progress-wrap"><div class="progress-bar" style="width:<?= $todayPct ?>%"></div></div>
    <?php endif; ?>
</div>

<!-- System status -->
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
        <tr style="border-bottom:1px solid var(--border)">
            <td style="padding:.6rem 0">Active Sessions</td>
            <td style="text-align:right"><span class="badge" style="background:#dbeafe;color:#1d4ed8"><?= $liveSessions ?> open</span></td>
        </tr>
        <tr>
            <td style="padding:.6rem 0">Server Time</td>
            <td style="text-align:right"><span class="badge" style="background:#dbeafe;color:#1d4ed8"><?= date('H:i:s') ?></span></td>
        </tr>
    </table>
</div>
</div>

<!-- ── Charts ─────────────────────────────────────────────────────────────── -->
<div style="display:grid;grid-template-columns:1fr 1fr;gap:1.5rem;margin-bottom:1.5rem">

<!-- Attendance by Department -->
<div class="card">
    <h3>Attendance by Department</h3>
    <?php if (empty($deptChart)): ?>
    <p class="text-muted">No data yet.</p>
    <?php else: ?>
    <canvas id="deptChart" height="220"></canvas>
    <?php endif; ?>
</div>

<!-- Attendance by Week -->
<div class="card">
    <h3>Weekly Attendance Trend</h3>
    <?php if (empty($weekChart)): ?>
    <p class="text-muted">No data yet.</p>
    <?php else: ?>
    <canvas id="weekChart" height="220"></canvas>
    <?php endif; ?>
</div>
</div>

<!-- Attendance by Course (horizontal bars) -->
<?php if (!empty($courseChart)): ?>
<div class="card">
    <h3>Attendance by Course (Top 8)</h3>
    <div style="display:flex;flex-direction:column;gap:.65rem">
    <?php foreach ($courseChart as $cc):
        $pct = (float)$cc['pct'];
        $st  = AttendanceCalculator::status($pct);
    ?>
    <div>
        <div style="display:flex;justify-content:space-between;font-size:.85rem;margin-bottom:.25rem">
            <span><strong><?= htmlspecialchars($cc['course']) ?></strong> <span class="text-muted">— <?= htmlspecialchars($cc['subject']) ?></span></span>
            <span style="font-weight:700;color:<?= $st['color'] ?>"><?= $pct ?>%</span>
        </div>
        <div class="progress-wrap">
            <div class="progress-bar" style="width:<?= $pct ?>%;background:<?= $st['color'] ?>"></div>
        </div>
        <small class="text-muted"><?= $cc['attended'] ?>/<?= $cc['total'] ?> sessions &nbsp; <?= $st['icon'] ?> <?= $st['label'] ?></small>
    </div>
    <?php endforeach; ?>
    </div>
</div>
<?php endif; ?>

<!-- ── Quick Links ──────────────────────────────────────────────────────────── -->
<div class="quick-links">
    <a href="users.php"              class="btn btn-primary">Manage Users</a>
    <a href="enrollment.php"         class="btn btn-secondary">Enrollment</a>
    <a href="classes.php"            class="btn btn-secondary">Courses</a>
    <a href="departments.php"        class="btn btn-secondary">Departments</a>
    <a href="academic_structure.php" class="btn btn-secondary">Structure</a>
    <a href="rooms.php"              class="btn btn-secondary">Rooms</a>
    <a href="schedule.php"           class="btn btn-secondary">Schedule</a>
    <a href="reports.php"            class="btn btn-secondary">Reports</a>
    <a href="search.php"             class="btn btn-secondary">🔍 Search</a>
    <a href="audit_log.php"          class="btn btn-secondary">Audit Log</a>
    <a href="backup.php"             class="btn btn-secondary">Backup</a>
</div>

<!-- ── Recent Activity ──────────────────────────────────────────────────────── -->
<div class="card">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:.75rem">
        <h3 style="margin:0;border:none;padding:0">Recent Attendance Activity</h3>
        <a href="reports.php" class="btn btn-secondary btn-sm">View All Reports</a>
    </div>
    <?php if (empty($recent)): ?>
        <p class="text-muted">No activity yet.</p>
    <?php else: ?>
    <div style="overflow-x:auto">
    <table class="table">
        <thead><tr><th>Student</th><th>Class</th><th>Status</th><th>Method</th><th>Time</th></tr></thead>
        <tbody>
        <?php foreach ($recent as $r): ?>
        <tr>
            <td><?= htmlspecialchars($r['student']) ?></td>
            <td><?= htmlspecialchars($r['class']) ?></td>
            <td><span class="badge badge-<?= $r['status'] ?>"><?= $r['status'] ?></span></td>
            <td><span class="badge" style="background:#f1f5f9;color:#475569"><?= $r['method'] ?></span></td>
            <td><?= date('d M, H:i', strtotime($r['marked_at'])) ?></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
    <?php endif; ?>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<script>
const isDark = () => document.documentElement.getAttribute('data-theme') === 'dark';
const gridColor = () => isDark() ? 'rgba(255,255,255,.08)' : 'rgba(0,0,0,.06)';
const textColor = () => isDark() ? '#94a3b8' : '#64748b';

// ── Department chart ──────────────────────────────────────────────────────────
<?php if (!empty($deptChart)): ?>
const deptData = <?= json_encode($deptChart) ?>;
new Chart(document.getElementById('deptChart'), {
    type: 'bar',
    data: {
        labels: deptData.map(d => d.dept),
        datasets: [{
            label: 'Attendance %',
            data: deptData.map(d => d.total > 0 ? Math.round(d.attended / d.total * 100) : 0),
            backgroundColor: deptData.map(d => {
                const pct = d.total > 0 ? d.attended / d.total * 100 : 0;
                return pct >= <?= $thresholdGood ?> ? '#16a34a' : pct >= <?= (int)$t['warning'] ?> ? '#d97706' : '#dc2626';
            }),
            borderRadius: 6
        }]
    },
    options: {
        responsive: true, maintainAspectRatio: false,
        plugins: { legend: { display: false } },
        scales: {
            y: { beginAtZero: true, max: 100, grid: { color: gridColor() }, ticks: { color: textColor(), callback: v => v + '%' } },
            x: { grid: { display: false }, ticks: { color: textColor(), maxRotation: 30 } }
        }
    }
});
<?php endif; ?>

// ── Weekly trend chart ────────────────────────────────────────────────────────
<?php if (!empty($weekChart)): ?>
const weekData = <?= json_encode($weekChart) ?>;
new Chart(document.getElementById('weekChart'), {
    type: 'line',
    data: {
        labels: weekData.map(w => w.week_start),
        datasets: [{
            label: 'Attendance %',
            data: weekData.map(w => w.total > 0 ? Math.round(w.attended / w.total * 100) : 0),
            borderColor: '#6366f1', backgroundColor: 'rgba(99,102,241,.12)',
            borderWidth: 2.5, pointRadius: 4, fill: true, tension: 0.3
        }]
    },
    options: {
        responsive: true, maintainAspectRatio: false,
        plugins: { legend: { display: false } },
        scales: {
            y: { beginAtZero: true, max: 100, grid: { color: gridColor() }, ticks: { color: textColor(), callback: v => v + '%' } },
            x: { grid: { display: false }, ticks: { color: textColor() } }
        }
    }
});
<?php endif; ?>

// System checks
navigator.mediaDevices && navigator.mediaDevices.getUserMedia({video:true})
    .then(()=>{ let e=document.getElementById('camStatus'); e.textContent='Available'; e.style.cssText='background:#dcfce7;color:#15803d'; })
    .catch(()=>{ let e=document.getElementById('camStatus'); e.textContent='Not Available'; e.style.cssText='background:#fee2e2;color:#b91c1c'; });

navigator.geolocation ? navigator.geolocation.getCurrentPosition(
    ()=>{ let e=document.getElementById('geoStatus'); e.textContent='Enabled'; e.style.cssText='background:#dcfce7;color:#15803d'; },
    ()=>{ let e=document.getElementById('geoStatus'); e.textContent='Denied'; e.style.cssText='background:#fee2e2;color:#b91c1c'; }
) : (document.getElementById('geoStatus').textContent='Not Supported');

// Setup warnings check
fetch('/attendance-system/api/setup_check.php')
    .then(r => r.json())
    .then(data => {
        if (!data.warnings || data.warnings.length === 0) return;
        const container = document.createElement('div');
        container.id = 'setupWarnings';
        container.style.cssText = 'margin-bottom:1rem';
        data.warnings.forEach(w => {
            const colors = { critical: ['#fee2e2','#b91c1c','#dc2626'], warning: ['#fef3c7','#b45309','#d97706'] };
            const [bg, fg, border] = colors[w.type] || colors.warning;
            const div = document.createElement('div');
            div.style.cssText = `background:${bg};border-left:4px solid ${border};border-radius:0 8px 8px 0;padding:.75rem 1rem;margin-bottom:.5rem;display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:.5rem`;
            div.innerHTML = `<span style="color:${fg}">${w.icon} <strong>${w.title}</strong> — ${w.message}</span>
                <a href="${w.action.url}" class="btn btn-sm" style="background:${border};color:#fff;border:none;flex-shrink:0">${w.action.label} →</a>`;
            container.appendChild(div);
        });
        // Insert after h1
        const h1 = document.querySelector('h1');
        if (h1) h1.parentNode.insertBefore(container, h1.nextSibling);
    })
    .catch(() => {}); // silent fail - don't block dashboard
</script>

<?php include '../includes/footer.php'; ?>
