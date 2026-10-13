<?php
/**
 * Ethiopian University Academic Structure Manager
 *
 * Manages the full hierarchy:
 *   University → Campus → College/Institute → Department → Program → Batch
 *
 * Also manages configurable attendance thresholds.
 */
require_once '../includes/auth_check.php';
requireRole('admin');
require_once '../config/db.php';
require_once '../includes/functions.php';
require_once '../includes/attendance_calculator.php';

$msg = ''; $msgType = 'success';
$tab = $_GET['tab'] ?? 'overview';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    verifyCsrf();
    $action = $_POST['action'];

    // ── University settings ──────────────────────────────────────────────────
    if ($action === 'save_university') {
        $keys = ['university_name','university_short','university_city','current_academic_year','current_semester','id_format'];
        foreach ($keys as $k) {
            $v = trim($_POST[$k] ?? '');
            $stmt = $conn->prepare("INSERT INTO settings (setting_key, setting_value) VALUES (?,?) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)");
            $stmt->bind_param('ss', $k, $v);
            $stmt->execute();
        }
        $msg = 'University settings saved.';

    // ── Attendance thresholds ────────────────────────────────────────────────
    } elseif ($action === 'save_thresholds') {
        $good    = max(1, min(100, (int)$_POST['attendance_threshold_good']));
        $warning = max(1, min($good - 1, (int)$_POST['attendance_threshold_warning']));
        $late    = in_array($_POST['attendance_late_counts'], ['0','0.5','1']) ? $_POST['attendance_late_counts'] : '1';
        $wEmail  = isset($_POST['attendance_warning_email']) ? '1' : '0';
        $cEmail  = isset($_POST['attendance_critical_email']) ? '1' : '0';

        foreach ([
            'attendance_threshold_good'    => $good,
            'attendance_threshold_warning' => $warning,
            'attendance_late_counts'       => $late,
            'attendance_warning_email'     => $wEmail,
            'attendance_critical_email'    => $cEmail,
        ] as $k => $v) {
            $stmt = $conn->prepare("INSERT INTO settings (setting_key, setting_value) VALUES (?,?) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)");
            $stmt->bind_param('ss', $k, $v);
            $stmt->execute();
        }
        AttendanceCalculator::resetCache();
        $msg = 'Attendance thresholds saved.';

    // ── Campus CRUD ───────────────────────────────────────────────────────────
    } elseif ($action === 'add_campus' || $action === 'edit_campus') {
        $name  = trim($_POST['name']);
        $short = trim($_POST['short_name'] ?? '');
        $city  = trim($_POST['city'] ?? '');
        $isMain= isset($_POST['is_main']) ? 1 : 0;
        if ($action === 'add_campus') {
            $stmt = $conn->prepare("INSERT IGNORE INTO campuses (university_id, name, short_name, city, is_main) VALUES (1,?,?,?,?)");
            $stmt->bind_param('sssi', $name, $short, $city, $isMain);
        } else {
            $id = (int)$_POST['id'];
            $stmt = $conn->prepare("UPDATE campuses SET name=?, short_name=?, city=?, is_main=? WHERE id=?");
            $stmt->bind_param('sssii', $name, $short, $city, $isMain, $id);
        }
        $msg = $stmt->execute() ? 'Campus saved.' : 'Error: '.$conn->error;

    } elseif ($action === 'delete_campus') {
        $conn->query("DELETE FROM campuses WHERE id=".(int)$_POST['id']);
        $msg = 'Campus deleted.';

    // ── College CRUD ──────────────────────────────────────────────────────────
    } elseif ($action === 'add_college' || $action === 'edit_college') {
        $campusId = (int)$_POST['campus_id'];
        $name     = trim($_POST['name']);
        $short    = trim($_POST['short_name'] ?? '');
        $type     = $_POST['college_type'] ?? 'college';
        $dean     = trim($_POST['dean_name'] ?? '');
        if ($action === 'add_college') {
            $stmt = $conn->prepare("INSERT IGNORE INTO colleges (campus_id, name, short_name, college_type, dean_name) VALUES (?,?,?,?,?)");
            $stmt->bind_param('issss', $campusId, $name, $short, $type, $dean);
        } else {
            $id = (int)$_POST['id'];
            $stmt = $conn->prepare("UPDATE colleges SET campus_id=?, name=?, short_name=?, college_type=?, dean_name=? WHERE id=?");
            $stmt->bind_param('issssi', $campusId, $name, $short, $type, $dean, $id);
        }
        $msg = $stmt->execute() ? 'College/Institute saved.' : 'Error: '.$conn->error;

    } elseif ($action === 'delete_college') {
        $conn->query("DELETE FROM colleges WHERE id=".(int)$_POST['id']);
        $msg = 'College deleted.';

    // ── Department CRUD ───────────────────────────────────────────────────────
    } elseif ($action === 'add_dept' || $action === 'edit_dept') {
        $collegeId = (int)$_POST['college_id'];
        $name      = trim($_POST['name']);
        $short     = trim($_POST['short_name'] ?? '');
        $head      = trim($_POST['head_name'] ?? '');
        if ($action === 'add_dept') {
            $stmt = $conn->prepare("INSERT IGNORE INTO departments (college_id, name, short_name, head_name) VALUES (?,?,?,?)");
            $stmt->bind_param('isss', $collegeId, $name, $short, $head);
        } else {
            $id = (int)$_POST['id'];
            $stmt = $conn->prepare("UPDATE departments SET college_id=?, name=?, short_name=?, head_name=? WHERE id=?");
            $stmt->bind_param('isssi', $collegeId, $name, $short, $head, $id);
        }
        $msg = $stmt->execute() ? 'Department saved.' : 'Error: '.$conn->error;

    } elseif ($action === 'delete_dept') {
        $conn->query("DELETE FROM departments WHERE id=".(int)$_POST['id']);
        $msg = 'Department deleted.';

    // ── Program CRUD ──────────────────────────────────────────────────────────
    } elseif ($action === 'add_program' || $action === 'edit_program') {
        $deptId  = (int)$_POST['department_id'];
        $name    = trim($_POST['name']);
        $short   = trim($_POST['short_name'] ?? '');
        $degree  = $_POST['degree_type'] ?? 'BSc';
        $yrs     = (int)($_POST['duration_years'] ?? 4);
        $sems    = $yrs * 2;
        if ($action === 'add_program') {
            $stmt = $conn->prepare("INSERT IGNORE INTO programs (department_id, name, short_name, degree_type, duration_years, total_semesters) VALUES (?,?,?,?,?,?)");
            $stmt->bind_param('isssii', $deptId, $name, $short, $degree, $yrs, $sems);
        } else {
            $id = (int)$_POST['id'];
            $stmt = $conn->prepare("UPDATE programs SET department_id=?, name=?, short_name=?, degree_type=?, duration_years=?, total_semesters=? WHERE id=?");
            $stmt->bind_param('isssiiii', $deptId, $name, $short, $degree, $yrs, $sems, $id);
        }
        $msg = $stmt->execute() ? 'Program saved.' : 'Error: '.$conn->error;

    } elseif ($action === 'delete_program') {
        $conn->query("DELETE FROM programs WHERE id=".(int)$_POST['id']);
        $msg = 'Program deleted.';

    // ── Batch CRUD ────────────────────────────────────────────────────────────
    } elseif ($action === 'add_batch' || $action === 'edit_batch') {
        $progId  = (int)$_POST['program_id'];
        $bname   = trim($_POST['batch_name']);
        $entry   = (int)$_POST['entry_year'];
        $grad    = !empty($_POST['expected_graduation_year']) ? (int)$_POST['expected_graduation_year'] : null;
        $total   = (int)($_POST['total_students'] ?? 0);
        if ($action === 'add_batch') {
            $stmt = $conn->prepare("INSERT IGNORE INTO batches (program_id, batch_name, entry_year, expected_graduation_year, total_students) VALUES (?,?,?,?,?)");
            $stmt->bind_param('isiii', $progId, $bname, $entry, $grad, $total);
        } else {
            $id = (int)$_POST['id'];
            $stmt = $conn->prepare("UPDATE batches SET program_id=?, batch_name=?, entry_year=?, expected_graduation_year=?, total_students=? WHERE id=?");
            $stmt->bind_param('isiiii', $progId, $bname, $entry, $grad, $total, $id);
        }
        $msg = $stmt->execute() ? 'Batch saved.' : 'Error: '.$conn->error;

    } elseif ($action === 'delete_batch') {
        $conn->query("DELETE FROM batches WHERE id=".(int)$_POST['id']);
        $msg = 'Batch deleted.';
    }

    $tab = $_POST['tab'] ?? $tab;
}

