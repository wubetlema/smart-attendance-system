<?php
require_once '../includes/auth_check.php';
requireRole('admin');
require_once '../config/db.php';
require_once '../includes/mailer.php';

$msg = ''; $msgType = 'success';

// ── Helpers ──────────────────────────────────────────────────────────────────
function uploadPhoto($file, $userId) {
    $ext     = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    $allowed = ['jpg','jpeg','png','gif','webp'];
    if (!in_array($ext, $allowed)) return null;
    $dir = '../assets/uploads/';
    if (!is_dir($dir)) mkdir($dir, 0755, true);
    $name = 'user_' . $userId . '_' . time() . '.' . $ext;
    return move_uploaded_file($file['tmp_name'], $dir . $name) ? $name : null;
}

// ── Export CSV ────────────────────────────────────────────────────────────────
if (isset($_GET['export'])) {
    global $conn;
    $role_filter = $_GET['role'] ?? '';
    $where = $role_filter ? "WHERE role='" . $conn->real_escape_string($role_filter) . "'" : '';
    $rows = $conn->query("SELECT name,email,phone,university_id,role,department,semester,section,is_active,created_at FROM users $where ORDER BY role,name")->fetch_all(MYSQLI_ASSOC);
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="users_export_' . date('Ymd_His') . '.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Name','Email','Phone','University ID','Role','Department','Semester','Section','Active','Created At']);
    foreach ($rows as $r) fputcsv($out, array_values($r));
    fclose($out);
    exit;
}

// ── Export Excel (HTML table as .xls) ────────────────────────────────────────
if (isset($_GET['export_excel'])) {
    global $conn;
    $role_filter = $_GET['role'] ?? '';
    $where = $role_filter ? "WHERE role='" . $conn->real_escape_string($role_filter) . "'" : '';
    $rows = $conn->query("SELECT name,email,phone,university_id,role,department,semester,section,is_active,created_at FROM users $where ORDER BY role,name")->fetch_all(MYSQLI_ASSOC);
    header('Content-Type: application/vnd.ms-excel');
    header('Content-Disposition: attachment; filename="users_export_' . date('Ymd_His') . '.xls"');
    echo '<table border="1"><thead><tr><th>Name</th><th>Email</th><th>Phone</th><th>University ID</th><th>Role</th><th>Department</th><th>Semester</th><th>Section</th><th>Active</th><th>Created At</th></tr></thead><tbody>';
    foreach ($rows as $r) {
        echo '<tr>';
        foreach ($r as $v) echo '<td>' . htmlspecialchars($v ?? '') . '</td>';
        echo '</tr>';
    }
    echo '</tbody></table>';
    exit;
}

// ── CSV Preview (AJAX) ────────────────────────────────────────────────────────
if (isset($_GET['csv_preview']) && !empty($_FILES['csv_file']['tmp_name'])) {
    header('Content-Type: application/json');
    $handle = fopen($_FILES['csv_file']['tmp_name'], 'r');
    fgetcsv($handle); // skip header
    $preview = []; $errors = [];
    $validRoles = ['student','teacher','admin'];
    $row_num = 1;
    while (($row = fgetcsv($handle)) !== false && count($preview) < 50) {
        $row_num++;
        if (count($row) < 4) { $errors[] = "Row $row_num: too few columns"; continue; }
        [$name, $univ_id, $password, $role] = $row;
        $email = $row[4] ?? ''; $dept = $row[5] ?? ''; $sem = $row[6] ?? ''; $sec = $row[7] ?? '';
        $rowErrors = [];
        if (empty(trim($name)))    $rowErrors[] = 'Name required';
        if (empty(trim($univ_id))) $rowErrors[] = 'University ID required';
        if (strlen(trim($password)) < 6) $rowErrors[] = 'Password too short';
        if (!in_array(trim($role), $validRoles)) $rowErrors[] = "Invalid role '$role'";
        if ($email && !filter_var($email, FILTER_VALIDATE_EMAIL)) $rowErrors[] = 'Invalid email';
        // Check duplicate
        $chk = $conn->prepare("SELECT id FROM users WHERE university_id=?");
        $uid = trim($univ_id); $chk->bind_param('s', $uid); $chk->execute();
        $exists = $chk->get_result()->num_rows > 0;
        $preview[] = ['row'=>$row_num,'name'=>trim($name),'university_id'=>trim($univ_id),'role'=>trim($role),'email'=>trim($email),'department'=>trim($dept),'semester'=>trim($sem),'section'=>trim($sec),'errors'=>$rowErrors,'duplicate'=>$exists];
    }
    fclose($handle);
    echo json_encode(['preview'=>$preview,'file_errors'=>$errors]);
    exit;
}

