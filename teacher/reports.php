<?php
require_once '../includes/auth_check.php';
requireRole('teacher');
require_once '../config/db.php';
$user = currentUser();

$class_id = (int)($_GET['class_id'] ?? 0);

$stmt = $conn->prepare("SELECT * FROM classes WHERE teacher_id=? ORDER BY name");
$stmt->bind_param('i', $user['id']);
$stmt->execute();
$classes = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

$records = [];
if ($class_id) {
    $stmt = $conn->prepare("
        SELECT u.name, u.university_id AS sid, a.status, a.method, a.marked_at,
               DATE(s.created_at) AS session_date
        FROM attendance a
        JOIN users u ON a.student_id = u.id
        JOIN attendance_sessions s ON a.session_id = s.id
        WHERE s.class_id = ? AND s.teacher_id = ?
        ORDER BY s.created_at DESC, u.name ASC
    ");
    $stmt->bind_param('ii', $class_id, $user['id']);
    $stmt->execute();
    $records = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
}

include '../includes/header.php';
?>
<h1>Attendance Reports</h1>

<form method="GET" class="card form-inline">
    <select name="class_id">
        <option value="">Select Class</option>
        <?php foreach ($classes as $c): ?>
        <option value="<?= $c['id'] ?>" <?= $class_id == $c['id'] ? 'selected' : '' ?>>
            <?= htmlspecialchars($c['name']) ?> - <?= htmlspecialchars($c['subject']) ?>
        </option>
        <?php endforeach; ?>
    </select>
    <button type="submit" class="btn btn-primary">View</button>
    <?php if ($class_id && $records): ?>
    <a href="?class_id=<?= $class_id ?>&export=csv" class="btn btn-secondary">Export CSV</a>
    <?php endif; ?>
</form>

<?php if ($records): ?>
<div class="card">
    <table class="table">
        <thead><tr><th>Student</th><th>ID</th><th>Date</th><th>Status</th><th>Method</th></tr></thead>
        <tbody>
        <?php foreach ($records as $r): ?>
        <tr>
            <td><?= htmlspecialchars($r['name']) ?></td>
            <td><?= htmlspecialchars($r['sid'] ?? '-') ?></td>
            <td><?= $r['session_date'] ?></td>
            <td><span class="badge badge-<?= $r['status'] ?>"><?= $r['status'] ?></span></td>
            <td><?= $r['method'] ?></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php elseif ($class_id): ?>
<div class="alert alert-info">No records found.</div>
<?php endif; ?>

<?php
if (isset($_GET['export']) && $_GET['export'] === 'csv' && $records) {
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="report.csv"');
    echo "Student,Student ID,Date,Status,Method\n";
    foreach ($records as $r) {
        echo '"'.$r['name'].'","'.($r['sid']??'').'",'.$r['session_date'].','.$r['status'].','.$r['method']."\n";
    }
    exit;
}
include '../includes/footer.php';
?>
