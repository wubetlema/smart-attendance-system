<?php
require_once '../includes/auth_check.php';
requireRole('teacher');
require_once '../config/db.php';
require_once '../includes/attendance_calculator.php';
$user = currentUser();

// ── My classes with per-class stats ───────────────────────────────────────────
$classStmt = $conn->prepare("
    SELECT c.*,
           COUNT(DISTINCT cs.student_id) AS enrolled,
           COUNT(DISTINCT s.id)          AS total_sessions
    FROM classes c
    LEFT JOIN class_students cs ON cs.class_id=c.id AND cs.status='active'
    LEFT JOIN attendance_sessions s ON s.class_id=c.id
    WHERE c.teacher_id=?
    GROUP BY c.id
    ORDER BY c.name
");
$classStmt->bind_param('i', $user['id']);
$classStmt->execute();
$classes = $classStmt->get_result()->fetch_all(MYSQLI_ASSOC);

// ── Today's sessions ──────────────────────────────────────────────────────────
$todaySessions = $conn->prepare("
    SELECT s.id, s.status, s.open_at, s.present_until, s.late_until,
           c.name AS class_name, c.subject,
           COUNT(a.id) AS checked_in
    FROM attendance_sessions s
    JOIN classes c ON s.class_id=c.id
    LEFT JOIN attendance a ON a.session_id=s.id
    WHERE s.teacher_id=? AND DATE(s.open_at)=CURDATE()
    GROUP BY s.id
    ORDER BY s.open_at ASC
");
$todaySessions->bind_param('i', $user['id']);
$todaySessions->execute();
$todaySessionRows = $todaySessions->get_result()->fetch_all(MYSQLI_ASSOC);

// ── Thresholds ────────────────────────────────────────────────────────────────
$t = AttendanceCalculator::thresholds();
$thresholdGood = (int)$t['good'];

// ── At-risk students (below threshold) ───────────────────────────────────────
$atRiskRows = $conn->query("
    SELECT u.id, u.name, u.university_id, c.name AS class_name, c.id AS class_id,
           COUNT(a.id) AS total,
           SUM(a.status IN ('present','late')) AS attended,
           ROUND(SUM(a.status IN ('present','late')) / NULLIF(COUNT(a.id),0) * 100, 1) AS pct
    FROM class_students cs
    JOIN users u ON cs.student_id=u.id
    JOIN classes c ON cs.class_id=c.id
    JOIN attendance_sessions s ON s.class_id=c.id
    JOIN attendance a ON a.session_id=s.id AND a.student_id=cs.student_id
    WHERE c.teacher_id={$user['id']}
    GROUP BY u.id, c.id
    HAVING total >= 3 AND pct < $thresholdGood
    ORDER BY pct ASC
    LIMIT 10
")->fetch_all(MYSQLI_ASSOC);

// ── Today's absent/late counts across all classes ─────────────────────────────
$todayAbsent = (int)$conn->query("
    SELECT COUNT(*) c FROM attendance a
    JOIN attendance_sessions s ON a.session_id=s.id
    JOIN classes c ON s.class_id=c.id
    WHERE c.teacher_id={$user['id']}
      AND DATE(a.marked_at)=CURDATE() AND a.status='absent'
")->fetch_assoc()['c'];

$todayLate = (int)$conn->query("
    SELECT COUNT(*) c FROM attendance a
    JOIN attendance_sessions s ON a.session_id=s.id
    JOIN classes c ON s.class_id=c.id
    WHERE c.teacher_id={$user['id']}
      AND DATE(a.marked_at)=CURDATE() AND a.status='late'
")->fetch_assoc()['c'];

$todayPresent = (int)$conn->query("
    SELECT COUNT(*) c FROM attendance a
    JOIN attendance_sessions s ON a.session_id=s.id
    JOIN classes c ON s.class_id=c.id
    WHERE c.teacher_id={$user['id']}
      AND DATE(a.marked_at)=CURDATE() AND a.status='present'
")->fetch_assoc()['c'];

// ── Overall average across my classes ─────────────────────────────────────────
$avgRow = $conn->query("
    SELECT ROUND(SUM(a.status IN ('present','late')) / NULLIF(COUNT(*),0) * 100, 1) AS avg_pct
    FROM attendance a
    JOIN attendance_sessions s ON a.session_id=s.id
    JOIN classes c ON s.class_id=c.id
    WHERE c.teacher_id={$user['id']}
")->fetch_assoc();
$avgPct = (float)($avgRow['avg_pct'] ?? 0);

// ── Pending correction requests ───────────────────────────────────────────────
$pendingCorrections = (int)$conn->query("
    SELECT COUNT(*) c FROM attendance_corrections cr
    JOIN classes c ON cr.class_id=c.id
    WHERE c.teacher_id={$user['id']} AND cr.status='pending'
")->fetch_assoc()['c'];

// ── Chart: per-class attendance % ─────────────────────────────────────────────
$classChartData = [];
foreach ($classes as $c) {
    $row = $conn->query("
        SELECT ROUND(SUM(a.status IN ('present','late')) / NULLIF(COUNT(*),0) * 100, 1) AS pct
        FROM attendance a
        JOIN attendance_sessions s ON a.session_id=s.id
        WHERE s.class_id={$c['id']}
    ")->fetch_assoc();
    $classChartData[] = [
        'name' => $c['name'],
        'pct'  => (float)($row['pct'] ?? 0),
    ];
}

// ── Next timetable class today ────────────────────────────────────────────────
$nextClass = $conn->query("
    SELECT s.start_time, s.end_time, s.room, c.name AS class_name, c.subject
    FROM schedules s
    JOIN classes c ON s.class_id=c.id
    WHERE c.teacher_id={$user['id']}
      AND s.day_of_week='".date('l')."'
      AND s.end_time > '".date('H:i:s')."'
    ORDER BY s.start_time ASC LIMIT 1
")->fetch_assoc();

include '../includes/header.php';
?>

<h1>Teacher Dashboard</h1>
<p class="text-muted" style="margin-top:-.5rem;margin-bottom:1.5rem">
    Welcome, <?= htmlspecialchars($user['name']) ?> &mdash; <?= date('l, d F Y') ?>
</p>

<!-- ── Alerts ─────────────────────────────────────────────────────────────── -->
<?php if (!empty($atRiskRows)): ?>
<div class="alert alert-error" style="justify-content:space-between;flex-wrap:wrap;gap:.5rem">
    <span>⚠️ <strong><?= count($atRiskRows) ?> student(s)</strong> are below <?= $thresholdGood ?>% attendance in your classes.</span>
    <a href="at_risk_students.php" class="btn btn-sm btn-danger">View List</a>
</div>
<?php endif; ?>
<?php if ($pendingCorrections > 0): ?>
<div class="alert alert-warning" style="justify-content:space-between;flex-wrap:wrap;gap:.5rem">
    <span>📝 <strong><?= $pendingCorrections ?> correction request(s)</strong> need your review.</span>
    <a href="correction_requests.php" class="btn btn-sm btn-warning">Review Now</a>
</div>
<?php endif; ?>

<!-- ── KPI Stats ───────────────────────────────────────────────────────────── -->
<div class="stats-grid">
    <div class="stat-card">
        <div class="stat-number"><?= count($classes) ?></div>
        <div class="stat-label">My Classes</div>
    </div>
    <div class="stat-card">
        <div class="stat-number"><?= count($todaySessionRows) ?></div>
        <div class="stat-label">Sessions Today</div>
    </div>
    <div class="stat-card" style="border-color:<?= $avgPct >= $thresholdGood ? '#16a34a' : '#d97706' ?>">
        <div class="stat-number" style="color:<?= $avgPct >= $thresholdGood ? '#16a34a' : '#d97706' ?>"><?= $avgPct ?>%</div>
        <div class="stat-label">Avg Attendance</div>
    </div>
    <div class="stat-card" style="border-color:#16a34a">
        <div class="stat-number" style="color:#16a34a"><?= $todayPresent ?></div>
        <div class="stat-label">Present Today</div>
    </div>
    <div class="stat-card" style="border-color:#d97706">
        <div class="stat-number" style="color:#d97706"><?= $todayLate ?></div>
        <div class="stat-label">Late Today</div>
    </div>
    <div class="stat-card" style="border-color:#dc2626">
        <div class="stat-number" style="color:#dc2626"><?= count($atRiskRows) ?></div>
        <div class="stat-label">At-Risk Students</div>
    </div>
</div>

<!-- ── Quick Links ────────────────────────────────────────────────────────── -->
<div class="quick-links">
    <a href="qr_generate.php"           class="btn btn-primary">📷 Start QR Session</a>
    <a href="mark_attendance.php"       class="btn btn-secondary">✏️ Mark Attendance</a>
    <a href="correction_requests.php"   class="btn btn-secondary">📝 Corrections<?= $pendingCorrections ? " ($pendingCorrections)" : '' ?></a>
    <a href="at_risk_students.php"      class="btn btn-secondary">⚠️ At-Risk</a>
    <a href="reports.php"               class="btn btn-secondary">📊 Reports</a>
    <a href="leaves.php"                class="btn btn-secondary">📋 Leaves</a>
    <a href="announcements.php"         class="btn btn-secondary">📢 Announcements</a>
</div>

<div style="display:grid;grid-template-columns:1.4fr 1fr;gap:1.5rem;margin-bottom:1.5rem">

<!-- My Classes with attendance bars -->
<div class="card">
    <h3>My Classes</h3>
    <?php if (empty($classes)): ?>
        <p class="text-muted">No classes assigned yet.</p>
    <?php else: ?>
    <div style="display:flex;flex-direction:column;gap:.85rem">
    <?php foreach ($classes as $c):
        $cd = array_filter($classChartData, fn($x) => $x['name'] === $c['name']);
        $cd = reset($cd);
        $pct = $cd ? $cd['pct'] : 0;
        $st  = AttendanceCalculator::status($pct ?: null);
    ?>
    <div style="padding:.85rem 1rem;border-radius:9px;border:1px solid var(--border);background:var(--card)">
        <div style="display:flex;justify-content:space-between;align-items:flex-start;flex-wrap:wrap;gap:.5rem;margin-bottom:.5rem">
            <div>
                <strong><?= htmlspecialchars($c['name']) ?></strong>
                <span class="text-muted"> — <?= htmlspecialchars($c['subject']) ?></span>
                <br>
                <small class="text-muted"><?= $c['enrolled'] ?> enrolled · <?= $c['total_sessions'] ?> sessions</small>
            </div>
            <div style="display:flex;gap:.3rem;flex-wrap:wrap">
                <a href="qr_generate.php?class_id=<?= $c['id'] ?>" class="btn btn-primary btn-sm">QR</a>
                <a href="mark_attendance.php?class_id=<?= $c['id'] ?>" class="btn btn-secondary btn-sm">Mark</a>
                <a href="at_risk_students.php?class_id=<?= $c['id'] ?>" class="btn btn-sm" style="background:#fef3c7;color:#b45309">At-Risk</a>
            </div>
        </div>
        <?php if ($pct > 0): ?>
        <div style="display:flex;align-items:center;gap:.75rem">
            <div style="flex:1">
                <div class="progress-wrap"><div class="progress-bar" style="width:<?= $pct ?>%;background:<?= $st['color'] ?>"></div></div>
            </div>
            <span style="font-weight:700;color:<?= $st['color'] ?>;font-size:.9rem;min-width:45px"><?= $pct ?>%</span>
            <span><?= $st['icon'] ?></span>
        </div>
        <?php endif; ?>
    </div>
    <?php endforeach; ?>
    </div>
    <?php endif; ?>
</div>

<!-- Right column -->
<div>

<!-- Today's sessions -->
<div class="card">
    <h3>Today's Sessions</h3>
    <?php if (empty($todaySessionRows)): ?>
    <p class="text-muted">No sessions today yet.
        <?= $nextClass ? 'Next class: <strong>' . htmlspecialchars($nextClass['class_name']) . '</strong> at ' . substr($nextClass['start_time'],0,5) : '' ?>
    </p>
    <?php else: ?>
    <?php
    $statusColors = ['open'=>['#dcfce7','#15803d','Open'],'late'=>['#fef3c7','#b45309','Late Period'],'closed'=>['#fee2e2','#b91c1c','Closed'],'not_started'=>['#f1f5f9','#475569','Not Started']];
    foreach ($todaySessionRows as $ts):
        [$sbg,$sfg,$slabel] = $statusColors[$ts['status']] ?? ['#f1f5f9','#475569','Unknown'];
    ?>
    <div style="display:flex;justify-content:space-between;align-items:center;padding:.6rem 0;border-bottom:1px solid var(--border);flex-wrap:wrap;gap:.4rem">
        <div>
            <strong><?= htmlspecialchars($ts['class_name']) ?></strong>
            <br><small class="text-muted"><?= $ts['open_at'] ? date('H:i', strtotime($ts['open_at'])) : '' ?> · <?= $ts['checked_in'] ?> checked in</small>
        </div>
        <div style="display:flex;align-items:center;gap:.4rem">
            <span class="badge" style="background:<?= $sbg ?>;color:<?= $sfg ?>"><?= $slabel ?></span>
            <?php if (in_array($ts['status'], ['open','late','not_started'])): ?>
            <a href="qr_generate.php?class_id=<?= $ts['id'] ?>&session_id=<?= $ts['id'] ?>" class="btn btn-primary btn-sm">View QR</a>
            <?php endif; ?>
        </div>
    </div>
    <?php endforeach; ?>
    <?php endif; ?>
</div>

<!-- At-risk students -->
<?php if (!empty($atRiskRows)): ?>
<div class="card">
    <h3>⚠️ At-Risk Students</h3>
    <div style="display:flex;flex-direction:column;gap:.5rem">
    <?php foreach (array_slice($atRiskRows, 0, 5) as $ar):
        $st = AttendanceCalculator::status((float)$ar['pct']);
    ?>
    <div style="display:flex;justify-content:space-between;align-items:center;padding:.5rem 0;border-bottom:1px solid var(--border)">
        <div>
            <strong style="font-size:.88rem"><?= htmlspecialchars($ar['name']) ?></strong>
            <br><small class="text-muted"><?= htmlspecialchars($ar['class_name']) ?></small>
        </div>
        <span style="font-weight:700;color:<?= $st['color'] ?>"><?= $ar['pct'] ?>%</span>
    </div>
    <?php endforeach; ?>
    </div>
    <?php if (count($atRiskRows) > 5): ?>
    <a href="at_risk_students.php" class="btn btn-secondary btn-sm" style="margin-top:.5rem">View all <?= count($atRiskRows) ?> →</a>
    <?php endif; ?>
</div>
<?php endif; ?>

</div><!-- end right col -->
</div>

<!-- Attendance % chart by class -->
<?php if (!empty($classChartData) && count($classChartData) > 1): ?>
<div class="card">
    <h3>Attendance % by Class</h3>
    <canvas id="classChart" height="160"></canvas>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<script>
const classData = <?= json_encode($classChartData) ?>;
const threshGood = <?= $thresholdGood ?>;
new Chart(document.getElementById('classChart'), {
    type: 'bar',
    data: {
        labels: classData.map(d => d.name),
        datasets: [{
            label: 'Attendance %',
            data: classData.map(d => d.pct),
            backgroundColor: classData.map(d => d.pct >= threshGood ? '#16a34a' : d.pct >= <?= (int)$t['warning'] ?> ? '#d97706' : '#dc2626'),
            borderRadius: 6
        }]
    },
    options: {
        responsive: true, maintainAspectRatio: false,
        plugins: { legend: { display: false } },
        scales: {
            y: { beginAtZero: true, max: 100, ticks: { callback: v => v + '%' } },
            x: { grid: { display: false } }
        }
    }
});
</script>
<?php endif; ?>

<?php include '../includes/footer.php'; ?>
