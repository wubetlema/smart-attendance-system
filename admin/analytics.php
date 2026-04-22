<?php
require_once '../includes/auth_check.php';
requireRole('admin');
require_once '../config/db.php';

$filter_type = $_GET['type'] ?? 'daily';
$filter_date = $_GET['date'] ?? date('Y-m-d');
$filter_week = $_GET['week'] ?? date('Y-W');
$filter_month= $_GET['month'] ?? date('Y-m');

// Build date condition
if ($filter_type === 'daily') {
    $dateCond = "DATE(a.marked_at) = '$filter_date'";
} elseif ($filter_type === 'weekly') {
    [$yr,$wk] = explode('-W', $filter_week);
    $dateCond = "YEARWEEK(a.marked_at,1) = YEARWEEK('$yr-01-01' + INTERVAL ($wk-1) WEEK, 1)";
} else {
    $dateCond = "DATE_FORMAT(a.marked_at,'%Y-%m') = '$filter_month'";
}

// Student-wise attendance
$studentStats = $conn->query("
    SELECT u.name, u.university_id, u.department,
           COUNT(*) AS total,
           SUM(a.status='present') AS present,
           SUM(a.status='absent') AS absent,
           SUM(a.status='late') AS late,
           ROUND(SUM(a.status='present')/COUNT(*)*100) AS pct
    FROM attendance a
    JOIN users u ON a.student_id=u.id
    WHERE $dateCond
    GROUP BY u.id
    ORDER BY pct ASC
")->fetch_all(MYSQLI_ASSOC);

// Teacher-wise summary
$teacherStats = $conn->query("
    SELECT u.name AS teacher, c.name AS course, c.subject,
           COUNT(DISTINCT s.id) AS sessions,
           COUNT(a.id) AS total_records,
           ROUND(AVG(a.status='present')*100) AS avg_pct
    FROM attendance_sessions s
    JOIN classes c ON s.class_id=c.id
    JOIN users u ON c.teacher_id=u.id
    LEFT JOIN attendance a ON a.session_id=s.id
    GROUP BY s.teacher_id, c.id
    ORDER BY avg_pct ASC
")->fetch_all(MYSQLI_ASSOC);

// Department-wise
$deptStats = $conn->query("
    SELECT u.department,
           COUNT(DISTINCT u.id) AS students,
           COUNT(a.id) AS total,
           SUM(a.status='present') AS present,
           ROUND(SUM(a.status='present')/NULLIF(COUNT(a.id),0)*100) AS pct
    FROM users u
    LEFT JOIN attendance a ON a.student_id=u.id
    WHERE u.role='student'
    GROUP BY u.department
    ORDER BY pct DESC
")->fetch_all(MYSQLI_ASSOC);

// Low attendance students (<75%)
$lowAttendance = $conn->query("
    SELECT u.name, u.university_id, u.department, u.email,
           COUNT(*) AS total,
           SUM(a.status='present') AS present,
           ROUND(SUM(a.status='present')/COUNT(*)*100) AS pct
    FROM attendance a
    JOIN users u ON a.student_id=u.id
    GROUP BY u.id
    HAVING pct < 75
    ORDER BY pct ASC
")->fetch_all(MYSQLI_ASSOC);

include '../includes/header.php';
?>
<h1>Reporting & Analytics</h1>

<!-- Filter -->
<div class="card">
    <form method="GET" class="form-inline">
        <select name="type" onchange="this.form.submit()">
            <option value="daily"   <?= $filter_type==='daily'  ?'selected':'' ?>>Daily</option>
            <option value="weekly"  <?= $filter_type==='weekly' ?'selected':'' ?>>Weekly</option>
            <option value="monthly" <?= $filter_type==='monthly'?'selected':'' ?>>Monthly</option>
        </select>
        <?php if ($filter_type==='daily'): ?>
            <input type="date" name="date" value="<?= $filter_date ?>">
        <?php elseif ($filter_type==='weekly'): ?>
            <input type="week" name="week" value="<?= $filter_week ?>">
        <?php else: ?>
            <input type="month" name="month" value="<?= $filter_month ?>">
        <?php endif; ?>
        <button type="submit" class="btn btn-primary">Filter</button>
        <a href="?type=<?= $filter_type ?>&date=<?= $filter_date ?>&export=csv" class="btn btn-secondary">Export CSV</a>
        <a href="?type=<?= $filter_type ?>&date=<?= $filter_date ?>&export=print" class="btn btn-secondary" target="_blank">Print / PDF</a>
    </form>
</div>

<!-- Low Attendance Alert -->
<?php if ($lowAttendance): ?>
<div class="card" style="border-color:#dc2626">
    <h3 style="color:#dc2626">Students Below 75% Attendance (<?= count($lowAttendance) ?>)</h3>
    <table class="table">
        <thead><tr><th>Student</th><th>University ID</th><th>Department</th><th>Present</th><th>Total</th><th>Rate</th></tr></thead>
        <tbody>
        <?php foreach ($lowAttendance as $r):
            $color = $r['pct'] < 50 ? '#dc2626' : '#d97706';
        ?>
        <tr>
            <td><?= htmlspecialchars($r['name']) ?></td>
            <td><code><?= htmlspecialchars($r['university_id']??'-') ?></code></td>
            <td><?= htmlspecialchars($r['department']??'-') ?></td>
            <td><?= $r['present'] ?></td>
            <td><?= $r['total'] ?></td>
            <td><span style="color:<?= $color ?>;font-weight:700"><?= $r['pct'] ?>%</span></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php endif; ?>

<!-- Student-wise -->
<div class="card">
    <h3>Student Attendance (<?= ucfirst($filter_type) ?>)</h3>
    <?php if (empty($studentStats)): ?>
        <p class="text-muted">No data for selected period.</p>
    <?php else: ?>
    <table class="table">
        <thead><tr><th>Student</th><th>ID</th><th>Dept</th><th>Present</th><th>Absent</th><th>Late</th><th>Rate</th></tr></thead>
        <tbody>
        <?php foreach ($studentStats as $r):
            $color = $r['pct'] >= 75 ? '#16a34a' : ($r['pct'] >= 50 ? '#d97706' : '#dc2626');
        ?>
        <tr>
            <td><?= htmlspecialchars($r['name']) ?></td>
            <td><code><?= htmlspecialchars($r['university_id']??'-') ?></code></td>
            <td><?= htmlspecialchars($r['department']??'-') ?></td>
            <td><?= $r['present'] ?></td>
            <td><?= $r['absent'] ?></td>
            <td><?= $r['late'] ?></td>
            <td>
                <div style="display:flex;align-items:center;gap:.5rem">
                    <div style="flex:1;background:#e2e8f0;border-radius:10px;height:8px;min-width:60px">
                        <div style="width:<?= $r['pct'] ?>%;background:<?= $color ?>;height:8px;border-radius:10px"></div>
                    </div>
                    <span style="color:<?= $color ?>;font-weight:700;font-size:.85rem"><?= $r['pct'] ?>%</span>
                </div>
            </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>
</div>

<!-- Teacher-wise -->
<div class="card">
    <h3>Teacher / Course Summary</h3>
    <table class="table">
        <thead><tr><th>Teacher</th><th>Course</th><th>Sessions</th><th>Records</th><th>Avg Attendance</th></tr></thead>
        <tbody>
        <?php foreach ($teacherStats as $r):
            $color = ($r['avg_pct']??0) >= 75 ? '#16a34a' : '#d97706';
        ?>
        <tr>
            <td><?= htmlspecialchars($r['teacher']) ?></td>
            <td><?= htmlspecialchars($r['course']) ?> <small class="text-muted"><?= htmlspecialchars($r['subject']) ?></small></td>
            <td><?= $r['sessions'] ?></td>
            <td><?= $r['total_records'] ?></td>
            <td><span style="color:<?= $color ?>;font-weight:700"><?= $r['avg_pct']??0 ?>%</span></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>

<!-- Department chart -->
<div class="card">
    <h3>Department Comparison</h3>
    <?php if (empty($deptStats)): ?>
        <p class="text-muted">No data yet.</p>
    <?php else: ?>
    <div style="display:flex;align-items:flex-end;gap:1rem;height:160px;padding:.5rem 0;overflow-x:auto">
    <?php foreach ($deptStats as $d):
        $h = max(10, (int)($d['pct'] ?? 0));
        $color = $h >= 75 ? '#4f46e5' : ($h >= 50 ? '#d97706' : '#dc2626');
    ?>
    <div style="flex:1;min-width:80px;display:flex;flex-direction:column;align-items:center;gap:.25rem">
        <span style="font-size:.8rem;font-weight:700;color:<?= $color ?>"><?= $d['pct']??0 ?>%</span>
        <div style="width:100%;background:<?= $color ?>;border-radius:4px 4px 0 0;height:<?= $h ?>px"></div>
        <span style="font-size:.7rem;color:var(--text-muted);text-align:center"><?= htmlspecialchars($d['department']??'N/A') ?></span>
        <span style="font-size:.7rem;color:var(--text-muted)"><?= $d['students'] ?> students</span>
    </div>
    <?php endforeach; ?>
    </div>
    <?php endif; ?>
</div>

<?php
// CSV Export
if (isset($_GET['export']) && $_GET['export'] === 'csv' && $studentStats) {
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="attendance_report_'.date('Ymd').'.csv"');
    echo "Student,University ID,Department,Present,Absent,Late,Rate%\n";
    foreach ($studentStats as $r) {
        echo '"'.$r['name'].'","'.($r['university_id']??'').'","'.($r['department']??'').'",'.
             $r['present'].','.$r['absent'].','.$r['late'].','.$r['pct']."\n";
    }
    exit;
}

// Print view
if (isset($_GET['export']) && $_GET['export'] === 'print') {
    echo '<style>body{font-family:sans-serif;padding:2rem} table{width:100%;border-collapse:collapse} th,td{border:1px solid #ddd;padding:.5rem} @media print{.no-print{display:none}}</style>';
    echo '<h2>Attendance Report - '.ucfirst($filter_type).'</h2>';
    echo '<p>Generated: '.date('d M Y H:i').'</p>';
    echo '<button onclick="window.print()" class="no-print" style="margin-bottom:1rem;padding:.5rem 1rem">Print</button>';
    echo '<table><thead><tr><th>Student</th><th>ID</th><th>Dept</th><th>Present</th><th>Absent</th><th>Late</th><th>Rate%</th></tr></thead><tbody>';
    foreach ($studentStats as $r) {
        echo '<tr><td>'.htmlspecialchars($r['name']).'</td><td>'.htmlspecialchars($r['university_id']??'').'</td><td>'.htmlspecialchars($r['department']??'').'</td><td>'.$r['present'].'</td><td>'.$r['absent'].'</td><td>'.$r['late'].'</td><td>'.$r['pct'].'%</td></tr>';
    }
    echo '</tbody></table>';
    exit;
}

include '../includes/footer.php';
?>
