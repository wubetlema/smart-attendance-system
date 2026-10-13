<?php
/**
 * Student — Attendance Correction Request
 *
 * Student can dispute a specific attendance record.
 * Workflow: Student submits → Teacher reviews → Approve/Reject → Audit log
 */
require_once '../includes/auth_check.php';
requireRole('student');
require_once '../config/db.php';
require_once '../includes/functions.php';
require_once '../includes/audit.php';
require_once '../includes/notification_engine.php';

$user = currentUser();
$msg = ''; $msgType = 'success';

// ── Submit correction request ─────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'submit') {
    verifyCsrf();

    $attendanceId     = (int)$_POST['attendance_id'];
    $requestedStatus  = in_array($_POST['requested_status'] ?? '', ['present','late']) ? $_POST['requested_status'] : 'present';
    $reason           = trim($_POST['reason'] ?? '');
    $evidencePath     = '';

    if (strlen($reason) < 10) {
        $msg = 'Please provide a detailed reason (at least 10 characters).'; $msgType = 'error';
    } else {
        // Verify this attendance record belongs to this student
        $stmt = $conn->prepare("
            SELECT a.id, a.status AS original_status, a.method,
                   s.id AS session_id, s.class_id,
                   c.name AS class_name, c.teacher_id,
                   DATE(s.open_at) AS session_date
            FROM attendance a
            JOIN attendance_sessions s ON a.session_id = s.id
            JOIN classes c ON s.class_id = c.id
            WHERE a.id = ? AND a.student_id = ?
        ");
        $stmt->bind_param('ii', $attendanceId, $user['id']);
        $stmt->execute();
        $record = $stmt->get_result()->fetch_assoc();

        if (!$record) {
            $msg = 'Attendance record not found.'; $msgType = 'error';
        } else {
            // Check no pending request already exists for this record
            $exists = $conn->prepare("SELECT id FROM attendance_corrections WHERE attendance_id=? AND status='pending'");
            $exists->bind_param('i', $attendanceId);
            $exists->execute();
            if ($exists->get_result()->num_rows > 0) {
                $msg = 'A correction request is already pending for this record.'; $msgType = 'error';
            } else {
                // Handle optional evidence file upload
                if (!empty($_FILES['evidence']['name'])) {
                    $ext = strtolower(pathinfo($_FILES['evidence']['name'], PATHINFO_EXTENSION));
                    $allowed = ['jpg','jpeg','png','pdf'];
                    if (in_array($ext, $allowed) && $_FILES['evidence']['size'] < 5 * 1024 * 1024) {
                        $dir = '../assets/uploads/evidence/';
                        if (!is_dir($dir)) mkdir($dir, 0755, true);
                        $fname = 'corr_' . $user['id'] . '_' . time() . '.' . $ext;
                        if (move_uploaded_file($_FILES['evidence']['tmp_name'], $dir . $fname)) {
                            $evidencePath = $fname;
                        }
                    }
                }

                $ins = $conn->prepare("
                    INSERT INTO attendance_corrections
                        (attendance_id, session_id, student_id, class_id,
                         original_status, original_method, requested_status, reason, evidence_path)
                    VALUES (?,?,?,?,?,?,?,?,?)
                ");
                $ins->bind_param('iiissssss',
                    $attendanceId,
                    $record['session_id'],
                    $user['id'],
                    $record['class_id'],
                    $record['original_status'],
                    $record['method'],
                    $requestedStatus,
                    $reason,
                    $evidencePath
                );

                if ($ins->execute()) {
                    $correctionId = $conn->insert_id;

                    // Audit
                    Audit::log(Audit::CORRECTION_REQUESTED, 'attendance_correction', $correctionId, [
                        'old'         => ['status' => $record['original_status']],
                        'new'         => ['status' => $requestedStatus],
                        'reason'      => $reason,
                        'description' => "Student requested correction for {$record['class_name']}",
                        'user_id'     => $user['id'],
                    ]);

                    // Notify teacher (in-app + email)
                    $studentName = $_SESSION['name'] ?? 'Student';
                    Notify::correctionRequested(
                        $record['teacher_id'],
                        $studentName,
                        $record['class_name'],
                        $correctionId,
                        $record['original_status'],
                        $requestedStatus,
                        $reason
                    );

                    $msg = 'Correction request submitted. Your teacher will review it shortly.';
                } else {
                    $msg = 'Error submitting request: ' . $conn->error; $msgType = 'error';
                }
            }
        }
    }
}

// ── Cancel a pending request ──────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'cancel') {
    verifyCsrf();
    $cid = (int)$_POST['correction_id'];
    $upd = $conn->prepare("UPDATE attendance_corrections SET status='cancelled' WHERE id=? AND student_id=? AND status='pending'");
    $upd->bind_param('ii', $cid, $user['id']);
    $upd->execute();
    if ($conn->affected_rows > 0) {
        Audit::log(Audit::CORRECTION_CANCELLED, 'attendance_correction', $cid, ['user_id' => $user['id']]);
        $msg = 'Request cancelled.';
    } else {
        $msg = 'Could not cancel (already reviewed or not found).'; $msgType = 'error';
    }
}

