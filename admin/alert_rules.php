<?php
require_once '../includes/auth_check.php';
requireRole('admin');
require_once '../config/db.php';

$msg = ''; $msgType = 'success';
$user = currentUser();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];

    if ($action === 'add' || $action === 'edit') {
        $id          = $action === 'edit' ? (int)$_POST['id'] : null;
        $name        = trim($_POST['name']);
        $desc        = trim($_POST['description'] ?? '');
        $type        = $_POST['rule_type'];
        $threshVal   = (float)($_POST['threshold_value'] ?? 75);
        $threshDays  = (int)($_POST['threshold_days'] ?? 3);
        $targetRole  = $_POST['target_role'];
        $priority    = $_POST['priority'];
        $notifyEmail = isset($_POST['notify_email']) ? 1 : 0;
        $notifySMS   = isset($_POST['notify_sms']) ? 1 : 0;
        $notifyInapp = isset($_POST['notify_inapp']) ? 1 : 0;
        $escalateHrs = (int)($_POST['escalate_after_hours'] ?? 24);
        $escalateTo  = $_POST['escalate_to_role'];
        $isActive    = isset($_POST['is_active']) ? 1 : 0;

        if ($action === 'add') {
            $stmt = $conn->prepare("INSERT INTO alert_rules (name,description,rule_type,threshold_value,threshold_days,target_role,priority,notify_email,notify_sms,notify_inapp,escalate_after_hours,escalate_to_role,is_active,created_by) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
            $stmt->bind_param('sssdisissisiii', $name,$desc,$type,$threshVal,$threshDays,$targetRole,$priority,$notifyEmail,$notifySMS,$notifyInapp,$escalateHrs,$escalateTo,$isActive,$user['id']);
        } else {
            $stmt = $conn->prepare("UPDATE alert_rules SET name=?,description=?,rule_type=?,threshold_value=?,threshold_days=?,target_role=?,priority=?,notify_email=?,notify_sms=?,notify_inapp=?,escalate_after_hours=?,escalate_to_role=?,is_active=? WHERE id=?");
            $stmt->bind_param('sssdisissisiii', $name,$desc,$type,$threshVal,$threshDays,$targetRole,$priority,$notifyEmail,$notifySMS,$notifyInapp,$escalateHrs,$escalateTo,$isActive,$id);
        }
        $msg = $stmt->execute() ? ($action === 'add' ? 'Alert rule created.' : 'Alert rule updated.') : 'Error: '.$conn->error;
        if (!$stmt->execute()) $msgType = 'error';

    } elseif ($action === 'toggle_active') {
        $id  = (int)$_POST['id'];
        $new = (int)$_POST['is_active'] ? 0 : 1;
        $conn->query("UPDATE alert_rules SET is_active=$new WHERE id=$id");
        $msg = $new ? 'Rule activated.' : 'Rule deactivated.';

    } elseif ($action === 'delete') {
        $id = (int)$_POST['id'];
        $conn->query("DELETE FROM alert_rules WHERE id=$id");
        $msg = 'Rule deleted.';
    }
}

$rules = $conn->query("SELECT r.*, u.name AS creator_name FROM alert_rules r LEFT JOIN users u ON r.created_by=u.id ORDER BY r.priority DESC, r.name")->fetch_all(MYSQLI_ASSOC);

$editRule = null;
if (isset($_GET['edit'])) {
    $eid = (int)$_GET['edit'];
    $stmt = $conn->prepare("SELECT * FROM alert_rules WHERE id=?");
    $stmt->bind_param('i', $eid); $stmt->execute();
    $editRule = $stmt->get_result()->fetch_assoc();
}

include '../includes/header.php';
?>
<h1>Alert Rules Configuration</h1>
<?php if ($msg): ?><div class="alert alert-<?= $msgType ?>"><?= htmlspecialchars($msg) ?></div><?php endif; ?>

