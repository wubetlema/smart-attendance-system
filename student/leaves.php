<?php
require_once '../includes/auth_check.php';
requireRole('student');
require_once '../config/db.php';
require_once '../includes/functions.php';
$user = currentUser();

$msg = ''; $msgType = 'success';

// ── Submit leave request ───────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    verifyCsrf();

    if ($_POST['action'] === 'submit') {
        $classId     = (int)$_POST['class_id'];
        $leaveDate   = trim($_POST['leave_date'] ?? '');
        $reason      = trim($_POST['reason'] ?? '');
        $leaveType   = in_array($_POST['leave_type']??'', ['medical','personal','family','other']) ? $_POST['leave_type'] : 'other';
        $evidencePath = '';

        if (!$classId || !$leaveDate || strlen($reason) < 10) {
            $msg = 'Please fill in all fields with a detailed reason.'; $msgType = 'error';
        } else {
            // Check enrolled
            $enrolled = $conn->prepare("SELECT id FROM class_students WHERE class_id=? AND student_id=? AND status='active'");
            $enrolled->bind_param('ii', $classId, $user['id']); $enrolled->execute();
            if (!$enrolled->get_result()->fetch_assoc()) {
                $msg = 'You are not enrolled in this class.'; $msgType = 'error';
            } else {
                // Evidence upload
                if (!empty($_FILES['evidence']['name'])) {
                    $ext = strtolower(pathinfo($_FILES['evidence']['name'], PATHINFO_EXTENSION));
                    if (in_array($ext, ['jpg','jpeg','png','pdf']) && $_FILES['evidence']['size'] < 5*1024*1024) {
                        $dir = '../assets/uploads/leaves/';
                        if (!is_dir($dir)) mkdir($dir, 0755, true);
                        $fname = 'leave_'.$user['id'].'_'.time().'.'.$ext;
                        if (move_uploaded_file($_FILES['evidence']['tmp_name'], $dir.$fname)) {
                            $evidencePath = $fname;
                        }
                    }
                }

                $stmt = $conn->prepare("
                    INSERT INTO leave_requests (student_id, class_id, leave_date, leave_type, reason, evidence_path, status)
                    VALUES (?,?,?,?,?,?,'pending')
                ");
                $stmt->bind_param('iissss', $user['id'], $classId, $leaveDate, $leaveType, $reason, $evidencePath);
                if ($stmt->execute()) {
                    // Notify teacher
                    $tcls = $conn->prepare("SELECT c.name, c.teacher_id, u.email, u.name AS tname FROM classes c JOIN users u ON c.teacher_id=u.id WHERE c.id=?");
                    $tcls->bind_param('i', $classId); $tcls->execute();
                    $cls = $tcls->get_result()->fetch_assoc();
                    if ($cls) {
                        $notif = $conn->prepare("INSERT INTO notifications (user_id, message) VALUES (?,?)");
                        $nm = "📋 Leave request from {$user['name']} for {$cls['name']} on $leaveDate. Reason: $reason";
                        $notif->bind_param('is', $cls['teacher_id'], $nm); $notif->execute();
                    }
                    $msg = 'Leave request submitted. Your teacher will review it.';
                } else {
                    $msg = 'Error: '.$conn->error; $msgType = 'error';
                }
            }
        }

    } elseif ($_POST['action'] === 'cancel') {
        $id = (int)$_POST['id'];
        $upd = $conn->prepare("UPDATE leave_requests SET status='cancelled' WHERE id=? AND student_id=? AND status='pending'");
        $upd->bind_param('ii', $id, $user['id']); $upd->execute();
        $msg = $conn->affected_rows > 0 ? 'Request cancelled.' : 'Cannot cancel (already reviewed).';
        if ($conn->affected_rows === 0) $msgType = 'error';
    }
}

