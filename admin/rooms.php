<?php
require_once '../includes/auth_check.php';
requireRole('admin');
require_once '../config/db.php';

$msg = ''; $msgType = 'success';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];

    if ($action === 'add') {
        $name      = trim($_POST['name']);
        $building  = trim($_POST['building'] ?? '');
        $capacity  = (int)($_POST['capacity'] ?? 0);
        $type      = $_POST['type'];
        $facilities= trim($_POST['facilities'] ?? '');
        $stmt = $conn->prepare("INSERT INTO rooms (name,building,capacity,type,facilities) VALUES (?,?,?,?,?)");
        $stmt->bind_param('ssiss', $name,$building,$capacity,$type,$facilities);
        $msg = $stmt->execute() ? 'Room added.' : 'Error: '.$conn->error;
        if (!$stmt->execute()) $msgType = 'error';

    } elseif ($action === 'edit') {
        $id        = (int)$_POST['id'];
        $name      = trim($_POST['name']);
        $building  = trim($_POST['building'] ?? '');
        $capacity  = (int)($_POST['capacity'] ?? 0);
        $type      = $_POST['type'];
        $facilities= trim($_POST['facilities'] ?? '');
        $stmt = $conn->prepare("UPDATE rooms SET name=?,building=?,capacity=?,type=?,facilities=? WHERE id=?");
        $stmt->bind_param('ssissi', $name,$building,$capacity,$type,$facilities,$id);
        $msg = $stmt->execute() ? 'Room updated.' : 'Error: '.$conn->error;
        if (!$stmt->execute()) $msgType = 'error';

    } elseif ($action === 'toggle_active') {
        $id  = (int)$_POST['id'];
        $new = (int)$_POST['is_active'] ? 0 : 1;
        $conn->query("UPDATE rooms SET is_active=$new WHERE id=$id");
        $msg = $new ? 'Room activated.' : 'Room deactivated.';

    } elseif ($action === 'delete') {
        $id = (int)$_POST['id'];
        $conn->query("DELETE FROM rooms WHERE id=$id");
        $msg = 'Room deleted.';
    }
}

$rooms = $conn->query("SELECT * FROM rooms ORDER BY building,name")->fetch_all(MYSQLI_ASSOC);

$editRoom = null;
if (isset($_GET['edit'])) {
    $eid = (int)$_GET['edit'];
    $stmt = $conn->prepare("SELECT * FROM rooms WHERE id=?");
    $stmt->bind_param('i', $eid); $stmt->execute();
    $editRoom = $stmt->get_result()->fetch_assoc();
}

include '../includes/header.php';
?>
<h1>Classroom & Room Management</h1>
<?php if ($msg): ?><div class="alert alert-<?= $msgType ?>"><?= htmlspecialchars($msg) ?></div><?php endif; ?>

<!-- Add/Edit Form -->
<div class="card">
    <h3><?= $editRoom ? 'Edit Room' : 'Add New Room' ?></h3>
    <form method="POST">
        <input type="hidden" name="action" value="<?= $editRoom ? 'edit' : 'add' ?>">
        <?php if ($editRoom): ?><input type="hidden" name="id" value="<?= $editRoom['id'] ?>"><?php endif; ?>
        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:.75rem">
            <div class="form-group" style="margin:0"><label>Room Name</label>
                <input type="text" name="name" required value="<?= htmlspecialchars($editRoom['name'] ?? '') ?>" placeholder="e.g. Room 201, Lab 1"></div>
            <div class="form-group" style="margin:0"><label>Building</label>
                <input type="text" name="building" value="<?= htmlspecialchars($editRoom['building'] ?? '') ?>" placeholder="e.g. Main Building, Block A"></div>
            <div class="form-group" style="margin:0"><label>Capacity</label>
                <input type="number" name="capacity" value="<?= $editRoom['capacity'] ?? 30 ?>" min="0" placeholder="Max students"></div>
            <div class="form-group" style="margin:0"><label>Type</label>
                <select name="type" required>
                    <?php foreach (['classroom','lab','lecture_hall','seminar','online'] as $t): ?>
                    <option value="<?= $t ?>" <?= ($editRoom['type']??'')===$t?'selected':'' ?>><?= ucfirst(str_replace('_',' ',$t)) ?></option>
                    <?php endforeach; ?>
                </select></div>
            <div class="form-group" style="margin:0;grid-column:span 2"><label>Facilities</label>
                <input type="text" name="facilities" value="<?= htmlspecialchars($editRoom['facilities'] ?? '') ?>" placeholder="e.g. Projector, Whiteboard, AC, Computers"></div>
        </div>
        <div style="margin-top:1rem;display:flex;gap:.75rem">
            <button type="submit" class="btn btn-primary"><?= $editRoom ? 'Save Changes' : 'Add Room' ?></button>
            <?php if ($editRoom): ?><a href="rooms.php" class="btn btn-secondary">Cancel</a><?php endif; ?>
        </div>
    </form>