// ── Fetch all hierarchy data ──────────────────────────────────────────────────
$uni      = $conn->query("SELECT * FROM university LIMIT 1")->fetch_assoc();
$campuses = $conn->query("SELECT c.*, COUNT(col.id) AS college_count FROM campuses c LEFT JOIN colleges col ON col.campus_id=c.id GROUP BY c.id ORDER BY c.is_main DESC, c.name")->fetch_all(MYSQLI_ASSOC);
$colleges = $conn->query("SELECT col.*, ca.name AS campus_name, COUNT(d.id) AS dept_count FROM colleges col JOIN campuses ca ON col.campus_id=ca.id LEFT JOIN departments d ON d.college_id=col.id GROUP BY col.id ORDER BY ca.name, col.name")->fetch_all(MYSQLI_ASSOC);
$depts    = $conn->query("SELECT d.*, col.name AS college_name, ca.name AS campus_name, COUNT(p.id) AS program_count FROM departments d JOIN colleges col ON d.college_id=col.id JOIN campuses ca ON col.campus_id=ca.id LEFT JOIN programs p ON p.department_id=d.id GROUP BY d.id ORDER BY ca.name, col.name, d.name")->fetch_all(MYSQLI_ASSOC);
$programs = $conn->query("SELECT p.*, d.name AS dept_name, col.name AS college_name, COUNT(b.id) AS batch_count FROM programs p JOIN departments d ON p.department_id=d.id JOIN colleges col ON d.college_id=col.id LEFT JOIN batches b ON b.program_id=p.id GROUP BY p.id ORDER BY d.name, p.name")->fetch_all(MYSQLI_ASSOC);
$batches  = $conn->query("SELECT b.*, p.name AS program_name, p.degree_type, d.name AS dept_name FROM batches b JOIN programs p ON b.program_id=p.id JOIN departments d ON p.department_id=d.id ORDER BY d.name, p.name, b.entry_year DESC")->fetch_all(MYSQLI_ASSOC);

