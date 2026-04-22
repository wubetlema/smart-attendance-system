<?php
require_once '../includes/auth_check.php';
requireRole('teacher');
require_once '../config/db.php';
$user = currentUser();

$msg = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $sid = (int)$_POST['student_id'];
    $cid = (int)$_POST['class_id'];
    $block = (int)$_POST['block'];
    $stmt = $conn->prepare("UPDATE class_students SET is_blocked=? WHERE student_id=? AND class_id=?");
    $stmt->bind_param('iii', $block, $sid, $cid);
    $stmt->execute();
    $msg = $block ? 'Student blocked.' : 'Student unblocked.';
}

$class_id = (int)($_GET['class_id'] ?? 0);
$stmt = $conn->prepare("SELECT * FROM classes WHERE teacher_id=? ORDER BY name");
$stmt->bind_param('i', $user['id']); $stmt->execute();
$classes = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

$students = [];
if ($class_id) {
    $stmt = $conn->prepare("
        SELECT u.id, u.name, u.university_id, u.department, u.semester, u.section, u.photo, u.email,
               cs.is_blocked,
               COUNT(a.id) AS total,
               SUM(a.status='present') AS present,
               ROUND(SUM(a.status='present')/NULLIF(COUNT(a.id),0)*100) AS pct
        FROM class_students cs
        JOIN users u ON cs.student_id=u.id
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

include '../includes/header.php';
?>
<h1>My Students</h1>
<?php if ($msg): ?><div class="alert alert-success"><?= htmlspecialchars($msg) ?></div><?php endif; ?>

<form method="GET" class="card form-inline">
    <select name="class_id" onchange="this.form.submit()">
        <option value="">Select Course</option>
        <?php foreach ($classes as $c): ?>
        <option value="<?= $c['id'] ?>" <?= $class_id==$c['id']?'selected':'' ?>><?= htmlspecialchars($c['name']) ?></option>
        <?php endforeach; ?>
    </select>
</form>

<?php if ($class_id && $students): ?>
<div class="card">
    <h3>Enrolled Students (<?= count($students) ?>)</h3>
    <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(280px,1fr));gap:1rem">
    <?php foreach ($students as $s):
        $color = ($s['pct']??0) >= 75 ? '#16a34a' : (($s['pct']??0) >= 50 ? '#d97706' : '#dc2626');
    ?>
    <div style="border:1px solid var(--border);border-radius:10px;padding:1rem;display:flex;gap:1rem;align-items:flex-start;<?= $s['is_blocked']?'opacity:.6;background:#fef2f2':'' ?>">
        <div style="width:56px;height:56px;border-radius:50%;overflow:hidden;flex-shrink:0;background:#e2e8f0;display:flex;align-items:center;justify-content:center">
            <?php if ($s['photo']): ?>
            <img src="/attendance-system/assets/uploads/<?= htmlspecialchars($s['photo']) ?>" style="width:100%;height:100%;object-fit:cover">
            <?php else: ?>
            <span style="font-size:1.5rem">👤</span>
            <?php endif; ?>
        </div>
        <div style="flex:1">
            <strong><?= htmlspecialchars($s['name']) ?></strong>
            <?php if ($s['is_blocked']): ?><span class="badge" style="background:#fee2e2;color:#b91c1c;margin-left:.4rem">Blocked</span><?php endif; ?>
            <br><small class="text-muted"><?= htmlspecialchars($s['university_id']??'') ?></small>
            <br><small class="text-muted"><?= htmlspecialchars($s['department']??'') ?> <?= htmlspecialchars($s['section']??'') ?></small>
            <div style="margin-top:.4rem;display:flex;align-items:center;gap:.4rem">
                <div style="flex:1;background:#e2e8f0;border-radius:10px;height:6px">
                    <div style="width:<?= $s['pct']??0 ?>%;background:<?= $color ?>;height:6px;border-radius:10px"></div>
                </div>
                <span style="color:<?= $color ?>;font-size:.8rem;font-weight:700"><?= $s['pct']??0 ?>%</span>
            </div>
            <form method="POST" style="margin-top:.5rem">
                <input type="hidden" name="class_id" value="<?= $class_id ?>">
                <input type="hidden" name="student_id" value="<?= $s['id'] ?>">
                <input type="hidden" name="block" value="<?= $s['is_blocked']?0:1 ?>">
                <button class="btn btn-sm" style="background:<?= $s['is_blocked']?'#16a34a':'#dc2626' ?>;color:#fff">
                    <?= $s['is_blocked']?'Unblock':'Block' ?>
                </button>
            </form>
        </div>
    </div>
    <?php endforeach; ?>
    </div>
</div>
<?php elseif ($class_id): ?>
<div class="alert alert-info">No students enrolled yet.</div>
<?php endif; ?>
<?php include '../includes/footer.php'; ?>