// ── Pre-fill from ?attendance_id=X ────────────────────────────────────────────
$preAttendanceId = (int)($_GET['attendance_id'] ?? 0);
$preRecord = null;
if ($preAttendanceId) {
    $pre = $conn->prepare("
        SELECT a.id, a.status, a.method, a.marked_at,
               c.name AS class_name, c.subject,
               DATE(s.open_at) AS session_date
        FROM attendance a
        JOIN attendance_sessions s ON a.session_id=s.id
        JOIN classes c ON s.class_id=c.id
        WHERE a.id=? AND a.student_id=?
    ");
    $pre->bind_param('ii', $preAttendanceId, $user['id']);
    $pre->execute();
    $preRecord = $pre->get_result()->fetch_assoc();
}

// ── My attendance records (correctable) ──────────────────────────────────────
$myRecords = $conn->query("
    SELECT a.id AS attendance_id, a.status, a.method, a.marked_at,
           c.name AS class_name, c.subject,
           DATE(s.open_at) AS session_date,
           (SELECT status FROM attendance_corrections WHERE attendance_id=a.id ORDER BY created_at DESC LIMIT 1) AS correction_status
    FROM attendance a
    JOIN attendance_sessions s ON a.session_id=s.id
    JOIN classes c ON s.class_id=c.id
    WHERE a.student_id={$user['id']}
    ORDER BY a.marked_at DESC
    LIMIT 30
")->fetch_all(MYSQLI_ASSOC);

// ── My correction requests ────────────────────────────────────────────────────
$myRequests = $conn->query("
    SELECT cr.*, c.name AS class_name, c.subject,
           DATE(s.open_at) AS session_date,
           u.name AS reviewer_name
    FROM attendance_corrections cr
    JOIN classes c ON cr.class_id=c.id
    JOIN attendance_sessions s ON cr.session_id=s.id
    LEFT JOIN users u ON cr.reviewed_by=u.id
    WHERE cr.student_id={$user['id']}
    ORDER BY cr.created_at DESC
")->fetch_all(MYSQLI_ASSOC);

include '../includes/header.php';
?>

<h1>Attendance Correction Requests</h1>

<div class="alert alert-info" style="font-size:.9rem">
    📋 If your attendance was recorded incorrectly, submit a correction request below.
    Your teacher will review it and respond. All changes are permanently logged.
</div>

<?php if ($msg): ?>
<div class="alert alert-<?= $msgType ?>"><?= htmlspecialchars($msg) ?></div>
<?php endif; ?>

<div style="display:grid;grid-template-columns:1fr 1.4fr;gap:1.5rem;align-items:start">

<!-- Left: Submit form -->
<div class="card">
    <h3>Submit Correction Request</h3>
    <form method="POST" enctype="multipart/form-data">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="submit">

        <div class="form-group">
            <label>Attendance Record to Correct</label>
            <select name="attendance_id" required id="attendanceSelect" onchange="updateOriginalStatus(this)">
                <option value="">Select a record...</option>
                <?php foreach ($myRecords as $r):
                    $canRequest = !$r['correction_status'] || $r['correction_status'] === 'rejected';
                    if (!$canRequest) continue;
                ?>
                <option value="<?= $r['attendance_id'] ?>"
                        data-status="<?= $r['status'] ?>"
                        <?= $preAttendanceId == $r['attendance_id'] ? 'selected' : '' ?>>
                    <?= htmlspecialchars($r['class_name']) ?> — <?= $r['session_date'] ?>
                    (Currently: <?= strtoupper($r['status']) ?>)
                </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="form-group">
            <label>Original Status</label>
            <div id="originalStatus" style="padding:.5rem .75rem;background:#fee2e2;border-radius:7px;color:#b91c1c;font-weight:600">
                <?= $preRecord ? strtoupper($preRecord['status']) : 'Select a record above' ?>
            </div>
        </div>

        <div class="form-group">
            <label>Requested Correction</label>
            <select name="requested_status" required>
                <option value="present">Present</option>
                <option value="late">Late</option>
            </select>
        </div>

        <div class="form-group">
            <label>Reason <span style="color:#dc2626">*</span></label>
            <textarea name="reason" rows="4" required minlength="10"
                      placeholder="Explain why the attendance record is incorrect. E.g.: I attended the class but my QR check-in failed due to a network error. I was sitting in the front row."
                      style="width:100%;padding:.65rem;border:1.5px solid var(--border);border-radius:8px;resize:vertical"></textarea>
            <small class="text-muted">Be specific. Vague reasons are harder for your teacher to verify.</small>
        </div>

        <div class="form-group">
            <label>Supporting Evidence (optional)</label>
            <input type="file" name="evidence" accept=".jpg,.jpeg,.png,.pdf">
            <small class="text-muted">Photo, screenshot, or document. Max 5MB. Accepted: JPG, PNG, PDF.</small>
        </div>

        <button type="submit" class="btn btn-primary btn-full">Submit Correction Request</button>
    </form>
</div>

<!-- Right: My requests -->
<div>
    <div class="card">
        <h3>My Correction Requests</h3>
        <?php if (empty($myRequests)): ?>
        <p class="text-muted">No correction requests yet.</p>
        <?php else: ?>
        <div style="display:flex;flex-direction:column;gap:.75rem">
        <?php
        $statusColors = [
            'pending'  => ['#fef3c7','#b45309','⏳'],
            'approved' => ['#dcfce7','#15803d','✅'],
            'rejected' => ['#fee2e2','#b91c1c','❌'],
            'cancelled'=> ['#f1f5f9','#64748b','🚫'],
        ];
        foreach ($myRequests as $r):
            [$bg, $fg, $icon] = $statusColors[$r['status']] ?? ['#f1f5f9','#64748b','–'];
        ?>
        <div style="border-radius:10px;border:1.5px solid <?= $bg ?>;padding:1rem;background:<?= $bg ?>22">
            <div style="display:flex;justify-content:space-between;align-items:flex-start;flex-wrap:wrap;gap:.5rem">
                <div>
                    <strong><?= htmlspecialchars($r['class_name']) ?></strong>
                    <span class="text-muted"> — <?= $r['session_date'] ?></span>
                    <br>
                    <small>
                        <span style="color:#dc2626;font-weight:600"><?= strtoupper($r['original_status']) ?></span>
                        → <span style="color:#16a34a;font-weight:600"><?= strtoupper($r['requested_status']) ?></span>
                    </small>
                </div>
                <span style="background:<?= $bg ?>;color:<?= $fg ?>;padding:.25rem .75rem;border-radius:12px;font-weight:700;font-size:.85rem">
                    <?= $icon ?> <?= ucfirst($r['status']) ?>
                </span>
            </div>

            <div style="margin-top:.5rem;font-size:.85rem;color:#475569">
                <strong>Reason:</strong> <?= htmlspecialchars($r['reason']) ?>
            </div>

            <?php if ($r['reviewer_notes']): ?>
            <div style="margin-top:.4rem;padding:.4rem .65rem;background:#fff;border-radius:6px;font-size:.83rem">
                <strong>Teacher's note:</strong> <?= htmlspecialchars($r['reviewer_notes']) ?>
                <?php if ($r['reviewer_name']): ?>
                <span class="text-muted"> — <?= htmlspecialchars($r['reviewer_name']) ?>, <?= $r['reviewed_at'] ? date('d M H:i', strtotime($r['reviewed_at'])) : '' ?></span>
                <?php endif; ?>
            </div>
            <?php endif; ?>

            <?php if ($r['status'] === 'pending'): ?>
            <form method="POST" style="margin-top:.5rem" onsubmit="return confirm('Cancel this request?')">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="cancel">
                <input type="hidden" name="correction_id" value="<?= $r['id'] ?>">
                <button class="btn btn-sm" style="background:#fee2e2;color:#b91c1c;border:none">Cancel Request</button>
            </form>
            <?php endif; ?>
        </div>
        <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>
</div>

</div>

<script>
function updateOriginalStatus(select) {
    const opt    = select.options[select.selectedIndex];
    const status = opt.dataset.status || '';
    const el     = document.getElementById('originalStatus');
    if (status) {
        el.textContent    = status.toUpperCase();
        el.style.background = status === 'absent' ? '#fee2e2' : '#fef3c7';
        el.style.color      = status === 'absent' ? '#b91c1c' : '#b45309';
    } else {
        el.textContent    = 'Select a record above';
        el.style.background = '#f1f5f9';
        el.style.color      = '#64748b';
    }
}
document.addEventListener('DOMContentLoaded', () => {
    const sel = document.getElementById('attendanceSelect');
    if (sel && sel.value) updateOriginalStatus(sel);
});
</script>

<?php include '../includes/footer.php'; ?>