</div>

<!-- Rooms Table -->
<div class="card">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:1rem;flex-wrap:wrap;gap:.5rem">
        <h3>All Rooms (<?= count($rooms) ?>)</h3>
        <input type="text" id="searchInput" placeholder="Search..." onkeyup="filterTable()" style="width:200px">
    </div>
    <div style="overflow-x:auto">
    <table class="table" id="roomsTable">
        <thead>
            <tr><th>Room</th><th>Building</th><th>Type</th><th>Capacity</th><th>Facilities</th><th>Status</th><th>Actions</th></tr>
        </thead>
        <tbody>
        <?php foreach ($rooms as $r): ?>
        <tr>
            <td><strong><?= htmlspecialchars($r['name']) ?></strong></td>
            <td><?= htmlspecialchars($r['building'] ?: '-') ?></td>
            <td><span class="badge" style="background:#dbeafe;color:#1e40af"><?= ucfirst(str_replace('_',' ',$r['type'])) ?></span></td>
            <td><?= $r['capacity'] ?: '-' ?></td>
            <td><small class="text-muted"><?= htmlspecialchars($r['facilities'] ?: '-') ?></small></td>
            <td><span class="badge" style="background:<?= $r['is_active']?'#dcfce7':'#fee2e2' ?>;color:<?= $r['is_active']?'#15803d':'#b91c1c' ?>"><?= $r['is_active']?'Active':'Inactive' ?></span></td>
            <td style="display:flex;gap:.3rem;flex-wrap:wrap">
                <a href="?edit=<?= $r['id'] ?>" class="btn btn-secondary btn-sm">Edit</a>
                <form method="POST" style="display:inline">
                    <input type="hidden" name="action" value="toggle_active">
                    <input type="hidden" name="id" value="<?= $r['id'] ?>">
                    <input type="hidden" name="is_active" value="<?= $r['is_active'] ?>">
                    <button class="btn btn-sm" style="background:<?= $r['is_active']?'#f59e0b':'#16a34a' ?>;color:#fff"><?= $r['is_active']?'Deactivate':'Activate' ?></button>
                </form>
                <form method="POST" style="display:inline" onsubmit="return confirm('Delete room?')">
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="id" value="<?= $r['id'] ?>">
                    <button class="btn btn-danger btn-sm">Delete</button>
                </form>
            </td>
        </tr>
        <?php endforeach; ?>
        <?php if (empty($rooms)): ?>
        <tr><td colspan="7" class="text-muted" style="text-align:center;padding:2rem">No rooms yet.</td></tr>
        <?php endif; ?>
        </tbody>
    </table>
    </div>
</div>

<script>
function filterTable() {
    const q = document.getElementById('searchInput').value.toLowerCase();
    document.querySelectorAll('#roomsTable tbody tr').forEach(r => {
        r.style.display = r.textContent.toLowerCase().includes(q) ? '' : 'none';
    });
}
</script>
<?php include '../includes/footer.php'; ?>
