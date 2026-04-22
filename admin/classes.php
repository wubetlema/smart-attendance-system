<?php
require_once '../includes/auth_check.php';
requireRole('admin');
require_once '../config/db.php';

$msg = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($_POST['action'] === 'add') {
        $name    = trim($_POST['name']);
        $subject = trim($_POST['subject']);
        $code    = trim($_POST['course_code']);
        $credits = (int)($_POST['credits'] ?? 3);
        $teacher = (int)$_POST['teacher_id'];
        $sched   = trim($_POST['schedule']);
        $sem     = trim($_POST['semester'] ?? '');
        $sec     = trim($_POST['section'] ?? '');
        $batch   = trim($_POST['batch_year'] ?? '');
        $stmt = $conn->prepare("INSERT INTO classes (name,subject,course_code,credits,teacher_id,schedule,semester,section,batch_year) VALUES (?,?,?,?,?,?,?,?,?)");
        $stmt->bind_param('ssssissss', $name,$subject,$code,$credits,$teacher,$sched,$sem,$sec,$batch);
        $msg = $stmt->execute() ? 'Course created.' : 'Error: '.$conn->error;

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

$classes  = $conn->query("SELECT c.*,u.name AS teacher_name FROM classes c JOIN users u ON c.teacher_id=u.id ORDER BY c.name")->fetch_all(MYSQLI_ASSOC);
$teachers = $conn->query("SELECT id,name FROM users WHERE role='teacher' ORDER BY name")->fetch_all(MYSQLI_ASSOC);
$students = $conn->query("SELECT id,name,university_id FROM users WHERE role='student' ORDER BY name")->fetch_all(MYSQLI_ASSOC);
$depts    = $conn->query("SELECT name FROM departments ORDER BY name")->fetch_all(MYSQLI_ASSOC);

include '../includes/header.php';
?>
<h1>Course & Batch Management</h1>
<?php if ($msg): ?><div class="alert alert-success"><?= htmlspecialchars($msg) ?></div><?php endif; ?>

<!-- Create Course -->
<div class="card">
    <h3>Create Course</h3>
    <form method="POST">
        <input type="hidden" name="action" value="add">
        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:.75rem">
            <div class="form-group" style="margin:0"><label>Course Name</label><input type="text" name="name" required></div>
            <div class="form-group" style="margin:0"><label>Subject</label><input type="text" name="subject" required></div>
            <div class="form-group" style="margin:0"><label>Course Code</label><input type="text" name="course_code" placeholder="e.g. CS301"></div>
            <div class="form-group" style="margin:0"><label>Credits</label><input type="number" name="credits" value="3" min="1" max="6"></div>
            <div class="form-group" style="margin:0"><label>Teacher</label>
                <select name="teacher_id" required>
                    <option value="">Select Teacher</option>
                    <?php foreach ($teachers as $t): ?>
                    <option value="<?= $t['id'] ?>"><?= htmlspecialchars($t['name']) ?></option>
                    <?php endforeach; ?>
                </select></div>
            <div class="form-group" style="margin:0"><label>Schedule</label><input type="text" name="schedule" placeholder="Mon 9AM"></div>
            <div class="form-group" style="margin:0"><label>Semester</label>
                <select name="semester">
                    <option value="">-- Select --</option>
                    <?php foreach (['Semester 1','Semester 2','Semester 3','Semester 4','Semester 5','Semester 6','Semester 7','Semester 8'] as $s): ?>
                    <option value="<?= $s ?>"><?= $s ?></option>
                    <?php endforeach; ?>
                </select></div>
            <div class="form-group" style="margin:0"><label>Section</label><input type="text" name="section" placeholder="A, B, C"></div>
            <div class="form-group" style="margin:0"><label>Batch Year</label><input type="text" name="batch_year" placeholder="e.g. 2024"></div>
        </div>
        <button type="submit" class="btn btn-primary" style="margin-top:1rem">Create Course</button>
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
            <?php foreach (['Semester 1','Semester 2','Semester 3','Semester 4','Semester 5','Semester 6','Semester 7','Semester 8'] as $s): ?>
            <option value="<?= $s ?>"><?= $s ?></option>
            <?php endforeach; ?>
        </select>
        <input type="text" name="bulk_sec" placeholder="Section (optional)" style="width:140px">
        <button type="submit" class="btn btn-secondary" onclick="return confirm('Bulk enroll all matching students?')">Bulk Enroll</button>
    </form>
</div>

<!-- Courses Table -->
<div class="card">
    <h3>All Courses</h3>
    <div style="overflow-x:auto">
    <table class="table">
        <thead><tr><th>Code</th><th>Course</th><th>Subject</th><th>Credits</th><th>Teacher</th><th>Semester</th><th>Section</th><th>Batch</th><th>Schedule</th><th>Action</th></tr></thead>
        <tbody>
        <?php foreach ($classes as $c): ?>
        <tr>
            <td><code><?= htmlspecialchars($c['course_code'] ?? '-') ?></code></td>
            <td><?= htmlspecialchars($c['name']) ?></td>
            <td><?= htmlspecialchars($c['subject']) ?></td>
            <td><?= $c['credits'] ?></td>
            <td><?= htmlspecialchars($c['teacher_name']) ?></td>
            <td><?= htmlspecialchars($c['semester'] ?? '-') ?></td>
            <td><?= htmlspecialchars($c['section'] ?? '-') ?></td>
            <td><?= htmlspecialchars($c['batch_year'] ?? '-') ?></td>
            <td><?= htmlspecialchars($c['schedule'] ?? '-') ?></td>
            <td>
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
<?php include '../includes/footer.php'; ?>
