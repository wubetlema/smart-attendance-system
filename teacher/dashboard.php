<?php
require_once '../includes/auth_check.php';
requireRole('teacher');
require_once '../config/db.php';
$user = currentUser();
include '../includes/header.php';

$classes = $conn->prepare("SELECT * FROM classes WHERE teacher_id=? ORDER BY name");
$classes->bind_param('i', $user['id']);
$classes->execute();
$classes = $classes->get_result()->fetch_all(MYSQLI_ASSOC);

$todaySessions = $conn->prepare("SELECT COUNT(*) as c FROM attendance_sessions WHERE teacher_id=? AND DATE(created_at)=CURDATE()");
$todaySessions->bind_param('i', $user['id']);
$todaySessions->execute();
$todaySessions = $todaySessions->get_result()->fetch_assoc()['c'];
?>
<h1>Teacher Dashboard</h1>
<p>Welcome, <?= htmlspecialchars($user['name']) ?></p>

<div class="stats-grid">
    <div class="stat-card">
        <div class="stat-number"><?= count($classes) ?></div>
        <div class="stat-label">My Classes</div>
    </div>
    <div class="stat-card">
        <div class="stat-number"><?= $todaySessions ?></div>
        <div class="stat-label">Sessions Today</div>
    </div>
</div>

<div class="quick-links">
    <a href="mark_attendance.php" class="btn btn-primary">Start Attendance Session</a>
    <a href="qr_generate.php" class="btn btn-secondary">Generate QR Code</a>
    <a href="reports.php" class="btn btn-secondary">View Reports</a>
</div>

<div class="card">
    <h3>My Classes</h3>
    <?php if (empty($classes)): ?>
        <p>No classes assigned yet.</p>
    <?php else: ?>
    <table class="table">
        <thead><tr><th>Class</th><th>Subject</th><th>Schedule</th><th>Actions</th></tr></thead>
        <tbody>
        <?php foreach ($classes as $c): ?>
        <tr>
            <td><?= htmlspecialchars($c['name']) ?></td>
            <td><?= htmlspecialchars($c['subject']) ?></td>
            <td><?= htmlspecialchars($c['schedule'] ?? '-') ?></td>
            <td>
                <a href="mark_attendance.php?class_id=<?= $c['id'] ?>" class="btn btn-primary btn-sm">Mark Attendance</a>
                <a href="qr_generate.php?class_id=<?= $c['id'] ?>" class="btn btn-secondary btn-sm">QR Code</a>
            </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>
</div>
<?php include '../includes/footer.php'; ?>
