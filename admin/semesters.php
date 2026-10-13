<?php
require_once '../includes/auth_check.php';
requireRole('admin');
require_once '../config/db.php';

$msg = ''; $msgType = 'success';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];

    if ($action === 'add') {
        $name      = trim($_POST['name']);
        $type      = $_POST['term_type'];
        $year      = trim($_POST['academic_year']);
        $start     = $_POST['start_date'];
        $end       = $_POST['end_date'];
        $isCurrent = isset($_POST['is_current']) ? 1 : 0;

        if ($isCurrent) $conn->query("UPDATE academic_terms SET is_current=0, status='completed'");

        $status = date('Y-m-d') < $start ? 'upcoming' : (date('Y-m-d') > $end ? 'completed' : 'active');
        if ($isCurrent) $status = 'active';

        $stmt = $conn->prepare("INSERT INTO academic_terms (name,term_type,academic_year,start_date,end_date,is_current,status) VALUES (?,?,?,?,?,?,?)");
        $stmt->bind_param('sssssss', $name,$type,$year,$start,$end,$isCurrent,$status);
        $msg = $stmt->execute() ? 'Term added.' : 'Error: '.$conn->error;
        if (!$stmt->execute()) $msgType = 'error';

    } elseif ($action === 'edit') {
        $id        = (int)$_POST['id'];
        $name      = trim($_POST['name']);
        $type      = $_POST['term_type'];
        $year      = trim($_POST['academic_year']);
        $start     = $_POST['start_date'];
        $end       = $_POST['end_date'];
        $isCurrent = isset($_POST['is_current']) ? 1 : 0;

        if ($isCurrent) $conn->query("UPDATE academic_terms SET is_current=0, status='completed' WHERE id!=$id");

        $status = date('Y-m-d') < $start ? 'upcoming' : (date('Y-m-d') > $end ? 'completed' : 'active');
        if ($isCurrent) $status = 'active';

        $stmt = $conn->prepare("UPDATE academic_terms SET name=?,term_type=?,academic_year=?,start_date=?,end_date=?,is_current=?,status=? WHERE id=?");
        $stmt->bind_param('sssssssi', $name,$type,$year,$start,$end,$isCurrent,$status,$id);
        $msg = $stmt->execute() ? 'Term updated.' : 'Error: '.$conn->error;
        if (!$stmt->execute()) $msgType = 'error';

    } elseif ($action === 'set_current') {
        $id = (int)$_POST['id'];
        $conn->query("UPDATE academic_terms SET is_current=0, status='completed'");
        $conn->query("UPDATE academic_terms SET is_current=1, status='active' WHERE id=$id");
        $msg = 'Current term updated.';

    } elseif ($action === 'delete') {
        $id = (int)$_POST['id'];
        $conn->query("DELETE FROM academic_terms WHERE id=$id");
        $msg = 'Term deleted.';
    }
}

$terms = $conn->query("SELECT * FROM academic_terms ORDER BY start_date DESC")->fetch_all(MYSQLI_ASSOC);
$currentTerm = $conn->query("SELECT * FROM academic_terms WHERE is_current=1 LIMIT 1")->fetch_assoc();

$editTerm = null;
if (isset($_GET['edit'])) {
    $eid = (int)$_GET['edit'];
    $stmt = $conn->prepare("SELECT * FROM academic_terms WHERE id=?");
    $stmt->bind_param('i', $eid); $stmt->execute();
    $editTerm = $stmt->get_result()->fetch_assoc();
}

include '../includes/header.php';
?>
<h1>Semester / Term Management</h1>
<?php if ($msg): ?><div class="alert alert-<?= $msgType ?>"><?= htmlspecialchars($msg) ?></div><?php endif; ?>

<?php if ($currentTerm): ?>
<div class="card" style="border-left:4px solid #6366f1">
    <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:.5rem">
        <div>
            <span class="text-muted" style="font-size:.85rem">Current Active Term</span>
            <h3 style="margin:.2rem 0"><?= htmlspecialchars($currentTerm['name']) ?></h3>
            <small class="text-muted"><?= $currentTerm['academic_year'] ?> &nbsp;|&nbsp; <?= date('d M Y', strtotime($currentTerm['start_date'])) ?> – <?= date('d M Y', strtotime($currentTerm['end_date'])) ?></small>
        </div>
        <?php
        $today = strtotime(date('Y-m-d'));
        $start = strtotime($currentTerm['start_date']);
        $end   = strtotime($currentTerm['end_date']);
        $total = $end - $start;
        $elapsed = max(0, $today - $start);
        $pct = $total > 0 ? min(100, round(($elapsed / $total) * 100)) : 0;
        $daysLeft = max(0, round(($end - $today) / 86400));
        ?>
        <div style="text-align:right">
            <div style="font-size:1.4rem;font-weight:700;color:#6366f1"><?= $daysLeft ?> days left</div>
            <div class="progress-bar-wrap" style="width:180px;margin-top:.3rem">
                <div class="progress-bar" style="width:<?= $pct ?>%;background:#6366f1"><?= $pct ?>%</div>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- Add/Edit Form -->
