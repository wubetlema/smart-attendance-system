<?php
require_once '../includes/auth_check.php';
requireRole('admin');
require_once '../config/db.php';

$msg = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($_POST['action'] === 'add') {
        $name = trim($_POST['name']);
        $stmt = $conn->prepare("INSERT IGNORE INTO departments (name) VALUES (?)");
        $stmt->bind_param('s', $name);
        $msg = $stmt->execute() ? 'Department added.' : 'Error: '.$conn->error;
    } elseif ($_POST['action'] === 'delete') {
        $id = (int)$_POST['id'];
        $conn->query("DELETE FROM departments WHERE id=$id");
        $msg = 'Department deleted.';
    }
}

$depts = $conn->query("SELECT d.*, COUNT(u.id) AS user_count FROM departments d LEFT JOIN users u ON u.department=d.name GROUP BY d.id ORDER BY d.name")->fetch_all(MYSQLI_ASSOC);
include '../includes/header.php';
?>
<h1>Manage Departments</h1>
<?php if ($msg): ?><div class="alert alert-success"><?= htmlspecialchars($msg) ?></div><?php endif; ?>

<div class="card">
    <h3>Add Department</h3>
    <form method="POST" class="form-inline">
        <input type="hidden" name="action" value="add">
        <input type="text" name="name" placeholder="Department name e.g. Computer Science" required style="flex:1">
        <button type="submit" class="btn btn-primary">Add</button>
    </form>
</div>

<div class="card">
    <h3>All Departments</h3>
    <?php if (empty($depts)): ?>
        <p class="text-muted">No departments yet.</p>
    <?php else: ?>
    <table class="table">
        <thead><tr><th>Department</th><th>Users</th><th>Created</th><th>Action</th></tr></thead>
        <tbody>
        <?php foreach ($depts as $d): ?>
        <tr>
            <td><?= htmlspecialchars($d['name']) ?></td>
            <td><?= $d['user_count'] ?></td>
            <td><?= date('d M Y', strtotime($d['created_at'])) ?></td>
            <td>
                <form method="POST" style="display:inline" onsubmit="return confirm('Delete department?')">
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="id" value="<?= $d['id'] ?>">
                    <button type="submit" class="btn btn-danger btn-sm">Delete</button>
                </form>
            </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>
</div>
<?php include '../includes/footer.php'; ?>