// Settings
$settings = [];
$sRows = $conn->query("SELECT setting_key, setting_value FROM settings")->fetch_all(MYSQLI_ASSOC);
foreach ($sRows as $r) $settings[$r['setting_key']] = $r['setting_value'];

$t = AttendanceCalculator::thresholds();

include '../includes/header.php';
?>

<h1>🏛️ Academic Structure</h1>
<p class="text-muted">Manage the university hierarchy and configure system-wide settings.</p>

<?php if ($msg): ?>
<div class="alert alert-<?= $msgType ?>"><?= htmlspecialchars($msg) ?></div>
<?php endif; ?>

<!-- Hierarchy breadcrumb overview -->
<div class="card" style="background:linear-gradient(135deg,#1e1b4b,#312e81);color:#fff;border:none;margin-bottom:1.25rem">
    <div style="font-size:.85rem;opacity:.8;margin-bottom:.5rem">Current University Hierarchy</div>
    <div style="display:flex;align-items:center;gap:.5rem;flex-wrap:wrap;font-size:.95rem;font-weight:600">
        <span><?= htmlspecialchars($settings['university_name'] ?? 'Wollo University') ?></span>
        <span style="opacity:.5">→</span>
        <span>Campus (<?= count($campuses) ?>)</span>
        <span style="opacity:.5">→</span>
        <span>College/Institute (<?= count($colleges) ?>)</span>
        <span style="opacity:.5">→</span>
        <span>Department (<?= count($depts) ?>)</span>
        <span style="opacity:.5">→</span>
        <span>Program (<?= count($programs) ?>)</span>
        <span style="opacity:.5">→</span>
        <span>Batch (<?= count($batches) ?>)</span>
        <span style="opacity:.5">→</span>
        <span>Year → Semester → Section → Course</span>
    </div>
</div>

<!-- Tabs -->
<div style="display:flex;gap:.3rem;flex-wrap:wrap;margin-bottom:1.25rem;border-bottom:2px solid var(--border);padding-bottom:.5rem">
    <?php
    $tabs = [
        'overview'   => '🏛️ Overview',
        'campuses'   => '🏙️ Campuses',
        'colleges'   => '🏫 Colleges',
        'departments'=> '📚 Departments',
        'programs'   => '🎓 Programs',
        'batches'    => '👥 Batches',
        'thresholds' => '📊 Attendance Rules',
        'university' => '⚙️ University Settings',
    ];
    foreach ($tabs as $key => $label):
    ?>
    <a href="?tab=<?= $key ?>"
       style="padding:.5rem 1rem;border-radius:8px 8px 0 0;text-decoration:none;font-weight:600;font-size:.88rem;
              background:<?= $tab===$key?'var(--primary)':'var(--hover-bg)' ?>;
              color:<?= $tab===$key?'#fff':'var(--text)' ?>">
        <?= $label ?>
    </a>
    <?php endforeach; ?>
</div>

