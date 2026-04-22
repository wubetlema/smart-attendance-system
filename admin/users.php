<?php
require_once '../includes/auth_check.php';
requireRole('admin');
require_once '../config/db.php';

$msg = ''; $msgType = 'success';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];

    if ($action === 'add') {
        $name    = trim($_POST['name']);
        $univ_id = trim($_POST['university_id']);
        $pass    = password_hash($_POST['password'], PASSWORD_DEFAULT);
        $role    = $_POST['role'];
        $dept    = trim($_POST['department'] ?? '');
        $email   = trim($_POST['email'] ?? '');
        $phone   = trim($_POST['phone'] ?? '');
        $sem     = trim($_POST['semester'] ?? '');
        $sec     = trim($_POST['section'] ?? '');
        $stmt    = $conn->prepare("INSERT INTO users (name,email,phone,university_id,password,role,department,semester,section) VALUES (?,?,?,?,?,?,?,?,?)");
        $stmt->bind_param('sssssssss', $name,$email,$phone,$univ_id,$pass,$role,$dept,$sem,$sec);
        $msg = $stmt->execute() ? 'User added.' : 'Error: '.$conn->error;

    } elseif ($action === 'edit') {
        $id   = (int)$_POST['id'];
        $name = trim($_POST['name']);
        $role = $_POST['role'];
        $dept = trim($_POST['department'] ?? '');
        $email= trim($_POST['email'] ?? '');
        $phone= trim($_POST['phone'] ?? '');
        $sem  = trim($_POST['semester'] ?? '');
        $sec  = trim($_POST['section'] ?? '');
        $stmt = $conn->prepare("UPDATE users SET name=?,email=?,phone=?,role=?,department=?,semester=?,section=? WHERE id=?");
        $stmt->bind_param('sssssssi', $name,$email,$phone,$role,$dept,$sem,$sec,$id);
        $msg = $stmt->execute() ? 'User updated.' : 'Error: '.$conn->error;

    } elseif ($action === 'reset_password') {
        $id   = (int)$_POST['id'];
        $pass = password_hash(trim($_POST['new_password']), PASSWORD_DEFAULT);
        $stmt = $conn->prepare("UPDATE users SET password=? WHERE id=?");
        $stmt->bind_param('si', $pass, $id);
        $msg = $stmt->execute() ? 'Password reset.' : 'Error: '.$conn->error;

    } elseif ($action === 'toggle_active') {
        $id  = (int)$_POST['id'];
        $new = $_POST['is_active'] ? 0 : 1;
        $conn->query("UPDATE users SET is_active=$new WHERE id=$id AND role!='admin'");
        $msg = $new ? 'Account activated.' : 'Account deactivated.';

    } elseif ($action === 'delete') {
        $id = (int)$_POST['id'];
        $conn->query("DELETE FROM users WHERE id=$id AND role!='admin'");
        $msg = 'User deleted.';

    } elseif ($action === 'csv_import') {
        if (!empty($_FILES['csv_file']['tmp_name'])) {
            $handle = fopen($_FILES['csv_file']['tmp_name'], 'r');
            fgetcsv($handle); // skip header
            $added = 0; $errors = 0;
            while (($row = fgetcsv($handle)) !== false) {
                if (count($row) < 4) { $errors++; continue; }
                [$name, $univ_id, $password, $role] = $row;
                $email = $row[4] ?? '';
                $dept  = $row[5] ?? '';
                $sem   = $row[6] ?? '';
                $sec   = $row[7] ?? '';
                $hash  = password_hash(trim($password), PASSWORD_DEFAULT);
                $stmt  = $conn->prepare("INSERT IGNORE INTO users (name,university_id,password,role,email,department,semester,section) VALUES (?,?,?,?,?,?,?,?)");
                $stmt->bind_param('ssssssss', $name,$univ_id,$hash,$role,$email,$dept,$sem,$sec);
                $stmt->execute() ? $added++ : $errors++;
            }
            fclose($handle);
            $msg = "CSV imported: $added added, $errors skipped/errors.";
        }
    }
}

$users = $conn->query("SELECT id,name,email,phone,university_id,role,department,semester,section,is_active,created_at FROM users ORDER BY role,name")->fetch_all(MYSQLI_ASSOC);
$depts = $conn->query("SELECT name FROM departments ORDER BY name")->fetch_all(MYSQLI_ASSOC);

