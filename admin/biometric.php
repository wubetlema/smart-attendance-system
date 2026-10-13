<?php
/**
 * Admin Biometric Enrollment Management
 *
 * Administrators can:
 *   - See all enrolled students (face registered)
 *   - See students with no face (not enrolled)
 *   - Approve pending enrollments (if require_admin_approval=1)
 *   - Revoke a student's biometric access
 *   - Re-activate revoked enrollment
 *   - Force re-registration (clears current face)
 *   - Delete face data entirely
 *   - View full face audit log per student
 *   - Configure biometric settings
 */
require_once '../includes/auth_check.php';
requireRole('admin');
require_once '../config/db.php';
require_once '../includes/functions.php';

$msg = ''; $msgType = 'success';
$tab = $_GET['tab'] ?? 'enrolled';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    verifyCsrf();
    $adminId = (int)$_SESSION['user_id'];
    $userId  = (int)($_POST['user_id'] ?? 0);

    // ── Approve enrollment ────────────────────────────────────────────────────
    if ($_POST['action'] === 'approve') {
        $conn->query("UPDATE users SET face_admin_approved=1, face_revoked=0 WHERE id=$userId");
        // Audit
        $conn->prepare("INSERT INTO face_registrations (user_id,action,performed_by,performed_role,liveness_passed,ip_address) VALUES (?,  'reactivated',?,  'admin',1,?)")
            ->bind_param('iis', $userId, $adminId, $_SERVER['REMOTE_ADDR']);
        $ins = $conn->prepare("INSERT INTO face_registrations (user_id, action, performed_by, performed_role, liveness_passed, ip_address) VALUES (?, 'reactivated', ?, 'admin', 1, ?)");
        $ins->bind_param('iis', $userId, $adminId, $_SERVER['REMOTE_ADDR']);
        $ins->execute();
        logActivity('biometric_approve', "Admin approved face enrollment for user $userId", $adminId);
        $msg = 'Face enrollment approved. Student can now use face check-in.';

    // ── Revoke enrollment (keep data, disable check-in) ───────────────────────
    } elseif ($_POST['action'] === 'revoke') {
        $notes = trim($_POST['admin_notes'] ?? '');
        $conn->query("UPDATE users SET face_revoked=1, face_admin_approved=0 WHERE id=$userId");
        $ins = $conn->prepare("INSERT INTO face_registrations (user_id, action, performed_by, performed_role, liveness_passed, ip_address, admin_notes) VALUES (?, 'revoked', ?, 'admin', 0, ?, ?)");
        $ins->bind_param('iiss', $userId, $adminId, $_SERVER['REMOTE_ADDR'], $notes);
        $ins->execute();
        logActivity('biometric_revoke', "Admin revoked face access for user $userId. Notes: $notes", $adminId);
        $msg = 'Face enrollment revoked. Student cannot use face check-in until re-approved.';

    // ── Force re-registration (clear face data) ────────────────────────────────
    } elseif ($_POST['action'] === 'force_reregister') {
        $notes = trim($_POST['admin_notes'] ?? 'Admin forced re-registration');
        $conn->query("UPDATE users SET face_descriptor=NULL, face_registered_at=NULL, face_admin_approved=0, face_revoked=0 WHERE id=$userId");
        $ins = $conn->prepare("INSERT INTO face_registrations (user_id, action, performed_by, performed_role, liveness_passed, ip_address, admin_notes) VALUES (?, 'deleted_by_admin', ?, 'admin', 0, ?, ?)");
        $ins->bind_param('iiss', $userId, $adminId, $_SERVER['REMOTE_ADDR'], $notes);
        $ins->execute();
        logActivity('biometric_force_rereg', "Admin cleared face data for user $userId. Notes: $notes", $adminId);
        $msg = 'Face data cleared. Student must re-register their face.';

    // ── Delete face data entirely ──────────────────────────────────────────────
    } elseif ($_POST['action'] === 'delete_face') {
        $notes = trim($_POST['admin_notes'] ?? 'Admin deleted biometric data');
        $conn->query("UPDATE users SET face_descriptor=NULL, face_registered_at=NULL, face_registered_by=NULL, face_admin_approved=0, face_revoked=0 WHERE id=$userId");
        $ins = $conn->prepare("INSERT INTO face_registrations (user_id, action, performed_by, performed_role, liveness_passed, ip_address, admin_notes) VALUES (?, 'deleted_by_admin', ?, 'admin', 0, ?, ?)");
        $ins->bind_param('iiss', $userId, $adminId, $_SERVER['REMOTE_ADDR'], $notes);
        $ins->execute();
        logActivity('biometric_delete', "Admin deleted face data for user $userId", $adminId);
        $msg = 'Biometric data permanently deleted.';

    // ── Save settings ─────────────────────────────────────────────────────────
    } elseif ($_POST['action'] === 'save_settings') {
        $settings = [
            'face_require_admin_approval'  => isset($_POST['face_require_admin_approval']) ? '1' : '0',
            'face_min_samples'             => max(1, min(10, (int)$_POST['face_min_samples'])),
            'face_max_registrations_per_hour' => max(1, min(20, (int)$_POST['face_max_registrations_per_hour'])),
        ];
        // Encryption key — only update if provided
        if (!empty($_POST['face_encryption_key']) && strlen($_POST['face_encryption_key']) >= 16) {
            $settings['face_encryption_key'] = trim($_POST['face_encryption_key']);
        }
        foreach ($settings as $k => $v) {
            $sv = (string)$v;
            $s  = $conn->prepare("INSERT INTO settings (setting_key, setting_value) VALUES (?,?) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)");
            $s->bind_param('ss', $k, $sv);
            $s->execute();
        }
        logActivity('biometric_settings_changed', 'Admin updated biometric settings', $adminId);
        $msg = 'Biometric settings saved.';
        $tab = 'settings';
    }
}