<!-- ── TAB: Overview ──────────────────────────────────────────────────────── -->
<?php if ($tab === 'overview'): ?>
<div class="card">
    <h3>University Hierarchy Tree</h3>
    <?php foreach ($campuses as $campus): ?>
    <div style="margin-bottom:1.25rem">
        <div style="font-weight:700;font-size:1.05rem;color:var(--primary);padding:.5rem 0;border-bottom:2px solid var(--primary);display:flex;align-items:center;gap:.5rem">
            🏙️ <?= htmlspecialchars($campus['name']) ?>
            <?php if ($campus['is_main']): ?><span class="badge" style="background:#ede9fe;color:#6d28d9;font-size:.7rem">Main Campus</span><?php endif; ?>
            <span class="text-muted" style="font-weight:400;font-size:.85rem;margin-left:.5rem"><?= htmlspecialchars($campus['city']) ?></span>
        </div>
        <?php
        $campusColleges = array_filter($colleges, fn($c) => $c['campus_id'] == $campus['id']);
        foreach ($campusColleges as $col):
        ?>
        <div style="margin-left:1.5rem;margin-top:.75rem">
            <div style="font-weight:600;color:#374151;display:flex;align-items:center;gap:.5rem">
                🏫 <?= htmlspecialchars($col['name']) ?>
                <span class="badge" style="background:#dbeafe;color:#1e40af;font-size:.72rem"><?= $col['college_type'] ?></span>
            </div>
            <?php
            $colDepts = array_filter($depts, fn($d) => $d['college_id'] == $col['id']);
            foreach ($colDepts as $dept):
            ?>
            <div style="margin-left:1.5rem;margin-top:.4rem">
                <div style="color:#6366f1;font-weight:600;font-size:.9rem">
                    📚 <?= htmlspecialchars($dept['name']) ?> (<?= htmlspecialchars($dept['short_name']) ?>)
                </div>
                <?php
                $deptProgs = array_filter($programs, fn($p) => $p['department_id'] == $dept['id']);
                foreach ($deptProgs as $prog):
                    $progBatches = array_filter($batches, fn($b) => $b['program_id'] == $prog['id']);
                ?>
                <div style="margin-left:1.5rem;margin-top:.25rem;font-size:.88rem;color:#374151">
                    🎓 <strong><?= htmlspecialchars($prog['degree_type'].' '.$prog['name']) ?></strong>
                    — <?= $prog['duration_years'] ?> years
                    <span class="text-muted">(<?= count($progBatches) ?> batches)</span>
                    <div style="margin-left:1.5rem;display:flex;gap:.4rem;flex-wrap:wrap;margin-top:.2rem">
                    <?php foreach ($progBatches as $b): ?>
                        <span style="background:#f0fdf4;color:#15803d;padding:.15rem .5rem;border-radius:12px;font-size:.78rem;border:1px solid #bbf7d0">
                            <?= htmlspecialchars($b['batch_name']) ?> (<?= $b['entry_year'] ?>)
                        </span>
                    <?php endforeach; ?>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endforeach; ?>
</div>

<!-- ── TAB: Campuses ─────────────────────────────────────────────────────── -->
<?php elseif ($tab === 'campuses'): ?>
<div style="display:grid;grid-template-columns:1fr 1.5fr;gap:1.25rem;align-items:start">
<div class="card">
    <h3>Add Campus</h3>
    <form method="POST">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="add_campus">
        <input type="hidden" name="tab" value="campuses">
        <div class="form-group"><label>Campus Name</label><input type="text" name="name" required placeholder="e.g. Kombolcha Institute of Technology"></div>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:.75rem">
            <div class="form-group" style="margin:0"><label>Short Name</label><input type="text" name="short_name" placeholder="KIoT"></div>
            <div class="form-group" style="margin:0"><label>City</label><input type="text" name="city" placeholder="Kombolcha"></div>
        </div>
        <label style="display:flex;align-items:center;gap:.5rem;margin:.75rem 0;cursor:pointer">
            <input type="checkbox" name="is_main"> Main Campus
        </label>
        <button type="submit" class="btn btn-primary btn-full">Add Campus</button>
    </form>
</div>
<div class="card">
    <h3>All Campuses (<?= count($campuses) ?>)</h3>
    <?php foreach ($campuses as $c): ?>
    <div style="display:flex;justify-content:space-between;align-items:center;padding:.65rem .75rem;border-radius:8px;background:var(--hover-bg);margin-bottom:.4rem;flex-wrap:wrap;gap:.5rem">
        <div>
            <strong><?= htmlspecialchars($c['name']) ?></strong>
            <?php if ($c['is_main']): ?><span class="badge" style="background:#ede9fe;color:#6d28d9;margin-left:.4rem">Main</span><?php endif; ?>
            <br><small class="text-muted"><?= htmlspecialchars($c['short_name']) ?><?= $c['city']?' — '.$c['city']:'' ?> · <?= $c['college_count'] ?> college(s)</small>
        </div>
        <form method="POST" style="display:inline" onsubmit="return confirm('Delete campus?')">
            <?= csrfField() ?><input type="hidden" name="action" value="delete_campus"><input type="hidden" name="tab" value="campuses"><input type="hidden" name="id" value="<?= $c['id'] ?>">
            <button class="btn btn-danger btn-sm">Delete</button>
        </form>
    </div>
    <?php endforeach; ?>
</div>
</div>