// ── POST Actions ──────────────────────────────────────────────────────────────
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
        if ($stmt->execute()) {
            $newId = $conn->insert_id;
            // Photo upload on add
            if (!empty($_FILES['photo']['name'])) {
                $photo = uploadPhoto($_FILES['photo'], $newId);
                if ($photo) $conn->query("UPDATE users SET photo='$photo' WHERE id=$newId");
            }
            $msg = 'User added.';
        } else {
            $msg = 'Error: ' . $conn->error; $msgType = 'error';
        }

    } elseif ($action === 'edit') {
        $id   = (int)$_POST['id'];
        $name = trim($_POST['name']);
        $role = $_POST['role'];
        $dept = trim($_POST['department'] ?? '');
        $email= trim($_POST['email'] ?? '');
        $phone= trim($_POST['phone'] ?? '');
        $sem  = trim($_POST['semester'] ?? '');
        $sec  = trim($_POST['section'] ?? '');
        // Photo upload on edit
        $photoSql = '';
        if (!empty($_FILES['photo']['name'])) {
            $photo = uploadPhoto($_FILES['photo'], $id);
            if ($photo) $photoSql = ", photo='$photo'";
        }
        $stmt = $conn->prepare("UPDATE users SET name=?,email=?,phone=?,role=?,department=?,semester=?,section=?$photoSql WHERE id=?");
        $stmt->bind_param('sssssssi', $name,$email,$phone,$role,$dept,$sem,$sec,$id);
        $msg = $stmt->execute() ? 'User updated.' : 'Error: '.$conn->error;

    } elseif ($action === 'reset_password') {
        $id   = (int)$_POST['id'];
        $pass = password_hash(trim($_POST['new_password']), PASSWORD_DEFAULT);
        $stmt = $conn->prepare("UPDATE users SET password=?, is_first_login=1, force_password_reset=0 WHERE id=?");
        $stmt->bind_param('si', $pass, $id);
        $msg = $stmt->execute() ? 'Password reset. User will be prompted to change it on next login.' : 'Error: '.$conn->error;

    } elseif ($action === 'force_password_reset') {
        // Set flag — user must change password at next login
        $id = (int)$_POST['id'];
        $conn->query("UPDATE users SET force_password_reset=1 WHERE id=$id AND role!='admin'");
        $msg = 'User will be required to change their password on next login.';

    } elseif ($action === 'force_logout_all_sessions') {
        // Terminate all active sessions for a specific user
        $id = (int)$_POST['id'];
        require_once '../includes/session_manager.php';
        $sessions = $conn->query("SELECT id FROM user_sessions WHERE user_id=$id AND is_active=1")->fetch_all(MYSQLI_ASSOC);
        foreach ($sessions as $s) forceLogoutSession($s['id'], $_SESSION['user_id']);
        $msg = 'All sessions for this user have been terminated.';

    } elseif ($action === 'toggle_active') {
        $id  = (int)$_POST['id'];
        $new = (int)$_POST['is_active'] ? 0 : 1;
        $conn->query("UPDATE users SET is_active=$new WHERE id=$id AND role!='admin'");
        $msg = $new ? 'Account activated.' : 'Account deactivated.';

    } elseif ($action === 'delete') {
        $id = (int)$_POST['id'];
        $conn->query("DELETE FROM users WHERE id=$id AND role!='admin'");
        $msg = 'User deleted.';

    } elseif ($action === 'bulk_email') {
        $ids     = array_map('intval', $_POST['user_ids'] ?? []);
        $subject = trim($_POST['email_subject']);
        $body    = trim($_POST['email_body']);
        if (empty($ids) || !$subject || !$body) {
            $msg = 'Select users and fill subject/message.'; $msgType = 'error';
        } else {
            $in   = implode(',', $ids);
            $rows = $conn->query("SELECT name, email FROM users WHERE id IN ($in) AND email != ''")->fetch_all(MYSQLI_ASSOC);
            $sent = 0; $failed = 0;
            foreach ($rows as $r) {
                $html = "<p>Dear <strong>" . htmlspecialchars($r['name']) . "</strong>,</p><p>" . nl2br(htmlspecialchars($body)) . "</p>";
                $res  = sendMail($r['email'], $r['name'], $subject, $html);
                $res['success'] ? $sent++ : $failed++;
            }
            $msg = "Bulk email: $sent sent" . ($failed ? ", $failed failed (no email or SMTP error)" : ".") . " out of " . count($ids) . " selected.";
            if ($failed) $msgType = 'error';
        }

    } elseif ($action === 'csv_import') {
        if (!empty($_FILES['csv_file']['tmp_name'])) {
            $handle = fopen($_FILES['csv_file']['tmp_name'], 'r');
            fgetcsv($handle); // skip header
            $added = 0; $skipped = 0; $errors = 0;
            while (($row = fgetcsv($handle)) !== false) {
                if (count($row) < 4) { $errors++; continue; }
                [$name, $univ_id, $password, $role] = $row;
                $email = $row[4] ?? ''; $dept = $row[5] ?? ''; $sem = $row[6] ?? ''; $sec = $row[7] ?? '';
                $validRoles = ['student','teacher','admin'];
                if (empty(trim($name)) || empty(trim($univ_id)) || strlen(trim($password)) < 6 || !in_array(trim($role), $validRoles)) {
                    $errors++; continue;
                }
                $hash = password_hash(trim($password), PASSWORD_DEFAULT);
                $stmt = $conn->prepare("INSERT IGNORE INTO users (name,university_id,password,role,email,department,semester,section) VALUES (?,?,?,?,?,?,?,?)");
                $n=$name; $u=trim($univ_id); $r=trim($role); $e=trim($email); $d=trim($dept); $s=trim($sem); $sc=trim($sec);
                $stmt->bind_param('ssssssss', $n,$u,$hash,$r,$e,$d,$s,$sc);
                if ($stmt->execute()) {
                    $conn->affected_rows > 0 ? $added++ : $skipped++;
                } else { $errors++; }
            }
            fclose($handle);
            $msg = "CSV imported: $added added, $skipped duplicates skipped, $errors invalid rows.";
            if ($errors > 0) $msgType = 'error';
        }
    }
}