// ── Enrolled classes ──────────────────────────────────────────────────────────
$classes = $conn->query("
    SELECT c.id, c.name, c.subject, u.name AS teacher
    FROM class_students cs
    JOIN classes c ON cs.class_id=c.id
    JOIN users u ON c.teacher_id=u.id
    WHERE cs.student_id={$user['id']} AND cs.status='active' AND c.is_active=1
    ORDER BY c.name
")->fetch_all(MYSQLI_ASSOC);

// ── My leave requests ─────────────────────────────────────────────────────────
$myLeaves = $conn->query("
    SELECT lr.*, c.name AS class_name, c.subject,
           u.name AS reviewer_name
    FROM leave_requests lr
    JOIN classes c ON lr.class_id=c.id
    LEFT JOIN users u ON lr.reviewed_by=u.id
    WHERE lr.student_id={$user['id']}
    ORDER BY lr.created_at DESC
")->fetch_all(MYSQLI_ASSOC);

include '../includes/header.php';
?>

<h1>📋 Leave Requests</h1>
<p class="text-muted">Submit a leave request for a class you could not attend. Your teacher will approve or reject it.</p>

<?php if ($msg): ?><div class="alert alert-<?= $msgType ?>"><?= htmlspecialchars($msg) ?></div><?php endif; ?>

<div style="display:grid;grid-template-columns:1fr 1.4fr;gap:1.5rem;align-items:start">

<!-- Submit form -->
<div class="card">
    <h3>New Leave Request</h3>
    <form method="POST" enctype="multipart/form-data">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="submit">
        <div class="form-group">
            <label>Class</label>
            <select name="class_id" required>
                <option value="">Select class...</option>
                <?php foreach ($classes as $c): ?>
                <option value="<?= $c['id'] ?>"><?= htmlspecialchars($c['name']) ?> — <?= htmlspecialchars($c['subject']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="form-group">
            <label>Date of Absence</label>
            <input type="date" name="leave_date" required max="<?= date('Y-m-d') ?>" value="<?= date('Y-m-d') ?>">
        </div>
        <div class="form-group">
            <label>Leave Type</label>
            <select name="leave_type">
                <option value="medical">🏥 Medical</option>
                <option value="family">👨‍👩‍👧 Family Emergency</option>
                <option value="personal">👤 Personal</option>
                <option value="other">📋 Other</option>
            </select>
        </div>
        <div class="form-group">
            <label>Reason <span style="color:#dc2626">*</span></label>
            <textarea name="reason" rows="4" required minlength="10"
                      placeholder="Explain why you were absent. Be specific."
                      style="width:100%;padding:.65rem;border:1.5px solid var(--border);border-radius:8px;resize:vertical"></textarea>
        </div>
        <div class="form-group">
            <label>Supporting Document (optional)</label>
            <input type="file" name="evidence" accept=".jpg,.jpeg,.png,.pdf">
            <small class="text-muted">Medical certificate, letter, etc. Max 5MB.</small>
        </div>
        <button type="submit" class="btn btn-primary btn-full">Submit Leave Request</button>
    </form>
</div>

<!-- My requests -->
<div class="card">
    <h3>My Requests</h3>
    <?php if (empty($myLeaves)): ?>
    <p class="text-muted">No leave requests yet.</p>
    <?php else: ?>
    <div style="display:flex;flex-direction:column;gap:.75rem">
    <?php
    $statusColors = [
        'pending'   => ['#fef3c7','#b45309','⏳'],
        'approved'  => ['#dcfce7','#15803d','✅'],
        'rejected'  => ['#fee2e2','#b91c1c','❌'],
        'cancelled' => ['#f1f5f9','#64748b','🚫'],
    ];
    foreach ($myLeaves as $lv):
        [$bg,$fg,$icon] = $statusColors[$lv['status']] ?? ['#f1f5f9','#64748b','–'];
    ?>
    <div style="border-radius:10px;border:1.5px solid <?= $bg ?>;padding:.9rem 1rem;background:<?= $bg ?>22">
        <div style="display:flex;justify-content:space-between;align-items:flex-start;flex-wrap:wrap;gap:.5rem">
            <div>
                <strong><?= htmlspecialchars($lv['class_name']) ?></strong>
                <span class="text-muted"> — <?= $lv['subject'] ?></span>
                <br>
                <small class="text-muted">
                    <?= date('d M Y', strtotime($lv['leave_date'])) ?>
                    · <?= ucfirst($lv['leave_type']) ?>
                    · Submitted <?= date('d M', strtotime($lv['created_at'])) ?>
                </small>
            </div>
            <span class="badge" style="background:<?= $bg ?>;color:<?= $fg ?>;font-weight:700"><?= $icon ?> <?= ucfirst($lv['status']) ?></span>
        </div>

        <div style="margin-top:.5rem;font-size:.85rem;color:#475569">
            <?= htmlspecialchars($lv['reason']) ?>
        </div>

        <?php if ($lv['reviewer_notes']): ?>
        <div style="margin-top:.4rem;padding:.4rem .65rem;background:#fff;border-radius:6px;font-size:.82rem">
            <strong>Teacher's note:</strong> <?= htmlspecialchars($lv['reviewer_notes']) ?>
            <?php if ($lv['reviewer_name']): ?><span class="text-muted"> — <?= htmlspecialchars($lv['reviewer_name']) ?></span><?php endif; ?>
        </div>
        <?php endif; ?>

        <?php if ($lv['status'] === 'pending'): ?>
        <form method="POST" style="margin-top:.5rem" onsubmit="return confirm('Cancel this leave request?')">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="cancel">
            <input type="hidden" name="id" value="<?= $lv['id'] ?>">
            <button class="btn btn-sm" style="background:#fee2e2;color:#b91c1c;border:none">Cancel Request</button>
        </form>
        <?php endif; ?>
    </div>
    <?php endforeach; ?>
    </div>
    <?php endif; ?>
</div>
</div>

<?php include '../includes/footer.php'; ?>