$editUser = null;
if (isset($_GET['edit'])) {
    $eid = (int)$_GET['edit'];
    $stmt = $conn->prepare("SELECT * FROM users WHERE id=?");
    $stmt->bind_param('i', $eid);
    $stmt->execute();
    $editUser = $stmt->get_result()->fetch_assoc();
}

include '../includes/header.php';
?>
<h1>Manage Users</h1>
<?php if ($msg): ?><div class="alert alert-<?= $msgType ?>"><?= htmlspecialchars($msg) ?></div><?php endif; ?>

<!-- Add/Edit Form -->
<div class="card">
    <h3><?= $editUser ? 'Edit User' : 'Add New User' ?></h3>
    <form method="POST" enctype="multipart/form-data">
        <input type="hidden" name="action" value="<?= $editUser ? 'edit' : 'add' ?>">
        <?php if ($editUser): ?><input type="hidden" name="id" value="<?= $editUser['id'] ?>"><?php endif; ?>
        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(190px,1fr));gap:.75rem">
            <div class="form-group" style="margin:0"><label>Full Name</label>
                <input type="text" name="name" required value="<?= htmlspecialchars($editUser['name'] ?? '') ?>"></div>
            <div class="form-group" style="margin:0"><label>University ID</label>
                <input type="text" name="university_id" <?= $editUser?'disabled':'required' ?> value="<?= htmlspecialchars($editUser['university_id'] ?? '') ?>" style="<?= $editUser?'background:#f1f5f9':'' ?>"></div>
            <div class="form-group" style="margin:0"><label>Email</label>
                <input type="email" name="email" value="<?= htmlspecialchars($editUser['email'] ?? '') ?>"></div>
            <div class="form-group" style="margin:0"><label>Phone</label>
                <input type="text" name="phone" value="<?= htmlspecialchars($editUser['phone'] ?? '') ?>"></div>
            <?php if (!$editUser): ?>
            <div class="form-group" style="margin:0"><label>Password</label>
                <input type="password" name="password" required></div>
            <?php endif; ?>
            <div class="form-group" style="margin:0"><label>Role</label>
                <select name="role" required>
                    <?php foreach (['student','teacher','admin'] as $r): ?>
                    <option value="<?= $r ?>" <?= ($editUser['role']??'')===$r?'selected':'' ?>><?= ucfirst($r) ?></option>
                    <?php endforeach; ?>
                </select></div>
            <div class="form-group" style="margin:0"><label>Department</label>
                <input type="text" name="department" list="dept-list" value="<?= htmlspecialchars($editUser['department'] ?? '') ?>">
                <datalist id="dept-list"><?php foreach ($depts as $d): ?><option value="<?= htmlspecialchars($d['name']) ?>"><?php endforeach; ?></datalist></div>
            <div class="form-group" style="margin:0"><label>Semester</label>
                <select name="semester">
                    <option value="">-- Select --</option>
                    <?php foreach (['Semester 1','Semester 2','Semester 3','Semester 4','Semester 5','Semester 6','Semester 7','Semester 8'] as $s): ?>
                    <option value="<?= $s ?>" <?= ($editUser['semester']??'')===$s?'selected':'' ?>><?= $s ?></option>
                    <?php endforeach; ?>
                </select></div>
            <div class="form-group" style="margin:0"><label>Section</label>
                <input type="text" name="section" placeholder="e.g. A, B" value="<?= htmlspecialchars($editUser['section'] ?? '') ?>"></div>
        </div>
        <div style="margin-top:1rem;display:flex;gap:.75rem">
            <button type="submit" class="btn btn-primary"><?= $editUser ? 'Save Changes' : 'Add User' ?></button>
            <?php if ($editUser): ?><a href="users.php" class="btn btn-secondary">Cancel</a><?php endif; ?>
        </div>
    </form>
</div>

<!-- CSV Import -->
<div class="card">
    <h3>Import Users via CSV</h3>
    <p class="text-muted" style="margin-bottom:.75rem">
        CSV columns: <code>name, university_id, password, role, email(opt), department(opt), semester(opt), section(opt)</code>
    </p>
    <a href="?download_template=1" class="btn btn-secondary btn-sm" style="margin-bottom:.75rem">Download Template</a>
    <form method="POST" enctype="multipart/form-data" class="form-inline">
        <input type="hidden" name="action" value="csv_import">
        <input type="file" name="csv_file" accept=".csv" required>
        <button type="submit" class="btn btn-primary">Import</button>
    </form>
</div>