<div class="card" style="border-left:4px solid #6366f1;margin-bottom:1.5rem">
    <h3>📢 Alert System Overview</h3>
    <p>Configure automated alerts for attendance issues, consecutive absences, late arrivals, and custom conditions. Alerts can be sent via email, SMS, and in-app notifications with escalation rules.</p>
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:1rem;margin-top:1rem">
        <?php
        $stats = [
            'active' => $conn->query("SELECT COUNT(*) c FROM alert_rules WHERE is_active=1")->fetch_assoc()['c'],
            'pending' => $conn->query("SELECT COUNT(*) c FROM alerts WHERE status='pending'")->fetch_assoc()['c'],
            'sent_today' => $conn->query("SELECT COUNT(*) c FROM alerts WHERE DATE(created_at)=CURDATE()")->fetch_assoc()['c'],
            'escalated' => $conn->query("SELECT COUNT(*) c FROM alerts WHERE escalated=1 AND status!='resolved'")->fetch_assoc()['c']
        ];
        ?>
        <div style="padding:1rem;background:var(--hover-bg);border-radius:8px">
            <div style="font-size:1.8rem;font-weight:700;color:#6366f1"><?= $stats['active'] ?></div>
            <div class="text-muted">Active Rules</div>
        </div>
        <div style="padding:1rem;background:var(--hover-bg);border-radius:8px">
            <div style="font-size:1.8rem;font-weight:700;color:#d97706"><?= $stats['pending'] ?></div>
            <div class="text-muted">Pending Alerts</div>
        </div>
        <div style="padding:1rem;background:var(--hover-bg);border-radius:8px">
            <div style="font-size:1.8rem;font-weight:700;color:#16a34a"><?= $stats['sent_today'] ?></div>
            <div class="text-muted">Sent Today</div>
        </div>
        <div style="padding:1rem;background:var(--hover-bg);border-radius:8px">
            <div style="font-size:1.8rem;font-weight:700;color:#dc2626"><?= $stats['escalated'] ?></div>
            <div class="text-muted">Escalated</div>
        </div>
    </div>
</div>

