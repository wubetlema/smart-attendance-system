<?php
require_once '../includes/auth_check.php';
requireRole('student');
require_once '../config/db.php';
$user = currentUser();

// Per-course attendance stats
$stmt = $conn->prepare("
    SELECT c.name AS class_name, c.subject,
           COUNT(*) AS total,
           SUM(a.status='present') AS present,
           SUM(a.status='absent') AS absent,
           SUM(a.status='late') AS late
    FROM attendance a
    JOIN attendance_sessions s ON a.session_id = s.id
    JOIN classes c ON s.class_id = c.id
    WHERE a.student_id = ?
    GROUP BY c.id
");
$stmt->bind_param('i', $user['id']);
$stmt->execute();
$courseStats = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// Full attendance history
$stmt = $conn->prepare("
    SELECT a.status, a.method, a.marked_at,
           c.name AS class_name, c.subject
    FROM attendance a
    JOIN attendance_sessions s ON a.session_id = s.id
    JOIN classes c ON s.class_id = c.id
    WHERE a.student_id = ?
    ORDER BY a.marked_at DESC
");
$stmt->bind_param('i', $user['id']);
$stmt->execute();
$records = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// Notifications
$stmt = $conn->prepare("SELECT * FROM notifications WHERE user_id=? ORDER BY created_at DESC LIMIT 5");
$stmt->bind_param('i', $user['id']);
$stmt->execute();
$notifications = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// Mark notifications as read
$conn->query("UPDATE notifications SET is_read=1 WHERE user_id={$user['id']}");

include '../includes/header.php';
?>
<h1>My Attendance</h1>

<?php if ($notifications): ?>
<div class="card">
    <h3>Notifications</h3>
    <?php foreach ($notifications as $n): ?>
    <div class="alert alert-<?= $n['is_read'] ? 'info' : 'success' ?>" style="margin-bottom:.5rem">
        <?= htmlspecialchars($n['message']) ?>
        <small style="float:right;opacity:.7"><?= date('d M, H:i', strtotime($n['created_at'])) ?></small>
    </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<!-- Per-course stats -->
<?php if ($courseStats): ?>
<div class="card">
    <h3>Attendance by Course</h3>
    <table class="table">
        <thead>
            <tr><th>Course</th><th>Subject</th><th>Total</th><th>Present</th><th>Absent</th><th>Late</th><th>Rate</th></tr>
        </thead>
        <tbody>
        <?php foreach ($courseStats as $cs):
            $pct = $cs['total'] > 0 ? round(($cs['present'] / $cs['total']) * 100) : 0;
            $color = $pct >= 75 ? '#16a34a' : ($pct >= 50 ? '#d97706' : '#dc2626');
        ?>
        <tr>
            <td><?= htmlspecialchars($cs['class_name']) ?></td>
            <td><?= htmlspecialchars($cs['subject']) ?></td>
            <td><?= $cs['total'] ?></td>
            <td style="color:#16a34a;font-weight:600"><?= $cs['present'] ?></td>
            <td style="color:#dc2626;font-weight:600"><?= $cs['absent'] ?></td>
            <td style="color:#d97706;font-weight:600"><?= $cs['late'] ?></td>
            <td>
                <div style="display:flex;align-items:center;gap:.5rem">
                    <div style="flex:1;background:#e2e8f0;border-radius:10px;height:10px">
                        <div style="width:<?= $pct ?>%;background:<?= $color ?>;height:10px;border-radius:10px"></div>
                    </div>
                    <span style="color:<?= $color ?>;font-weight:700;font-size:.85rem"><?= $pct ?>%</span>
                </div>
            </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php endif; ?>

<!-- Full history -->
<div class="card">
    <h3>Attendance History</h3>
    <?php if (empty($records)): ?>
        <p class="text-muted">No attendance records yet.</p>
    <?php else: ?>
    <table class="table">
        <thead>
            <tr><th>Course</th><th>Subject</th><th>Date & Time</th><th>Status</th><th>Method</th></tr>
        </thead>
        <tbody>
        <?php foreach ($records as $r): ?>
        <tr>
            <td><?= htmlspecialchars($r['class_name']) ?></td>
            <td><?= htmlspecialchars($r['subject']) ?></td>
            <td><?= date('d M Y, H:i', strtotime($r['marked_at'])) ?></td>
            <td><span class="badge badge-<?= $r['status'] ?>"><?= $r['status'] ?></span></td>
            <td><?= $r['method'] ?></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>
</div>
<?php include '../includes/footer.php'; ?>