<?php
// CSV template download
if (isset($_GET['download_template'])) {
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="users_template.csv"');
    echo "name,university_id,password,role,email,department,semester,section\n";
    echo "John Doe,WU/URR/0001/24,password123,student,john@email.com,Computer Science,Semester 1,A\n";
    echo "Jane Smith,WU/TCH/0001/24,password123,teacher,jane@email.com,Computer Science,,\n";
    exit;
}
?>

<!-- Users Table -->
<div class="card">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:1rem">
        <h3>All Users (<?= count($users) ?>)</h3>
        <input type="text" id="searchInput" placeholder="Search..." onkeyup="filterTable()" style="width:200px">
    </div>
    <div style="overflow-x:auto">
    <table class="table" id="usersTable">
        <thead>
            <tr><th>Name</th><th>University ID</th><th>Role</th><th>Dept</th><th>Sem/Sec</th><th>Status</th><th>Actions</th></tr>
        </thead>
        <tbody>
        <?php foreach ($users as $u): ?>
        <tr>
            <td><?= htmlspecialchars($u['name']) ?><?php if($u['email']): ?><br><small class="text-muted"><?= htmlspecialchars($u['email']) ?></small><?php endif; ?></td>
            <td><code><?= htmlspecialchars($u['university_id'] ?? '-') ?></code></td>
            <td><span class="badge badge-<?= $u['role'] ?>"><?= $u['role'] ?></span></td>
            <td><?= htmlspecialchars($u['department'] ?? '-') ?></td>
            <td><?= htmlspecialchars($u['semester'] ?? '') ?><?= $u['section'] ? ' / '.$u['section'] : '' ?></td>
            <td><span class="badge" style="background:<?= $u['is_active']?'#dcfce7':'#fee2e2' ?>;color:<?= $u['is_active']?'#15803d':'#b91c1c' ?>"><?= $u['is_active']?'Active':'Inactive' ?></span></td>
            <td style="display:flex;gap:.3rem;flex-wrap:wrap">
                <a href="?edit=<?= $u['id'] ?>" class="btn btn-secondary btn-sm">Edit</a>
                <?php if ($u['role'] !== 'admin'): ?>
                <form method="POST" style="display:inline">
                    <input type="hidden" name="action" value="toggle_active">
                    <input type="hidden" name="id" value="<?= $u['id'] ?>">
                    <input type="hidden" name="is_active" value="<?= $u['is_active'] ?>">
                    <button class="btn btn-sm" style="background:<?= $u['is_active']?'#f59e0b':'#16a34a' ?>;color:#fff"><?= $u['is_active']?'Deactivate':'Activate' ?></button>
                </form>
                <button class="btn btn-sm" style="background:#6366f1;color:#fff" onclick="showReset(<?= $u['id'] ?>,'<?= htmlspecialchars($u['name']) ?>')">Reset PW</button>
                <form method="POST" style="display:inline" onsubmit="return confirm('Delete?')">
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="id" value="<?= $u['id'] ?>">
                    <button class="btn btn-danger btn-sm">Delete</button>
                </form>
                <?php endif; ?>
            </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
</div>

<!-- Reset Password Modal -->
<div id="resetModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:999;align-items:center;justify-content:center">
    <div class="card" style="width:360px;margin:0">
        <h3>Reset Password</h3>
        <p id="resetName" style="margin-bottom:1rem;color:var(--text-muted)"></p>
        <form method="POST">
            <input type="hidden" name="action" value="reset_password">
            <input type="hidden" name="id" id="resetUserId">
            <div class="form-group"><label>New Password</label>
                <input type="password" name="new_password" required autocomplete="new-password"></div>
            <div style="display:flex;gap:.75rem">
                <button type="submit" class="btn btn-primary">Reset</button>
                <button type="button" class="btn btn-secondary" onclick="closeReset()">Cancel</button>
            </div>
        </form>
    </div>
</div>
<script>
function showReset(id,name){document.getElementById('resetUserId').value=id;document.getElementById('resetName').textContent='User: '+name;document.getElementById('resetModal').style.display='flex';}
function closeReset(){document.getElementById('resetModal').style.display='none';}
function filterTable(){const q=document.getElementById('searchInput').value.toLowerCase();document.querySelectorAll('#usersTable tbody tr').forEach(r=>r.style.display=r.textContent.toLowerCase().includes(q)?'':'none');}
</script>
<?php include '../includes/footer.php'; ?>
