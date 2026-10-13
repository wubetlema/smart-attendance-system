<?php
require_once '../includes/auth_check.php';
requireRole('teacher');
require_once '../config/db.php';
$user = currentUser();

// Threshold from settings or default 75%
$threshold = 75;
$settingRow = $conn->query("SELECT setting_value FROM settings WHERE setting_key='attendance_threshold' LIMIT 1")->fetch_assoc();
if ($settingRow) $threshold = (float)$settingRow['setting_value'];

$filterClass = isset($_GET['class_id']) ? (int)$_GET['class_id'] : 0;

// Teacher's classes
$stmt = $conn->prepare("SELECT id, name, subject FROM classes WHERE teacher_id=? ORDER BY name");
$stmt->bind_param('i', $user['id']); $stmt->execute();
$classes = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// Build at-risk query
$classWhere = $filterClass ? "AND c.id=$filterClass" : '';

$atRisk = $conn->query("
    SELECT
        u.id AS student_id,
        u.name AS student_name,
        u.university_id,
        u.email,
        u.photo,
        c.id AS class_id,
        c.name AS class_name,
        c.subject,
        COUNT(a.id) AS total,
        SUM(a.status='present') AS present,
        SUM(a.status='absent') AS absent,
        SUM(a.status='late') AS late,
        ROUND(SUM(a.status='present') / COUNT(a.id) * 100, 1) AS pct,
        MAX(a.marked_at) AS last_seen
    FROM class_students cs
    JOIN users u ON cs.student_id=u.id
    JOIN classes c ON cs.class_id=c.id
    JOIN attendance_sessions s ON s.class_id=c.id
    JOIN attendance a ON a.session_id=s.id AND a.student_id=cs.student_id
    WHERE c.teacher_id={$user['id']} $classWhere
    GROUP BY u.id, c.id
    HAVING total >= 2 AND pct < $threshold
    ORDER BY pct ASC, c.name ASC
")->fetch_all(MYSQLI_ASSOC);

// Consecutive absences (3+)
$streakStudents = $conn->query("
    SELECT
        u.id AS student_id,
        u.name AS student_name,
        u.university_id,
        u.photo,
        c.id AS class_id,
        c.name AS class_name
    FROM class_students cs
    JOIN users u ON cs.student_id=u.id
    JOIN classes c ON cs.class_id=c.id
    WHERE c.teacher_id={$user['id']} $classWhere
")->fetch_all(MYSQLI_ASSOC);

// Calculate streaks
$streakData = [];
foreach ($streakStudents as $s) {
    $stmt2 = $conn->prepare("
        SELECT a.status FROM attendance a
        JOIN attendance_sessions sess ON a.session_id=sess.id
        WHERE a.student_id=? AND sess.class_id=?
        ORDER BY a.marked_at DESC LIMIT 5
    ");
    $stmt2->bind_param('ii', $s['student_id'], $s['class_id']); $stmt2->execute();
    $records = $stmt2->get_result()->fetch_all(MYSQLI_ASSOC);
    $streak = 0;
    foreach ($records as $r) {
        if ($r['status'] === 'absent') $streak++;
        else break;
    }
    if ($streak >= 3) {
        $s['streak'] = $streak;
        $streakData[] = $s;
    }
}

include '../includes/header.php';
?>
<h1>At-Risk Students</h1>
<p class="text-muted">Students below <?= $threshold ?>% attendance or with 3+ consecutive absences in your classes.</p>

<!-- Class Filter -->
<div class="card">
    <form method="GET" style="display:flex;gap:.75rem;align-items:center;flex-wrap:wrap">
        <select name="class_id" onchange="this.form.submit()">
            <option value="">All My Classes</option>
            <?php foreach ($classes as $c): ?>
            <option value="<?= $c['id'] ?>" <?= $filterClass==$c['id']?'selected':'' ?>><?= htmlspecialchars($c['name']) ?> — <?= htmlspecialchars($c['subject']) ?></option>
            <?php endforeach; ?>
        </select>
        <a href="at_risk_students.php" class="btn btn-secondary btn-sm">Clear</a>
    </form>
</div>

<!-- Consecutive Absences Section -->
<?php if (!empty($streakData)): ?>
<div class="card" style="border-left:4px solid #b91c1c">
    <h3>🚨 Consecutive Absences (3+ missed classes)</h3>
    <div style="display:grid;gap:.75rem;margin-top:.75rem">
    <?php foreach ($streakData as $s): ?>
    <div style="display:flex;align-items:center;gap:1rem;padding:.75rem;background:#fef2f2;border-radius:8px;flex-wrap:wrap">
        <?php if ($s['photo']): ?>
        <img src="/attendance-system/assets/uploads/<?= htmlspecialchars($s['photo']) ?>" style="width:44px;height:44px;border-radius:50%;object-fit:cover;border:2px solid #fca5a5">
        <?php else: ?>
        <div style="width:44px;height:44px;border-radius:50%;background:#fee2e2;display:flex;align-items:center;justify-content:center;font-size:1.2rem;flex-shrink:0">👤</div>
        <?php endif; ?>
        <div style="flex:1">
            <strong><?= htmlspecialchars($s['student_name']) ?></strong>
            <small class="text-muted"> — <?= htmlspecialchars($s['university_id'] ?? '') ?></small>
            <br><small class="text-muted"><?= htmlspecialchars($s['class_name']) ?></small>
        </div>
        <span style="background:#fee2e2;color:#b91c1c;font-weight:700;padding:.3rem .75rem;border-radius:20px;font-size:.9rem">
            <?= $s['streak'] ?> consecutive absences
        </span>
    </div>
    <?php endforeach; ?>
    </div>
</div>
<?php endif; ?>

<!-- Low Attendance Section -->
<div class="card">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:1rem;flex-wrap:wrap;gap:.5rem">
        <h3>📉 Low Attendance Students (below <?= $threshold ?>%)</h3>
        <span class="badge" style="background:#fee2e2;color:#b91c1c;font-size:.9rem"><?= count($atRisk) ?> student(s)</span>
    </div>

    <?php if (empty($atRisk)): ?>
        <p class="text-muted">No students below <?= $threshold ?>% attendance. 🎉</p>
    <?php else: ?>
    <div style="overflow-x:auto">
    <table class="table" id="atRiskTable">
        <thead>
            <tr>
                <th>Student</th>
                <th>Class</th>
                <th>Attendance %</th>
                <th>Present</th>
                <th>Absent</th>
                <th>Late</th>
                <th>Last Seen</th>
                <th>Risk</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($atRisk as $s):
            $isDetention = $s['pct'] < 50;
            $color = $isDetention ? '#b91c1c' : ($s['pct'] < 65 ? '#c2410c' : '#d97706');
            $bg    = $isDetention ? '#fef2f2' : ($s['pct'] < 65 ? '#fff7ed' : '#fffbeb');
        ?>
        <tr style="background:<?= $bg ?>">
            <td>
                <div style="display:flex;align-items:center;gap:.6rem">
                    <?php if ($s['photo']): ?>
                    <img src="/attendance-system/assets/uploads/<?= htmlspecialchars($s['photo']) ?>" style="width:32px;height:32px;border-radius:50%;object-fit:cover">
                    <?php else: ?>
                    <div style="width:32px;height:32px;border-radius:50%;background:#e2e8f0;display:flex;align-items:center;justify-content:center;font-size:.9rem">👤</div>
                    <?php endif; ?>
                    <div>
                        <strong><?= htmlspecialchars($s['student_name']) ?></strong>
                        <br><small class="text-muted"><?= htmlspecialchars($s['university_id'] ?? '') ?></small>
                    </div>
                </div>
            </td>
            <td><?= htmlspecialchars($s['class_name']) ?><br><small class="text-muted"><?= htmlspecialchars($s['subject']) ?></small></td>
            <td>
                <div style="display:flex;align-items:center;gap:.5rem">
                    <div style="width:60px;background:#e2e8f0;border-radius:4px;height:8px;overflow:hidden">
                        <div style="width:<?= $s['pct'] ?>%;background:<?= $color ?>;height:100%"></div>
                    </div>
                    <strong style="color:<?= $color ?>"><?= $s['pct'] ?>%</strong>
                </div>
            </td>
            <td style="color:#16a34a;font-weight:600"><?= $s['present'] ?></td>
            <td style="color:#dc2626;font-weight:600"><?= $s['absent'] ?></td>
            <td style="color:#d97706;font-weight:600"><?= $s['late'] ?></td>
            <td><small class="text-muted"><?= $s['last_seen'] ? date('d M Y', strtotime($s['last_seen'])) : '-' ?></small></td>
            <td>
                <?php if ($isDetention): ?>
                <span class="badge" style="background:#fee2e2;color:#b91c1c">🚨 Detention Risk</span>
                <?php elseif ($s['pct'] < 65): ?>
                <span class="badge" style="background:#fed7aa;color:#c2410c">⚠️ High Risk</span>
                <?php else: ?>
                <span class="badge" style="background:#fef3c7;color:#b45309">⚠️ At Risk</span>
                <?php endif; ?>
            </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>

    <!-- Summary by class -->
    <div style="margin-top:1.5rem">
        <h4>Summary by Class</h4>
        <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(220px,1fr));gap:.75rem;margin-top:.75rem">
        <?php
        $byClass = [];
        foreach ($atRisk as $s) {
            $key = $s['class_id'];
            if (!isset($byClass[$key])) $byClass[$key] = ['name'=>$s['class_name'],'count'=>0,'detention'=>0];
            $byClass[$key]['count']++;
            if ($s['pct'] < 50) $byClass[$key]['detention']++;
        }
        foreach ($byClass as $cls):
        ?>
        <div style="padding:.75rem;background:var(--hover-bg);border-radius:8px">
            <div style="font-weight:600;margin-bottom:.3rem"><?= htmlspecialchars($cls['name']) ?></div>
            <div style="font-size:.9rem"><span style="color:#d97706"><?= $cls['count'] ?> at risk</span><?= $cls['detention']?' &nbsp;|&nbsp; <span style="color:#b91c1c">'.$cls['detention'].' detention risk</span>':'' ?></div>
        </div>
        <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>
</div>

<?php include '../includes/footer.php'; ?>