<!-- ── TAB: Colleges ─────────────────────────────────────────────────────── -->
<?php elseif ($tab === 'colleges'): ?>
<div style="display:grid;grid-template-columns:1fr 1.5fr;gap:1.25rem;align-items:start">
<div class="card">
    <h3>Add College / Institute</h3>
    <form method="POST">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="add_college">
        <input type="hidden" name="tab" value="colleges">
        <div class="form-group"><label>Campus</label>
            <select name="campus_id" required>
                <option value="">Select campus...</option>
                <?php foreach ($campuses as $c): ?><option value="<?= $c['id'] ?>"><?= htmlspecialchars($c['name']) ?></option><?php endforeach; ?>
            </select></div>
        <div class="form-group"><label>Name</label><input type="text" name="name" required placeholder="e.g. Kombolcha Institute of Technology"></div>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:.75rem">
            <div class="form-group" style="margin:0"><label>Short Name</label><input type="text" name="short_name" placeholder="KIoT"></div>
            <div class="form-group" style="margin:0"><label>Type</label>
                <select name="college_type">
                    <?php foreach (['college','institute','school','faculty','center'] as $t): ?>
                    <option value="<?= $t ?>"><?= ucfirst($t) ?></option>
                    <?php endforeach; ?>
                </select></div>
        </div>
        <div class="form-group"><label>Dean / Director Name (optional)</label><input type="text" name="dean_name" placeholder="Dr. "></div>
        <button type="submit" class="btn btn-primary btn-full">Add College</button>
    </form>
</div>
<div class="card">
    <h3>All Colleges (<?= count($colleges) ?>)</h3>
    <div style="overflow-x:auto"><table class="table">
        <thead><tr><th>Name</th><th>Type</th><th>Campus</th><th>Depts</th><th>Action</th></tr></thead>
        <tbody>
        <?php foreach ($colleges as $c): ?>
        <tr>
            <td><strong><?= htmlspecialchars($c['name']) ?></strong><?php if ($c['short_name']): ?><br><code><?= htmlspecialchars($c['short_name']) ?></code><?php endif; ?></td>
            <td><span class="badge" style="background:#dbeafe;color:#1e40af"><?= $c['college_type'] ?></span></td>
            <td><?= htmlspecialchars($c['campus_name']) ?></td>
            <td><?= $c['dept_count'] ?></td>
            <td><form method="POST" style="display:inline" onsubmit="return confirm('Delete?')"><?= csrfField() ?><input type="hidden" name="action" value="delete_college"><input type="hidden" name="tab" value="colleges"><input type="hidden" name="id" value="<?= $c['id'] ?>"><button class="btn btn-danger btn-sm">Delete</button></form></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table></div>
</div>
</div>

<!-- ── TAB: Departments ──────────────────────────────────────────────────── -->
<?php elseif ($tab === 'departments'): ?>
<div style="display:grid;grid-template-columns:1fr 1.5fr;gap:1.25rem;align-items:start">
<div class="card">
    <h3>Add Department</h3>
    <form method="POST">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="add_dept">
        <input type="hidden" name="tab" value="departments">
        <div class="form-group"><label>College / Institute</label>
            <select name="college_id" required>
                <option value="">Select college...</option>
                <?php foreach ($colleges as $c): ?><option value="<?= $c['id'] ?>"><?= htmlspecialchars($c['campus_name'].' → '.$c['name']) ?></option><?php endforeach; ?>
            </select></div>
        <div class="form-group"><label>Department Name</label><input type="text" name="name" required placeholder="e.g. Software Engineering"></div>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:.75rem">
            <div class="form-group" style="margin:0"><label>Short Name</label><input type="text" name="short_name" placeholder="SE"></div>
            <div class="form-group" style="margin:0"><label>Department Head (optional)</label><input type="text" name="head_name" placeholder="Dr. "></div>
        </div>
        <button type="submit" class="btn btn-primary btn-full">Add Department</button>
    </form>
</div>
<div class="card">
    <h3>All Departments (<?= count($depts) ?>)</h3>
    <div style="overflow-x:auto"><table class="table">
        <thead><tr><th>Department</th><th>College</th><th>Campus</th><th>Programs</th><th>Action</th></tr></thead>
        <tbody>
        <?php foreach ($depts as $d): ?>
        <tr>
            <td><strong><?= htmlspecialchars($d['name']) ?></strong><?php if ($d['short_name']): ?> <code style="font-size:.78rem">(<?= htmlspecialchars($d['short_name']) ?>)</code><?php endif; ?><?php if ($d['head_name']): ?><br><small class="text-muted">Head: <?= htmlspecialchars($d['head_name']) ?></small><?php endif; ?></td>
            <td><?= htmlspecialchars($d['college_name']) ?></td>
            <td><?= htmlspecialchars($d['campus_name']) ?></td>
            <td><?= $d['program_count'] ?></td>
            <td><form method="POST" style="display:inline" onsubmit="return confirm('Delete?')"><?= csrfField() ?><input type="hidden" name="action" value="delete_dept"><input type="hidden" name="tab" value="departments"><input type="hidden" name="id" value="<?= $d['id'] ?>"><button class="btn btn-danger btn-sm">Delete</button></form></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table></div>