// ── Download CSV template ─────────────────────────────────────────────────────
if (isset($_GET['download_template'])) {
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="users_template.csv"');
    echo "name,university_id,password,role,email,department,semester,section\n";
    echo "John Doe,WU/URR/0001/24,password123,student,john@email.com,Computer Science,Semester 1,A\n";
    echo "Jane Smith,WU/TCH/0001/24,password123,teacher,jane@email.com,Computer Science,,\n";
    exit;
}

// ── Fetch data ────────────────────────────────────────────────────────────────
$users = $conn->query("SELECT id,name,email,phone,university_id,role,department,semester,section,is_active,photo,created_at FROM users ORDER BY role,name")->fetch_all(MYSQLI_ASSOC);
$depts = $conn->query("SELECT name FROM departments ORDER BY name")->fetch_all(MYSQLI_ASSOC);

$editUser = null;
if (isset($_GET['edit'])) {
    $eid = (int)$_GET['edit'];
    $stmt = $conn->prepare("SELECT * FROM users WHERE id=?");
    $stmt->bind_param('i', $eid); $stmt->execute();
    $editUser = $stmt->get_result()->fetch_assoc();
}

include '../includes/header.php';
?>
<h1>Manage Users</h1>
<?php if ($msg): ?><div class="alert alert-<?= $msgType ?>"><?= htmlspecialchars($msg) ?></div><?php endif; ?>

<!-- ── Add / Edit Form ──────────────────────────────────────────────────────── -->
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
                    <?php foreach (['Semester 1','Semester 2'] as $s): ?>
                    <option value="<?= $s ?>" <?= ($editUser['semester']??'')===$s?'selected':'' ?>><?= $s ?></option>
                    <?php endforeach; ?>
                </select></div>
            <div class="form-group" style="margin:0"><label>Section</label>
                <input type="text" name="section" placeholder="e.g. A, B" value="<?= htmlspecialchars($editUser['section'] ?? '') ?>"></div>
            <div class="form-group" style="margin:0"><label>Profile Photo</label>
                <?php if ($editUser && $editUser['photo']): ?>
                <div style="margin-bottom:.4rem">
                    <img src="/attendance-system/assets/uploads/<?= htmlspecialchars($editUser['photo']) ?>" style="width:48px;height:48px;border-radius:50%;object-fit:cover;border:2px solid var(--primary)">
                </div>
                <?php endif; ?>
                <input type="file" name="photo" accept="image/*" id="photoInput" onchange="previewPhoto(this)">
                <img id="photoPreview" style="display:none;width:48px;height:48px;border-radius:50%;object-fit:cover;margin-top:.4rem;border:2px solid var(--primary)">
            </div>
        </div>
        <div style="margin-top:1rem;display:flex;gap:.75rem">
            <button type="submit" class="btn btn-primary"><?= $editUser ? 'Save Changes' : 'Add User' ?></button>
            <?php if ($editUser): ?><a href="users.php" class="btn btn-secondary">Cancel</a><?php endif; ?>
        </div>
    </form>
