<?php
/**
 * Teacher — Review Attendance Correction Requests
 *
 * Teacher can Approve or Reject student requests.
 * Every decision is permanently recorded in the audit log.
 * Emails are sent to the student on every decision.
 */
require_once '../includes/auth_check.php';
requireRole('teacher');
require_once '../config/db.php';
require_once '../includes/functions.php';
require_once '../includes/audit.php';
require_once '../includes/notification_engine.php';

$user = currentUser();
$msg = ''; $msgType = 'success';

// ── Approve or Reject ─────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    verifyCsrf();

    $corrId     = (int)$_POST['correction_id'];
    $action     = $_POST['action']; // 'approve' or 'reject'
    $notes      = trim($_POST['reviewer_notes'] ?? '');

    // Load the correction request + verify it belongs to this teacher's class
    $stmt = $conn->prepare("
        SELECT cr.*, c.name AS class_name, c.subject,
               u.name AS student_name, u.email AS student_email,
               DATE(s.open_at) AS session_date
        FROM attendance_corrections cr
        JOIN classes c ON cr.class_id=c.id
        JOIN users u ON cr.student_id=u.id
        JOIN attendance_sessions s ON cr.session_id=s.id
        WHERE cr.id=? AND c.teacher_id=? AND cr.status='pending'
    ");
    $stmt->bind_param('ii', $corrId, $user['id']);
    $stmt->execute();
    $req = $stmt->get_result()->fetch_assoc();

    if (!$req) {
        $msg = 'Request not found or already reviewed.'; $msgType = 'error';
    } else {
        if ($action === 'approve') {
            // 1. Update attendance record
            $upd = $conn->prepare("
                UPDATE attendance
                SET status=?, method='manual', edited_by=?, edited_at=NOW(), original_status=?
                WHERE id=?
            ");
            $upd->bind_param('sissi', $req['requested_status'], $user['id'], $req['original_status'], $req['attendance_id']);
            $upd->execute();

            // 2. Mark correction as approved
            $conn->prepare("
                UPDATE attendance_corrections
                SET status='approved', reviewed_by=?, reviewed_at=NOW(), reviewer_notes=?
                WHERE id=?
            ")->bind_param('isi', $user['id'], $notes, $corrId) && $conn->prepare("
                UPDATE attendance_corrections
                SET status='approved', reviewed_by=?, reviewed_at=NOW(), reviewer_notes=?
                WHERE id=?
            ")->execute();
            $upd2 = $conn->prepare("UPDATE attendance_corrections SET status='approved', reviewed_by=?, reviewed_at=NOW(), reviewer_notes=? WHERE id=?");
            $upd2->bind_param('isi', $user['id'], $notes, $corrId);
            $upd2->execute();

            // 3. Audit
            Audit::correctionApproved(
                $corrId, $req['student_id'],
                $req['original_status'], $req['requested_status'],
                $user['id']
            );

            // 4. Email student
            Notify::correctionStatusChanged(
                $req['student_id'],
                $req['class_name'],
                'approved',
                $notes,
                $req['session_date']
            );

            $msg = "Correction approved. {$req['student_name']}'s attendance updated to " . strtoupper($req['requested_status']) . ".";

        } elseif ($action === 'reject') {
            if (empty($notes)) {
                $msg = 'Please provide a reason for rejection.'; $msgType = 'error';
                goto render;
            }

            $upd = $conn->prepare("UPDATE attendance_corrections SET status='rejected', reviewed_by=?, reviewed_at=NOW(), reviewer_notes=? WHERE id=?");
            $upd->bind_param('isi', $user['id'], $notes, $corrId);
            $upd->execute();

            Audit::correctionRejected($corrId, $user['id'], $notes);

            Notify::correctionStatusChanged(
                $req['student_id'],
                $req['class_name'],
                'rejected',
                $notes,
                $req['session_date']
            );

            $msg = "Correction request rejected. {$req['student_name']} has been notified.";
        }
    }
}

render:
// ── Load pending requests for this teacher ────────────────────────────────────
$pending = $conn->query("
    SELECT cr.*, c.name AS class_name, c.subject,
           u.name AS student_name, u.university_id, u.email AS student_email,
           DATE(s.open_at) AS session_date,
           s.open_at
    FROM attendance_corrections cr
    JOIN classes c ON cr.class_id=c.id
    JOIN users u ON cr.student_id=u.id
    JOIN attendance_sessions s ON cr.session_id=s.id
    WHERE c.teacher_id={$user['id']} AND cr.status='pending'
    ORDER BY cr.created_at ASC
")->fetch_all(MYSQLI_ASSOC);

// ── Reviewed history ──────────────────────────────────────────────────────────
$filterStatus = $_GET['status'] ?? 'all';
$statusWhere  = $filterStatus !== 'all' ? "AND cr.status='" . $conn->real_escape_string($filterStatus) . "'" : "AND cr.status IN ('approved','rejected','cancelled')";
$history = $conn->query("
    SELECT cr.*, c.name AS class_name,
           u.name AS student_name, u.university_id,
           DATE(s.open_at) AS session_date
    FROM attendance_corrections cr
    JOIN classes c ON cr.class_id=c.id
    JOIN users u ON cr.student_id=u.id
    JOIN attendance_sessions s ON cr.session_id=s.id
    WHERE c.teacher_id={$user['id']} $statusWhere
    ORDER BY cr.updated_at DESC
    LIMIT 50
")->fetch_all(MYSQLI_ASSOC);

// Single request detail
$viewId = (int)($_GET['id'] ?? 0);
$viewReq = null;
if ($viewId) {
    $vs = $conn->prepare("
        SELECT cr.*, c.name AS class_name, c.subject,
               u.name AS student_name, u.university_id, u.email,
               u.department, u.semester, u.section,
               DATE(s.open_at) AS session_date, s.open_at,
               -- Attendance context for this session
               (SELECT COUNT(*) FROM attendance a2 JOIN attendance_sessions s2 ON a2.session_id=s2.id WHERE a2.student_id=cr.student_id AND s2.class_id=cr.class_id) AS total_sessions,
               (SELECT SUM(a2.status='present') FROM attendance a2 JOIN attendance_sessions s2 ON a2.session_id=s2.id WHERE a2.student_id=cr.student_id AND s2.class_id=cr.class_id) AS present_count
        FROM attendance_corrections cr
        JOIN classes c ON cr.class_id=c.id
        JOIN users u ON cr.student_id=u.id
        JOIN attendance_sessions s ON cr.session_id=s.id
        WHERE cr.id=? AND c.teacher_id=?
    ");
    $vs->bind_param('ii', $viewId, $user['id']);
    $vs->execute();
    $viewReq = $vs->get_result()->fetch_assoc();
}

include '../includes/header.php';
?>

<h1>Correction Requests</h1>

<?php if ($msg): ?>
<div class="alert alert-<?= $msgType ?>"><?= htmlspecialchars($msg) ?></div>
<?php endif; ?>

<!-- Pending count banner -->
<?php if (!empty($pending)): ?>
<div class="alert" style="background:#fef3c7;border-left:4px solid #d97706;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:.5rem">
    <span>⏳ <strong><?= count($pending) ?> pending</strong> correction request(s) require your review.</span>
</div>
<?php endif; ?>

<!-- Single request detail panel -->
<?php if ($viewReq): ?>
<div class="card" style="border-left:4px solid #6366f1">
    <div style="display:flex;justify-content:space-between;align-items:flex-start;flex-wrap:wrap;gap:1rem;margin-bottom:1.25rem">
        <div>
            <h3 style="margin:0"><?= htmlspecialchars($viewReq['class_name']) ?></h3>
            <p class="text-muted" style="margin:.25rem 0"><?= htmlspecialchars($viewReq['subject']) ?> · <?= $viewReq['session_date'] ?></p>
        </div>
        <a href="correction_requests.php" class="btn btn-secondary btn-sm">← Back to List</a>
    </div>

    <div style="display:grid;grid-template-columns:1fr 1fr;gap:1rem;margin-bottom:1.25rem">
        <!-- Student info -->
        <div style="background:#f8fafc;border-radius:8px;padding:.85rem 1rem">
            <h4 style="margin:0 0 .5rem;font-size:.85rem;text-transform:uppercase;color:#94a3b8;letter-spacing:.05em">Student</h4>
            <div style="font-weight:700"><?= htmlspecialchars($viewReq['student_name']) ?></div>
            <div style="font-size:.85rem;color:#64748b"><code><?= htmlspecialchars($viewReq['university_id']) ?></code></div>
            <?php if ($viewReq['department']): ?><div style="font-size:.82rem;color:#64748b"><?= htmlspecialchars($viewReq['department']) ?></div><?php endif; ?>
            <?php if ($viewReq['semester'] || $viewReq['section']): ?>
            <div style="font-size:.82rem;color:#64748b"><?= htmlspecialchars($viewReq['semester'].' · Section '.$viewReq['section']) ?></div>
            <?php endif; ?>
        </div>

        <!-- Attendance context -->
        <div style="background:#f8fafc;border-radius:8px;padding:.85rem 1rem">
            <h4 style="margin:0 0 .5rem;font-size:.85rem;text-transform:uppercase;color:#94a3b8;letter-spacing:.05em">Attendance Context</h4>
            <?php
            $total   = (int)$viewReq['total_sessions'];
            $present = (int)$viewReq['present_count'];
            $pct     = $total > 0 ? round($present / $total * 100) : 0;
            ?>
            <div style="font-size:.9rem">
                <span>Sessions: <strong><?= $total ?></strong></span> ·
                <span>Present: <strong><?= $present ?></strong></span> ·
                <span style="color:<?= $pct<75?'#dc2626':'#16a34a' ?>;font-weight:700"><?= $pct ?>%</span>
            </div>
            <?php if ($pct < 75): ?>
            <div style="margin-top:.25rem;font-size:.8rem;color:#dc2626">⚠️ Student is already below 75% threshold</div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Correction details -->
    <div style="background:#fffbeb;border:1.5px solid #fde68a;border-radius:10px;padding:1rem;margin-bottom:1.25rem">
        <div style="display:flex;align-items:center;gap:1.5rem;flex-wrap:wrap">
            <div style="text-align:center">
                <div style="font-size:.75rem;text-transform:uppercase;color:#94a3b8;margin-bottom:.25rem">Original</div>
                <div style="padding:.4rem .9rem;background:#fee2e2;color:#b91c1c;border-radius:8px;font-weight:700"><?= strtoupper($viewReq['original_status']) ?></div>
            </div>
            <div style="font-size:1.5rem;color:#94a3b8">→</div>
            <div style="text-align:center">
                <div style="font-size:.75rem;text-transform:uppercase;color:#94a3b8;margin-bottom:.25rem">Requested</div>
                <div style="padding:.4rem .9rem;background:#dcfce7;color:#15803d;border-radius:8px;font-weight:700"><?= strtoupper($viewReq['requested_status']) ?></div>
            </div>
            <div style="flex:1;min-width:200px">
                <div style="font-size:.75rem;text-transform:uppercase;color:#94a3b8;margin-bottom:.25rem">Student's Reason</div>
                <div style="font-size:.9rem;color:#1e293b"><?= htmlspecialchars($viewReq['reason']) ?></div>
            </div>
        </div>
        <?php if ($viewReq['evidence_path']): ?>
        <div style="margin-top:.75rem;padding:.5rem .75rem;background:#fff;border-radius:6px">
            📎 Evidence submitted:
            <a href="/attendance-system/assets/uploads/evidence/<?= htmlspecialchars($viewReq['evidence_path']) ?>" target="_blank" style="color:#6366f1">
                View File
            </a>
        </div>
        <?php endif; ?>
    </div>

    <?php if ($viewReq['status'] === 'pending'): ?>
    <!-- Review form -->
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:1rem">
        <form method="POST" onsubmit="return confirm('Approve this correction? The attendance record will be updated immediately.')">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="approve">
            <input type="hidden" name="correction_id" value="<?= $viewReq['id'] ?>">
            <div class="form-group"><label>Note for student (optional)</label>
                <textarea name="reviewer_notes" rows="3" placeholder="e.g. Verified with class list. Approved."
                          style="width:100%;padding:.6rem;border:1.5px solid var(--border);border-radius:8px;resize:vertical"></textarea>
            </div>
            <button type="submit" class="btn btn-primary btn-full" style="background:#16a34a">✅ Approve Correction</button>
        </form>

        <form method="POST" onsubmit="return confirm('Reject this correction request?')">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="reject">
            <input type="hidden" name="correction_id" value="<?= $viewReq['id'] ?>">
            <div class="form-group"><label>Reason for rejection <span style="color:#dc2626">*</span></label>
                <textarea name="reviewer_notes" rows="3" required placeholder="e.g. QR scan records do not match. Student was not present."
                          style="width:100%;padding:.6rem;border:1.5px solid var(--border);border-radius:8px;resize:vertical"></textarea>
            </div>
            <button type="submit" class="btn btn-danger btn-full">❌ Reject Request</button>
        </form>
    </div>
    <?php else: ?>
    <div class="alert alert-<?= $viewReq['status']==='approved'?'success':'error' ?>">
        This request was <strong><?= $viewReq['status'] ?></strong>
        <?= $viewReq['reviewed_at'] ? ' on ' . date('d M Y H:i', strtotime($viewReq['reviewed_at'])) : '' ?>.
        <?php if ($viewReq['reviewer_notes']): ?>
        Note: <?= htmlspecialchars($viewReq['reviewer_notes']) ?>
        <?php endif; ?>
    </div>
    <?php endif; ?>
</div>
<?php endif; ?>

<!-- Pending requests list -->
<?php if (!empty($pending)): ?>
<div class="card">
    <h3>Pending Requests (<?= count($pending) ?>)</h3>
    <div style="display:flex;flex-direction:column;gap:.75rem">
    <?php foreach ($pending as $p): ?>
    <div style="padding:1rem;border-radius:10px;border:1.5px solid #fde68a;background:#fffbeb;display:flex;justify-content:space-between;align-items:flex-start;flex-wrap:wrap;gap:.75rem">
        <div>
            <strong><?= htmlspecialchars($p['student_name']) ?></strong>
            <code style="font-size:.78rem;margin-left:.4rem"><?= htmlspecialchars($p['university_id']) ?></code>
            <br>
            <span style="color:#475569;font-size:.88rem"><?= htmlspecialchars($p['class_name']) ?> · <?= $p['session_date'] ?></span>
            <br>
            <small>
                <span style="color:#dc2626;font-weight:600"><?= strtoupper($p['original_status']) ?></span>
                → <span style="color:#16a34a;font-weight:600"><?= strtoupper($p['requested_status']) ?></span>
                · Submitted: <?= date('d M H:i', strtotime($p['created_at'])) ?>
            </small>
            <div style="font-size:.82rem;color:#64748b;margin-top:.2rem">
                Reason: <?= htmlspecialchars(substr($p['reason'],0,100)) . (strlen($p['reason'])>100?'...':'') ?>
            </div>
        </div>
        <a href="?id=<?= $p['id'] ?>" class="btn btn-primary btn-sm">Review →</a>
    </div>
    <?php endforeach; ?>
    </div>
</div>
<?php endif; ?>

<!-- History -->
<div class="card">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:.75rem;flex-wrap:wrap;gap:.5rem">
        <h3 style="margin:0">Reviewed Requests</h3>
        <div style="display:flex;gap:.4rem">
            <?php foreach (['all'=>'All','approved'=>'Approved','rejected'=>'Rejected','cancelled'=>'Cancelled'] as $v=>$l): ?>
            <a href="?status=<?= $v ?>" class="btn btn-sm btn-secondary" style="<?= $filterStatus===$v?'background:var(--primary);color:#fff':'' ?>"><?= $l ?></a>
            <?php endforeach; ?>
        </div>
    </div>
    <?php if (empty($history)): ?>
    <p class="text-muted">No reviewed requests yet.</p>
    <?php else: ?>
    <div style="overflow-x:auto"><table class="table">
        <thead><tr><th>Student</th><th>Class</th><th>Date</th><th>Change</th><th>Status</th><th>Reviewed</th><th>Action</th></tr></thead>
        <tbody>
        <?php
        $sc = ['approved'=>['#dcfce7','#15803d','✅'],'rejected'=>['#fee2e2','#b91c1c','❌'],'cancelled'=>['#f1f5f9','#64748b','🚫']];
        foreach ($history as $h):
            [$hbg,$hfg,$hicon] = $sc[$h['status']] ?? ['#f1f5f9','#64748b','–'];
        ?>
        <tr>
            <td><strong><?= htmlspecialchars($h['student_name']) ?></strong><br><code style="font-size:.75rem"><?= htmlspecialchars($h['university_id']) ?></code></td>
            <td><?= htmlspecialchars($h['class_name']) ?></td>
            <td><?= $h['session_date'] ?></td>
            <td>
                <span style="color:#dc2626;font-weight:600"><?= strtoupper($h['original_status']) ?></span> →
                <span style="color:#16a34a;font-weight:600"><?= strtoupper($h['requested_status']) ?></span>
            </td>
            <td><span class="badge" style="background:<?= $hbg ?>;color:<?= $hfg ?>"><?= $hicon ?> <?= ucfirst($h['status']) ?></span></td>
            <td><small class="text-muted"><?= $h['reviewed_at'] ? date('d M H:i', strtotime($h['reviewed_at'])) : '–' ?></small></td>
            <td><a href="?id=<?= $h['id'] ?>" class="btn btn-secondary btn-sm">View</a></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table></div>
    <?php endif; ?>
</div>

<?php include '../includes/footer.php'; ?>