</div>
</div>

<!-- ── TAB: Programs ──────────────────────────────────────────────────────── -->
<?php elseif ($tab === 'programs'): ?>
<div style="display:grid;grid-template-columns:1fr 1.5fr;gap:1.25rem;align-items:start">
<div class="card">
    <h3>Add Program</h3>
    <form method="POST">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="add_program">
        <input type="hidden" name="tab" value="programs">
        <div class="form-group"><label>Department</label>
            <select name="department_id" required>
                <option value="">Select department...</option>
                <?php foreach ($depts as $d): ?><option value="<?= $d['id'] ?>"><?= htmlspecialchars($d['college_name'].' → '.$d['name']) ?></option><?php endforeach; ?>
            </select></div>
        <div class="form-group"><label>Program Name</label><input type="text" name="name" required placeholder="e.g. Software Engineering"></div>
        <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:.75rem">
            <div class="form-group" style="margin:0"><label>Short</label><input type="text" name="short_name" placeholder="SE"></div>
            <div class="form-group" style="margin:0"><label>Degree</label>
                <select name="degree_type">
                    <?php foreach (['BSc','BA','MSc','MA','PhD','Diploma','Certificate'] as $deg): ?>
                    <option value="<?= $deg ?>"><?= $deg ?></option>
                    <?php endforeach; ?>
                </select></div>
            <div class="form-group" style="margin:0"><label>Years</label><input type="number" name="duration_years" value="4" min="1" max="7"></div>
        </div>
        <button type="submit" class="btn btn-primary btn-full" style="margin-top:.75rem">Add Program</button>
    </form>
</div>
<div class="card">
    <h3>All Programs (<?= count($programs) ?>)</h3>
    <div style="overflow-x:auto"><table class="table">
        <thead><tr><th>Program</th><th>Degree</th><th>Duration</th><th>Department</th><th>Batches</th><th>Action</th></tr></thead>
        <tbody>
        <?php foreach ($programs as $p): ?>
        <tr>
            <td><strong><?= htmlspecialchars($p['name']) ?></strong><?php if ($p['short_name']): ?> <code style="font-size:.78rem">(<?= htmlspecialchars($p['short_name']) ?>)</code><?php endif; ?></td>
            <td><span class="badge" style="background:#ede9fe;color:#6d28d9"><?= $p['degree_type'] ?></span></td>
            <td><?= $p['duration_years'] ?> yr / <?= $p['total_semesters'] ?> sem</td>
            <td><?= htmlspecialchars($p['dept_name']) ?></td>
            <td><?= $p['batch_count'] ?></td>
            <td><form method="POST" style="display:inline" onsubmit="return confirm('Delete?')"><?= csrfField() ?><input type="hidden" name="action" value="delete_program"><input type="hidden" name="tab" value="programs"><input type="hidden" name="id" value="<?= $p['id'] ?>"><button class="btn btn-danger btn-sm">Delete</button></form></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table></div>
</div>
</div>

<!-- ── TAB: Batches ──────────────────────────────────────────────────────── -->
<?php elseif ($tab === 'batches'): ?>
<div style="display:grid;grid-template-columns:1fr 1.5fr;gap:1.25rem;align-items:start">
<div class="card">
    <h3>Add Batch (Intake Year)</h3>
    <p class="text-muted" style="font-size:.85rem">A batch is a cohort of students who joined the same program in the same year.</p>
    <form method="POST">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="add_batch">
        <input type="hidden" name="tab" value="batches">
        <div class="form-group"><label>Program</label>
            <select name="program_id" required>
                <option value="">Select program...</option>
                <?php foreach ($programs as $p): ?><option value="<?= $p['id'] ?>"><?= htmlspecialchars($p['dept_name'].' → '.$p['degree_type'].' '.$p['name']) ?></option><?php endforeach; ?>
            </select></div>
        <div class="form-group"><label>Batch Name</label><input type="text" name="batch_name" required placeholder="e.g. 2021/22 Intake"></div>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:.75rem">
            <div class="form-group" style="margin:0"><label>Entry Year</label><input type="number" name="entry_year" required value="<?= date('Y') ?>" min="2000" max="2100"></div>
            <div class="form-group" style="margin:0"><label>Expected Graduation</label><input type="number" name="expected_graduation_year" placeholder="<?= date('Y')+4 ?>" min="2000" max="2110"></div>
        </div>
        <div class="form-group"><label>Total Students (approx.)</label><input type="number" name="total_students" value="0" min="0"></div>
        <button type="submit" class="btn btn-primary btn-full">Add Batch</button>
    </form>