<div class="card">
    <h3><?= $editTerm ? 'Edit Term' : 'Add New Term' ?></h3>
    <form method="POST">
        <input type="hidden" name="action" value="<?= $editTerm ? 'edit' : 'add' ?>">
        <?php if ($editTerm): ?><input type="hidden" name="id" value="<?= $editTerm['id'] ?>"><?php endif; ?>
        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:.75rem">
            <div class="form-group" style="margin:0"><label>Term Name</label>
                <input type="text" name="name" required value="<?= htmlspecialchars($editTerm['name'] ?? '') ?>" placeholder="e.g. Semester 1 2024/25"></div>
            <div class="form-group" style="margin:0"><label>Type</label>
                <select name="term_type" required>
                    <?php foreach (['semester','trimester','quarter','year'] as $t): ?>
                    <option value="<?= $t ?>" <?= ($editTerm['term_type']??'semester')===$t?'selected':'' ?>><?= ucfirst($t) ?></option>
                    <?php endforeach; ?>
                </select></div>
            <div class="form-group" style="margin:0"><label>Academic Year</label>
                <input type="text" name="academic_year" required value="<?= htmlspecialchars($editTerm['academic_year'] ?? '') ?>" placeholder="e.g. 2024/25"></div>
            <div class="form-group" style="margin:0"><label>Start Date</label>
                <input type="date" name="start_date" required value="<?= $editTerm['start_date'] ?? '' ?>"></div>
            <div class="form-group" style="margin:0"><label>End Date</label>
                <input type="date" name="end_date" required value="<?= $editTerm['end_date'] ?? '' ?>"></div>
            <div class="form-group" style="margin:0;display:flex;align-items:flex-end">
                <label style="display:flex;align-items:center;gap:.5rem;cursor:pointer;padding-bottom:.4rem">
                    <input type="checkbox" name="is_current" <?= ($editTerm['is_current']??0)?'checked':'' ?>>
                    Set as Current Term
                </label>
            </div>
        </div>
        <div style="margin-top:1rem;display:flex;gap:.75rem">
            <button type="submit" class="btn btn-primary"><?= $editTerm ? 'Save Changes' : 'Add Term' ?></button>
            <?php if ($editTerm): ?><a href="semesters.php" class="btn btn-secondary">Cancel</a><?php endif; ?>
        </div>
    </form>
</div>

<!-- Terms Table -->
<div class="card">
    <h3>All Terms (<?= count($terms) ?>)</h3>
    <div style="overflow-x:auto">
    <table class="table">
        <thead><tr><th>Term</th><th>Type</th><th>Academic Year</th><th>Start</th><th>End</th><th>Status</th><th>Actions</th></tr></thead>
        <tbody>
        <?php foreach ($terms as $t): ?>
        <tr style="<?= $t['is_current']?'background:var(--hover-bg)':'' ?>">
            <td>
                <?= htmlspecialchars($t['name']) ?>
                <?php if ($t['is_current']): ?><span class="badge" style="background:#ede9fe;color:#6d28d9;margin-left:.4rem">Current</span><?php endif; ?>
            </td>
            <td><?= ucfirst($t['term_type']) ?></td>
            <td><?= htmlspecialchars($t['academic_year']) ?></td>
            <td><?= date('d M Y', strtotime($t['start_date'])) ?></td>
            <td><?= date('d M Y', strtotime($t['end_date'])) ?></td>
            <td>
                <?php
                $colors = ['upcoming'=>['#dbeafe','#1e40af'],'active'=>['#dcfce7','#15803d'],'completed'=>['#f1f5f9','#64748b']];
                $c = $colors[$t['status']] ?? $colors['upcoming'];
                ?>
                <span class="badge" style="background:<?= $c[0] ?>;color:<?= $c[1] ?>"><?= ucfirst($t['status']) ?></span>
            </td>
            <td style="display:flex;gap:.3rem;flex-wrap:wrap">
                <a href="?edit=<?= $t['id'] ?>" class="btn btn-secondary btn-sm">Edit</a>
                <?php if (!$t['is_current']): ?>
                <form method="POST" style="display:inline">
                    <input type="hidden" name="action" value="set_current">
                    <input type="hidden" name="id" value="<?= $t['id'] ?>">
                    <button class="btn btn-sm" style="background:#6366f1;color:#fff">Set Current</button>
                </form>
                <?php endif; ?>
                <form method="POST" style="display:inline" onsubmit="return confirm('Delete term?')">
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="id" value="<?= $t['id'] ?>">
                    <button class="btn btn-danger btn-sm">Delete</button>
                </form>
            </td>
        </tr>
        <?php endforeach; ?>
        <?php if (empty($terms)): ?>
        <tr><td colspan="7" class="text-muted" style="text-align:center;padding:2rem">No terms yet.</td></tr>
        <?php endif; ?>
        </tbody>
    </table>
    </div>
</div>
<?php include '../includes/footer.php'; ?>
