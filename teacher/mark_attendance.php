<?php
require_once '../includes/auth_check.php';
requireRole('teacher');
require_once '../config/db.php';
require_once '../includes/functions.php';
$user = currentUser();

$class_id = (int)($_GET['class_id'] ?? 0);
$msg = ''; $msgType = 'success';

// Handle manual marking
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['session_id'])) {
    $session_id = (int)$_POST['session_id'];
    $saved = 0;
    foreach ($_POST['status'] as $student_id => $status) {
        $student_id = (int)$student_id;
        $status = in_array($status, ['present','absent','late']) ? $status : 'absent';
        $result = markAttendance($session_id, $student_id, $status, 'manual');
        if ($result['success']) $saved++;
    }
    $msg = "Attendance saved for $saved student(s). Alerts checked automatically.";
}

// Create new session if class selected
$session = null;
if ($class_id && !isset($_POST['session_id'])) {
    $session = createSession($class_id, $user['id'], 'manual', 60);
}
if (isset($_POST['session_id'])) {
    $session = ['id' => (int)$_POST['session_id']];
}

// Get teacher classes
$stmt = $conn->prepare("SELECT * FROM classes WHERE teacher_id=? ORDER BY name");
$stmt->bind_param('i', $user['id']);
$stmt->execute();
$classes = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// Get students for selected class
$students = [];
if ($class_id) {
    $stmt = $conn->prepare("
        SELECT u.id, u.name, u.university_id AS sid, u.photo,
               COALESCE(ROUND(SUM(a.status='present')/NULLIF(COUNT(a.id),0)*100,1), 0) AS pct,
               COUNT(a.id) AS total_sessions
        FROM users u
        JOIN class_students cs ON u.id=cs.student_id
        LEFT JOIN attendance a ON a.student_id=u.id
            AND a.session_id IN (SELECT id FROM attendance_sessions WHERE class_id=?)
        WHERE cs.class_id=?
        GROUP BY u.id
        ORDER BY u.name
    ");
    $stmt->bind_param('ii', $class_id, $class_id);
    $stmt->execute();
    $students = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
}

// Threshold
$threshold = 75;
$settingRow = $conn->query("SELECT setting_value FROM settings WHERE setting_key='attendance_threshold' LIMIT 1")->fetch_assoc();
if ($settingRow) $threshold = (float)$settingRow['setting_value'];

include '../includes/header.php';
?>
<h1>Mark Attendance</h1>
<?php if ($msg): ?><div class="alert alert-<?= $msgType ?>"><?= htmlspecialchars($msg) ?></div><?php endif; ?>

<div class="card">
    <form method="GET">
        <div class="form-inline">
            <select name="class_id" required>
                <option value="">Select Class</option>
                <?php foreach ($classes as $c): ?>
                <option value="<?= $c['id'] ?>" <?= $class_id == $c['id'] ? 'selected' : '' ?>>
                    <?= htmlspecialchars($c['name']) ?> — <?= htmlspecialchars($c['subject']) ?>
                </option>
                <?php endforeach; ?>
            </select>
            <button type="submit" class="btn btn-primary">Load Students</button>
        </div>
    </form>
</div>

<?php if ($class_id && !empty($students)):
    // Pre-class at-risk banner
    $atRiskInClass = array_filter($students, fn($s) => $s['total_sessions'] >= 2 && $s['pct'] < $threshold);
    if (!empty($atRiskInClass)):
?>
<div class="card" style="border-left:4px solid #d97706;background:#fffbeb">
    <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:.5rem;margin-bottom:.75rem">
        <h3 style="margin:0">⚠️ Low Attendance Students in This Class</h3>
        <a href="at_risk_students.php?class_id=<?= $class_id ?>" class="btn btn-sm" style="background:#d97706;color:#fff">Full Report</a>
    </div>
    <div style="display:grid;gap:.5rem">
    <?php foreach ($atRiskInClass as $s):
        $isDetention = $s['pct'] < 50;
        $color = $isDetention ? '#b91c1c' : '#d97706';
    ?>
    <div style="display:flex;align-items:center;gap:.75rem;padding:.5rem .75rem;background:<?= $isDetention?'#fef2f2':'#fef3c7' ?>;border-radius:8px;flex-wrap:wrap">
        <?php if ($s['photo']): ?>
        <img src="/attendance-system/assets/uploads/<?= htmlspecialchars($s['photo']) ?>" style="width:32px;height:32px;border-radius:50%;object-fit:cover">
        <?php else: ?>
        <div style="width:32px;height:32px;border-radius:50%;background:#e2e8f0;display:flex;align-items:center;justify-content:center;font-size:.85rem">👤</div>
        <?php endif; ?>
        <span style="flex:1"><strong><?= htmlspecialchars($s['name']) ?></strong> <small class="text-muted"><?= htmlspecialchars($s['sid'] ?? '') ?></small></span>
        <span style="font-weight:700;color:<?= $color ?>"><?= $s['pct'] ?>%</span>
        <?php if ($isDetention): ?>
        <span class="badge" style="background:#fee2e2;color:#b91c1c">🚨 Detention Risk</span>
        <?php else: ?>
        <span class="badge" style="background:#fef3c7;color:#b45309">⚠️ At Risk</span>
        <?php endif; ?>
    </div>
    <?php endforeach; ?>
    </div>
</div>
<?php endif; ?>

<?php if ($session): ?>
<div class="card">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:1rem;flex-wrap:wrap;gap:.5rem">
        <h3>Mark Attendance (<?= count($students) ?> students)</h3>
        <div style="display:flex;gap:.5rem">
            <button onclick="markAll('present')" class="btn btn-sm" style="background:#16a34a;color:#fff">All Present</button>
            <button onclick="markAll('absent')" class="btn btn-danger btn-sm">All Absent</button>
        </div>
    </div>
    <form method="POST">
        <input type="hidden" name="session_id" value="<?= $session['id'] ?>">
        <table class="table">
            <thead><tr><th></th><th>Student</th><th>ID</th><th>Attendance</th><th>Status</th></tr></thead>
            <tbody>
            <?php foreach ($students as $s):
                $pctColor = $s['pct'] < 50 ? '#b91c1c' : ($s['pct'] < $threshold ? '#d97706' : '#16a34a');
            ?>
            <tr>
                <td>
                    <?php if ($s['photo']): ?>
                    <img src="/attendance-system/assets/uploads/<?= htmlspecialchars($s['photo']) ?>" style="width:32px;height:32px;border-radius:50%;object-fit:cover">
                    <?php else: ?>
                    <div style="width:32px;height:32px;border-radius:50%;background:#e2e8f0;display:flex;align-items:center;justify-content:center;font-size:.85rem">👤</div>
                    <?php endif; ?>
                </td>
                <td><?= htmlspecialchars($s['name']) ?></td>
                <td><code><?= htmlspecialchars($s['sid'] ?? '-') ?></code></td>
                <td>
                    <?php if ($s['total_sessions'] > 0): ?>
                    <div style="display:flex;align-items:center;gap:.4rem">
                        <div style="width:50px;background:#e2e8f0;border-radius:4px;height:6px;overflow:hidden">
                            <div style="width:<?= $s['pct'] ?>%;background:<?= $pctColor ?>;height:100%"></div>
                        </div>
                        <small style="color:<?= $pctColor ?>;font-weight:600"><?= $s['pct'] ?>%</small>
                    </div>
                    <?php else: ?>
                    <small class="text-muted">New</small>
                    <?php endif; ?>
                </td>
                <td>
                    <select name="status[<?= $s['id'] ?>]" class="status-select">
                        <option value="present">Present</option>
                        <option value="absent">Absent</option>
                        <option value="late">Late</option>
                    </select>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <button type="submit" class="btn btn-primary">Save Attendance & Check Alerts</button>
    </form>
</div>
<?php endif; ?>

<?php elseif ($class_id && empty($students)): ?>
<div class="alert alert-info">No students enrolled in this class yet.</div>
<?php endif; ?>

<script>
function markAll(status) {
    document.querySelectorAll('.status-select').forEach(s => s.value = status);
}
</script>
<?php include '../includes/footer.php'; ?>