</div>
<div class="card">
    <h3>All Batches (<?= count($batches) ?>)</h3>
    <div style="overflow-x:auto"><table class="table">
        <thead><tr><th>Batch</th><th>Program</th><th>Entry</th><th>Grad</th><th>Students</th><th>Action</th></tr></thead>
        <tbody>
        <?php foreach ($batches as $b): ?>
        <tr>
            <td><strong><?= htmlspecialchars($b['batch_name']) ?></strong></td>
            <td><?= htmlspecialchars($b['degree_type'].' '.$b['program_name']) ?><br><small class="text-muted"><?= htmlspecialchars($b['dept_name']) ?></small></td>
            <td><?= $b['entry_year'] ?></td>
            <td><?= $b['expected_graduation_year'] ?: '–' ?></td>
            <td><?= $b['total_students'] ?: '–' ?></td>
            <td><form method="POST" style="display:inline" onsubmit="return confirm('Delete?')"><?= csrfField() ?><input type="hidden" name="action" value="delete_batch"><input type="hidden" name="tab" value="batches"><input type="hidden" name="id" value="<?= $b['id'] ?>"><button class="btn btn-danger btn-sm">Delete</button></form></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table></div>
</div>
</div>

<!-- ── TAB: Attendance Thresholds ────────────────────────────────────────── -->
<?php elseif ($tab === 'thresholds'): ?>
<div style="display:grid;grid-template-columns:1fr 1fr;gap:1.25rem;align-items:start">
<div class="card">
    <h3>📊 Configure Attendance Thresholds</h3>
    <p class="text-muted" style="font-size:.88rem">These settings apply system-wide. All dashboards and reports use these values.</p>
    <form method="POST">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="save_thresholds">
        <input type="hidden" name="tab" value="thresholds">

        <div class="form-group">
            <label style="display:flex;align-items:center;gap:.5rem">
                🟢 Good — Minimum % <span style="color:#15803d;font-weight:700" id="goodVal"><?= $t['good'] ?>%</span>
            </label>
            <input type="range" name="attendance_threshold_good" id="goodRange"
                   min="50" max="100" value="<?= (int)$t['good'] ?>"
                   oninput="updateThresholdDisplay()"
                   style="width:100%;accent-color:#16a34a">
            <small class="text-muted">Students at or above this are marked Good (green).</small>
        </div>

        <div class="form-group">
            <label style="display:flex;align-items:center;gap:.5rem">
                🟡 Warning — Minimum % <span style="color:#b45309;font-weight:700" id="warnVal"><?= $t['warning'] ?>%</span>
            </label>
            <input type="range" name="attendance_threshold_warning" id="warnRange"
                   min="30" max="90" value="<?= (int)$t['warning'] ?>"
                   oninput="updateThresholdDisplay()"
                   style="width:100%;accent-color:#d97706">
            <small class="text-muted">Between this and Good = Warning (yellow). Below this = Critical (red).</small>
        </div>

        <div class="form-group">
            <label>How Late arrivals count</label>
            <select name="attendance_late_counts">
                <option value="1"   <?= $t['late_counts']==1?'selected':'' ?>>Full attendance (same as Present)</option>
                <option value="0.5" <?= $t['late_counts']==0.5?'selected':'' ?>>Half attendance (0.5 weight)</option>
                <option value="0"   <?= $t['late_counts']==0?'selected':'' ?>>Not counted (same as Absent)</option>
            </select>
        </div>

        <div class="form-group">
            <label>Email notifications</label>
            <div style="display:flex;flex-direction:column;gap:.4rem">
                <label style="display:flex;align-items:center;gap:.6rem;cursor:pointer">
                    <input type="checkbox" name="attendance_warning_email" <?= ($settings['attendance_warning_email']??'1')==='1'?'checked':'' ?>>
                    Send email when student enters Warning zone
                </label>
                <label style="display:flex;align-items:center;gap:.6rem;cursor:pointer">
                    <input type="checkbox" name="attendance_critical_email" <?= ($settings['attendance_critical_email']??'1')==='1'?'checked':'' ?>>
                    Send email when student enters Critical zone
                </label>
            </div>
        </div>

        <button type="submit" class="btn btn-primary btn-full">Save Thresholds</button>
    </form>
</div>

