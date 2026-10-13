<?php
/**
 * Admin Enrollment Management
 *
 * Covers the full workflow:
 *   Admin creates course → Teacher assigned → Students enrolled
 *   → Roster generated → Attendance begins
 *
 * Features:
 *   - CSV student import (creates user accounts)
 *   - CSV enrollment import (assigns existing students to classes)
 *   - Bulk enroll by dept/semester/section
 *   - Individual enroll / remove / transfer
 *   - Section management (move students between sections)
 *   - Enrollment history (full audit trail)
 *   - Download example CSV templates
 *   - Class roster export
 */
require_once '../includes/auth_check.php';
requireRole('admin');
require_once '../config/db.php';
require_once '../includes/functions.php';

$msg = ''; $msgType = 'success';

// ── CSV Template Downloads ────────────────────────────────────────────────────
if (isset($_GET['template'])) {
    $type = $_GET['template'];
    header('Content-Type: text/csv');

    if ($type === 'students') {
        header('Content-Disposition: attachment; filename="student_import_template.csv"');
        echo "University_ID,Name,Email,Department,Year,Section,Password\n";
        echo "WU/SE/001/24,Wubet Lema,wubet@example.com,Software Engineering,4,A,TempPass@2024\n";
        echo "WU/SE/002/24,Abebe Kebede,abebe@example.com,Software Engineering,4,A,TempPass@2024\n";
        echo "WU/CS/001/24,Sara Ahmed,sara@example.com,Computer Science,3,B,TempPass@2024\n";
    } elseif ($type === 'enrollments') {
        header('Content-Disposition: attachment; filename="enrollment_import_template.csv"');
        echo "University_ID,Course_Code,Section,Academic_Year\n";
        echo "WU/SE/001/24,CS301,A,2024/25\n";
        echo "WU/SE/002/24,CS301,A,2024/25\n";
        echo "WU/CS/001/24,CS401,B,2024/25\n";
    }
    exit;
}

