<?php
require_once '../includes/auth_check.php';
requireRole('admin');
require_once '../config/db.php';

$q       = trim($_GET['q'] ?? '');
$type    = $_GET['type'] ?? 'all';
$results = [];

if (strlen($q) >= 2) {
    $esc = $conn->real_escape_string($q);

    if ($type === 'all' || $type === 'students') {
        $results['students'] = $conn->query("
            SELECT id, name, university_id, email, department, semester, section, is_active
            FROM users WHERE role='student'
              AND (name LIKE '%$esc%' OR university_id LIKE '%$esc%' OR email LIKE '%$esc%' OR department LIKE '%$esc%')
            LIMIT 20")->fetch_all(MYSQLI_ASSOC);
    }
    if ($type === 'all' || $type === 'teachers') {
        $results['teachers'] = $conn->query("
            SELECT id, name, university_id, email, department, is_active
            FROM users WHERE role='teacher'
              AND (name LIKE '%$esc%' OR university_id LIKE '%$esc%' OR email LIKE '%$esc%')
            LIMIT 20")->fetch_all(MYSQLI_ASSOC);
    }
    if ($type === 'all' || $type === 'courses') {
        $results['courses'] = $conn->query("
            SELECT c.id, c.name, c.subject, c.course_code, c.section, c.semester,
                   u.name AS teacher_name, c.is_active
            FROM classes c JOIN users u ON c.teacher_id=u.id
            WHERE c.name LIKE '%$esc%' OR c.subject LIKE '%$esc%' OR c.course_code LIKE '%$esc%'
            LIMIT 20")->fetch_all(MYSQLI_ASSOC);
    }
    if ($type === 'all' || $type === 'attendance') {
        $results['attendance'] = $conn->query("
            SELECT a.id, a.status, a.method, a.marked_at,
                   u.name AS student_name, u.university_id,
                   c.name AS class_name, c.subject
            FROM attendance a
            JOIN users u ON a.student_id=u.id
            JOIN attendance_sessions s ON a.session_id=s.id
            JOIN classes c ON s.class_id=c.id
            WHERE u.name LIKE '%$esc%' OR u.university_id LIKE '%$esc%' OR c.name LIKE '%$esc%'
            ORDER BY a.marked_at DESC LIMIT 20")->fetch_all(MYSQLI_ASSOC);
    }
    if ($type === 'all' || $type === 'departments') {
        $results['departments'] = $conn->query("
            SELECT d.id, d.name, d.short_name, col.name AS college_name
            FROM departments d LEFT JOIN colleges col ON d.college_id=col.id
            WHERE d.name LIKE '%$esc%' OR d.short_name LIKE '%$esc%'
            LIMIT 10")->fetch_all(MYSQLI_ASSOC);
    }
}

$totalResults = array_sum(array_map('count', $results));
include '../includes/header.php';
?>

<h1>🔍 Global Search</h1>

<!-- Search form -->
<div class="card" style="margin-bottom:1.25rem">
    <form method="GET" style="display:flex;gap:.75rem;flex-wrap:wrap;align-items:flex-end">
        <div class="form-group" style="margin:0;flex:1;min-width:220px">
            <label>Search</label>
            <input type="text" name="q" value="<?= htmlspecialchars($q) ?>"
                   placeholder="Name, ID, course, department..."
                   autofocus style="width:100%">
        </div>
        <div class="form-group" style="margin:0">
            <label>Category</label>
            <select name="type">
                <?php foreach (['all'=>'All Categories','students'=>'Students','teachers'=>'Teachers','courses'=>'Courses','attendance'=>'Attendance','departments'=>'Departments'] as $v=>$l): ?>
                <option value="<?= $v ?>" <?= $type===$v?'selected':'' ?>><?= $l ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <button type="submit" class="btn btn-primary">Search</button>
        <?php if ($q): ?>
        <a href="search.php" class="btn btn-secondary">Clear</a>
        <?php endif; ?>
    </form>
</div>

<?php if ($q && strlen($q) < 2): ?>
<div class="alert alert-warning">Please enter at least 2 characters.</div>
<?php elseif ($q): ?>

<p class="text-muted" style="margin-bottom:1rem">
    Found <strong><?= $totalResults ?></strong> result(s) for "<strong><?= htmlspecialchars($q) ?></strong>"
</p>

<?php if (!empty($results['students'])): ?>
<div class="card">
    <h3>👥 Students (<?= count($results['students']) ?>)</h3>
    <div style="overflow-x:auto"><table class="table">
        <thead><tr><th>Name</th><th>University ID</th><th>Email</th><th>Dept</th><th>Sem/Sec</th><th>Status</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($results['students'] as $s): ?>
        <tr>
            <td><strong><?= htmlspecialchars($s['name']) ?></strong></td>
            <td><code><?= htmlspecialchars($s['university_id']??'') ?></code></td>
            <td><?= htmlspecialchars($s['email']??'') ?></td>
            <td><?= htmlspecialchars($s['department']??'–') ?></td>
            <td><?= htmlspecialchars($s['semester']??'') ?><?= $s['section']?' / '.$s['section']:'' ?></td>
            <td><span class="badge" style="background:<?= $s['is_active']?'#dcfce7':'#fee2e2' ?>;color:<?= $s['is_active']?'#15803d':'#b91c1c' ?>"><?= $s['is_active']?'Active':'Inactive' ?></span></td>
            <td><a href="users.php?edit=<?= $s['id'] ?>" class="btn btn-secondary btn-sm">Edit</a></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table></div>
</div>
<?php endif; ?>

<?php if (!empty($results['teachers'])): ?>
<div class="card">
    <h3>👨‍🏫 Teachers (<?= count($results['teachers']) ?>)</h3>
    <div style="overflow-x:auto"><table class="table">
        <thead><tr><th>Name</th><th>University ID</th><th>Email</th><th>Dept</th><th>Status</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($results['teachers'] as $t): ?>
        <tr>
            <td><strong><?= htmlspecialchars($t['name']) ?></strong></td>
            <td><code><?= htmlspecialchars($t['university_id']??'') ?></code></td>
            <td><?= htmlspecialchars($t['email']??'') ?></td>
            <td><?= htmlspecialchars($t['department']??'–') ?></td>
            <td><span class="badge" style="background:<?= $t['is_active']?'#dcfce7':'#fee2e2' ?>;color:<?= $t['is_active']?'#15803d':'#b91c1c' ?>"><?= $t['is_active']?'Active':'Inactive' ?></span></td>
            <td><a href="users.php?edit=<?= $t['id'] ?>" class="btn btn-secondary btn-sm">Edit</a></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table></div>
</div>
<?php endif; ?>

<?php if (!empty($results['courses'])): ?>
<div class="card">
    <h3>📚 Courses (<?= count($results['courses']) ?>)</h3>
    <div style="overflow-x:auto"><table class="table">
        <thead><tr><th>Code</th><th>Course</th><th>Subject</th><th>Teacher</th><th>Sem/Sec</th><th>Status</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($results['courses'] as $c): ?>
        <tr>
            <td><code><?= htmlspecialchars($c['course_code']??'–') ?></code></td>
            <td><strong><?= htmlspecialchars($c['name']) ?></strong></td>
            <td><?= htmlspecialchars($c['subject']) ?></td>
            <td><?= htmlspecialchars($c['teacher_name']) ?></td>
            <td><?= htmlspecialchars($c['semester']??'') ?><?= $c['section']?' / '.$c['section']:'' ?></td>
            <td><span class="badge" style="background:<?= $c['is_active']?'#dcfce7':'#fee2e2' ?>;color:<?= $c['is_active']?'#15803d':'#b91c1c' ?>"><?= $c['is_active']?'Active':'Inactive' ?></span></td>
            <td><a href="classes.php?edit=<?= $c['id'] ?>" class="btn btn-secondary btn-sm">Edit</a></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table></div>
</div>
<?php endif; ?>

<?php if (!empty($results['attendance'])): ?>
<div class="card">
    <h3>📋 Attendance Records (<?= count($results['attendance']) ?>)</h3>
    <div style="overflow-x:auto"><table class="table">
        <thead><tr><th>Student</th><th>Course</th><th>Status</th><th>Method</th><th>Date</th></tr></thead>
        <tbody>
        <?php foreach ($results['attendance'] as $a): ?>
        <tr>
            <td><strong><?= htmlspecialchars($a['student_name']) ?></strong><br><code style="font-size:.75rem"><?= htmlspecialchars($a['university_id']) ?></code></td>
            <td><?= htmlspecialchars($a['class_name']) ?><br><small class="text-muted"><?= htmlspecialchars($a['subject']) ?></small></td>
            <td><span class="badge badge-<?= $a['status'] ?>"><?= $a['status'] ?></span></td>
            <td><?= htmlspecialchars($a['method']) ?></td>
            <td><?= date('d M Y H:i', strtotime($a['marked_at'])) ?></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table></div>
</div>
<?php endif; ?>

<?php if (!empty($results['departments'])): ?>
<div class="card">
    <h3>🏫 Departments (<?= count($results['departments']) ?>)</h3>
    <div style="display:flex;flex-wrap:wrap;gap:.5rem">
    <?php foreach ($results['departments'] as $d): ?>
    <div style="padding:.5rem 1rem;background:var(--hover-bg);border-radius:8px;border:1px solid var(--border)">
        <strong><?= htmlspecialchars($d['name']) ?></strong>
        <?php if ($d['short_name']): ?><code style="font-size:.75rem;margin-left:.3rem"><?= htmlspecialchars($d['short_name']) ?></code><?php endif; ?>
        <?php if ($d['college_name']): ?><br><small class="text-muted"><?= htmlspecialchars($d['college_name']) ?></small><?php endif; ?>
    </div>
    <?php endforeach; ?>
    </div>
</div>
<?php endif; ?>

<?php if ($totalResults === 0): ?>
<div class="card" style="text-align:center;padding:3rem">
    <div style="font-size:3rem;margin-bottom:.75rem">🔍</div>
    <h3>No results found</h3>
    <p class="text-muted">No matches for "<strong><?= htmlspecialchars($q) ?></strong>" in <?= $type === 'all' ? 'any category' : $type ?>.</p>
</div>
<?php endif; ?>

<?php else: ?>
<div class="card" style="text-align:center;padding:3rem">
    <div style="font-size:3rem;margin-bottom:.75rem">🔍</div>
    <h3>Search the System</h3>
    <p class="text-muted">Search across students, teachers, courses, attendance records, and departments.</p>
    <div style="display:flex;gap:.75rem;justify-content:center;flex-wrap:wrap;margin-top:1rem">
        <?php foreach (['students','teachers','courses','attendance','departments'] as $cat): ?>
        <a href="?q=&type=<?= $cat ?>" class="btn btn-secondary btn-sm"><?= ucfirst($cat) ?></a>
        <?php endforeach; ?>
    </div>
</div>
<?php endif; ?>

<?php include '../includes/footer.php'; ?>
