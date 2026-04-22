<?php
require_once '../includes/auth_check.php';
requireRole('teacher');
require_once '../config/db.php';
require_once '../includes/functions.php';
$user = currentUser();

$msg = ''; $msgType = 'success';

// Handle approve/reject from dashboard quick links
if (isset($_GET['approve'])) {
    $id = (int)$_GET['approve'];
    $conn->prepare("UPDATE leave_requests SET status='approved' WHERE id=?")->bind_param('i',$id) && $conn->prepare("UPDATE leave_requests SET status='approved' WHERE id=?")->execute();
    $stmt = $conn->prepare("UPDATE leave_requests SET status='approved' WHERE id=?");
    $stmt->bind_param('i',$id); $stmt->execute();
    // Mark as excused in attendance
    $lr = $conn->query("SELECT * FROM leave_requests WHERE id=$id")->fetch_assoc();
    if ($lr) {
        $stmt2 = $conn->prepare("UPDATE attendance SET status='late', remark='Excused' WHERE student_id=? AND session_id IN (SELECT id FROM attendance_sessions WHERE class_id=? AND DATE(created_at)=?)");
        $stmt2->bind_param('iis', $lr['student_id'], $lr['class_id'], $lr['session_date']);
        $stmt2->execute();
        $notif = "Your leave request for ".date('d M Y', strtotime($lr['session_date']))." has been approved.";
        $ns = $conn->prepare("INSERT INTO notifications (user_id,message) VALUES (?,?)");
        $ns->bind_param('is', $lr['student_id'], $notif); $ns->execute();
    }
    $msg = 'Leave approved.';
} elseif (isset($_GET['reject'])) {
    $id = (int)$_GET['reject'];
    $stmt = $conn->prepare("UPDATE leave_requests SET status='rejected' WHERE id=?");
    $stmt->bind_param('i',$id); $stmt->execute();
    $lr = $conn->query("SELECT * FROM leave_requests WHERE id=$id")->fetch_assoc();
    if ($lr) {
        $notif = "Your leave request for ".date('d M Y', strtotime($lr['session_date']))." has been rejected.";
        $ns = $conn->prepare("INSERT INTO notifications (user_id,message) VALUES (?,?)");
        $ns->bind_param('is', $lr['student_id'], $notif); $ns->execute();
    }
    $msg = 'Leave rejected.';
}

// Get all leave requests for teacher's classes
$stmt = $conn->prepare("
    SELECT lr.*, u.name AS student_name, u.university_id,
           c.name AS class_name, c.subject
    FROM leave_requests lr
    JOIN users u ON lr.student_id=u.id
    JOIN classes c ON lr.class_id=c.id
    WHERE c.teacher_id=?
    ORDER BY lr.status ASC, lr.created_at DESC
");
$stmt->bind_param('i', $user['id']);
$stmt->execute();
$leaves = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

include '../includes/header.php';
?>
<h1>Leave & Excuse Management</h1>
<?php if ($msg): ?><div class="alert alert-<?= $msgType ?>"><?= htmlspecialchars($msg) ?></div><?php endif; ?>

<div class="card">
    <h3>Leave Requests</h3>
    <?php if (empty($leaves)): ?>
        <p class="text-muted">No leave requests yet.</p>
    <?php else: ?>
    <table class="table">
        <thead><tr><th>Student</th><th>Course</th><th>Date</th><th>Reason</th><th>Document</th><th>Status</th><th>Action</th></tr></thead>
        <tbody>
        <?php foreach ($leaves as $l): ?>
        <tr>
            <td><?= htmlspecialchars($l['student_name']) ?><br><small class="text-muted"><?= htmlspecialchars($l['university_id']??'') ?></small></td>
            <td><?= htmlspecialchars($l['class_name']) ?></td>
            <td><?= $l['session_date'] ?></td>
            <td><?= htmlspecialchars($l['reason']) ?></td>
            <td><?php if($l['document']): ?><a href="/attendance-system/assets/uploads/<?= htmlspecialchars($l['document']) ?>" target="_blank" class="btn btn-secondary btn-sm">View</a><?php else: ?>-<?php endif; ?></td>
            <td><span class="badge badge-<?= $l['status']==='approved'?'present':($l['status']==='rejected'?'absent':'late') ?>"><?= $l['status'] ?></span></td>
            <td>
                <?php if ($l['status']==='pending'): ?>
                <a href="?approve=<?= $l['id'] ?>" class="btn btn-success btn-sm">Approve</a>
                <a href="?reject=<?= $l['id'] ?>" class="btn btn-danger btn-sm">Reject</a>
                <?php endif; ?>
            </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>
</div>
<?php include '../includes/footer.php'; ?>
