<?php
require_once '../includes/auth_check.php';
requireRole('teacher');
require_once '../config/db.php';
require_once '../includes/functions.php';
$user = currentUser();

$class_id = (int)($_GET['class_id'] ?? 0);
$msg = '';

// Handle manual marking
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['session_id'])) {
    $session_id = (int)$_POST['session_id'];
    foreach ($_POST['status'] as $student_id => $status) {
        $student_id = (int)$student_id;
        $status = in_array($status, ['present','absent','late']) ? $status : 'absent';
        markAttendance($session_id, $student_id, $status, 'manual');
    }
    $msg = 'Attendance saved successfully.';
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
    $stmt = $conn->prepare("SELECT u.id,u.name,u.university_id AS sid FROM users u JOIN class_students cs ON u.id=cs.student_id WHERE cs.class_id=? ORDER BY u.name");
    $stmt->bind_param('i', $class_id);
    $stmt->execute();
    $students = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
}

include '../includes/header.php';
?>
<h1>Mark Attendance</h1>
<?php if ($msg): ?><div class="alert alert-success"><?= htmlspecialchars($msg) ?></div><?php endif; ?>

<div class="card">
    <form method="GET">
        <div class="form-inline">
            <select name="class_id" required>
                <option value="">Select Class</option>
                <?php foreach ($classes as $c): ?>
                <option value="<?= $c['id'] ?>" <?= $class_id == $c['id'] ? 'selected' : '' ?>>
                    <?= htmlspecialchars($c['name']) ?> - <?= htmlspecialchars($c['subject']) ?>
                </option>
                <?php endforeach; ?>
            </select>
            <button type="submit" class="btn btn-primary">Load Students</button>
        </div>
    </form>
</div>

<?php if ($class_id && $students && $session): ?>
<div class="card">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:1rem">
        <h3>Mark Attendance</h3>
        <div>
            <button onclick="markAll('present')" class="btn btn-success btn-sm">All Present</button>
            <button onclick="markAll('absent')" class="btn btn-danger btn-sm">All Absent</button>
        </div>
    </div>
    <form method="POST">
        <input type="hidden" name="session_id" value="<?= $session['id'] ?>">
        <table class="table">
            <thead><tr><th>Student</th><th>ID</th><th>Status</th></tr></thead>
            <tbody>
            <?php foreach ($students as $s): ?>
            <tr>
                <td><?= htmlspecialchars($s['name']) ?></td>
                <td><?= htmlspecialchars($s['sid'] ?? '-') ?></td>
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
        <button type="submit" class="btn btn-primary">Save Attendance</button>
    </form>
</div>
<?php elseif ($class_id && empty($students)): ?>
<div class="alert alert-info">No students enrolled in this class yet.</div>
<?php endif; ?>

<script>
function markAll(status) {
    document.querySelectorAll('.status-select').forEach(s => s.value = status);
}
</script>
<?php include '../includes/footer.php'; ?>
