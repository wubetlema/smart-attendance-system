<?php
require_once '../includes/auth_check.php';
requireRole('admin');
require_once '../config/db.php';
include '../includes/header.php';

$class_id = (int)($_GET['class_id'] ?? 0);
$classes  = $conn->query("SELECT c.*,u.name AS teacher_name FROM classes c JOIN users u ON c.teacher_id=u.id ORDER BY c.name")->fetch_all(MYSQLI_ASSOC);

$records = [];
if ($class_id) {
    $stmt = $conn->prepare("
        SELECT u.name, u.university_id AS sid, a.status, a.method, a.marked_at,
               DATE(s.created_at) AS session_date
        FROM attendance a
        JOIN users u ON a.student_id = u.id
        JOIN attendance_sessions s ON a.session_id = s.id
        WHERE s.class_id = ?
        ORDER BY s.created_at DESC, u.name ASC
    ");
    $stmt->bind_param('i', $class_id);
    $stmt->execute();
    $records = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
}
?>
<h1>Attendance Reports</h1>

<form method="GET" class="form-inline card">
    <select name="class_id" required>
        <option value="">Select Class</option>
        <?php foreach ($classes as $c): ?>
        <option value="<?= $c['id'] ?>" <?= $class_id == $c['id'] ? 'selected' : '' ?>>
            <?= htmlspecialchars($c['name']) ?> - <?= htmlspecialchars($c['subject']) ?>
        </option>
        <?php endforeach; ?>
    </select>
    <button type="submit" class="btn btn-primary">View Report</button>
</form>

<?php if ($records): ?>
<div class="card">
    <div style="display:flex;justify-content:space-between;align-items:center">
        <h3>Attendance Records</h3>
        <a href="?class_id=<?= $class_id ?>&export=csv" class="btn btn-secondary">Export CSV</a>
    </div>
    <table class="table">
        <thead><tr><th>Student</th><th>ID</th><th>Date</th><th>Status</th><th>Method</th></tr></thead>
        <tbody>
        <?php foreach ($records as $r): ?>
        <tr>
            <td><?= htmlspecialchars($r['name']) ?></td>
            <td><?= htmlspecialchars($r['sid'] ?? '-') ?></td>
            <td><?= htmlspecialchars($r['session_date']) ?></td>
            <td><span class="badge badge-<?= $r['status'] ?>"><?= $r['status'] ?></span></td>
            <td><?= $r['method'] ?></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php elseif ($class_id): ?>
<div class="alert alert-info">No attendance records found for this class.</div>
<?php endif; ?>

<?php
// CSV export
if (isset($_GET['export']) && $_GET['export'] === 'csv' && $records) {
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="attendance_report.csv"');
    echo "Student,Student ID,Date,Status,Method\n";
    foreach ($records as $r) {
        echo implode(',', [
            '"' . $r['name'] . '"',
            '"' . ($r['sid'] ?? '') . '"',
            $r['session_date'],
            $r['status'],
            $r['method']
        ]) . "\n";
    }
    exit;
}
include '../includes/footer.php';
?>