</div>

<!-- ── CSV Import with Preview ─────────────────────────────────────────────── -->
<div class="card">
    <h3>Import Users via CSV</h3>
    <p class="text-muted" style="margin-bottom:.75rem">
        Columns: <code>name, university_id, password, role, email(opt), department(opt), semester(opt), section(opt)</code>
    </p>
    <a href="?download_template=1" class="btn btn-secondary btn-sm" style="margin-bottom:.75rem">Download Template</a>
    <form id="csvPreviewForm" enctype="multipart/form-data">
        <div style="display:flex;gap:.75rem;align-items:center;flex-wrap:wrap">
            <input type="file" name="csv_file" id="csvFile" accept=".csv" required>
            <button type="button" class="btn btn-secondary" onclick="previewCSV()">Preview & Validate</button>
        </div>
    </form>

    <!-- Preview Table -->
    <div id="csvPreviewArea" style="display:none;margin-top:1rem">
        <div id="csvPreviewStats" style="margin-bottom:.75rem"></div>
        <div style="overflow-x:auto;max-height:320px;overflow-y:auto">
        <table class="table" id="csvPreviewTable">
            <thead><tr><th>#</th><th>Name</th><th>University ID</th><th>Role</th><th>Email</th><th>Dept</th><th>Status</th></tr></thead>
            <tbody id="csvPreviewBody"></tbody>
        </table>
        </div>
        <form method="POST" enctype="multipart/form-data" id="csvImportForm" style="margin-top:.75rem">
            <input type="hidden" name="action" value="csv_import">
            <input type="file" name="csv_file" id="csvFileHidden" style="display:none">
            <button type="submit" class="btn btn-primary" id="csvImportBtn" disabled>Import Valid Rows</button>
            <span class="text-muted" style="margin-left:.5rem;font-size:.85rem">Duplicates and invalid rows will be skipped.</span>
        </form>
    </div>
</div>

<!-- ── Export ──────────────────────────────────────────────────────────────── -->
<div class="card">
    <h3>Export Users</h3>
    <div style="display:flex;gap:.75rem;flex-wrap:wrap;align-items:center">
        <select id="exportRole" style="padding:.45rem .75rem;border:1px solid var(--border);border-radius:7px;background:var(--card-bg);color:var(--text)">
            <option value="">All Roles</option>
            <option value="student">Students</option>
            <option value="teacher">Teachers</option>
            <option value="admin">Admins</option>
        </select>
        <button class="btn btn-secondary" onclick="exportUsers('csv')">Export CSV</button>
        <button class="btn btn-secondary" onclick="exportUsers('excel')">Export Excel (.xls)</button>
    </div>
</div>

