<?php
require_once '../includes/auth_check.php';
requireRole('teacher');
require_once '../config/db.php';
$user = currentUser();

$msg = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $class_id = (int)$_POST['class_id'];
    $message  = trim($_POST['message']);
    $stmt = $conn->prepare("INSERT INTO announcements (teacher_id,class_id,message) VALUES (?,?,?)");
    $stmt->bind_param('iis', $user['id'], $class_id, $message);
    if ($stmt->execute()) {
        // Notify all enrolled students
        $students = $conn->query("SELECT student_id FROM class_students WHERE class_id=$class_id AND is_blocked=0")->fetch_all(MYSQLI_ASSOC);
        $cls = $conn->query("SELECT name FROM classes WHERE id=$class_id")->fetch_assoc();
        foreach ($students as $s) {
            $notif = "Announcement from {$user['name']} [{$cls['name']}]: $message";
            $ns = $conn->prepare("INSERT INTO notifications (user_id,message) VALUES (?,?)");
            $ns->bind_param('is', $s['student_id'], $notif); $ns->execute();
        }
        $msg = 'Announcement sent to all enrolled students.';
    }
}

$stmt = $conn->prepare("SELECT * FROM classes WHERE teacher_id=? ORDER BY name");
$stmt->bind_param('i', $user['id']); $stmt->execute();
$classes = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

$stmt = $conn->prepare("SELECT a.*, c.name AS class_name FROM announcements a JOIN classes c ON a.class_id=c.id WHERE a.teacher_id=? ORDER BY a.created_at DESC LIMIT 20");
$stmt->bind_param('i', $user['id']); $stmt->execute();
$announcements = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

include '../includes/header.php';
?>
<h1>Announcements</h1>
<?php if ($msg): ?><div class="alert alert-success"><?= htmlspecialchars($msg) ?></div><?php endif; ?>

<div class="card">
    <h3>Send Announcement</h3>
    <form method="POST">
        <div class="form-group"><label>Course</label>
            <select name="class_id" required>
                <option value="">Select Course</option>
                <?php foreach ($classes as $c): ?>
                <option value="<?= $c['id'] ?>"><?= htmlspecialchars($c['name']) ?></option>
                <?php endforeach; ?>
            </select></div>
        <div class="form-group"><label>Message</label>
            <textarea name="message" rows="3" required style="width:100%;padding:.6rem;border:1px solid var(--border);border-radius:7px" placeholder="Type your announcement..."></textarea></div>
        <button type="submit" class="btn btn-primary">Send to All Students</button>
    </form>
</div>

<div class="card">
    <h3>Sent Announcements</h3>
    <?php if (empty($announcements)): ?><p class="text-muted">No announcements yet.</p>
    <?php else: ?>
    <?php foreach ($announcements as $a): ?>
    <div style="border-bottom:1px solid var(--border);padding:.75rem 0">
        <span class="badge badge-teacher"><?= htmlspecialchars($a['class_name']) ?></span>
        <span class="text-muted" style="font-size:.8rem;margin-left:.5rem"><?= date('d M Y H:i', strtotime($a['created_at'])) ?></span>
        <p style="margin-top:.4rem"><?= htmlspecialchars($a['message']) ?></p>
    </div>
    <?php endforeach; ?>
    <?php endif; ?>
</div>
<?php include '../includes/footer.php'; ?>
