<?php
require_once '../includes/auth_check.php';
requireRole('admin');
require_once '../config/db.php';

$msg = ''; $msgType = 'success';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($_POST['action'] === 'add') {
        $class_id = (int)$_POST['class_id'];
        $day      = $_POST['day_of_week'];
        $start    = $_POST['start_time'];
        $end      = $_POST['end_time'];
        $room     = trim($_POST['room'] ?? '');

        // Conflict check: same room, same day, overlapping time
        $conflict = $conn->prepare("
            SELECT s.id, c.name FROM schedules s
            JOIN classes c ON s.class_id=c.id
            WHERE s.room=? AND s.day_of_week=?
            AND s.start_time < ? AND s.end_time > ?
        ");
        $conflict->bind_param('ssss', $room, $day, $end, $start);
        $conflict->execute();
        $clash = $conflict->get_result()->fetch_assoc();

        if ($clash && $room) {
            $msg = "Schedule conflict: Room '$room' is already booked for '{$clash['name']}' at that time.";
            $msgType = 'error';
        } else {
            $stmt = $conn->prepare("INSERT INTO schedules (class_id,day_of_week,start_time,end_time,room) VALUES (?,?,?,?,?)");
            $stmt->bind_param('issss', $class_id, $day, $start, $end, $room);
            $msg = $stmt->execute() ? 'Schedule added.' : 'Error: '.$conn->error;
        }
    } elseif ($_POST['action'] === 'delete') {
        $id = (int)$_POST['id'];
        $conn->query("DELETE FROM schedules WHERE id=$id");
        $msg = 'Schedule deleted.';
    }
}

$schedules = $conn->query("
    SELECT s.*, c.name AS class_name, c.subject, c.course_code, u.name AS teacher
    FROM schedules s
    JOIN classes c ON s.class_id=c.id
    JOIN users u ON c.teacher_id=u.id
    ORDER BY FIELD(s.day_of_week,'Monday','Tuesday','Wednesday','Thursday','Friday','Saturday','Sunday'), s.start_time
")->fetch_all(MYSQLI_ASSOC);

$classes = $conn->query("SELECT c.id, c.name, c.subject, c.course_code, u.name AS teacher FROM classes c JOIN users u ON c.teacher_id=u.id ORDER BY c.name")->fetch_all(MYSQLI_ASSOC);

include '../includes/header.php';
?>
<h1>Schedule & Timetable</h1>
<?php if ($msg): ?><div class="alert alert-<?= $msgType ?>"><?= htmlspecialchars($msg) ?></div><?php endif; ?>

<div class="card">
    <h3>Add Class Schedule</h3>
    <form method="POST">
        <input type="hidden" name="action" value="add">
        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(170px,1fr));gap:.75rem">
            <div class="form-group" style="margin:0"><label>Course</label>
                <select name="class_id" required>
                    <option value="">Select Course</option>
                    <?php foreach ($classes as $c): ?>
                    <option value="<?= $c['id'] ?>"><?= htmlspecialchars(($c['course_code']?'['.$c['course_code'].'] ':'').$c['name']) ?></option>
                    <?php endforeach; ?>
                </select></div>
            <div class="form-group" style="margin:0"><label>Day</label>
                <select name="day_of_week" required>
                    <?php foreach (['Monday','Tuesday','Wednesday','Thursday','Friday','Saturday','Sunday'] as $d): ?>
                    <option><?= $d ?></option>
                    <?php endforeach; ?>
                </select></div>
            <div class="form-group" style="margin:0"><label>Start Time</label><input type="time" name="start_time" required></div>
            <div class="form-group" style="margin:0"><label>End Time</label><input type="time" name="end_time" required></div>
            <div class="form-group" style="margin:0"><label>Room</label><input type="text" name="room" placeholder="e.g. Lab 1, Room 201"></div>
        </div>
        <button type="submit" class="btn btn-primary" style="margin-top:1rem">Add Schedule</button>
    </form>
</div>

<!-- Timetable Grid -->
<div class="card">
    <h3>Weekly Timetable</h3>
    <?php
    $days = ['Monday','Tuesday','Wednesday','Thursday','Friday','Saturday','Sunday'];
    $byDay = [];
    foreach ($schedules as $s) $byDay[$s['day_of_week']][] = $s;
    ?>
    <div style="overflow-x:auto">
    <table class="table">
        <thead><tr><th>Day</th><th>Time</th><th>Course</th><th>Teacher</th><th>Room</th><th>Action</th></tr></thead>
        <tbody>
        <?php foreach ($days as $day): ?>
            <?php if (!empty($byDay[$day])): ?>
                <?php foreach ($byDay[$day] as $i => $s): ?>
                <tr>
                    <?php if ($i === 0): ?><td rowspan="<?= count($byDay[$day]) ?>" style="font-weight:600;background:#f8fafc"><?= $day ?></td><?php endif; ?>
                    <td><?= substr($s['start_time'],0,5) ?> - <?= substr($s['end_time'],0,5) ?></td>
                    <td><?= htmlspecialchars(($s['course_code']?'['.$s['course_code'].'] ':'').$s['class_name']) ?><br><small class="text-muted"><?= htmlspecialchars($s['subject']) ?></small></td>
                    <td><?= htmlspecialchars($s['teacher']) ?></td>
                    <td><?= htmlspecialchars($s['room'] ?? '-') ?></td>
                    <td>
                        <form method="POST" style="display:inline" onsubmit="return confirm('Delete?')">
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="id" value="<?= $s['id'] ?>">
                            <button class="btn btn-danger btn-sm">Delete</button>
                        </form>
                    </td>
                </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        <?php endforeach; ?>
        <?php if (empty($schedules)): ?>
        <tr><td colspan="6" class="text-muted" style="text-align:center;padding:2rem">No schedules yet.</td></tr>
        <?php endif; ?>
        </tbody>
    </table>
    </div>
</div>
<?php include '../includes/footer.php'; ?>
