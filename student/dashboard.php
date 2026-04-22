<?php
require_once '../includes/auth_check.php';
requireRole('student');
require_once '../config/db.php';
$user = currentUser();
include '../includes/header.php';

// Attendance summary
$stmt = $conn->prepare("
    SELECT
        COUNT(*) AS total,
        SUM(status='present') AS present,
        SUM(status='absent') AS absent,
        SUM(status='late') AS late
    FROM attendance WHERE student_id=?
");
$stmt->bind_param('i', $user['id']);
$stmt->execute();
$summary = $stmt->get_result()->fetch_assoc();

$pct = $summary['total'] > 0 ? round(($summary['present'] / $summary['total']) * 100) : 0;

// Enrolled classes
$stmt = $conn->prepare("SELECT c.name,c.subject,c.schedule,u.name AS teacher FROM classes c JOIN class_students cs ON c.id=cs.class_id JOIN users u ON c.teacher_id=u.id WHERE cs.student_id=?");
$stmt->bind_param('i', $user['id']);
$stmt->execute();
$myClasses = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
?>
<h1>Student Dashboard</h1>
<p>Welcome, <?= htmlspecialchars($user['name']) ?></p>

<div class="stats-grid">
    <div class="stat-card">
        <div class="stat-number"><?= $summary['total'] ?></div>
        <div class="stat-label">Total Sessions</div>
    </div>
    <div class="stat-card" style="border-color:#27ae60">
        <div class="stat-number" style="color:#27ae60"><?= $summary['present'] ?></div>
        <div class="stat-label">Present</div>
    </div>
    <div class="stat-card" style="border-color:#e74c3c">
        <div class="stat-number" style="color:#e74c3c"><?= $summary['absent'] ?></div>
        <div class="stat-label">Absent</div>
    </div>
    <div class="stat-card" style="border-color:#f39c12">
        <div class="stat-number" style="color:#f39c12"><?= $summary['late'] ?></div>
        <div class="stat-label">Late</div>
    </div>
</div>

<div class="card">
    <h3>Attendance Rate</h3>
    <div class="progress-bar-wrap">
        <div class="progress-bar" style="width:<?= $pct ?>%"><?= $pct ?>%</div>
    </div>
</div>

<div class="quick-links">
    <a href="qr_checkin.php" class="btn btn-primary">QR Check-in</a>
    <a href="face_checkin.php" class="btn btn-secondary">Face Check-in</a>
    <a href="my_attendance.php" class="btn btn-secondary">View My Attendance</a>
</div>

<div class="card">
    <h3>My Classes</h3>
    <?php if (empty($myClasses)): ?>
        <p>You are not enrolled in any classes yet.</p>
    <?php else: ?>
    <table class="table">
        <thead><tr><th>Class</th><th>Subject</th><th>Teacher</th><th>Schedule</th></tr></thead>
        <tbody>
        <?php foreach ($myClasses as $c): ?>
        <tr>
            <td><?= htmlspecialchars($c['name']) ?></td>
            <td><?= htmlspecialchars($c['subject']) ?></td>
            <td><?= htmlspecialchars($c['teacher']) ?></td>
            <td><?= htmlspecialchars($c['schedule'] ?? '-') ?></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>
</div>
<?php include '../includes/footer.php'; ?>