// ── Roster Export ─────────────────────────────────────────────────────────────
if (isset($_GET['export_roster'])) {
    $classId = (int)$_GET['export_roster'];
    $class   = $conn->query("SELECT name, subject, course_code FROM classes WHERE id=$classId")->fetch_assoc();
    if ($class) {
        $rows = $conn->query("
            SELECT u.university_id, u.name, u.email, u.department, u.semester,
                   cs.section, cs.academic_year, cs.status, cs.enrolled_at
            FROM class_students cs
            JOIN users u ON cs.student_id=u.id
            WHERE cs.class_id=$classId
            ORDER BY cs.section, u.name
        ")->fetch_all(MYSQLI_ASSOC);
        header('Content-Type: text/csv');
        header('Content-Disposition: attachment; filename="roster_' . preg_replace('/[^a-z0-9]/i','_',$class['name']) . '.csv"');
        $out = fopen('php://output','w');
        fputcsv($out, ['University ID','Name','Email','Department','Semester','Section','Academic Year','Status','Enrolled At']);
        foreach ($rows as $r) fputcsv($out, array_values($r));
        fclose($out);
        exit;
    }
}

// ── Helpers ───────────────────────────────────────────────────────────────────
function logEnrollment(int $studentId, int $classId, string $action, int $performedBy, int $fromClass = 0, int $toClass = 0, string $notes = ''): void {
    global $conn;
    $stmt = $conn->prepare("
        INSERT INTO enrollment_history
            (student_id, class_id, action, performed_by, from_class_id, to_class_id, notes)
        VALUES (?,?,?,?,?,?,?)
    ");
    $from = $fromClass ?: null;
    $to   = $toClass   ?: null;
    $stmt->bind_param('iisiiss', $studentId, $classId, $action, $performedBy, $from, $to, $notes);
    $stmt->execute();
}

// ── POST Actions ──────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    verifyCsrf();
    $adminId = (int)$_SESSION['user_id'];

    // ── Individual Enroll ─────────────────────────────────────────────────────
    if ($_POST['action'] === 'enroll') {
        $classId   = (int)$_POST['class_id'];
        $studentId = (int)$_POST['student_id'];
        $section   = trim($_POST['section'] ?? '');
        $year      = trim($_POST['academic_year'] ?? '');
        $stmt = $conn->prepare("
            INSERT IGNORE INTO class_students (class_id, student_id, section, academic_year, enrolled_by)
            VALUES (?,?,?,?,?)
        ");
        $stmt->bind_param('iissi', $classId, $studentId, $section, $year, $adminId);
        if ($stmt->execute() && $conn->affected_rows > 0) {
            logEnrollment($studentId, $classId, 'enrolled', $adminId);
            $msg = 'Student enrolled successfully.';
        } else {
            $msg = 'Student is already enrolled in this class.'; $msgType = 'error';
        }

    // ── Remove Enrollment ─────────────────────────────────────────────────────
    } elseif ($_POST['action'] === 'remove') {
        $classId   = (int)$_POST['class_id'];
        $studentId = (int)$_POST['student_id'];
        $notes     = trim($_POST['notes'] ?? '');
        $upd = $conn->prepare("UPDATE class_students SET status='dropped' WHERE class_id=? AND student_id=?");
        $upd->bind_param('ii', $classId, $studentId);
        $upd->execute();
        logEnrollment($studentId, $classId, 'dropped', $adminId, 0, 0, $notes);
        $msg = 'Student removed from class (marked as dropped).';

    // ── Transfer Student ──────────────────────────────────────────────────────
    } elseif ($_POST['action'] === 'transfer') {
        $fromClassId = (int)$_POST['from_class_id'];
        $toClassId   = (int)$_POST['to_class_id'];
        $studentId   = (int)$_POST['student_id'];
        $notes       = trim($_POST['notes'] ?? '');
        $section     = trim($_POST['section'] ?? '');

        // Mark old enrollment as transferred
        $upd = $conn->prepare("
            UPDATE class_students
            SET status='transferred', transferred_from=?, transferred_at=NOW()
            WHERE class_id=? AND student_id=?
        ");
        $upd->bind_param('iii', $fromClassId, $fromClassId, $studentId);
        $upd->execute();

        // Enroll in new class
        $ins = $conn->prepare("
            INSERT IGNORE INTO class_students (class_id, student_id, section, enrolled_by)
            VALUES (?,?,?,?)
        ");
        $ins->bind_param('iisi', $toClassId, $studentId, $section, $adminId);
        $ins->execute();

        logEnrollment($studentId, $fromClassId, 'transferred_out', $adminId, $fromClassId, $toClassId, $notes);
        logEnrollment($studentId, $toClassId,   'transferred_in',  $adminId, $fromClassId, $toClassId, $notes);
        $msg = 'Student transferred successfully.';

    // ── Change Section ────────────────────────────────────────────────────────
    } elseif ($_POST['action'] === 'change_section') {
        $classId   = (int)$_POST['class_id'];
        $studentId = (int)$_POST['student_id'];
        $newSection = trim($_POST['new_section']);
        $upd = $conn->prepare("UPDATE class_students SET section=? WHERE class_id=? AND student_id=?");
        $upd->bind_param('sii', $newSection, $classId, $studentId);
        $upd->execute();
        logEnrollment($studentId, $classId, 'enrolled', $adminId, 0, 0, "Section changed to $newSection");
        $msg = 'Section updated.';

    // ── Bulk Enroll by Department / Semester / Section ────────────────────────
    } elseif ($_POST['action'] === 'bulk_enroll') {
        $classId  = (int)$_POST['class_id'];
        $dept     = trim($_POST['bulk_dept'] ?? '');
        $semester = trim($_POST['bulk_sem']  ?? '');
        $section  = trim($_POST['bulk_sec']  ?? '');
        $year     = trim($_POST['academic_year'] ?? '');

        $where = "role='student' AND is_active=1";
        if ($dept)     $where .= " AND department='" . $conn->real_escape_string($dept) . "'";
        if ($semester) $where .= " AND semester='"   . $conn->real_escape_string($semester) . "'";
        if ($section)  $where .= " AND section='"    . $conn->real_escape_string($section) . "'";

        $students = $conn->query("SELECT id FROM users WHERE $where")->fetch_all(MYSQLI_ASSOC);
        $added = 0;
        foreach ($students as $s) {
            $sid  = (int)$s['id'];
            $stmt = $conn->prepare("INSERT IGNORE INTO class_students (class_id, student_id, section, academic_year, enrolled_by) VALUES (?,?,?,?,?)");
            $stmt->bind_param('iissi', $classId, $sid, $section, $year, $adminId);
            if ($stmt->execute() && $conn->affected_rows > 0) {
                logEnrollment($sid, $classId, 'enrolled', $adminId, 0, 0, "Bulk enroll");
                $added++;
            }
        }
        $msg = "Bulk enrolled $added student(s) into the class.";
        if ($added === 0 && !empty($students)) { $msg = 'All matching students are already enrolled.'; $msgType = 'error'; }
        if (empty($students)) { $msg = 'No students found matching those filters.'; $msgType = 'error'; }

    // ── CSV Student Import ────────────────────────────────────────────────────
    } elseif ($_POST['action'] === 'csv_import_students') {
        if (!empty($_FILES['csv_file']['tmp_name'])) {
            $handle  = fopen($_FILES['csv_file']['tmp_name'], 'r');
            $headers = fgetcsv($handle); // skip header row
            $added   = 0; $skipped = 0; $errors = 0;

            while (($row = fgetcsv($handle)) !== false) {
                if (count($row) < 2) { $errors++; continue; }
                $univId  = trim($row[0] ?? '');
                $name    = trim($row[1] ?? '');
                $email   = trim($row[2] ?? '');
                $dept    = trim($row[3] ?? '');
                $year    = trim($row[4] ?? '');
                $section = trim($row[5] ?? '');
                $rawPass = trim($row[6] ?? 'TempPass@2024');

                if (!$univId || !$name) { $errors++; continue; }
                if (strlen($rawPass) < 6) $rawPass = 'TempPass@2024';

                $hash = password_hash($rawPass, PASSWORD_BCRYPT, ['cost' => 10]);
                $sem  = $year ? "Semester $year" : '';

                $stmt = $conn->prepare("
                    INSERT IGNORE INTO users
                        (university_id, name, email, password, role, department, semester, section, is_first_login, is_active)
                    VALUES (?,?,?,?,'student',?,?,?,1,1)
                ");
                $stmt->bind_param('sssssss', $univId, $name, $email, $hash, $dept, $sem, $section);
                if ($stmt->execute()) {
                    $conn->affected_rows > 0 ? $added++ : $skipped++;
                } else { $errors++; }
            }
            fclose($handle);

            // Log the import batch
            $stmt = $conn->prepare("INSERT INTO csv_import_batches (type, filename, total_rows, added, skipped, errors, performed_by) VALUES ('students',?,?,?,?,?,?)");
            $fname = $_FILES['csv_file']['name'];
            $total = $added + $skipped + $errors;
            $stmt->bind_param('siiii i', $fname, $total, $added, $skipped, $errors, $adminId);
            $stmt->execute();

            $msg     = "Student CSV import complete: $added created, $skipped already existed, $errors invalid rows.";
            $msgType = $errors > 0 ? 'error' : 'success';
        } else {
            $msg = 'No file uploaded.'; $msgType = 'error';
        }

    // ── CSV Enrollment Import ─────────────────────────────────────────────────
    } elseif ($_POST['action'] === 'csv_import_enrollments') {
        if (!empty($_FILES['csv_file']['tmp_name'])) {
            $handle  = fopen($_FILES['csv_file']['tmp_name'], 'r');
            $headers = fgetcsv($handle);
            $added   = 0; $skipped = 0; $errors = 0; $notFound = [];

            while (($row = fgetcsv($handle)) !== false) {
                if (count($row) < 2) { $errors++; continue; }
                $univId     = trim($row[0] ?? '');
                $courseCode = trim($row[1] ?? '');
                $section    = trim($row[2] ?? '');
                $year       = trim($row[3] ?? '');

                if (!$univId || !$courseCode) { $errors++; continue; }

                // Look up student
                $sStmt = $conn->prepare("SELECT id FROM users WHERE university_id=? AND role='student'");
                $sStmt->bind_param('s', $univId); $sStmt->execute();
                $student = $sStmt->get_result()->fetch_assoc();

                // Look up class by course code
                $cStmt = $conn->prepare("SELECT id FROM classes WHERE course_code=? AND is_active=1 LIMIT 1");
                $cStmt->bind_param('s', $courseCode); $cStmt->execute();
                $class = $cStmt->get_result()->fetch_assoc();

                if (!$student) { $notFound[] = "$univId (student not found)"; $errors++; continue; }
                if (!$class)   { $notFound[] = "$courseCode (course not found)"; $errors++; continue; }

                $sid = (int)$student['id'];
                $cid = (int)$class['id'];
                $ins = $conn->prepare("INSERT IGNORE INTO class_students (class_id, student_id, section, academic_year, enrolled_by) VALUES (?,?,?,?,?)");
                $ins->bind_param('iissi', $cid, $sid, $section, $year, $adminId);
                if ($ins->execute()) {
                    if ($conn->affected_rows > 0) {
                        logEnrollment($sid, $cid, 'enrolled', $adminId, 0, 0, "CSV enrollment import");
                        $added++;
                    } else { $skipped++; }
                } else { $errors++; }
            }
            fclose($handle);

            $stmt = $conn->prepare("INSERT INTO csv_import_batches (type, filename, total_rows, added, skipped, errors, performed_by, notes) VALUES ('enrollments',?,?,?,?,?,?,?)");
            $fname = $_FILES['csv_file']['name'];
            $total = $added + $skipped + $errors;
            $nfNote = !empty($notFound) ? 'Not found: ' . implode(', ', array_slice($notFound, 0, 10)) : '';
            $stmt->bind_param('siiiiis', $fname, $total, $added, $skipped, $errors, $adminId, $nfNote);
            $stmt->execute();

            $msg     = "Enrollment CSV import: $added enrolled, $skipped duplicates, $errors errors.";
            if (!empty($notFound)) $msg .= ' Not found: ' . implode(', ', array_slice($notFound, 0, 5)) . (count($notFound) > 5 ? '...' : '');
            $msgType = $errors > 0 ? 'error' : 'success';
        } else {
            $msg = 'No file uploaded.'; $msgType = 'error';
        }
    }
}

// ── Data Fetching ─────────────────────────────────────────────────────────────
$classes  = $conn->query("
    SELECT c.id, c.name, c.subject, c.course_code, c.section, c.department,
           c.semester, c.max_students, c.is_active,
           u.name AS teacher_name,
           COUNT(cs.id) AS enrolled_count
    FROM classes c
    JOIN users u ON c.teacher_id = u.id
    LEFT JOIN class_students cs ON cs.class_id = c.id AND cs.status = 'active'
    WHERE c.is_active = 1
    GROUP BY c.id
    ORDER BY c.name
")->fetch_all(MYSQLI_ASSOC);

$students = $conn->query("
    SELECT id, name, university_id, department, semester, section
    FROM users WHERE role='student' AND is_active=1 ORDER BY name
")->fetch_all(MYSQLI_ASSOC);

$depts = $conn->query("SELECT DISTINCT department FROM users WHERE department != '' ORDER BY department")->fetch_all(MYSQLI_ASSOC);

// Selected class roster
$selectedClassId = (int)($_GET['class_id'] ?? 0);
$roster = [];
$selectedClass = null;
if ($selectedClassId) {
    $stmt = $conn->prepare("SELECT c.*, u.name AS teacher_name FROM classes c JOIN users u ON c.teacher_id=u.id WHERE c.id=?");
    $stmt->bind_param('i', $selectedClassId); $stmt->execute();
    $selectedClass = $stmt->get_result()->fetch_assoc();

    $roster = $conn->query("
        SELECT u.id AS student_id, u.name, u.university_id, u.email, u.department,
               cs.section, cs.academic_year, cs.status, cs.enrolled_at,
               eu.name AS enrolled_by_name,
               SUM(a.status IN ('present','late')) AS attended,
               COUNT(a.id) AS total_sessions,
               ROUND(SUM(a.status IN ('present','late')) / NULLIF(COUNT(a.id),0) * 100) AS attendance_pct
        FROM class_students cs
        JOIN users u ON cs.student_id = u.id
        LEFT JOIN users eu ON cs.enrolled_by = eu.id
        LEFT JOIN attendance a ON a.student_id = u.id
            AND a.session_id IN (SELECT id FROM attendance_sessions WHERE class_id=$selectedClassId)
        WHERE cs.class_id = $selectedClassId
        GROUP BY u.id, cs.id
        ORDER BY cs.status, cs.section, u.name
    ")->fetch_all(MYSQLI_ASSOC);
}

// Enrollment history
$historyClassId = (int)($_GET['history_class'] ?? $selectedClassId);
$history = [];
if ($historyClassId) {
    $history = $conn->query("
        SELECT eh.*, u.name AS student_name, u.university_id,
               p.name AS performed_by_name,
               fc.name AS from_class_name, tc.name AS to_class_name
        FROM enrollment_history eh
        JOIN users u ON eh.student_id = u.id
        LEFT JOIN users p ON eh.performed_by = p.id
        LEFT JOIN classes fc ON eh.from_class_id = fc.id
        LEFT JOIN classes tc ON eh.to_class_id   = tc.id
        WHERE eh.class_id = $historyClassId
        ORDER BY eh.created_at DESC
        LIMIT 100
    ")->fetch_all(MYSQLI_ASSOC);
}

// Import batch log
$importLog = $conn->query("
    SELECT b.*, u.name AS performed_by_name
    FROM csv_import_batches b LEFT JOIN users u ON b.performed_by=u.id
    ORDER BY b.created_at DESC LIMIT 20
")->fetch_all(MYSQLI_ASSOC);

include '../includes/header.php';
?>

<h1>Student Enrollment Management</h1>

<?php if ($msg): ?>
<div class="alert alert-<?= $msgType ?>"><?= htmlspecialchars($msg) ?></div>
<?php endif; ?>

<!-- Workflow banner -->
<div class="card" style="background:linear-gradient(135deg,#ede9fe,#dbeafe);border:none;margin-bottom:1.25rem">
    <h3 style="margin:0 0 .5rem">Enrollment Workflow</h3>
    <div style="display:flex;align-items:center;gap:.5rem;flex-wrap:wrap;font-size:.88rem;color:#334155">
        <span style="background:#fff;padding:.3rem .7rem;border-radius:20px;font-weight:600">1. Create Course</span>
        <span>→</span>
        <span style="background:#fff;padding:.3rem .7rem;border-radius:20px;font-weight:600">2. Assign Teacher</span>
        <span>→</span>
        <span style="background:#6366f1;color:#fff;padding:.3rem .7rem;border-radius:20px;font-weight:600">3. Enroll Students ← You are here</span>
        <span>→</span>
        <span style="background:#fff;padding:.3rem .7rem;border-radius:20px;font-weight:600">4. Roster Ready</span>
        <span>→</span>
        <span style="background:#fff;padding:.3rem .7rem;border-radius:20px;font-weight:600">5. Attendance Begins</span>
    </div>
</div>

<div style="display:grid;grid-template-columns:1fr 1.6fr;gap:1.5rem;align-items:start">

<!-- Left column: enrollment tools -->
<div>
    <!-- Download Templates -->
    <div class="card">
        <h3>📥 Download CSV Templates</h3>
        <div style="display:flex;flex-direction:column;gap:.6rem">
            <a href="?template=students" class="btn btn-secondary" style="text-align:center">
                Student Import Template <small style="display:block;opacity:.7">University_ID, Name, Email, Dept, Year, Section, Password</small>
            </a>
            <a href="?template=enrollments" class="btn btn-secondary" style="text-align:center">
                Enrollment Import Template <small style="display:block;opacity:.7">University_ID, Course_Code, Section, Academic_Year</small>
            </a>
        </div>
    </div>

    <!-- CSV Student Import -->
    <div class="card">
        <h3>📤 Import Students via CSV</h3>
        <p class="text-muted" style="font-size:.88rem">Creates student accounts automatically. Duplicate IDs are skipped. All imported students will be prompted to change their password on first login.</p>
        <form method="POST" enctype="multipart/form-data">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="csv_import_students">
            <div class="form-group">
                <label>Select CSV File</label>
                <input type="file" name="csv_file" accept=".csv" required>
            </div>
            <button type="submit" class="btn btn-primary btn-full">Import Students</button>
        </form>
    </div>

    <!-- CSV Enrollment Import -->
    <div class="card">
        <h3>📤 Import Enrollments via CSV</h3>
        <p class="text-muted" style="font-size:.88rem">Enrolls existing students into courses by University ID and Course Code. Both must already exist in the system.</p>
        <form method="POST" enctype="multipart/form-data">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="csv_import_enrollments">
            <div class="form-group">
                <label>Select CSV File</label>
                <input type="file" name="csv_file" accept=".csv" required>
            </div>
            <button type="submit" class="btn btn-primary btn-full">Import Enrollments</button>
        </form>
    </div>

    <!-- Individual Enroll -->
    <div class="card">
        <h3>➕ Enroll Individual Student</h3>
        <form method="POST">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="enroll">
            <div class="form-group">
                <label>Course</label>
                <select name="class_id" required>
                    <option value="">Select course...</option>
                    <?php foreach ($classes as $c): ?>
                    <option value="<?= $c['id'] ?>"><?= htmlspecialchars(($c['course_code']?'['.$c['course_code'].'] ':'').$c['name']) ?> — <?= htmlspecialchars($c['subject']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label>Student</label>
                <select name="student_id" required>
                    <option value="">Select student...</option>
                    <?php foreach ($students as $s): ?>
                    <option value="<?= $s['id'] ?>"><?= htmlspecialchars($s['name']) ?> (<?= htmlspecialchars($s['university_id'] ?? '') ?>)</option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:.75rem">
                <div class="form-group" style="margin:0">
                    <label>Section (optional)</label>
                    <input type="text" name="section" placeholder="A, B, C...">
                </div>
                <div class="form-group" style="margin:0">
                    <label>Academic Year</label>
                    <input type="text" name="academic_year" placeholder="2024/25">
                </div>
            </div>
            <button type="submit" class="btn btn-primary btn-full" style="margin-top:.75rem">Enroll Student</button>
        </form>
    </div>

    <!-- Bulk Enroll -->
    <div class="card">
        <h3>⚡ Bulk Enroll</h3>
        <p class="text-muted" style="font-size:.88rem">Enroll all students matching the selected filters into a course at once.</p>
        <form method="POST" onsubmit="return confirm('Bulk enroll all matching students?')">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="bulk_enroll">
            <div class="form-group">
                <label>Course</label>
                <select name="class_id" required>
                    <option value="">Select course...</option>
                    <?php foreach ($classes as $c): ?>
                    <option value="<?= $c['id'] ?>"><?= htmlspecialchars(($c['course_code']?'['.$c['course_code'].'] ':'').$c['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:.75rem">
                <div class="form-group" style="margin:0">
                    <label>Department</label>
                    <select name="bulk_dept">
                        <option value="">Any</option>
                        <?php foreach ($depts as $d): ?>
                        <option value="<?= htmlspecialchars($d['department']) ?>"><?= htmlspecialchars($d['department']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group" style="margin:0">
                    <label>Semester</label>
                    <select name="bulk_sem">
                        <option value="">Any</option>
                        <?php foreach (range(1,8) as $n): ?>
                        <option value="Semester <?= $n ?>">Semester <?= $n ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:.75rem;margin-top:.75rem">
                <div class="form-group" style="margin:0">
                    <label>Section</label>
                    <input type="text" name="bulk_sec" placeholder="A (leave blank = all)">
                </div>
                <div class="form-group" style="margin:0">
                    <label>Academic Year</label>
                    <input type="text" name="academic_year" placeholder="2024/25">
                </div>
            </div>
            <button type="submit" class="btn btn-secondary btn-full" style="margin-top:.75rem">Bulk Enroll</button>
        </form>
    </div>
</div>

<!-- Right column: roster and history -->
<div>
    <!-- Class selector -->
    <div class="card">
        <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:.75rem">
            <h3 style="margin:0">Class Roster</h3>
            <form method="GET" style="display:flex;gap:.5rem;flex-wrap:wrap;align-items:center">
                <select name="class_id" onchange="this.form.submit()" style="min-width:240px">
                    <option value="">Select a class to view roster...</option>
                    <?php foreach ($classes as $c): ?>
                    <option value="<?= $c['id'] ?>" <?= $selectedClassId==$c['id']?'selected':'' ?>>
                        <?= htmlspecialchars(($c['course_code']?'['.$c['course_code'].'] ':'').$c['name']) ?>
                        (<?= $c['enrolled_count'] ?>/<?= $c['max_students'] ?>)
                    </option>
                    <?php endforeach; ?>
                </select>
            </form>
        </div>
    </div>

    <?php if ($selectedClass && !empty($roster)): ?>
    <!-- Roster Stats -->
    <div style="display:grid;grid-template-columns:repeat(4,1fr);gap:.75rem;margin-bottom:1rem">
        <?php
        $active   = count(array_filter($roster, fn($r) => $r['status']==='active'));
        $dropped  = count(array_filter($roster, fn($r) => $r['status']==='dropped'));
        $avgPct   = $active > 0 ? round(array_sum(array_column(array_filter($roster, fn($r)=>$r['status']==='active'),'attendance_pct')) / $active) : 0;
        $atRisk   = count(array_filter($roster, fn($r) => $r['status']==='active' && $r['attendance_pct'] !== null && $r['attendance_pct'] < 75));
        ?>
        <div class="stat-card"><div class="stat-number"><?= $active ?></div><div class="stat-label">Enrolled</div></div>
        <div class="stat-card" style="border-color:<?= $dropped>0?'#f59e0b':'#16a34a' ?>"><div class="stat-number"><?= $dropped ?></div><div class="stat-label">Dropped</div></div>
        <div class="stat-card"><div class="stat-number"><?= $avgPct ?>%</div><div class="stat-label">Avg Attendance</div></div>
        <div class="stat-card" style="border-color:<?= $atRisk>0?'#dc2626':'#16a34a' ?>"><div class="stat-number" style="color:<?= $atRisk>0?'#dc2626':'#16a34a' ?>"><?= $atRisk ?></div><div class="stat-label">At Risk (<75%)</div></div>
    </div>

    <div class="card">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:.75rem;flex-wrap:wrap;gap:.5rem">
            <h3 style="margin:0">
                <?= htmlspecialchars($selectedClass['name']) ?>
                <small class="text-muted">— <?= htmlspecialchars($selectedClass['subject']) ?></small>
            </h3>
            <div style="display:flex;gap:.5rem;flex-wrap:wrap">
                <a href="?class_id=<?= $selectedClassId ?>&export_roster=<?= $selectedClassId ?>" class="btn btn-secondary btn-sm">Export CSV</a>
                <a href="?class_id=<?= $selectedClassId ?>&history_class=<?= $selectedClassId ?>" class="btn btn-secondary btn-sm">View History</a>
            </div>
        </div>
        <div style="overflow-x:auto">
        <table class="table" id="rosterTable">
            <thead>
                <tr>
                    <th>Student</th><th>University ID</th><th>Section</th>
                    <th>Attendance</th><th>Status</th><th>Actions</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($roster as $r):
                $pct = $r['attendance_pct'] ?? null;
                $pctColor = ($pct === null) ? '#94a3b8' : ($pct >= 75 ? '#16a34a' : ($pct >= 60 ? '#d97706' : '#dc2626'));
                $statusColors = ['active'=>['#dcfce7','#15803d'],'dropped'=>['#fee2e2','#b91c1c'],'transferred'=>['#fef3c7','#b45309'],'completed'=>['#dbeafe','#1e40af']];
                [$sbg,$sfg] = $statusColors[$r['status']] ?? ['#f1f5f9','#475569'];
            ?>
            <tr id="row-<?= $r['student_id'] ?>">
                <td>
                    <strong><?= htmlspecialchars($r['name']) ?></strong>
                    <?php if ($r['email']): ?><br><small class="text-muted"><?= htmlspecialchars($r['email']) ?></small><?php endif; ?>
                </td>
                <td><code><?= htmlspecialchars($r['university_id'] ?? '') ?></code></td>
                <td>
                    <span id="sec-<?= $r['student_id'] ?>"><?= htmlspecialchars($r['section'] ?: '–') ?></span>
                    <button class="btn btn-sm" style="padding:.1rem .4rem;font-size:.75rem;margin-left:.3rem"
                            onclick="editSection(<?= $r['student_id'] ?>, '<?= htmlspecialchars($r['section'],ENT_QUOTES) ?>')">✏️</button>
                </td>
                <td>
                    <?php if ($pct !== null): ?>
                    <div style="display:flex;align-items:center;gap:.5rem">
                        <div style="flex:1;height:6px;background:#e2e8f0;border-radius:3px;min-width:60px">
                            <div style="height:100%;width:<?= $pct ?>%;background:<?= $pctColor ?>;border-radius:3px"></div>
                        </div>
                        <span style="color:<?= $pctColor ?>;font-weight:600;font-size:.88rem"><?= $pct ?>%</span>
                    </div>
                    <small class="text-muted"><?= $r['attended'] ?>/<?= $r['total_sessions'] ?> sessions</small>
                    <?php else: ?>
                    <span class="text-muted">No sessions yet</span>
                    <?php endif; ?>
                </td>
                <td><span class="badge" style="background:<?= $sbg ?>;color:<?= $sfg ?>"><?= ucfirst($r['status']) ?></span></td>
                <td>
                    <?php if ($r['status'] === 'active'): ?>
                    <div style="display:flex;gap:.3rem;flex-wrap:wrap">
                        <!-- Transfer -->
                        <button class="btn btn-sm" style="background:#6366f1;color:#fff"
                                onclick="showTransfer(<?= $r['student_id'] ?>, '<?= htmlspecialchars($r['name'],ENT_QUOTES) ?>', <?= $selectedClassId ?>)">
                            Transfer
                        </button>
                        <!-- Remove -->
                        <form method="POST" style="display:inline" onsubmit="return confirm('Drop <?= htmlspecialchars($r['name'],ENT_QUOTES) ?> from this class?')">
                            <?= csrfField() ?>
                            <input type="hidden" name="action" value="remove">
                            <input type="hidden" name="class_id" value="<?= $selectedClassId ?>">
                            <input type="hidden" name="student_id" value="<?= $r['student_id'] ?>">
                            <button class="btn btn-danger btn-sm">Drop</button>
                        </form>
                    </div>
                    <?php else: ?>
                    <span class="text-muted" style="font-size:.82rem">–</span>
                    <?php endif; ?>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
    </div>

    <?php elseif ($selectedClassId): ?>
    <div class="card" style="text-align:center">
        <div style="font-size:2.5rem;margin-bottom:.75rem">📭</div>
        <h3>No Students Enrolled</h3>
        <p class="text-muted">Use the enrollment tools on the left to add students.</p>
    </div>
    <?php endif; ?>

    <!-- Enrollment History -->
    <?php if (!empty($history)): ?>
    <div class="card">
        <h3>Enrollment History</h3>
        <div style="overflow-x:auto;max-height:400px;overflow-y:auto">
        <table class="table">
            <thead><tr><th>Student</th><th>Action</th><th>Details</th><th>By</th><th>When</th></tr></thead>
            <tbody>
            <?php
            $actionColors = [
                'enrolled'         => ['#dcfce7','#15803d'],
                'dropped'          => ['#fee2e2','#b91c1c'],
                'transferred_in'   => ['#dbeafe','#1e40af'],
                'transferred_out'  => ['#fef3c7','#b45309'],
                'reactivated'      => ['#dcfce7','#15803d'],
                'completed'        => ['#f0fdf4','#166534'],
            ];
            foreach ($history as $h):
                [$hbg,$hfg] = $actionColors[$h['action']] ?? ['#f1f5f9','#475569'];
            ?>
            <tr>
                <td>
                    <strong><?= htmlspecialchars($h['student_name']) ?></strong>
                    <br><code style="font-size:.78rem"><?= htmlspecialchars($h['university_id']) ?></code>
                </td>
                <td><span class="badge" style="background:<?= $hbg ?>;color:<?= $hfg ?>"><?= ucfirst(str_replace('_',' ',$h['action'])) ?></span></td>
                <td>
                    <?php if ($h['from_class_name']): ?><small>From: <?= htmlspecialchars($h['from_class_name']) ?></small><?php endif; ?>
                    <?php if ($h['to_class_name']):   ?><small> → <?= htmlspecialchars($h['to_class_name']) ?></small><?php endif; ?>
                    <?php if ($h['notes']): ?><br><small class="text-muted"><?= htmlspecialchars($h['notes']) ?></small><?php endif; ?>
                </td>
                <td><small><?= htmlspecialchars($h['performed_by_name'] ?? 'System') ?></small></td>
                <td><small class="text-muted"><?= date('d M Y H:i', strtotime($h['created_at'])) ?></small></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
    </div>
    <?php endif; ?>

    <!-- Import Batch Log -->
    <?php if (!empty($importLog)): ?>
    <div class="card">
        <h3>Recent Import Log</h3>
        <div style="overflow-x:auto">
        <table class="table">
            <thead><tr><th>Type</th><th>File</th><th>Added</th><th>Skipped</th><th>Errors</th><th>By</th><th>When</th></tr></thead>
            <tbody>
            <?php foreach ($importLog as $b): ?>
            <tr>
                <td><span class="badge" style="background:<?= $b['type']==='students'?'#dbeafe':'#ede9fe' ?>;color:<?= $b['type']==='students'?'#1e40af':'#6d28d9' ?>"><?= $b['type'] ?></span></td>
                <td><small><?= htmlspecialchars($b['filename']) ?></small></td>
                <td style="color:#15803d;font-weight:600"><?= $b['added'] ?></td>
                <td style="color:#d97706"><?= $b['skipped'] ?></td>
                <td style="color:<?= $b['errors']>0?'#dc2626':'#94a3b8' ?>"><?= $b['errors'] ?></td>
                <td><small><?= htmlspecialchars($b['performed_by_name'] ?? '–') ?></small></td>
                <td><small class="text-muted"><?= date('d M Y H:i', strtotime($b['created_at'])) ?></small></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
    </div>
    <?php endif; ?>
</div><!-- end right column -->
</div><!-- end grid -->

<!-- Section edit inline form (hidden) -->
<form method="POST" id="sectionForm" style="display:none">
    <?= csrfField() ?>
    <input type="hidden" name="action" value="change_section">
    <input type="hidden" name="class_id" value="<?= $selectedClassId ?>">
    <input type="hidden" name="student_id" id="sectionStudentId">
    <input type="text"   name="new_section" id="sectionInput" placeholder="New section">
    <button type="submit" class="btn btn-primary btn-sm">Save</button>
    <button type="button" class="btn btn-secondary btn-sm" onclick="cancelSection()">Cancel</button>
</form>

<!-- Transfer Modal -->
<div id="transferModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:999;align-items:center;justify-content:center">
    <div class="card" style="width:420px;max-width:95vw;margin:0">
        <h3>Transfer Student</h3>
        <p id="transferStudentName" class="text-muted"></p>
        <form method="POST">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="transfer">
            <input type="hidden" name="student_id" id="transferStudentId">
            <input type="hidden" name="from_class_id" id="transferFromClass">
            <div class="form-group">
                <label>Transfer To Class</label>
                <select name="to_class_id" required id="transferToClass">
                    <option value="">Select destination class...</option>
                    <?php foreach ($classes as $c): ?>
                    <?php if ($c['id'] != $selectedClassId): ?>
                    <option value="<?= $c['id'] ?>"><?= htmlspecialchars(($c['course_code']?'['.$c['course_code'].'] ':'').$c['name']) ?></option>
                    <?php endif; ?>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label>New Section (optional)</label>
                <input type="text" name="section" placeholder="A, B, C...">
            </div>
            <div class="form-group">
                <label>Notes (optional)</label>
                <input type="text" name="notes" placeholder="Reason for transfer...">
            </div>
            <div style="display:flex;gap:.75rem">
                <button type="submit" class="btn btn-primary">Transfer</button>
                <button type="button" class="btn btn-secondary" onclick="closeTransfer()">Cancel</button>
            </div>
        </form>
    </div>
</div>

<script>
// ── Section editing ───────────────────────────────────────────────────────────
function editSection(studentId, current) {
    const form = document.getElementById('sectionForm');
    const row  = document.getElementById('row-' + studentId);
    document.getElementById('sectionStudentId').value = studentId;
    document.getElementById('sectionInput').value     = current;
    row.querySelector('td:nth-child(3)').appendChild(form);
    form.style.display = 'inline-flex';
    form.style.gap     = '.4rem';
    form.style.alignItems = 'center';
    document.getElementById('sectionInput').focus();
}
function cancelSection() {
    document.getElementById('sectionForm').style.display = 'none';
}

// ── Transfer modal ────────────────────────────────────────────────────────────
function showTransfer(studentId, name, fromClassId) {
    document.getElementById('transferStudentId').value   = studentId;
    document.getElementById('transferFromClass').value   = fromClassId;
    document.getElementById('transferStudentName').textContent = 'Student: ' + name;
    document.getElementById('transferModal').style.display = 'flex';
}
function closeTransfer() {
    document.getElementById('transferModal').style.display = 'none';
}

// ── Table search ──────────────────────────────────────────────────────────────
<?php if (!empty($roster)): ?>
const searchInput = document.createElement('input');
searchInput.type = 'text';
searchInput.placeholder = 'Search roster...';
searchInput.style = 'padding:.4rem .7rem;border:1px solid var(--border);border-radius:7px;width:200px';
searchInput.oninput = function() {
    const q = this.value.toLowerCase();
    document.querySelectorAll('#rosterTable tbody tr').forEach(r => {
        r.style.display = r.textContent.toLowerCase().includes(q) ? '' : 'none';
    });
};
document.querySelector('#rosterTable').closest('.card')
    .querySelector('div').appendChild(searchInput);
<?php endif; ?>
</script>

<?php include '../includes/footer.php'; ?>