<!-- ── Bulk Email ──────────────────────────────────────────────────────────── -->
<div class="card">
    <h3>Bulk Email Notification</h3>
    <form method="POST" id="bulkEmailForm">
        <input type="hidden" name="action" value="bulk_email">
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:.75rem;margin-bottom:.75rem">
            <div class="form-group" style="margin:0"><label>Subject</label>
                <input type="text" name="email_subject" required placeholder="Email subject..."></div>
            <div class="form-group" style="margin:0"><label>Filter by Role (for quick select)</label>
                <select id="bulkRoleFilter" onchange="filterBulkUsers(this.value)">
                    <option value="">All</option>
                    <option value="student">Students</option>
                    <option value="teacher">Teachers</option>
                </select></div>
        </div>
        <div class="form-group"><label>Message</label>
            <textarea name="email_body" rows="3" required style="width:100%;padding:.6rem;border:1px solid var(--border);border-radius:7px" placeholder="Your message..."></textarea></div>
        <div style="margin-bottom:.75rem;display:flex;gap:.75rem;align-items:center;flex-wrap:wrap">
            <button type="button" class="btn btn-secondary btn-sm" onclick="selectAllBulk(true)">Select All</button>
            <button type="button" class="btn btn-secondary btn-sm" onclick="selectAllBulk(false)">Deselect All</button>
            <span id="bulkCount" class="text-muted" style="font-size:.85rem">0 selected</span>
        </div>
        <div id="bulkUserList" style="max-height:220px;overflow-y:auto;border:1px solid var(--border);border-radius:8px;padding:.5rem;display:grid;grid-template-columns:repeat(auto-fill,minmax(220px,1fr));gap:.3rem">
            <?php foreach ($users as $u): if (!$u['email']) continue; ?>
            <label class="bulk-user-item" data-role="<?= $u['role'] ?>" style="display:flex;align-items:center;gap:.5rem;padding:.35rem .5rem;border-radius:6px;cursor:pointer;font-size:.88rem">
                <input type="checkbox" name="user_ids[]" value="<?= $u['id'] ?>" onchange="updateBulkCount()">
                <?php if ($u['photo']): ?>
                <img src="/attendance-system/assets/uploads/<?= htmlspecialchars($u['photo']) ?>" style="width:26px;height:26px;border-radius:50%;object-fit:cover">
                <?php else: ?>
                <span style="width:26px;height:26px;border-radius:50%;background:#e2e8f0;display:inline-flex;align-items:center;justify-content:center;font-size:.8rem">👤</span>
                <?php endif; ?>
                <span><?= htmlspecialchars($u['name']) ?> <small class="text-muted">(<?= $u['role'] ?>)</small></span>
            </label>
            <?php endforeach; ?>
        </div>
        <button type="submit" class="btn btn-primary" style="margin-top:.75rem">Send Emails</button>
    </form>
</div>

<!-- ── Users Table ─────────────────────────────────────────────────────────── -->
<div class="card">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:1rem;flex-wrap:wrap;gap:.5rem">
        <h3>All Users (<?= count($users) ?>)</h3>
        <div style="display:flex;gap:.5rem;flex-wrap:wrap">
            <select id="roleFilter" onchange="filterTable()" style="padding:.4rem .6rem;border:1px solid var(--border);border-radius:7px;background:var(--card-bg);color:var(--text)">
                <option value="">All Roles</option>
                <option value="student">Students</option>
                <option value="teacher">Teachers</option>
                <option value="admin">Admins</option>
            </select>
            <input type="text" id="searchInput" placeholder="Search..." onkeyup="filterTable()" style="width:180px">
        </div>
    </div>
    <div style="overflow-x:auto">
    <table class="table" id="usersTable">
        <thead>
            <tr><th></th><th>Name</th><th>University ID</th><th>Role</th><th>Dept</th><th>Sem/Sec</th><th>Status</th><th>Actions</th></tr>
        </thead>
        <tbody>
        <?php foreach ($users as $u): ?>
        <tr data-role="<?= $u['role'] ?>">
            <td>
                <?php if ($u['photo']): ?>
                <img src="/attendance-system/assets/uploads/<?= htmlspecialchars($u['photo']) ?>" style="width:36px;height:36px;border-radius:50%;object-fit:cover;border:2px solid var(--border)">
                <?php else: ?>
                <div style="width:36px;height:36px;border-radius:50%;background:#e2e8f0;display:flex;align-items:center;justify-content:center;font-size:1rem">👤</div>
                <?php endif; ?>
            </td>
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
                <button class="btn btn-sm" style="background:#6366f1;color:#fff" onclick="showReset(<?= $u['id'] ?>,'<?= htmlspecialchars($u['name'],ENT_QUOTES) ?>')">Reset PW</button>
                <form method="POST" style="display:inline" onsubmit="return confirm('Delete <?= htmlspecialchars($u['name'],ENT_QUOTES) ?>?')">
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

<!-- ── Reset Password Modal ────────────────────────────────────────────────── -->
<div id="resetModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:999;align-items:center;justify-content:center">
    <div class="card" style="width:360px;margin:0">
        <h3>Reset Password</h3>
        <p id="resetName" style="margin-bottom:1rem;color:var(--text-muted)"></p>
        <form method="POST">
            <input type="hidden" name="action" value="reset_password">
            <input type="hidden" name="id" id="resetUserId">
            <div class="form-group"><label>New Password</label>
                <input type="password" name="new_password" required autocomplete="new-password" minlength="6"></div>
            <div style="display:flex;gap:.75rem">
                <button type="submit" class="btn btn-primary">Reset</button>
                <button type="button" class="btn btn-secondary" onclick="closeReset()">Cancel</button>
            </div>
        </form>
    </div>
</div>