// ── Data ──────────────────────────────────────────────────────────────────────
// Stats
$stats = $conn->query("
    SELECT
        COUNT(*) AS total_students,
        SUM(face_descriptor IS NOT NULL AND face_descriptor != '') AS enrolled,
        SUM(face_descriptor IS NOT NULL AND face_admin_approved=0 AND face_revoked=0) AS pending_approval,
        SUM(face_revoked=1) AS revoked,
        SUM(face_descriptor IS NULL OR face_descriptor='') AS not_enrolled
    FROM users WHERE role='student' AND is_active=1
")->fetch_assoc();

// Enrolled students
$enrolled = $conn->query("
    SELECT u.id, u.name, u.university_id, u.email, u.department,
           u.face_registered_at, u.face_admin_approved, u.face_revoked,
           d.name AS dept_name, p.name AS program_name,
           rb.name AS registered_by_name
    FROM users u
    LEFT JOIN departments d ON u.department_id=d.id
    LEFT JOIN programs p ON u.program_id=p.id
    LEFT JOIN users rb ON u.face_registered_by=rb.id
    WHERE u.role='student' AND u.is_active=1
      AND u.face_descriptor IS NOT NULL AND u.face_descriptor != ''
    ORDER BY u.face_registered_at DESC
")->fetch_all(MYSQLI_ASSOC);

// Not enrolled
$notEnrolled = $conn->query("
    SELECT u.id, u.name, u.university_id, u.department, u.created_at,
           d.name AS dept_name
    FROM users u
    LEFT JOIN departments d ON u.department_id=d.id
    WHERE u.role='student' AND u.is_active=1
      AND (u.face_descriptor IS NULL OR u.face_descriptor='')
    ORDER BY u.name
")->fetch_all(MYSQLI_ASSOC);

// Audit log
$auditUserId = (int)($_GET['audit_user'] ?? 0);
$auditLog    = [];
if ($auditUserId) {
    $auditLog = $conn->query("
        SELECT fr.*, u.name AS subject_name, u.university_id,
               p.name AS performed_by_name
        FROM face_registrations fr
        JOIN users u ON fr.user_id=u.id
        LEFT JOIN users p ON fr.performed_by=p.id
        WHERE fr.user_id=$auditUserId
        ORDER BY fr.created_at DESC
    ")->fetch_all(MYSQLI_ASSOC);
}

// Recent audit (all users, last 30 events)
$recentAudit = $conn->query("
    SELECT fr.*, u.name AS student_name, u.university_id,
           p.name AS performed_by_name
    FROM face_registrations fr
    JOIN users u ON fr.user_id=u.id
    LEFT JOIN users p ON fr.performed_by=p.id
    ORDER BY fr.created_at DESC LIMIT 30
")->fetch_all(MYSQLI_ASSOC);

// Settings
$settings = [];
$sRows = $conn->query("SELECT setting_key, setting_value FROM settings WHERE setting_key LIKE 'face_%'")->fetch_all(MYSQLI_ASSOC);
foreach ($sRows as $r) $settings[$r['setting_key']] = $r['setting_value'];

include '../includes/header.php';
?>

<h1>🔒 Biometric Enrollment Management</h1>
<p class="text-muted">Biometric data is sensitive. All actions are permanently recorded in the audit log.</p>

<?php if ($msg): ?>
<div class="alert alert-<?= $msgType ?>"><?= htmlspecialchars($msg) ?></div>
<?php endif; ?>

<!-- Stats -->
<div class="stats-grid" style="margin-bottom:1.5rem">
    <div class="stat-card">
        <div class="stat-number"><?= $stats['total_students'] ?></div>
        <div class="stat-label">Total Students</div>
    </div>
    <div class="stat-card" style="border-color:#16a34a">
        <div class="stat-number" style="color:#16a34a"><?= $stats['enrolled'] ?></div>
        <div class="stat-label">Face Enrolled</div>
    </div>
    <div class="stat-card" style="border-color:#d97706">
        <div class="stat-number" style="color:#d97706"><?= $stats['pending_approval'] ?></div>
        <div class="stat-label">Pending Approval</div>
    </div>
    <div class="stat-card" style="border-color:#dc2626">
        <div class="stat-number" style="color:#dc2626"><?= $stats['revoked'] ?></div>
        <div class="stat-label">Revoked</div>
    </div>
    <div class="stat-card" style="border-color:#94a3b8">
        <div class="stat-number" style="color:#94a3b8"><?= $stats['not_enrolled'] ?></div>
        <div class="stat-label">Not Enrolled</div>
    </div>
</div>

<!-- Tabs -->
<div style="display:flex;gap:.3rem;flex-wrap:wrap;margin-bottom:1.25rem;border-bottom:2px solid var(--border);padding-bottom:.5rem">
    <?php
    $tabs = ['enrolled'=>'✅ Enrolled','pending'=>'⏳ Pending','not_enrolled'=>'⚠️ Not Enrolled','audit'=>'📋 Audit Log','settings'=>'⚙️ Settings'];
    foreach ($tabs as $k=>$l):
        $count = match($k) { 'pending'=>$stats['pending_approval'], 'not_enrolled'=>$stats['not_enrolled'], default=>'' };
    ?>
    <a href="?tab=<?= $k ?>"
       style="padding:.5rem 1rem;border-radius:8px 8px 0 0;text-decoration:none;font-weight:600;font-size:.88rem;
              background:<?= $tab===$k?'var(--primary)':'var(--hover-bg)' ?>;
              color:<?= $tab===$k?'#fff':'var(--text)' ?>">
        <?= $l ?><?= $count ? " ($count)" : '' ?>
    </a>
    <?php endforeach; ?>
</div>

<!-- ── TAB: Enrolled ──────────────────────────────────────────────────────── -->
<?php if ($tab === 'enrolled'): ?>
<div class="card">
    <h3>Enrolled Students (<?= count($enrolled) ?>)</h3>
    <?php if (empty($enrolled)): ?>
    <p class="text-muted">No students have registered their face yet.</p>
    <?php else: ?>
    <div style="overflow-x:auto">
    <table class="table">
        <thead><tr><th>Student</th><th>Department</th><th>Status</th><th>Registered</th><th>Actions</th></tr></thead>
        <tbody>
        <?php foreach ($enrolled as $e):
            $statusLabel = $e['face_revoked']
                ? ['🚫 Revoked',    '#fee2e2','#b91c1c']
                : ($e['face_admin_approved']
                    ? ['✅ Active',  '#dcfce7','#15803d']
                    : ['⏳ Pending', '#fef3c7','#b45309']);
            [$slabel,$sbg,$sfg] = $statusLabel;
        ?>
        <tr>
            <td>
                <strong><?= htmlspecialchars($e['name']) ?></strong>
                <br><code style="font-size:.75rem"><?= htmlspecialchars($e['university_id']??'') ?></code>
                <?php if ($e['email']): ?><br><small class="text-muted"><?= htmlspecialchars($e['email']) ?></small><?php endif; ?>
            </td>
            <td><?= htmlspecialchars($e['dept_name'] ?: ($e['department'] ?: '–')) ?></td>
            <td><span class="badge" style="background:<?= $sbg ?>;color:<?= $sfg ?>"><?= $slabel ?></span></td>
            <td>
                <small><?= $e['face_registered_at'] ? date('d M Y H:i', strtotime($e['face_registered_at'])) : '–' ?></small>
                <?php if ($e['registered_by_name']): ?>
                <br><small class="text-muted">by <?= htmlspecialchars($e['registered_by_name']) ?></small>
                <?php endif; ?>
            </td>
            <td>
                <div style="display:flex;gap:.3rem;flex-wrap:wrap">
                    <?php if (!$e['face_admin_approved'] && !$e['face_revoked']): ?>
                    <form method="POST" style="display:inline">
                        <?= csrfField() ?><input type="hidden" name="action" value="approve"><input type="hidden" name="user_id" value="<?= $e['id'] ?>">
                        <button class="btn btn-sm" style="background:#16a34a;color:#fff">Approve</button>
                    </form>
                    <?php endif; ?>
                    <?php if (!$e['face_revoked']): ?>
                    <button class="btn btn-sm" style="background:#d97706;color:#fff"
                            onclick="showAdminAction(<?= $e['id'] ?>, '<?= htmlspecialchars($e['name'],ENT_QUOTES) ?>', 'revoke')">Revoke</button>
                    <?php else: ?>
                    <form method="POST" style="display:inline">
                        <?= csrfField() ?><input type="hidden" name="action" value="approve"><input type="hidden" name="user_id" value="<?= $e['id'] ?>">
                        <button class="btn btn-sm" style="background:#16a34a;color:#fff">Re-activate</button>
                    </form>
                    <?php endif; ?>
                    <button class="btn btn-sm" style="background:#6366f1;color:#fff"
                            onclick="showAdminAction(<?= $e['id'] ?>, '<?= htmlspecialchars($e['name'],ENT_QUOTES) ?>', 'force_reregister')">Force Re-reg</button>
                    <button class="btn btn-danger btn-sm"
                            onclick="showAdminAction(<?= $e['id'] ?>, '<?= htmlspecialchars($e['name'],ENT_QUOTES) ?>', 'delete_face')">Delete Data</button>
                    <a href="?tab=audit&audit_user=<?= $e['id'] ?>" class="btn btn-secondary btn-sm">Audit</a>
                </div>
            </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
    <?php endif; ?>
</div>

<!-- ── TAB: Not Enrolled ─────────────────────────────────────────────────── -->
<?php elseif ($tab === 'not_enrolled'): ?>
<div class="card">
    <h3>Students Without Face Registration (<?= count($notEnrolled) ?>)</h3>
    <p class="text-muted">These students cannot use face check-in.</p>
    <div style="overflow-x:auto">
    <table class="table">
        <thead><tr><th>Student</th><th>University ID</th><th>Department</th><th>Joined</th></tr></thead>
        <tbody>
        <?php foreach ($notEnrolled as $ne): ?>
        <tr>
            <td><strong><?= htmlspecialchars($ne['name']) ?></strong></td>
            <td><code><?= htmlspecialchars($ne['university_id']??'') ?></code></td>
            <td><?= htmlspecialchars($ne['dept_name'] ?: '–') ?></td>
            <td><small class="text-muted"><?= date('d M Y', strtotime($ne['created_at'])) ?></small></td>
        </tr>
        <?php endforeach; ?>
        <?php if (empty($notEnrolled)): ?><tr><td colspan="4" class="text-muted" style="text-align:center;padding:2rem">All active students have registered their face.</td></tr><?php endif; ?>
        </tbody>
    </table>
    </div>
</div>

<!-- ── TAB: Audit Log ─────────────────────────────────────────────────────── -->
<?php elseif ($tab === 'audit'): ?>
<?php if ($auditUserId && !empty($auditLog)): ?>
<div class="card" style="border-left:4px solid #6366f1">
    <h3>Audit Log — <?= htmlspecialchars($auditLog[0]['student_name'] ?? '') ?></h3>
    <a href="?tab=audit" class="btn btn-secondary btn-sm" style="margin-bottom:.75rem">← All Events</a>
    <div style="overflow-x:auto">
    <table class="table">
        <thead><tr><th>Action</th><th>By</th><th>Samples</th><th>Liveness</th><th>IP</th><th>Notes</th><th>Time</th></tr></thead>
        <tbody>
        <?php
        $aColors = ['registered'=>['#dcfce7','#15803d'],'updated'=>['#dbeafe','#1e40af'],'deleted_by_user'=>['#fee2e2','#b91c1c'],'deleted_by_admin'=>['#fee2e2','#b91c1c'],'revoked'=>['#fef3c7','#b45309'],'reactivated'=>['#dcfce7','#15803d'],'failed_attempt'=>['#fef3c7','#b45309']];
        foreach ($auditLog as $a):
            [$abg,$afg] = $aColors[$a['action']] ?? ['#f1f5f9','#475569'];
        ?>
        <tr>
            <td><span class="badge" style="background:<?= $abg ?>;color:<?= $afg ?>"><?= ucfirst(str_replace('_',' ',$a['action'])) ?></span></td>
            <td><?= htmlspecialchars($a['performed_by_name'] ?? '–') ?><br><small class="text-muted"><?= $a['performed_role'] ?></small></td>
            <td><?= $a['samples_used'] ?: '–' ?></td>
            <td><?= $a['liveness_passed'] ? '✅' : '–' ?></td>
            <td><code style="font-size:.75rem"><?= htmlspecialchars($a['ip_address']) ?></code></td>
            <td><small><?= htmlspecialchars(substr($a['admin_notes'],0,60)) ?></small></td>
            <td><small class="text-muted"><?= date('d M Y H:i', strtotime($a['created_at'])) ?></small></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
</div>
<?php else: ?>
<div class="card">
    <h3>Recent Biometric Events (Last 30)</h3>
    <div style="overflow-x:auto">
    <table class="table">
        <thead><tr><th>Student</th><th>Action</th><th>By</th><th>IP</th><th>Time</th><th></th></tr></thead>
        <tbody>
        <?php
        foreach ($recentAudit as $a):
            [$abg,$afg] = $aColors[$a['action']] ?? ['#f1f5f9','#475569'];
        ?>
        <tr>
            <td>
                <strong><?= htmlspecialchars($a['student_name']) ?></strong>
                <br><code style="font-size:.75rem"><?= htmlspecialchars($a['university_id']) ?></code>
            </td>
            <td><span class="badge" style="background:<?= $abg ?>;color:<?= $afg ?>"><?= ucfirst(str_replace('_',' ',$a['action'])) ?></span></td>
            <td><?= htmlspecialchars($a['performed_by_name'] ?? '–') ?></td>
            <td><code style="font-size:.75rem"><?= htmlspecialchars($a['ip_address']) ?></code></td>
            <td><small class="text-muted"><?= date('d M Y H:i', strtotime($a['created_at'])) ?></small></td>
            <td><a href="?tab=audit&audit_user=<?= $a['user_id'] ?>" class="btn btn-secondary btn-sm">View All</a></td>
        </tr>
        <?php endforeach; ?>
        <?php if (empty($recentAudit)): ?><tr><td colspan="6" class="text-muted" style="text-align:center;padding:2rem">No biometric events yet.</td></tr><?php endif; ?>
        </tbody>
    </table>
    </div>
</div>
<?php endif; ?>

<!-- ── TAB: Settings ──────────────────────────────────────────────────────── -->
<?php elseif ($tab === 'settings'): ?>
<div class="card" style="max-width:600px">
    <h3>⚙️ Biometric Settings</h3>
    <div class="alert alert-warning" style="margin-bottom:1.25rem;font-size:.88rem">
        ⚠️ The encryption key protects biometric data at rest. Set it once before deployment and never change it — changing the key makes all existing face data unreadable.
    </div>
    <form method="POST">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="save_settings">

        <div class="form-group">
            <label>AES-256 Encryption Key for Face Descriptors</label>
            <input type="password" name="face_encryption_key"
                   placeholder="Leave blank to keep current key"
                   autocomplete="new-password">
            <small class="text-muted">
                Min 16 characters. Use a random string of 32+ chars.
                <?= !empty($settings['face_encryption_key']) ? '✅ Key is set.' : '⚠️ No key set — descriptors stored as plain JSON.' ?>
            </small>
        </div>

        <div class="form-group">
            <label>Minimum Face Samples per Registration</label>
            <input type="number" name="face_min_samples"
                   value="<?= (int)($settings['face_min_samples'] ?? 3) ?>"
                   min="1" max="10">
            <small class="text-muted">More samples = better accuracy. Recommended: 3.</small>
        </div>

        <div class="form-group">
            <label>Max Registration Attempts per Hour</label>
            <input type="number" name="face_max_registrations_per_hour"
                   value="<?= (int)($settings['face_max_registrations_per_hour'] ?? 3) ?>"
                   min="1" max="20">
        </div>

        <div class="form-group">
            <label style="display:flex;align-items:center;gap:.6rem;cursor:pointer">
                <input type="checkbox" name="face_require_admin_approval"
                       <?= ($settings['face_require_admin_approval'] ?? '0') === '1' ? 'checked' : '' ?>>
                Require admin approval before new enrollments are active
            </label>
            <small class="text-muted">If enabled, new face registrations show as "Pending" until an admin approves them.</small>
        </div>

        <button type="submit" class="btn btn-primary">Save Settings</button>
    </form>
</div>
<?php endif; ?>

<!-- Admin action modal -->
<div id="adminModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:999;align-items:center;justify-content:center">
    <div class="card" style="width:420px;max-width:95vw;margin:0">
        <h3 id="modalTitle">Confirm Action</h3>
        <p id="modalDesc" style="color:var(--text-muted);margin-bottom:1rem"></p>
        <form method="POST" id="adminForm">
            <?= csrfField() ?>
            <input type="hidden" name="action" id="modalAction">
            <input type="hidden" name="user_id" id="modalUserId">
            <div class="form-group">
                <label>Admin Notes (reason for this action)</label>
                <textarea name="admin_notes" rows="3" placeholder="Reason for this action..."
                          style="width:100%;padding:.6rem;border:1.5px solid var(--border);border-radius:8px;resize:vertical"></textarea>
            </div>
            <div style="display:flex;gap:.75rem">
                <button type="submit" id="modalConfirmBtn" class="btn btn-danger">Confirm</button>
                <button type="button" class="btn btn-secondary" onclick="closeModal()">Cancel</button>
            </div>
        </form>
    </div>
</div>

<script>
const actionConfig = {
    revoke:          { title: 'Revoke Face Enrollment', desc: 'This student will no longer be able to use face check-in. Their face data is kept.', btnClass: '#d97706' },
    force_reregister:{ title: 'Force Re-Registration',  desc: 'Face data will be cleared. Student must register their face again.',              btnClass: '#6366f1' },
    delete_face:     { title: 'Delete Biometric Data',  desc: '⚠️ Face descriptor will be permanently deleted. This cannot be undone.',          btnClass: '#dc2626' },
};

function showAdminAction(userId, name, action) {
    const cfg = actionConfig[action] || {};
    document.getElementById('modalTitle').textContent      = cfg.title || action;
    document.getElementById('modalDesc').textContent       = `Student: ${name}\n${cfg.desc || ''}`;
    document.getElementById('modalAction').value           = action;
    document.getElementById('modalUserId').value           = userId;
    document.getElementById('modalConfirmBtn').style.background = cfg.btnClass || '#dc2626';
    document.getElementById('adminModal').style.display   = 'flex';
}
function closeModal() {
    document.getElementById('adminModal').style.display = 'none';
}
</script>

<?php include '../includes/footer.php'; ?>
