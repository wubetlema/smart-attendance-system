<?php
require_once '../includes/auth_check.php';
requireRole('admin');
require_once '../config/db.php';
require_once '../includes/attendance_calculator.php';
require_once '../includes/functions.php';
require_once '../includes/pdf_generator.php';

$reportType = $_GET['type']    ?? 'student';
$format     = $_GET['format']  ?? '';
$classId    = (int)($_GET['class_id']    ?? 0);
$deptId     = (int)($_GET['dept_id']     ?? 0);
$semesterId = (int)($_GET['semester_id'] ?? 0);
$dateFrom   = $_GET['date_from'] ?? date('Y-m-d', strtotime('-30 days'));
$dateTo     = $_GET['date_to']   ?? date('Y-m-d');
$threshold  = (int)(AttendanceCalculator::thresholds()['good']);

// ── Build report data ─────────────────────────────────────────────────────────
function buildStudentReport($conn, $classId, $deptId, $dateFrom, $dateTo) {
    $where = "1=1";
    if ($classId) $where .= " AND s.class_id=$classId";
    if ($deptId)  $where .= " AND c.department_id=$deptId";
    $where .= " AND DATE(a.marked_at) BETWEEN '$dateFrom' AND '$dateTo'";

    return $conn->query("
        SELECT
            u.university_id, u.name AS student_name, u.email, u.department,
            c.name AS class_name, c.subject, c.course_code,
            COUNT(*) AS total,
            SUM(a.status='present') AS present,
            SUM(a.status='late')    AS late,
            SUM(a.status='absent')  AS absent,
            ROUND(SUM(a.status IN ('present','late')) / COUNT(*) * 100, 1) AS pct
        FROM attendance a
        JOIN users u ON a.student_id=u.id
        JOIN attendance_sessions s ON a.session_id=s.id
        JOIN classes c ON s.class_id=c.id
        WHERE $where
        GROUP BY u.id, c.id
        ORDER BY u.name, c.name
    ")->fetch_all(MYSQLI_ASSOC);
}

function buildCourseReport($conn, $classId, $deptId, $dateFrom, $dateTo) {
    $where = "1=1";
    if ($classId) $where .= " AND c.id=$classId";
    if ($deptId)  $where .= " AND c.department_id=$deptId";
    $where .= " AND DATE(a.marked_at) BETWEEN '$dateFrom' AND '$dateTo'";

    return $conn->query("
        SELECT
            c.course_code, c.name AS class_name, c.subject,
            u.name AS teacher_name,
            COUNT(DISTINCT a.student_id) AS total_students,
            COUNT(*) AS total_records,
            SUM(a.status='present') AS present,
            SUM(a.status='late')    AS late,
            SUM(a.status='absent')  AS absent,
            ROUND(SUM(a.status IN ('present','late')) / COUNT(*) * 100, 1) AS pct
        FROM attendance a
        JOIN attendance_sessions s ON a.session_id=s.id
        JOIN classes c ON s.class_id=c.id
        JOIN users u ON c.teacher_id=u.id
        WHERE $where
        GROUP BY c.id
        ORDER BY pct DESC
    ")->fetch_all(MYSQLI_ASSOC);
}

function buildAtRiskReport($conn, $threshold, $deptId) {
    $deptWhere = $deptId ? "AND c.department_id=$deptId" : '';
    return $conn->query("
        SELECT
            u.university_id, u.name AS student_name, u.email, u.department,
            c.name AS class_name, c.subject, u.name AS teacher_name,
            COUNT(*) AS total,
            SUM(a.status='present') AS present,
            SUM(a.status='late')    AS late,
            SUM(a.status='absent')  AS absent,
            ROUND(SUM(a.status IN ('present','late')) / COUNT(*) * 100, 1) AS pct,
            tc.name AS teacher
        FROM attendance a
        JOIN users u ON a.student_id=u.id
        JOIN attendance_sessions s ON a.session_id=s.id
        JOIN classes c ON s.class_id=c.id
        JOIN users tc ON c.teacher_id=tc.id
        WHERE 1=1 $deptWhere
        GROUP BY u.id, c.id
        HAVING total >= 3 AND pct < $threshold
        ORDER BY pct ASC
    ")->fetch_all(MYSQLI_ASSOC);
}

function buildDeptReport($conn, $dateFrom, $dateTo) {
    return $conn->query("
        SELECT
            d.name AS dept_name, d.short_name,
            COUNT(DISTINCT u.id) AS students,
            COUNT(*) AS total_records,
            SUM(a.status='present') AS present,
            SUM(a.status='late')    AS late,
            SUM(a.status='absent')  AS absent,
            ROUND(SUM(a.status IN ('present','late')) / NULLIF(COUNT(*),0) * 100, 1) AS pct
        FROM attendance a
        JOIN users u ON a.student_id=u.id
        JOIN attendance_sessions s ON a.session_id=s.id
        JOIN classes c ON s.class_id=c.id
        LEFT JOIN departments d ON c.department_id=d.id
        WHERE DATE(a.marked_at) BETWEEN '$dateFrom' AND '$dateTo'
        GROUP BY d.id
        ORDER BY pct DESC
    ")->fetch_all(MYSQLI_ASSOC);
}

// Generate data based on type
$data = match($reportType) {
    'student'    => buildStudentReport($conn, $classId, $deptId, $dateFrom, $dateTo),
    'course'     => buildCourseReport($conn, $classId, $deptId, $dateFrom, $dateTo),
    'at_risk'    => buildAtRiskReport($conn, $threshold, $deptId),
    'department' => buildDeptReport($conn, $dateFrom, $dateTo),
    default      => [],
};

// ── Export CSV ────────────────────────────────────────────────────────────────
if ($format === 'csv' && !empty($data)) {
    $filename = "report_{$reportType}_" . date('Ymd_His') . ".csv";
    header('Content-Type: text/csv');
    header("Content-Disposition: attachment; filename=\"$filename\"");
    $out = fopen('php://output', 'w');
    if (!empty($data)) {
        fputcsv($out, array_keys($data[0]));
        foreach ($data as $row) fputcsv($out, array_values($row));
    }
    fclose($out);
    exit;
}

// ── Export Excel (HTML table) ─────────────────────────────────────────────────
if ($format === 'excel' && !empty($data)) {
    $filename = "report_{$reportType}_" . date('Ymd_His') . ".xls";
    header('Content-Type: application/vnd.ms-excel');
    header("Content-Disposition: attachment; filename=\"$filename\"");
    echo '<table border="1"><thead><tr>';
    foreach (array_keys($data[0]) as $col) echo '<th>' . htmlspecialchars(ucwords(str_replace('_',' ',$col))) . '</th>';
    echo '</tr></thead><tbody>';
    foreach ($data as $row) {
        echo '<tr>';
        foreach ($row as $v) echo '<td>' . htmlspecialchars($v ?? '') . '</td>';
        echo '</tr>';
    }
    echo '</tbody></table>';
    exit;
}

// ── Export PDF (print-ready HTML) ─────────────────────────────────────────────
if ($format === 'pdf' && !empty($data)) {
    $filters = [
        'date_from' => $dateFrom,
        'date_to'   => $dateTo,
        'dept'      => $deptId  ? $conn->query("SELECT name FROM departments WHERE id=$deptId")->fetch_assoc()['name'] ?? '' : 'All',
        'course'    => $classId ? $conn->query("SELECT name FROM classes WHERE id=$classId")->fetch_assoc()['name'] ?? '' : 'All',
    ];
    match($reportType) {
        'student'    => PdfReport::studentReport($data, $filters),
        'course'     => PdfReport::courseReport($data, $filters),
        'at_risk'    => PdfReport::atRiskReport($data, $threshold),
        'department' => PdfReport::departmentReport($data, $dateFrom, $dateTo),
        default      => PdfReport::generate(ucfirst($reportType).' Report', array_keys($data[0] ?? []), array_map('array_values', $data)),
    };
    exit;
}

// ── Filter options ────────────────────────────────────────────────────────────
$classes = $conn->query("SELECT id, name, subject, course_code FROM classes WHERE is_active=1 ORDER BY name")->fetch_all(MYSQLI_ASSOC);
$depts   = $conn->query("SELECT id, name FROM departments ORDER BY name")->fetch_all(MYSQLI_ASSOC);

include '../includes/header.php';
?>

<h1>📊 Reports</h1>

<!-- Filters -->
<div class="card" style="margin-bottom:1.25rem">
    <form method="GET" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:.75rem;align-items:flex-end">
        <div class="form-group" style="margin:0">
            <label>Report Type</label>
            <select name="type" onchange="this.form.submit()">
                <?php foreach (['student'=>'Student Attendance','course'=>'Course Attendance','at_risk'=>'At-Risk Students','department'=>'Department Summary'] as $v=>$l): ?>
                <option value="<?= $v ?>" <?= $reportType===$v?'selected':'' ?>><?= $l ?></option>
                <?php endforeach; ?>
            </select>
        </div>

        <?php if (in_array($reportType, ['student','course'])): ?>
        <div class="form-group" style="margin:0">
            <label>Course Filter</label>
            <select name="class_id">
                <option value="">All Courses</option>
                <?php foreach ($classes as $c): ?>
                <option value="<?= $c['id'] ?>" <?= $classId===$c['id']?'selected':'' ?>>
                    <?= htmlspecialchars(($c['course_code']?'['.$c['course_code'].'] ':'').$c['name']) ?>
                </option>
                <?php endforeach; ?>
            </select>
        </div>
        <?php endif; ?>

        <div class="form-group" style="margin:0">
            <label>Department</label>
            <select name="dept_id">
                <option value="">All Departments</option>
                <?php foreach ($depts as $d): ?>
                <option value="<?= $d['id'] ?>" <?= $deptId===$d['id']?'selected':'' ?>><?= htmlspecialchars($d['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>

        <?php if ($reportType !== 'at_risk'): ?>
        <div class="form-group" style="margin:0">
            <label>From Date</label>
            <input type="date" name="date_from" value="<?= $dateFrom ?>">
        </div>
        <div class="form-group" style="margin:0">
            <label>To Date</label>
            <input type="date" name="date_to" value="<?= $dateTo ?>">
        </div>
        <?php endif; ?>

        <div style="display:flex;gap:.4rem;align-items:flex-end">
            <button type="submit" class="btn btn-primary">Generate</button>
            <a href="?type=<?= $reportType ?>" class="btn btn-secondary">Reset</a>
        </div>
    </form>
</div>

<!-- Export buttons -->
<?php if (!empty($data)): ?>
<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:.75rem;flex-wrap:wrap;gap:.5rem">
    <p class="text-muted" style="margin:0"><strong><?= count($data) ?></strong> record(s) found</p>
    <div style="display:flex;gap:.5rem">
        <a href="?<?= http_build_query(array_merge($_GET, ['format'=>'csv'])) ?>"
           class="btn btn-secondary btn-sm">⬇ Export CSV</a>
        <a href="?<?= http_build_query(array_merge($_GET, ['format'=>'excel'])) ?>"
           class="btn btn-secondary btn-sm">⬇ Export Excel</a>
        <a href="?<?= http_build_query(array_merge($_GET, ['format'=>'pdf'])) ?>"
           class="btn btn-secondary btn-sm" target="_blank"
           style="background:#dc2626;color:#fff;border:none">🖨️ Export PDF</a>
    </div>
</div>
<?php endif; ?>

<!-- Report Table -->
<?php if (empty($data)): ?>
<div class="card" style="text-align:center;padding:2.5rem">
    <div style="font-size:2.5rem;margin-bottom:.75rem">📊</div>
    <h3>No data found</h3>
    <p class="text-muted">Adjust your filters and generate the report.</p>
</div>

<?php elseif ($reportType === 'student'): ?>
<div class="card" style="padding:0">
<div style="overflow-x:auto">
<table class="table" style="margin:0">
    <thead><tr><th>Student</th><th>University ID</th><th>Course</th><th>Present</th><th>Late</th><th>Absent</th><th>Total</th><th>Attendance %</th></tr></thead>
    <tbody>
    <?php foreach ($data as $r):
        $pct = (float)$r['pct'];
        $st  = AttendanceCalculator::status($pct);
    ?>
    <tr>
        <td><strong><?= htmlspecialchars($r['student_name']) ?></strong><br><small class="text-muted"><?= htmlspecialchars($r['department']??'') ?></small></td>
        <td><code><?= htmlspecialchars($r['university_id']??'') ?></code></td>
        <td><?= htmlspecialchars($r['class_name']) ?><br><small class="text-muted"><?= htmlspecialchars($r['subject']) ?></small></td>
        <td style="color:#16a34a;font-weight:600"><?= $r['present'] ?></td>
        <td style="color:#d97706"><?= $r['late'] ?></td>
        <td style="color:#dc2626"><?= $r['absent'] ?></td>
        <td><?= $r['total'] ?></td>
        <td>
            <div style="display:flex;align-items:center;gap:.5rem">
                <div style="flex:1;height:6px;background:#e2e8f0;border-radius:3px;min-width:50px">
                    <div style="height:100%;width:<?= min(100,$pct) ?>%;background:<?= $st['color'] ?>;border-radius:3px"></div>
                </div>
                <span style="font-weight:700;color:<?= $st['color'] ?>;font-size:.88rem"><?= $pct ?>%</span>
                <span><?= $st['icon'] ?></span>
            </div>
        </td>
    </tr>
    <?php endforeach; ?>
    </tbody>
</table>
</div></div>

<?php elseif ($reportType === 'course'): ?>
<div class="card" style="padding:0">
<div style="overflow-x:auto">
<table class="table" style="margin:0">
    <thead><tr><th>Code</th><th>Course</th><th>Teacher</th><th>Students</th><th>Present</th><th>Late</th><th>Absent</th><th>Attendance %</th></tr></thead>
    <tbody>
    <?php foreach ($data as $r):
        $pct = (float)$r['pct'];
        $st  = AttendanceCalculator::status($pct);
    ?>
    <tr>
        <td><code><?= htmlspecialchars($r['course_code']??'–') ?></code></td>
        <td><strong><?= htmlspecialchars($r['class_name']) ?></strong><br><small class="text-muted"><?= htmlspecialchars($r['subject']) ?></small></td>
        <td><?= htmlspecialchars($r['teacher_name']) ?></td>
        <td><?= $r['total_students'] ?></td>
        <td style="color:#16a34a;font-weight:600"><?= $r['present'] ?></td>
        <td style="color:#d97706"><?= $r['late'] ?></td>
        <td style="color:#dc2626"><?= $r['absent'] ?></td>
        <td>
            <div style="display:flex;align-items:center;gap:.5rem">
                <div style="flex:1;height:6px;background:#e2e8f0;border-radius:3px;min-width:50px">
                    <div style="height:100%;width:<?= min(100,$pct) ?>%;background:<?= $st['color'] ?>;border-radius:3px"></div>
                </div>
                <span style="font-weight:700;color:<?= $st['color'] ?>"><?= $pct ?>%</span>
                <span><?= $st['icon'] ?></span>
            </div>
        </td>
    </tr>
    <?php endforeach; ?>
    </tbody>
</table>
</div></div>

<?php elseif ($reportType === 'at_risk'): ?>
<div class="alert alert-error" style="margin-bottom:.75rem">
    🚨 <strong><?= count($data) ?> student(s)</strong> are below the <?= $threshold ?>% attendance threshold.
</div>
<div class="card" style="padding:0">
<div style="overflow-x:auto">
<table class="table" style="margin:0">
    <thead><tr><th>Student</th><th>University ID</th><th>Course</th><th>Teacher</th><th>Present</th><th>Absent</th><th>Total</th><th>Attendance %</th></tr></thead>
    <tbody>
    <?php foreach ($data as $r):
        $pct = (float)$r['pct'];
        $st  = AttendanceCalculator::status($pct);
    ?>
    <tr style="background:<?= $pct < 50 ? '#fff5f5' : '#fffbeb' ?>">
        <td><strong><?= htmlspecialchars($r['student_name']) ?></strong><br><small><?= htmlspecialchars($r['department']??'') ?></small></td>
        <td><code><?= htmlspecialchars($r['university_id']??'') ?></code></td>
        <td><?= htmlspecialchars($r['class_name']) ?></td>
        <td><?= htmlspecialchars($r['teacher']??'') ?></td>
        <td style="color:#16a34a;font-weight:600"><?= $r['present'] ?></td>
        <td style="color:#dc2626;font-weight:600"><?= $r['absent'] ?></td>
        <td><?= $r['total'] ?></td>
        <td><span style="font-weight:800;color:<?= $st['color'] ?>;font-size:1rem"><?= $pct ?>% <?= $st['icon'] ?></span></td>
    </tr>
    <?php endforeach; ?>
    </tbody>
</table>
</div></div>

<?php elseif ($reportType === 'department'): ?>
<div class="card" style="padding:0">
<div style="overflow-x:auto">
<table class="table" style="margin:0">
    <thead><tr><th>Department</th><th>Students</th><th>Present</th><th>Late</th><th>Absent</th><th>Total Records</th><th>Attendance %</th></tr></thead>
    <tbody>
    <?php foreach ($data as $r):
        $pct = (float)$r['pct'];
        $st  = AttendanceCalculator::status($pct);
    ?>
    <tr>
        <td><strong><?= htmlspecialchars($r['dept_name']??'Unknown') ?></strong>
            <?php if ($r['short_name']): ?><code style="font-size:.75rem;margin-left:.3rem"><?= htmlspecialchars($r['short_name']) ?></code><?php endif; ?></td>
        <td><?= $r['students'] ?></td>
        <td style="color:#16a34a;font-weight:600"><?= $r['present'] ?></td>
        <td style="color:#d97706"><?= $r['late'] ?></td>
        <td style="color:#dc2626"><?= $r['absent'] ?></td>
        <td><?= $r['total_records'] ?></td>
        <td>
            <div style="display:flex;align-items:center;gap:.5rem">
                <div style="flex:1;height:8px;background:#e2e8f0;border-radius:4px;min-width:60px">
                    <div style="height:100%;width:<?= min(100,$pct) ?>%;background:<?= $st['color'] ?>;border-radius:4px"></div>
                </div>
                <span style="font-weight:700;color:<?= $st['color'] ?>"><?= $pct ?>% <?= $st['icon'] ?></span>
            </div>
        </td>
    </tr>
    <?php endforeach; ?>
    </tbody>
</table>
</div></div>
<?php endif; ?>

<?php include '../includes/footer.php'; ?>
