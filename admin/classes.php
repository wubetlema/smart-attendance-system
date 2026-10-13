<?php
require_once '../includes/auth_check.php';
requireRole('admin');
require_once '../config/db.php';

$msg = ''; $msgType = 'success';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($_POST['action'] === 'add') {
        $name      = trim($_POST['name']);
        $subject   = trim($_POST['subject']);
        $code      = trim($_POST['course_code']);
        $credits   = (int)($_POST['credits'] ?? 3);
        $teacher   = (int)$_POST['teacher_id'];
        $sched     = trim($_POST['schedule']);
        $sem       = trim($_POST['semester'] ?? '');
        $sec       = trim($_POST['section'] ?? '');
        $batch     = trim($_POST['batch_year'] ?? '');
        $termId    = $_POST['term_id'] ? (int)$_POST['term_id'] : null;
        $roomId    = $_POST['room_id'] ? (int)$_POST['room_id'] : null;
        $dept      = trim($_POST['department'] ?? '');
        $maxStud   = (int)($_POST['max_students'] ?? 50);
        $stmt = $conn->prepare("INSERT INTO classes (name,subject,course_code,credits,teacher_id,schedule,semester,section,batch_year,term_id,room_id,department,max_students) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)");
        $stmt->bind_param('ssssissssiisi', $name,$subject,$code,$credits,$teacher,$sched,$sem,$sec,$batch,$termId,$roomId,$dept,$maxStud);
        $msg = $stmt->execute() ? 'Course created.' : 'Error: '.$conn->error;
        if (!$stmt->execute()) $msgType = 'error';

    } elseif ($_POST['action'] === 'edit') {
        $id        = (int)$_POST['id'];
        $name      = trim($_POST['name']);
        $subject   = trim($_POST['subject']);
        $code      = trim($_POST['course_code']);
        $credits   = (int)($_POST['credits'] ?? 3);
        $teacher   = (int)$_POST['teacher_id'];
        $sched     = trim($_POST['schedule']);
        $sem       = trim($_POST['semester'] ?? '');
        $sec       = trim($_POST['section'] ?? '');
        $batch     = trim($_POST['batch_year'] ?? '');
        $termId    = $_POST['term_id'] ? (int)$_POST['term_id'] : null;
        $roomId    = $_POST['room_id'] ? (int)$_POST['room_id'] : null;
        $dept      = trim($_POST['department'] ?? '');
        $maxStud   = (int)($_POST['max_students'] ?? 50);
        $stmt = $conn->prepare("UPDATE classes SET name=?,subject=?,course_code=?,credits=?,teacher_id=?,schedule=?,semester=?,section=?,batch_year=?,term_id=?,room_id=?,department=?,max_students=? WHERE id=?");
        $stmt->bind_param('ssssissssiisii', $name,$subject,$code,$credits,$teacher,$sched,$sem,$sec,$batch,$termId,$roomId,$dept,$maxStud,$id);
        $msg = $stmt->execute() ? 'Course updated.' : 'Error: '.$conn->error;
        if (!$stmt->execute()) $msgType = 'error';

    } elseif ($_POST['action'] === 'toggle_active') {
        $id  = (int)$_POST['id'];
        $new = (int)$_POST['is_active'] ? 0 : 1;
        $conn->query("UPDATE classes SET is_active=$new WHERE id=$id");
        $msg = $new ? 'Course activated.' : 'Course deactivated.';

    } elseif ($_POST['action'] === 'enroll') {
        $class_id   = (int)$_POST['class_id'];
        $student_id = (int)$_POST['student_id'];
        $stmt = $conn->prepare("INSERT IGNORE INTO class_students (class_id,student_id) VALUES (?,?)");
        $stmt->bind_param('ii', $class_id, $student_id);
        $msg = $stmt->execute() ? 'Student enrolled.' : 'Error: '.$conn->error;

    } elseif ($_POST['action'] === 'bulk_enroll') {
        $class_id = (int)$_POST['class_id'];
        $dept     = trim($_POST['bulk_dept'] ?? '');
        $sem      = trim($_POST['bulk_sem'] ?? '');
        $sec      = trim($_POST['bulk_sec'] ?? '');
        $where    = "role='student'";
        if ($dept) $where .= " AND department='".  $conn->real_escape_string($dept)."'";
        if ($sem)  $where .= " AND semester='".$conn->real_escape_string($sem)."'";
        if ($sec)  $where .= " AND section='".$conn->real_escape_string($sec)."'";
        $students = $conn->query("SELECT id FROM users WHERE $where")->fetch_all(MYSQLI_ASSOC);
        $added = 0;
        foreach ($students as $s) {
            $sid  = $s['id'];
            $stmt = $conn->prepare("INSERT IGNORE INTO class_students (class_id,student_id) VALUES (?,?)");
            $stmt->bind_param('ii', $class_id, $sid);
            if ($stmt->execute() && $conn->affected_rows > 0) $added++;
        }
        $msg = "Bulk enrolled $added students.";

    } elseif ($_POST['action'] === 'delete') {
        $id = (int)$_POST['id'];
        $conn->query("DELETE FROM classes WHERE id=$id");
        $msg = 'Course deleted.';
    }
}