<!-- Add/Edit Form -->
<div class="card">
    <h3><?= $editRule ? 'Edit Alert Rule' : 'Create Alert Rule' ?></h3>
    <form method="POST">
        <input type="hidden" name="action" value="<?= $editRule ? 'edit' : 'add' ?>">
        <?php if ($editRule): ?><input type="hidden" name="id" value="<?= $editRule['id'] ?>"><?php endif; ?>
        
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:.75rem;margin-bottom:.75rem">
            <div class="form-group" style="margin:0;grid-column:span 2"><label>Rule Name</label>
                <input type="text" name="name" required value="<?= htmlspecialchars($editRule['name'] ?? '') ?>" placeholder="e.g. Low Attendance Warning"></div>
            
            <div class="form-group" style="margin:0;grid-column:span 2"><label>Description</label>
                <textarea name="description" rows="2" style="width:100%;padding:.6rem;border:1px solid var(--border);border-radius:7px" placeholder="Describe when this alert triggers..."><?= htmlspecialchars($editRule['description'] ?? '') ?></textarea></div>
            
            <div class="form-group" style="margin:0"><label>Rule Type</label>
                <select name="rule_type" required id="ruleType" onchange="updateThresholdFields()">
                    <?php foreach (['attendance_low'=>'Low Attendance %','absence_streak'=>'Consecutive Absences','late_streak'=>'Repeated Late Arrivals','no_checkin'=>'No Check-in','custom'=>'Custom Condition'] as $k=>$v): ?>
                    <option value="<?= $k ?>" <?= ($editRule['rule_type']??'')===$k?'selected':'' ?>><?= $v ?></option>
                    <?php endforeach; ?>
                </select></div>
            
            <div class="form-group" style="margin:0"><label>Priority</label>
                <select name="priority" required>
                    <?php foreach (['low','medium','high','critical'] as $p): ?>
                    <option value="<?= $p ?>" <?= ($editRule['priority']??'medium')===$p?'selected':'' ?>><?= ucfirst($p) ?></option>
                    <?php endforeach; ?>
                </select></div>
            
            <div class="form-group" style="margin:0" id="thresholdValueField">
                <label>Threshold Value (%)</label>
                <input type="number" name="threshold_value" value="<?= $editRule['threshold_value'] ?? 75 ?>" min="0" max="100" step="0.01" placeholder="e.g. 75">
                <small class="text-muted">Alert when attendance falls below this %</small>
            </div>
            
            <div class="form-group" style="margin:0" id="thresholdDaysField">
                <label>Threshold Days/Count</label>
                <input type="number" name="threshold_days" value="<?= $editRule['threshold_days'] ?? 3 ?>" min="1" placeholder="e.g. 3">
                <small class="text-muted">Number of consecutive days/occurrences</small>
            </div>
            
            <div class="form-group" style="margin:0"><label>Target Role</label>
                <select name="target_role" required>
                    <?php foreach (['student','teacher','admin','all'] as $r): ?>
                    <option value="<?= $r ?>" <?= ($editRule['target_role']??'student')===$r?'selected':'' ?>><?= ucfirst($r) ?></option>
                    <?php endforeach; ?>
                </select></div>
        </div>

        <fieldset style="border:1px solid var(--border);border-radius:8px;padding:1rem;margin-bottom:.75rem">
            <legend style="font-weight:600;padding:0 .5rem">Notification Channels</legend>
            <div style="display:flex;gap:1.5rem;flex-wrap:wrap">
                <label style="display:flex;align-items:center;gap:.5rem;cursor:pointer">
                    <input type="checkbox" name="notify_inapp" <?= ($editRule['notify_inapp']??1)?'checked':'' ?>>
                    📱 In-App Notification
                </label>
                <label style="display:flex;align-items:center;gap:.5rem;cursor:pointer">
                    <input type="checkbox" name="notify_email" <?= ($editRule['notify_email']??1)?'checked':'' ?>>
                    📧 Email
                </label>
                <label style="display:flex;align-items:center;gap:.5rem;cursor:pointer">
                    <input type="checkbox" name="notify_sms" <?= ($editRule['notify_sms']??0)?'checked':'' ?>>
                    📲 SMS (requires SMS gateway)
                </label>
            </div>
        </fieldset>

        <fieldset style="border:1px solid var(--border);border-radius:8px;padding:1rem;margin-bottom:.75rem">
            <legend style="font-weight:600;padding:0 .5rem">Escalation Rules</legend>
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:.75rem">
                <div class="form-group" style="margin:0"><label>Escalate After (hours)</label>
                    <input type="number" name="escalate_after_hours" value="<?= $editRule['escalate_after_hours'] ?? 24 ?>" min="1" placeholder="24">
                    <small class="text-muted">Escalate if unread after X hours</small>
                </div>
                <div class="form-group" style="margin:0"><label>Escalate To</label>
                    <select name="escalate_to_role">
                        <?php foreach (['none'=>'No Escalation','teacher'=>'Teacher','admin'=>'Admin'] as $k=>$v): ?>
                        <option value="<?= $k ?>" <?= ($editRule['escalate_to_role']??'teacher')===$k?'selected':'' ?>><?= $v ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
        </fieldset>

        <div style="display:flex;gap:.75rem;align-items:center">
            <label style="display:flex;align-items:center;gap:.5rem;cursor:pointer">
                <input type="checkbox" name="is_active" <?= ($editRule['is_active']??1)?'checked':'' ?>>
                <strong>Rule Active</strong>
            </label>
        </div>

        <div style="margin-top:1rem;display:flex;gap:.75rem">
            <button type="submit" class="btn btn-primary"><?= $editRule ? 'Save Changes' : 'Create Rule' ?></button>
            <?php if ($editRule): ?><a href="alert_rules.php" class="btn btn-secondary">Cancel</a><?php endif; ?>
        </div>
    </form>
</div>

