<?php
require_once '../includes/auth_check.php';
requireRole('teacher');
require_once '../config/db.php';
require_once '../includes/functions.php';
$user = currentUser();

$msg = ''; $msgType = 'success';

// ── Approve / Reject ──────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    verifyCsrf();

    $lrId  = (int)$_POST['leave_id'];
    $action = $_POST['action'];
    $notes  = trim($_POST['reviewer_notes'] ?? '');

    // Verify this leave belongs to one of this teacher's classes
    $stmt = $conn->prepare("
        SELECT lr.*, c.name AS class_name, c.subject,
               u.name AS student_name, u.email AS student_email
        FROM leave_requests lr
        JOIN classes c ON lr.class_id=c.id
        JOIN users u ON lr.student_id=u.id
        WHERE lr.id=? AND c.teacher_id=? AND lr.status='pending'
    ");
    $stmt->bind_param('ii', $lrId, $user['id']);
    $stmt->execute();
    $req = $stmt->get_result()->fetch_assoc();

    if (!$req) {
        $msg = 'Request not found or already reviewed.'; $msgType = 'error';
    } else {
        $newStatus = $action === 'approve' ? 'approved' : 'rejected';

        if ($action === 'reject' && empty($notes)) {
            $msg = 'Please provide a reason for rejection.'; $msgType = 'error';
        } else {
            $upd = $conn->prepare("
                UPDATE leave_requests
                SET status=?, reviewed_by=?, reviewed_at=NOW(), reviewer_notes=?
                WHERE id=?
            ");
            $upd->bind_param('sisi', $newStatus, $user['id'], $notes, $lrId);
            $upd->execute();

            // Notify student
            $icon  = $action === 'approve' ? '✅' : '❌';
            $label = $action === 'approve' ? 'APPROVED' : 'REJECTED';
            $notifMsg = "$icon Leave request for {$req['class_name']} on " .
                        date('d M Y', strtotime($req['leave_date'])) .
                        ": $label." . ($notes ? " Note: $notes" : '');

            $ns = $conn->prepare("INSERT INTO notifications (user_id, message) VALUES (?,?)");
            $ns->bind_param('is', $req['student_id'], $notifMsg); $ns->execute();

            $msg = "Leave request {$label}. {$req['student_name']} has been notified.";
        }
    }
}

// ── Data ──────────────────────────────────────────────────────────────────────
$filter = $_GET['status'] ?? 'pending';
$statusWhere = $filter !== 'all' ? "AND lr.status='" . $conn->real_escape_string($filter) . "'" : '';

$requests = $conn->query("
    SELECT lr.*,
           u.name AS student_name, u.university_id, u.department, u.semester, u.section,
           c.name AS class_name, c.subject,
           rv.name AS reviewer_name
    FROM leave_requests lr
    JOIN users u ON lr.student_id=u.id
    JOIN classes c ON lr.class_id=c.id
    LEFT JOIN users rv ON lr.reviewed_by=rv.id
    WHERE c.teacher_id={$user['id']} $statusWhere
    ORDER BY lr.created_at DESC
")->fetch_all(MYSQLI_ASSOC);

$pendingCount = (int)$conn->query("
    SELECT COUNT(*) c FROM leave_requests lr
    JOIN classes c ON lr.class_id=c.id
    WHERE c.teacher_id={$user['id']} AND lr.status='pending'
")->fetch_assoc()['c'];

// Single request detail
$viewId  = (int)($_GET['id'] ?? 0);
$viewReq = null;
if ($viewId) {
    $vs = $conn->prepare("
        SELECT lr.*, c.name AS class_name, c.subject,
               u.name AS student_name, u.university_id, u.email,
               u.department, u.semester, u.section,
               -- Student's attendance in this class
               (SELECT COUNT(*) FROM attendance a JOIN attendance_sessions s ON a.session_id=s.id WHERE a.student_id=lr.student_id AND s.class_id=lr.class_id) AS total_sessions,
               (SELECT SUM(a.status IN ('present','late')) FROM attendance a JOIN attendance_sessions s ON a.session_id=s.id WHERE a.student_id=lr.student_id AND s.class_id=lr.class_id) AS attended
        FROM leave_requests lr
        JOIN classes c ON lr.class_id=c.id
        JOIN users u ON lr.student_id=u.id
        WHERE lr.id=? AND c.teacher_id=?
    ");
    $vs->bind_param('ii', $viewId, $user['id']); $vs->execute();
    $viewReq = $vs->get_result()->fetch_assoc();
}

include '../includes/header.php';
?>

<h1>📋 Leave Requests</h1>

<?php if ($pendingCount > 0): ?>
<div class="alert alert-warning" style="justify-content:space-between;flex-wrap:wrap;gap:.5rem">
    <span>⏳ <strong><?= $pendingCount ?> pending</strong> leave request(s) need your review.</span>
</div>
<?php endif; ?>

<?php if ($msg): ?><div class="alert alert-<?= $msgType ?>"><?= htmlspecialchars($msg) ?></div><?php endif; ?>

<!-- Detail panel -->
<?php if ($viewReq): ?>
<div class="card" style="border-left:4px solid #6366f1;margin-bottom:1.25rem">
    <div style="display:flex;justify-content:space-between;align-items:flex-start;flex-wrap:wrap;gap:.75rem;margin-bottom:1.25rem">
        <div>
            <h3 style="margin:0"><?= htmlspecialchars($viewReq['class_name']) ?></h3>
            <p class="text-muted" style="margin:.2rem 0"><?= htmlspecialchars($viewReq['subject']) ?></p>
        </div>
        <a href="leaves.php" class="btn btn-secondary btn-sm">← Back to List</a>
    </div>

    <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:1rem;margin-bottom:1.25rem">
        <div style="background:#f8fafc;border-radius:8px;padding:.85rem">
            <div style="font-size:.75rem;text-transform:uppercase;color:#94a3b8;margin-bottom:.3rem">Student</div>
            <div style="font-weight:700"><?= htmlspecialchars($viewReq['student_name']) ?></div>
            <div><code style="font-size:.8rem"><?= htmlspecialchars($viewReq['university_id']) ?></code></div>
            <div style="font-size:.82rem;color:#64748b"><?= htmlspecialchars($viewReq['department']??'') ?></div>
        </div>
        <div style="background:#f8fafc;border-radius:8px;padding:.85rem">
            <div style="font-size:.75rem;text-transform:uppercase;color:#94a3b8;margin-bottom:.3rem">Leave Details</div>
            <div style="font-weight:700"><?= date('d M Y', strtotime($viewReq['leave_date'])) ?></div>
            <div><span class="badge" style="background:#dbeafe;color:#1e40af"><?= ucfirst($viewReq['leave_type']) ?></span></div>
            <div style="font-size:.82rem;color:#64748b">Submitted: <?= date('d M H:i', strtotime($viewReq['created_at'])) ?></div>
        </div>
        <div style="background:#f8fafc;border-radius:8px;padding:.85rem">
            <div style="font-size:.75rem;text-transform:uppercase;color:#94a3b8;margin-bottom:.3rem">Attendance Context</div>
            <?php
            $total   = (int)$viewReq['total_sessions'];
            $attended = (int)$viewReq['attended'];
            $pct = $total > 0 ? round($attended / $total * 100) : 0;
            ?>
            <div style="font-weight:700"><?= $attended ?>/<?= $total ?> sessions</div>
            <div style="font-weight:700;color:<?= $pct < 75 ? '#dc2626' : '#16a34a' ?>"><?= $pct ?>%</div>
            <?php if ($pct < 75): ?><div style="font-size:.78rem;color:#dc2626">⚠️ Below 75%</div><?php endif; ?>
        </div>
    </div>

    <div style="background:#fffbeb;border:1.5px solid #fde68a;border-radius:10px;padding:1rem;margin-bottom:1.25rem">
        <div style="font-size:.75rem;text-transform:uppercase;color:#94a3b8;margin-bottom:.35rem">Student's Reason</div>
        <div style="font-size:.92rem;color:#1e293b"><?= htmlspecialchars($viewReq['reason']) ?></div>
        <?php if ($viewReq['evidence_path']): ?>
        <div style="margin-top:.5rem">
            📎 <a href="/attendance-system/assets/uploads/leaves/<?= htmlspecialchars($viewReq['evidence_path']) ?>"
                  target="_blank" style="color:#6366f1">View Supporting Document</a>
        </div>
        <?php endif; ?>
    </div>

    <?php if ($viewReq['status'] === 'pending'): ?>
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:1rem">
        <form method="POST" onsubmit="return confirm('Approve this leave request?')">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="approve">
            <input type="hidden" name="leave_id" value="<?= $viewReq['id'] ?>">
            <div class="form-group"><label>Note for student (optional)</label>
                <textarea name="reviewer_notes" rows="3" placeholder="e.g. Approved. Please catch up on missed material."
                          style="width:100%;padding:.6rem;border:1.5px solid var(--border);border-radius:8px;resize:vertical"></textarea></div>
            <button type="submit" class="btn btn-primary btn-full" style="background:#16a34a">✅ Approve Leave</button>
        </form>
        <form method="POST" onsubmit="return confirm('Reject this leave request?')">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="reject">
            <input type="hidden" name="leave_id" value="<?= $viewReq['id'] ?>">
            <div class="form-group"><label>Reason for rejection <span style="color:#dc2626">*</span></label>
                <textarea name="reviewer_notes" rows="3" required placeholder="e.g. No valid documentation provided."
                          style="width:100%;padding:.6rem;border:1.5px solid var(--border);border-radius:8px;resize:vertical"></textarea></div>
            <button type="submit" class="btn btn-danger btn-full">❌ Reject</button>
        </form>
    </div>
    <?php else: ?>
    <div class="alert alert-<?= $viewReq['status']==='approved'?'success':'error' ?>">
        This request was <strong><?= $viewReq['status'] ?></strong>
        <?= $viewReq['reviewed_at'] ? 'on '.date('d M Y H:i', strtotime($viewReq['reviewed_at'])) : '' ?>.
        <?= $viewReq['reviewer_notes'] ? 'Note: '.htmlspecialchars($viewReq['reviewer_notes']) : '' ?>
    </div>
    <?php endif; ?>
</div>
<?php endif; ?>

<!-- Filter tabs -->
<div style="display:flex;gap:.4rem;margin-bottom:.75rem;flex-wrap:wrap">
    <?php foreach (['pending'=>'⏳ Pending','approved'=>'✅ Approved','rejected'=>'❌ Rejected','all'=>'All'] as $v=>$l): ?>
    <a href="?status=<?= $v ?>" class="btn btn-sm <?= $filter===$v?'btn-primary':'btn-secondary' ?>">
        <?= $l ?>
        <?php if ($v==='pending' && $pendingCount > 0): ?>
        <span style="background:#dc2626;color:#fff;border-radius:10px;padding:0 5px;font-size:.7rem;margin-left:.2rem"><?= $pendingCount ?></span>
        <?php endif; ?>
    </a>
    <?php endforeach; ?>
</div>

<!-- Requests list -->
<div class="card">
    <h3>Leave Requests <?= $filter !== 'all' ? '— '.ucfirst($filter) : '' ?> (<?= count($requests) ?>)</h3>
    <?php if (empty($requests)): ?>
    <p class="text-muted">No <?= $filter !== 'all' ? $filter : '' ?> leave requests.</p>
    <?php else: ?>
    <div style="overflow-x:auto">
    <table class="table">
        <thead><tr><th>Student</th><th>Class</th><th>Date</th><th>Type</th><th>Status</th><th>Submitted</th><th>Action</th></tr></thead>
        <tbody>
        <?php
        $sc = ['pending'=>['#fef3c7','#b45309','⏳'],'approved'=>['#dcfce7','#15803d','✅'],'rejected'=>['#fee2e2','#b91c1c','❌'],'cancelled'=>['#f1f5f9','#64748b','🚫']];
        foreach ($requests as $r):
            [$bg,$fg,$icon] = $sc[$r['status']] ?? ['#f1f5f9','#64748b','–'];
        ?>
        <tr>
            <td>
                <strong><?= htmlspecialchars($r['student_name']) ?></strong>
                <br><code style="font-size:.75rem"><?= htmlspecialchars($r['university_id']) ?></code>
            </td>
            <td><?= htmlspecialchars($r['class_name']) ?><br><small class="text-muted"><?= htmlspecialchars($r['subject']) ?></small></td>
            <td><?= date('d M Y', strtotime($r['leave_date'])) ?></td>
            <td><span class="badge" style="background:#dbeafe;color:#1e40af"><?= ucfirst($r['leave_type']) ?></span></td>
            <td><span class="badge" style="background:<?= $bg ?>;color:<?= $fg ?>"><?= $icon ?> <?= ucfirst($r['status']) ?></span></td>
            <td><small class="text-muted"><?= date('d M H:i', strtotime($r['created_at'])) ?></small></td>
            <td><a href="?id=<?= $r['id'] ?>&status=<?= $filter ?>" class="btn btn-secondary btn-sm">Review</a></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
    <?php endif; ?>
</div>

<?php include '../includes/footer.php'; ?>