$classes  = $conn->query("
    SELECT c.*, u.name AS teacher_name, r.name AS room_name, t.name AS term_name
    FROM classes c
    JOIN users u ON c.teacher_id=u.id
    LEFT JOIN rooms r ON c.room_id=r.id
    LEFT JOIN academic_terms t ON c.term_id=t.id
    ORDER BY c.name
")->fetch_all(MYSQLI_ASSOC);
$teachers = $conn->query("SELECT id,name,department FROM users WHERE role='teacher' ORDER BY name")->fetch_all(MYSQLI_ASSOC);
$students = $conn->query("SELECT id,name,university_id FROM users WHERE role='student' ORDER BY name")->fetch_all(MYSQLI_ASSOC);
$depts    = $conn->query("SELECT name FROM departments ORDER BY name")->fetch_all(MYSQLI_ASSOC);
$rooms    = $conn->query("SELECT id,name,building,capacity FROM rooms WHERE is_active=1 ORDER BY name")->fetch_all(MYSQLI_ASSOC);
$terms    = $conn->query("SELECT id,name,academic_year FROM academic_terms ORDER BY start_date DESC")->fetch_all(MYSQLI_ASSOC);
$currentTerm = $conn->query("SELECT id FROM academic_terms WHERE is_current=1 LIMIT 1")->fetch_assoc();

$editClass = null;
if (isset($_GET['edit'])) {
    $eid = (int)$_GET['edit'];
    $stmt = $conn->prepare("SELECT * FROM classes WHERE id=?");
    $stmt->bind_param('i', $eid); $stmt->execute();
    $editClass = $stmt->get_result()->fetch_assoc();
}

include '../includes/header.php';
?>
<h1>Course & Subject Management</h1>
<?php if ($msg): ?><div class="alert alert-<?= $msgType ?>"><?= htmlspecialchars($msg) ?></div><?php endif; ?>

<!-- Create / Edit Course -->
<div class="card">
    <h3><?= $editClass ? 'Edit Course' : 'Create Course' ?></h3>
    <form method="POST">
        <input type="hidden" name="action" value="<?= $editClass ? 'edit' : 'add' ?>">
        <?php if ($editClass): ?><input type="hidden" name="id" value="<?= $editClass['id'] ?>"><?php endif; ?>
        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:.75rem">
            <div class="form-group" style="margin:0"><label>Course Name</label>
                <input type="text" name="name" required value="<?= htmlspecialchars($editClass['name'] ?? '') ?>"></div>
            <div class="form-group" style="margin:0"><label>Subject</label>
                <input type="text" name="subject" required value="<?= htmlspecialchars($editClass['subject'] ?? '') ?>"></div>
            <div class="form-group" style="margin:0"><label>Course Code</label>
                <input type="text" name="course_code" placeholder="e.g. CS301" value="<?= htmlspecialchars($editClass['course_code'] ?? '') ?>"></div>
            <div class="form-group" style="margin:0"><label>Credits</label>
                <input type="number" name="credits" value="<?= $editClass['credits'] ?? 3 ?>" min="1" max="6"></div>
            <div class="form-group" style="margin:0"><label>Department</label>
                <input type="text" name="department" list="dept-list" value="<?= htmlspecialchars($editClass['department'] ?? '') ?>">
                <datalist id="dept-list"><?php foreach ($depts as $d): ?><option value="<?= htmlspecialchars($d['name']) ?>"><?php endforeach; ?></datalist></div>
            <div class="form-group" style="margin:0"><label>Max Students</label>
                <input type="number" name="max_students" value="<?= $editClass['max_students'] ?? 50 ?>" min="1"></div>

            <!-- Teacher Assignment -->
            <div class="form-group" style="margin:0"><label>Assigned Teacher</label>
                <select name="teacher_id" required id="teacherSelect" onchange="showTeacherInfo(this)">
                    <option value="">Select Teacher</option>
                    <?php foreach ($teachers as $t): ?>
                    <option value="<?= $t['id'] ?>" data-dept="<?= htmlspecialchars($t['department'] ?? '') ?>" <?= ($editClass['teacher_id']??'')==$t['id']?'selected':'' ?>><?= htmlspecialchars($t['name']) ?><?= $t['department']?' ('.$t['department'].')':'' ?></option>
                    <?php endforeach; ?>
                </select>
                <small id="teacherDept" class="text-muted"></small></div>

            <!-- Room Assignment -->
            <div class="form-group" style="margin:0"><label>Classroom / Room</label>
                <select name="room_id">
                    <option value="">No room assigned</option>
                    <?php foreach ($rooms as $r): ?>
                    <option value="<?= $r['id'] ?>" <?= ($editClass['room_id']??'')==$r['id']?'selected':'' ?>><?= htmlspecialchars($r['name']) ?><?= $r['building']?' — '.$r['building']:'' ?> (cap: <?= $r['capacity'] ?>)</option>
                    <?php endforeach; ?>
                </select></div>

            <!-- Term Assignment -->
            <div class="form-group" style="margin:0"><label>Academic Term</label>
                <select name="term_id">
                    <option value="">No term</option>
                    <?php foreach ($terms as $t): ?>
                    <option value="<?= $t['id'] ?>" <?= ($editClass['term_id']??($currentTerm['id']??''))==$t['id']?'selected':'' ?>><?= htmlspecialchars($t['name']) ?> (<?= $t['academic_year'] ?>)</option>
                    <?php endforeach; ?>
                </select></div>

            <div class="form-group" style="margin:0"><label>Schedule</label>
                <input type="text" name="schedule" placeholder="Mon 9AM" value="<?= htmlspecialchars($editClass['schedule'] ?? '') ?>"></div>
            <div class="form-group" style="margin:0"><label>Semester</label>
                <select name="semester">
                    <option value="">-- Select --</option>
                    <?php foreach (['Semester 1','Semester 2'] as $s): ?>
                    <option value="<?= $s ?>" <?= ($editClass['semester']??'')===$s?'selected':'' ?>><?= $s ?></option>
                    <?php endforeach; ?>
                </select></div>
            <div class="form-group" style="margin:0"><label>Section</label>
                <input type="text" name="section" placeholder="A, B, C" value="<?= htmlspecialchars($editClass['section'] ?? '') ?>"></div>
            <div class="form-group" style="margin:0"><label>Batch Year</label>
                <input type="text" name="batch_year" placeholder="e.g. 2024" value="<?= htmlspecialchars($editClass['batch_year'] ?? '') ?>"></div>
        </div>
        <div style="margin-top:1rem;display:flex;gap:.75rem">
            <button type="submit" class="btn btn-primary"><?= $editClass ? 'Save Changes' : 'Create Course' ?></button>
            <?php if ($editClass): ?><a href="classes.php" class="btn btn-secondary">Cancel</a><?php endif; ?>
        </div>
    </form>
</div>

<!-- Individual Enroll -->
<div class="card">
    <h3>Enroll Student (Individual)</h3>
    <form method="POST" class="form-inline">
        <input type="hidden" name="action" value="enroll">
        <select name="class_id" required>
            <option value="">Select Course</option>
            <?php foreach ($classes as $c): ?>
            <option value="<?= $c['id'] ?>"><?= htmlspecialchars($c['course_code']?'['.$c['course_code'].'] ':'') . htmlspecialchars($c['name']) ?></option>
            <?php endforeach; ?>
        </select>
        <select name="student_id" required>
            <option value="">Select Student</option>
            <?php foreach ($students as $s): ?>
            <option value="<?= $s['id'] ?>"><?= htmlspecialchars($s['name']) ?> (<?= htmlspecialchars($s['university_id'] ?? '') ?>)</option>
            <?php endforeach; ?>
        </select>
        <button type="submit" class="btn btn-primary">Enroll</button>
    </form>
</div>

<!-- Bulk Enroll -->
<div class="card">
    <h3>Bulk Enroll by Department / Semester / Section</h3>
    <form method="POST" class="form-inline">
        <input type="hidden" name="action" value="bulk_enroll">
        <select name="class_id" required>
            <option value="">Select Course</option>
            <?php foreach ($classes as $c): ?>
            <option value="<?= $c['id'] ?>"><?= htmlspecialchars($c['course_code']?'['.$c['course_code'].'] ':'') . htmlspecialchars($c['name']) ?></option>
            <?php endforeach; ?>
        </select>
        <select name="bulk_dept">
            <option value="">Any Department</option>
            <?php foreach ($depts as $d): ?>
            <option value="<?= htmlspecialchars($d['name']) ?>"><?= htmlspecialchars($d['name']) ?></option>
            <?php endforeach; ?>
        </select>
        <select name="bulk_sem">
            <option value="">Any Semester</option>
            <?php foreach (['Semester 1','Semester 2'] as $s): ?>
            <option value="<?= $s ?>"><?= $s ?></option>
            <?php endforeach; ?>
        </select>
        <input type="text" name="bulk_sec" placeholder="Section (optional)" style="width:140px">
        <button type="submit" class="btn btn-secondary" onclick="return confirm('Bulk enroll all matching students?')">Bulk Enroll</button>
    </form>
</div>

<!-- Teacher Workload Overview -->
<div class="card">
    <h3>Teacher Workload</h3>
    <div style="overflow-x:auto">
    <table class="table">
        <thead><tr><th>Teacher</th><th>Courses Assigned</th><th>Total Students</th><th>Courses</th></tr></thead>
        <tbody>
        <?php
        $workload = $conn->query("
            SELECT u.id, u.name, u.department,
                   COUNT(DISTINCT c.id) AS course_count,
                   COUNT(DISTINCT cs.student_id) AS student_count,
                   GROUP_CONCAT(DISTINCT c.name ORDER BY c.name SEPARATOR ', ') AS course_list
            FROM users u
            LEFT JOIN classes c ON c.teacher_id=u.id
            LEFT JOIN class_students cs ON cs.class_id=c.id
            WHERE u.role='teacher'
            GROUP BY u.id
            ORDER BY course_count DESC
        ")->fetch_all(MYSQLI_ASSOC);
        foreach ($workload as $w):
        ?>
        <tr>
            <td><?= htmlspecialchars($w['name']) ?><?php if($w['department']): ?><br><small class="text-muted"><?= htmlspecialchars($w['department']) ?></small><?php endif; ?></td>
            <td><span class="badge" style="background:#dbeafe;color:#1e40af;font-size:1rem"><?= $w['course_count'] ?></span></td>
            <td><?= $w['student_count'] ?></td>
            <td><small class="text-muted"><?= htmlspecialchars($w['course_list'] ?: '-') ?></small></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
</div>

<!-- Courses Table -->
<div class="card">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:1rem;flex-wrap:wrap;gap:.5rem">
        <h3>All Courses (<?= count($classes) ?>)</h3>
        <div style="display:flex;gap:.5rem;flex-wrap:wrap">
            <select id="termFilter" onchange="filterCourses()" style="padding:.4rem .6rem;border:1px solid var(--border);border-radius:7px;background:var(--card-bg);color:var(--text)">
                <option value="">All Terms</option>
                <?php foreach ($terms as $t): ?>
                <option value="<?= htmlspecialchars($t['name']) ?>"><?= htmlspecialchars($t['name']) ?></option>
                <?php endforeach; ?>
            </select>
            <input type="text" id="searchInput" placeholder="Search..." onkeyup="filterCourses()" style="width:180px">
        </div>
    </div>
    <div style="overflow-x:auto">
    <table class="table" id="coursesTable">
        <thead><tr><th>Code</th><th>Course</th><th>Subject</th><th>Cr</th><th>Teacher</th><th>Room</th><th>Term</th><th>Sem</th><th>Sec</th><th>Batch</th><th>Status</th><th>Actions</th></tr></thead>
        <tbody>
        <?php foreach ($classes as $c): ?>
        <tr data-term="<?= htmlspecialchars($c['term_name'] ?? '') ?>">
            <td><code><?= htmlspecialchars($c['course_code'] ?? '-') ?></code></td>
            <td><?= htmlspecialchars($c['name']) ?><?php if($c['department']): ?><br><small class="text-muted"><?= htmlspecialchars($c['department']) ?></small><?php endif; ?></td>
            <td><?= htmlspecialchars($c['subject']) ?></td>
            <td><?= $c['credits'] ?></td>
            <td><?= htmlspecialchars($c['teacher_name']) ?></td>
            <td><?= htmlspecialchars($c['room_name'] ?? '-') ?></td>
            <td><?= htmlspecialchars($c['term_name'] ?? '-') ?></td>
            <td><?= htmlspecialchars($c['semester'] ?? '-') ?></td>
            <td><?= htmlspecialchars($c['section'] ?? '-') ?></td>
            <td><?= htmlspecialchars($c['batch_year'] ?? '-') ?></td>
            <td><span class="badge" style="background:<?= ($c['is_active']??1)?'#dcfce7':'#fee2e2' ?>;color:<?= ($c['is_active']??1)?'#15803d':'#b91c1c' ?>"><?= ($c['is_active']??1)?'Active':'Inactive' ?></span></td>
            <td style="display:flex;gap:.3rem;flex-wrap:wrap">
                <a href="?edit=<?= $c['id'] ?>" class="btn btn-secondary btn-sm">Edit</a>
                <form method="POST" style="display:inline">
                    <input type="hidden" name="action" value="toggle_active">
                    <input type="hidden" name="id" value="<?= $c['id'] ?>">
                    <input type="hidden" name="is_active" value="<?= $c['is_active'] ?? 1 ?>">
                    <button class="btn btn-sm" style="background:<?= ($c['is_active']??1)?'#f59e0b':'#16a34a' ?>;color:#fff"><?= ($c['is_active']??1)?'Deactivate':'Activate' ?></button>
                </form>
                <form method="POST" style="display:inline" onsubmit="return confirm('Delete course?')">
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="id" value="<?= $c['id'] ?>">
                    <button class="btn btn-danger btn-sm">Delete</button>
                </form>
            </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
</div>

<script>
function showTeacherInfo(sel) {
    const opt = sel.options[sel.selectedIndex];
    const dept = opt.dataset.dept || '';
    document.getElementById('teacherDept').textContent = dept ? 'Department: ' + dept : '';
}
function filterCourses() {
    const q    = document.getElementById('searchInput').value.toLowerCase();
    const term = document.getElementById('termFilter').value;
    document.querySelectorAll('#coursesTable tbody tr').forEach(r => {
        const matchQ    = r.textContent.toLowerCase().includes(q);
        const matchTerm = !term || r.dataset.term === term;
        r.style.display = (matchQ && matchTerm) ? '' : 'none';
    });
}
</script>
<?php include '../includes/footer.php'; ?>