<!-- Rules Table -->
<div class="card">
    <h3>All Alert Rules (<?= count($rules) ?>)</h3>
    <div style="overflow-x:auto">
    <table class="table">
        <thead><tr><th>Rule</th><th>Type</th><th>Threshold</th><th>Target</th><th>Priority</th><th>Channels</th><th>Escalation</th><th>Status</th><th>Actions</th></tr></thead>
        <tbody>
        <?php foreach ($rules as $r):
            $priorityColors = ['low'=>['#f1f5f9','#64748b'],'medium'=>['#fef3c7','#b45309'],'high'=>['#fed7aa','#c2410c'],'critical'=>['#fee2e2','#b91c1c']];
            $pc = $priorityColors[$r['priority']] ?? $priorityColors['medium'];
        ?>
        <tr>
            <td>
                <strong><?= htmlspecialchars($r['name']) ?></strong>
                <?php if($r['description']): ?><br><small class="text-muted"><?= htmlspecialchars($r['description']) ?></small><?php endif; ?>
            </td>
            <td><?= ucfirst(str_replace('_',' ',$r['rule_type'])) ?></td>
            <td>
                <?php if ($r['rule_type'] === 'attendance_low'): ?>
                    < <?= $r['threshold_value'] ?>%
                <?php elseif (in_array($r['rule_type'], ['absence_streak','late_streak'])): ?>
                    <?= $r['threshold_days'] ?> times
                <?php else: ?>
                    -
                <?php endif; ?>
            </td>
            <td><?= ucfirst($r['target_role']) ?></td>
            <td><span class="badge" style="background:<?= $pc[0] ?>;color:<?= $pc[1] ?>"><?= ucfirst($r['priority']) ?></span></td>
            <td>
                <small>
                    <?= $r['notify_inapp']?'📱':'' ?>
                    <?= $r['notify_email']?'📧':'' ?>
                    <?= $r['notify_sms']?'📲':'' ?>
                </small>
            </td>
            <td><small class="text-muted"><?= $r['escalate_to_role']!=='none'?ucfirst($r['escalate_to_role']).' ('.$r['escalate_after_hours'].'h)':'None' ?></small></td>
            <td><span class="badge" style="background:<?= $r['is_active']?'#dcfce7':'#fee2e2' ?>;color:<?= $r['is_active']?'#15803d':'#b91c1c' ?>"><?= $r['is_active']?'Active':'Inactive' ?></span></td>
            <td style="display:flex;gap:.3rem;flex-wrap:wrap">
                <a href="?edit=<?= $r['id'] ?>" class="btn btn-secondary btn-sm">Edit</a>
                <form method="POST" style="display:inline">
                    <input type="hidden" name="action" value="toggle_active">
                    <input type="hidden" name="id" value="<?= $r['id'] ?>">
                    <input type="hidden" name="is_active" value="<?= $r['is_active'] ?>">
                    <button class="btn btn-sm" style="background:<?= $r['is_active']?'#f59e0b':'#16a34a' ?>;color:#fff"><?= $r['is_active']?'Deactivate':'Activate' ?></button>
                </form>
                <form method="POST" style="display:inline" onsubmit="return confirm('Delete rule?')">
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="id" value="<?= $r['id'] ?>">
                    <button class="btn btn-danger btn-sm">Delete</button>
                </form>
            </td>
        </tr>
        <?php endforeach; ?>
        <?php if (empty($rules)): ?>
        <tr><td colspan="9" class="text-muted" style="text-align:center;padding:2rem">No alert rules configured yet.</td></tr>
        <?php endif; ?>
        </tbody>
    </table>
    </div>
</div>

<script>
function updateThresholdFields() {
    const type = document.getElementById('ruleType').value;
    const valField = document.getElementById('thresholdValueField');
    const daysField = document.getElementById('thresholdDaysField');
    
    if (type === 'attendance_low') {
        valField.style.display = 'block';
        daysField.style.display = 'none';
    } else if (type === 'absence_streak' || type === 'late_streak') {
        valField.style.display = 'none';
        daysField.style.display = 'block';
    } else {
        valField.style.display = 'none';
        daysField.style.display = 'none';
    }
}
updateThresholdFields();
</script>
<?php include '../includes/footer.php'; ?>