<script>
// ── Photo preview ─────────────────────────────────────────────────────────────
function previewPhoto(input) {
    const preview = document.getElementById('photoPreview');
    if (input.files && input.files[0]) {
        const reader = new FileReader();
        reader.onload = e => { preview.src = e.target.result; preview.style.display = 'block'; };
        reader.readAsDataURL(input.files[0]);
    }
}

// ── Reset password modal ──────────────────────────────────────────────────────
function showReset(id, name) {
    document.getElementById('resetUserId').value = id;
    document.getElementById('resetName').textContent = 'User: ' + name;
    document.getElementById('resetModal').style.display = 'flex';
}
function closeReset() { document.getElementById('resetModal').style.display = 'none'; }

// ── Table filter ──────────────────────────────────────────────────────────────
function filterTable() {
    const q    = document.getElementById('searchInput').value.toLowerCase();
    const role = document.getElementById('roleFilter').value;
    document.querySelectorAll('#usersTable tbody tr').forEach(r => {
        const matchQ    = r.textContent.toLowerCase().includes(q);
        const matchRole = !role || r.dataset.role === role;
        r.style.display = (matchQ && matchRole) ? '' : 'none';
    });
}

// ── Export ────────────────────────────────────────────────────────────────────
function exportUsers(type) {
    const role = document.getElementById('exportRole').value;
    const param = type === 'excel' ? 'export_excel' : 'export';
    window.location = 'users.php?' + param + '=1' + (role ? '&role=' + role : '');
}

// ── Bulk email ────────────────────────────────────────────────────────────────
function filterBulkUsers(role) {
    document.querySelectorAll('.bulk-user-item').forEach(el => {
        el.style.display = (!role || el.dataset.role === role) ? '' : 'none';
    });
    updateBulkCount();
}
function selectAllBulk(checked) {
    document.querySelectorAll('.bulk-user-item').forEach(el => {
        if (el.style.display !== 'none') el.querySelector('input').checked = checked;
    });
    updateBulkCount();
}
function updateBulkCount() {
    const n = document.querySelectorAll('#bulkUserList input:checked').length;
    document.getElementById('bulkCount').textContent = n + ' selected';
}

// ── CSV Preview ───────────────────────────────────────────────────────────────
async function previewCSV() {
    const fileInput = document.getElementById('csvFile');
    if (!fileInput.files[0]) { alert('Select a CSV file first.'); return; }

    const fd = new FormData(document.getElementById('csvPreviewForm'));
    fd.append('csv_file', fileInput.files[0]);

    const res  = await fetch('users.php?csv_preview=1', { method: 'POST', body: fd });
    const data = await res.json();

    const tbody = document.getElementById('csvPreviewBody');
    tbody.innerHTML = '';
    let valid = 0, invalid = 0, dupes = 0;

    data.preview.forEach(row => {
        const hasErr = row.errors.length > 0;
        const isDupe = row.duplicate;
        if (!hasErr && !isDupe) valid++;
        else if (isDupe) dupes++;
        else invalid++;

        const status = hasErr
            ? `<span style="color:#dc2626;font-size:.8rem">❌ ${row.errors.join(', ')}</span>`
            : isDupe
                ? `<span style="color:#d97706;font-size:.8rem">⚠️ Duplicate</span>`
                : `<span style="color:#16a34a;font-size:.8rem">✅ Valid</span>`;

        tbody.innerHTML += `<tr style="background:${hasErr?'#fef2f2':isDupe?'#fffbeb':''}">
            <td>${row.row}</td>
            <td>${row.name}</td>
            <td>${row.university_id}</td>
            <td>${row.role}</td>
            <td>${row.email}</td>
            <td>${row.department}</td>
            <td>${status}</td>
        </tr>`;
    });

    document.getElementById('csvPreviewStats').innerHTML =
        `<span style="color:#16a34a;font-weight:600">${valid} valid</span> &nbsp;|&nbsp; ` +
        `<span style="color:#d97706">${dupes} duplicates</span> &nbsp;|&nbsp; ` +
        `<span style="color:#dc2626">${invalid} invalid</span>`;

    // Copy file to hidden import form
    const dt = new DataTransfer();
    dt.items.add(fileInput.files[0]);
    document.getElementById('csvFileHidden').files = dt.files;
    document.getElementById('csvImportBtn').disabled = valid === 0;
    document.getElementById('csvPreviewArea').style.display = 'block';
}
</script>
<?php include '../includes/footer.php'; ?>