<div class="card">
    <h3>Live Preview</h3>
    <p class="text-muted" style="font-size:.85rem">This shows how attendance percentages will appear across the system.</p>
    <div id="previewArea">
        <?php
        $examples = [
            ['name' => 'Software Architecture', 'pct' => 91],
            ['name' => 'Database Systems',       'pct' => 72],
            ['name' => 'Web Development',        'pct' => 56],
            ['name' => 'Operating Systems',      'pct' => 80],
            ['name' => 'Mobile Development',     'pct' => 63],
        ];
        foreach ($examples as $ex):
            $st = AttendanceCalculator::status($ex['pct']);
        ?>
        <div class="preview-row" data-pct="<?= $ex['pct'] ?>"
             style="display:flex;align-items:center;justify-content:space-between;padding:.6rem 0;border-bottom:1px solid var(--border)">
            <span style="font-size:.9rem"><?= $ex['name'] ?></span>
            <div style="display:flex;align-items:center;gap:.75rem">
                <div style="width:120px">
                    <?= AttendanceCalculator::progressBar($ex['pct']) ?>
                </div>
                <span class="status-badge" style="font-weight:700;font-size:.9rem;background:<?= $st['bg'] ?>;color:<?= $st['color'] ?>;padding:.2rem .6rem;border-radius:12px">
                    <?= $st['icon'] ?> <?= $ex['pct'] ?>%
                </span>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
    <div style="margin-top:1rem;padding:.75rem;background:var(--hover-bg);border-radius:8px">
        <div id="legendArea"><?= AttendanceCalculator::legend() ?></div>
    </div>
</div>
</div>

<!-- ── TAB: University Settings ──────────────────────────────────────────── -->
<?php elseif ($tab === 'university'): ?>
<div class="card" style="max-width:600px">
    <h3>⚙️ University Settings</h3>
    <form method="POST">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="save_university">
        <input type="hidden" name="tab" value="university">
        <div class="form-group"><label>University Full Name</label><input type="text" name="university_name" required value="<?= htmlspecialchars($settings['university_name'] ?? 'Wollo University') ?>"></div>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:.75rem">
            <div class="form-group" style="margin:0"><label>Abbreviation</label><input type="text" name="university_short" value="<?= htmlspecialchars($settings['university_short'] ?? 'WU') ?>"></div>
            <div class="form-group" style="margin:0"><label>City / Location</label><input type="text" name="university_city" value="<?= htmlspecialchars($settings['university_city'] ?? 'Dessie, Ethiopia') ?>"></div>
        </div>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:.75rem;margin-top:.75rem">
            <div class="form-group" style="margin:0"><label>Current Academic Year</label><input type="text" name="current_academic_year" placeholder="2024/25" value="<?= htmlspecialchars($settings['current_academic_year'] ?? '2024/25') ?>"></div>
            <div class="form-group" style="margin:0"><label>Current Semester</label>
                <select name="current_semester">
                    <?php foreach (['Semester 1','Semester 2'] as $s): ?>
                    <option value="<?= $s ?>" <?= ($settings['current_semester']??'')===$s?'selected':'' ?>><?= $s ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>
        <div class="form-group"><label>Student ID Format Pattern</label>
            <input type="text" name="id_format" value="<?= htmlspecialchars($settings['id_format'] ?? 'WU/{DEPT}/{NUM}/{YEAR}') ?>">
            <small class="text-muted">Tokens: {DEPT} = department code, {NUM} = sequence number, {YEAR} = entry year (e.g. WU/SE/001/24)</small>
        </div>
        <button type="submit" class="btn btn-primary">Save Settings</button>
    </form>
</div>
<?php endif; ?>

<script>
function updateThresholdDisplay() {
    const good = parseInt(document.getElementById('goodRange').value);
    const warn = parseInt(document.getElementById('warnRange').value);
    document.getElementById('goodVal').textContent = good + '%';
    document.getElementById('warnVal').textContent = warn + '%';

    // Update live preview
    document.querySelectorAll('.preview-row').forEach(row => {
        const pct    = parseInt(row.dataset.pct);
        const badge  = row.querySelector('.status-badge');
        let color, bg, icon;
        if (pct >= good)      { color='#15803d'; bg='#dcfce7'; icon='🟢'; }
        else if (pct >= warn) { color='#b45309'; bg='#fef3c7'; icon='🟡'; }
        else                  { color='#b91c1c'; bg='#fee2e2'; icon='🔴'; }
        badge.style.color      = color;
        badge.style.background = bg;
        badge.textContent      = icon + ' ' + pct + '%';

        // Bar color
        const bar = row.querySelector('div[style*="height:8px"] > div');
        if (bar) bar.style.background = color;
    });

    document.getElementById('legendArea').innerHTML =
        `<div style="display:flex;gap:1rem;flex-wrap:wrap;font-size:.82rem;color:#475569">
            <span>🟢 Good ≥ ${good}%</span>
            <span>🟡 Warning ${warn}–${good-1}%</span>
            <span>🔴 Critical &lt; ${warn}%</span>
         </div>`;
}
</script>

<?php include '../includes/footer.php'; ?>
