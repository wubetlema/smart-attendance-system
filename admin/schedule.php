<?php
require_once '../includes/auth_check.php';
requireRole('admin');
require_once '../config/db.php';

$msg = ''; $msgType = 'success';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    if ($_POST['action'] === 'add' || $_POST['action'] === 'edit') {
        $isEdit   = $_POST['action'] === 'edit';
        $id       = $isEdit ? (int)$_POST['id'] : null;
        $class_id = (int)$_POST['class_id'];
        $day      = $_POST['day_of_week'];
        $start    = $_POST['start_time'];
        $end      = $_POST['end_time'];
        $room     = trim($_POST['room'] ?? '');
        $room_id  = !empty($_POST['room_id']) ? (int)$_POST['room_id'] : null;

        if ($room_id && !$room) {
            $rn = $conn->query("SELECT name FROM rooms WHERE id=$room_id")->fetch_assoc();
            if ($rn) $room = $rn['name'];
        }

        if ($start >= $end) {
            $msg = 'End time must be after start time.'; $msgType = 'error';
        } else {
            $selfExclude = $isEdit ? "AND s.id != $id" : '';

            // Teacher conflict
            $teacherConflict = $conn->query("
                SELECT c.name FROM schedules s
                JOIN classes c ON s.class_id=c.id
                WHERE c.teacher_id=(SELECT teacher_id FROM classes WHERE id=$class_id)
                AND s.day_of_week='$day'
                AND s.start_time < '$end' AND s.end_time > '$start'
                AND s.class_id != $class_id $selfExclude
                LIMIT 1
            ")->fetch_assoc();

            // Room conflict
            $roomConflict = null;
            if ($room) {
                $esc = $conn->real_escape_string($room);
                $roomConflict = $conn->query("
                    SELECT c.name FROM schedules s
                    JOIN classes c ON s.class_id=c.id
                    WHERE s.room='$esc' AND s.day_of_week='$day'
                    AND s.start_time < '$end' AND s.end_time > '$start'
                    $selfExclude LIMIT 1
                ")->fetch_assoc();
            }

            if ($teacherConflict) {
                $msg = "Teacher conflict: Teacher already scheduled for '{$teacherConflict['name']}' at that time.";
                $msgType = 'error';
            } elseif ($roomConflict) {
                $msg = "Room conflict: '$room' already booked for '{$roomConflict['name']}' at that time.";
                $msgType = 'error';
            } else {
                if ($isEdit) {
                    $stmt = $conn->prepare("UPDATE schedules SET day_of_week=?,start_time=?,end_time=?,room=?,room_id=? WHERE id=?");
                    $stmt->bind_param('ssssii', $day,$start,$end,$room,$room_id,$id);
                } else {
                    $stmt = $conn->prepare("INSERT INTO schedules (class_id,day_of_week,start_time,end_time,room,room_id) VALUES (?,?,?,?,?,?)");
                    $stmt->bind_param('issssi', $class_id,$day,$start,$end,$room,$room_id);
                }
                $msg = $stmt->execute() ? ($isEdit ? 'Schedule updated.' : 'Schedule added.') : 'Error: '.$conn->error;
                if (!$stmt->execute()) $msgType = 'error';
            }
        }

    } elseif ($_POST['action'] === 'delete') {
        $id = (int)$_POST['id'];
        $conn->query("DELETE FROM schedules WHERE id=$id");
        $msg = 'Schedule deleted.';
    }
}

// Detect conflicts for row highlighting
$conflictIds = [];
$tcRows = $conn->query("
    SELECT s1.id id1, s2.id id2 FROM schedules s1
    JOIN schedules s2 ON s1.id < s2.id AND s1.day_of_week=s2.day_of_week
        AND s1.start_time < s2.end_time AND s1.end_time > s2.start_time
    JOIN classes c1 ON s1.class_id=c1.id JOIN classes c2 ON s2.class_id=c2.id
    WHERE c1.teacher_id=c2.teacher_id
")->fetch_all(MYSQLI_ASSOC);
foreach ($tcRows as $r) { $conflictIds[$r['id1']] = 'teacher'; $conflictIds[$r['id2']] = 'teacher'; }

$rcRows = $conn->query("
    SELECT s1.id id1, s2.id id2 FROM schedules s1
    JOIN schedules s2 ON s1.id < s2.id AND s1.day_of_week=s2.day_of_week
        AND s1.start_time < s2.end_time AND s1.end_time > s2.start_time
        AND s1.room=s2.room
    WHERE s1.room IS NOT NULL AND s1.room != ''
")->fetch_all(MYSQLI_ASSOC);
foreach ($rcRows as $r) { $conflictIds[$r['id1']] = 'room'; $conflictIds[$r['id2']] = 'room'; }

$schedules = $conn->query("
    SELECT s.*, c.name AS class_name, c.subject, c.course_code, c.id AS class_id,
           u.name AS teacher, r.capacity AS room_capacity
    FROM schedules s JOIN classes c ON s.class_id=c.id JOIN users u ON c.teacher_id=u.id
    LEFT JOIN rooms r ON s.room_id=r.id
    ORDER BY FIELD(s.day_of_week,'Monday','Tuesday','Wednesday','Thursday','Friday','Saturday','Sunday'), s.start_time
")->fetch_all(MYSQLI_ASSOC);

$classes = $conn->query("SELECT c.id, c.name, c.subject, c.course_code, u.name AS teacher FROM classes c JOIN users u ON c.teacher_id=u.id WHERE c.is_active=1 ORDER BY c.name")->fetch_all(MYSQLI_ASSOC);
$rooms   = $conn->query("SELECT id, name, building, capacity FROM rooms WHERE is_active=1 ORDER BY name")->fetch_all(MYSQLI_ASSOC);

$editSchedule = null;
if (isset($_GET['edit'])) {
    $eid = (int)$_GET['edit'];
    $stmt = $conn->prepare("SELECT s.*, c.id AS class_id FROM schedules s JOIN classes c ON s.class_id=c.id WHERE s.id=?");
    $stmt->bind_param('i', $eid); $stmt->execute();
    $editSchedule = $stmt->get_result()->fetch_assoc();
}

include '../includes/header.php';
?>
<h1>Schedule & Timetable</h1>
<div style="display:flex;gap:.75rem;margin-bottom:1rem;flex-wrap:wrap;align-items:center">
    <a href="timetable_engine.php" class="btn btn-primary">🤖 Smart Timetable Engine</a>
    <?php if (!empty($conflictIds)): ?>
    <span style="background:#fee2e2;color:#b91c1c;padding:.4rem .8rem;border-radius:20px;font-size:.85rem;font-weight:600">
        ⚠️ <?= count($conflictIds) ?> conflict(s) detected
    </span>
    <?php else: ?>
    <span style="background:#dcfce7;color:#15803d;padding:.4rem .8rem;border-radius:20px;font-size:.85rem;font-weight:600">✓ No conflicts</span>
    <?php endif; ?>
</div>
<?php if ($msg): ?><div class="alert alert-<?= $msgType ?>"><?= htmlspecialchars($msg) ?></div><?php endif; ?>

<!-- Add/Edit Form -->
<div class="card">
    <h3><?= $editSchedule ? 'Edit Schedule Entry' : 'Add Class Schedule' ?></h3>
    <form method="POST">
        <input type="hidden" name="action" value="<?= $editSchedule ? 'edit' : 'add' ?>">
        <?php if ($editSchedule): ?>
        <input type="hidden" name="id" value="<?= $editSchedule['id'] ?>">
        <input type="hidden" name="class_id" value="<?= $editSchedule['class_id'] ?>">
        <?php endif; ?>
        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(170px,1fr));gap:.75rem">
            <?php if (!$editSchedule): ?>
            <div class="form-group" style="margin:0"><label>Course</label>
                <select name="class_id" required id="classSelect" onchange="liveCheck()">
                    <option value="">Select Course</option>
                    <?php foreach ($classes as $c): ?>
                    <option value="<?= $c['id'] ?>" data-teacher="<?= htmlspecialchars($c['teacher'],ENT_QUOTES) ?>">
                        <?= htmlspecialchars(($c['course_code']?'['.$c['course_code'].'] ':'').$c['name']) ?>
                    </option>
                    <?php endforeach; ?>
                </select></div>
            <?php endif; ?>
            <div class="form-group" style="margin:0"><label>Day</label>
                <select name="day_of_week" required id="daySelect" onchange="liveCheck()">
                    <?php foreach (['Monday','Tuesday','Wednesday','Thursday','Friday','Saturday','Sunday'] as $d): ?>
                    <option <?= ($editSchedule['day_of_week']??'')===$d?'selected':'' ?>><?= $d ?></option>
                    <?php endforeach; ?>
                </select></div>
            <div class="form-group" style="margin:0"><label>Start Time</label>
                <input type="time" name="start_time" required id="startTime" value="<?= substr($editSchedule['start_time']??'',0,5) ?>" onchange="liveCheck()"></div>
            <div class="form-group" style="margin:0"><label>End Time</label>
                <input type="time" name="end_time" required id="endTime" value="<?= substr($editSchedule['end_time']??'',0,5) ?>" onchange="liveCheck()"></div>
            <div class="form-group" style="margin:0"><label>Room (text)</label>
                <input type="text" name="room" id="roomText" value="<?= htmlspecialchars($editSchedule['room'] ?? '') ?>" placeholder="e.g. Lab 1" onchange="liveCheck()"></div>
            <div class="form-group" style="margin:0"><label>Room (from list)</label>
                <select name="room_id" id="roomSelect" onchange="syncRoom()">
                    <option value="">Select Room</option>
                    <?php foreach ($rooms as $r): ?>
                    <option value="<?= $r['id'] ?>" data-name="<?= htmlspecialchars($r['name'],ENT_QUOTES) ?>"
                        <?= ($editSchedule['room_id']??'')==$r['id']?'selected':'' ?>>
                        <?= htmlspecialchars($r['name']) ?><?= $r['building']?' — '.$r['building']:'' ?> (cap: <?= $r['capacity'] ?>)
                    </option>
                    <?php endforeach; ?>
                </select></div>
        </div>
        <div id="liveWarn" style="display:none;margin-top:.75rem;padding:.6rem 1rem;background:#fef3c7;border-left:4px solid #d97706;border-radius:0 8px 8px 0;font-size:.9rem">
            ⚠️ <span id="liveWarnMsg"></span>
        </div>
        <div style="margin-top:1rem;display:flex;gap:.75rem">
            <button type="submit" class="btn btn-primary"><?= $editSchedule ? 'Save Changes' : 'Add Schedule' ?></button>
            <?php if ($editSchedule): ?><a href="schedule.php" class="btn btn-secondary">Cancel</a><?php endif; ?>
        </div>
    </form>
</div>

<!-- Timetable -->
<div class="card">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:1rem;flex-wrap:wrap;gap:.5rem">
        <h3>Weekly Timetable (<?= count($schedules) ?> entries)</h3>
        <div style="display:flex;gap:.5rem;flex-wrap:wrap">
            <button class="btn btn-secondary btn-sm" onclick="setView('table')">Table</button>
            <button class="btn btn-secondary btn-sm" onclick="setView('grid')">Grid</button>
            <select id="filterTeacher" onchange="filterTeacher()" style="padding:.4rem .6rem;border:1px solid var(--border);border-radius:7px;background:var(--card-bg);color:var(--text)">
                <option value="">All Teachers</option>
                <?php
                $tlist = $conn->query("SELECT DISTINCT u.name FROM users u JOIN classes c ON c.teacher_id=u.id JOIN schedules s ON s.class_id=c.id ORDER BY u.name")->fetch_all(MYSQLI_ASSOC);
                foreach ($tlist as $t): ?>
                <option value="<?= htmlspecialchars($t['name'],ENT_QUOTES) ?>"><?= htmlspecialchars($t['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
    </div>

    <!-- Table View -->
    <div id="tableView" style="overflow-x:auto">
    <?php
    $days = ['Monday','Tuesday','Wednesday','Thursday','Friday','Saturday','Sunday'];
    $byDay = [];
    foreach ($schedules as $s) $byDay[$s['day_of_week']][] = $s;
    ?>
    <table class="table" id="schedTable">
        <thead><tr><th>Day</th><th>Time</th><th>Course</th><th>Teacher</th><th>Room</th><th>Status</th><th>Actions</th></tr></thead>
        <tbody>
        <?php foreach ($days as $day): if (empty($byDay[$day])) continue; ?>
            <?php foreach ($byDay[$day] as $i => $s):
                $hasC = isset($conflictIds[$s['id']]);
                $cType = $conflictIds[$s['id']] ?? null;
                $rowBg = $hasC ? ($cType==='teacher'?'#fef2f2':'#fff7ed') : '';
            ?>
            <tr style="background:<?= $rowBg ?>" data-teacher="<?= htmlspecialchars($s['teacher'],ENT_QUOTES) ?>">
                <?php if ($i===0): ?><td rowspan="<?= count($byDay[$day]) ?>" style="font-weight:600;background:var(--hover-bg)"><?= $day ?></td><?php endif; ?>
                <td><?= substr($s['start_time'],0,5) ?> – <?= substr($s['end_time'],0,5) ?></td>
                <td><?= htmlspecialchars(($s['course_code']?'['.$s['course_code'].'] ':'').$s['class_name']) ?><br><small class="text-muted"><?= htmlspecialchars($s['subject']) ?></small></td>
                <td><?= htmlspecialchars($s['teacher']) ?></td>
                <td><?= htmlspecialchars($s['room'] ?: '-') ?><?php if($s['room_capacity']): ?><br><small class="text-muted">cap: <?= $s['room_capacity'] ?></small><?php endif; ?></td>
                <td>
                    <?php if ($hasC): ?>
                    <span class="badge" style="background:<?= $cType==='teacher'?'#fee2e2':'#fed7aa' ?>;color:<?= $cType==='teacher'?'#b91c1c':'#c2410c' ?>">
                        ⚠️ <?= ucfirst($cType) ?>
                    </span>
                    <?php else: ?>
                    <span class="badge" style="background:#dcfce7;color:#15803d">✓ OK</span>
                    <?php endif; ?>
                </td>
                <td style="display:flex;gap:.3rem;flex-wrap:wrap">
                    <a href="?edit=<?= $s['id'] ?>" class="btn btn-secondary btn-sm">Edit</a>
                    <form method="POST" style="display:inline" onsubmit="return confirm('Delete?')">
                        <input type="hidden" name="action" value="delete">
                        <input type="hidden" name="id" value="<?= $s['id'] ?>">
                        <button class="btn btn-danger btn-sm">Delete</button>
                    </form>
                </td>
            </tr>
            <?php endforeach; ?>
        <?php endforeach; ?>
        <?php if (empty($schedules)): ?>
        <tr><td colspan="7" class="text-muted" style="text-align:center;padding:2rem">No schedules yet. <a href="timetable_engine.php">Use the Smart Engine</a>.</td></tr>
        <?php endif; ?>
        </tbody>
    </table>
    </div>

    <!-- Grid View -->
    <div id="gridView" style="display:none;overflow-x:auto">
    <?php $workDays = ['Monday','Tuesday','Wednesday','Thursday','Friday']; ?>
    <table style="width:100%;border-collapse:collapse;min-width:700px">
        <thead><tr>
            <th style="width:60px;padding:.5rem;border:1px solid var(--border);background:var(--hover-bg)">Time</th>
            <?php foreach ($workDays as $d): ?>
            <th style="padding:.5rem;border:1px solid var(--border);background:var(--hover-bg);text-align:center"><?= $d ?></th>
            <?php endforeach; ?>
        </tr></thead>
        <tbody>
        <?php foreach (['08:00','09:00','10:00','11:00','12:00','13:00','14:00','15:00','16:00','17:00'] as $hr): ?>
        <tr>
            <td style="padding:.4rem;border:1px solid var(--border);font-size:.78rem;color:var(--text-muted)"><?= $hr ?></td>
            <?php foreach ($workDays as $d): ?>
            <td style="padding:.25rem;border:1px solid var(--border);vertical-align:top;height:48px">
                <?php foreach ($byDay[$d] ?? [] as $s):
                    if ((int)substr($s['start_time'],0,2) !== (int)$hr) continue;
                    $hasC = isset($conflictIds[$s['id']]);
                    $bg = $hasC ? '#fee2e2' : '#ede9fe';
                    $bdr = $hasC ? '#b91c1c' : '#6366f1';
                ?>
                <div style="background:<?= $bg ?>;border-left:3px solid <?= $bdr ?>;padding:.25rem .4rem;border-radius:0 5px 5px 0;font-size:.75rem;margin-bottom:.15rem">
                    <strong><?= htmlspecialchars($s['class_name']) ?></strong>
                    <br><?= substr($s['start_time'],0,5) ?>–<?= substr($s['end_time'],0,5) ?>
                    <?php if ($s['room']): ?><br><span style="color:var(--text-muted)"><?= htmlspecialchars($s['room']) ?></span><?php endif; ?>
                    <?php if ($hasC): ?><br><span style="color:#b91c1c;font-weight:700">⚠️</span><?php endif; ?>
                </div>
                <?php endforeach; ?>
            </td>
            <?php endforeach; ?>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
</div>

<script>
const sched = <?= json_encode(array_map(fn($s) => ['class_id'=>$s['class_id'],'teacher'=>$s['teacher'],'day'=>$s['day_of_week'],'start'=>substr($s['start_time'],0,5),'end'=>substr($s['end_time'],0,5),'room'=>$s['room'],'id'=>$s['id']], $schedules)) ?>;

function syncRoom() {
    const sel = document.getElementById('roomSelect');
    const opt = sel.options[sel.selectedIndex];
    if (opt.value) { document.getElementById('roomText').value = opt.dataset.name || ''; liveCheck(); }
}

function liveCheck() {
    const classEl = document.getElementById('classSelect');
    const day   = document.getElementById('daySelect')?.value;
    const start = document.getElementById('startTime')?.value;
    const end   = document.getElementById('endTime')?.value;
    const room  = document.getElementById('roomText')?.value?.toLowerCase();
    const warn  = document.getElementById('liveWarn');
    const wmsg  = document.getElementById('liveWarnMsg');
    if (!day || !start || !end) return;
    const teacher = classEl?.options[classEl.selectedIndex]?.dataset?.teacher || '';
    for (const s of sched) {
        if (s.day !== day) continue;
        if (!(start < s.end && end > s.start)) continue;
        if (teacher && s.teacher === teacher) {
            warn.style.display = 'block';
            wmsg.textContent = `Teacher "${teacher}" already has a class at this time.`;
            return;
        }
        if (room && s.room && room === s.room.toLowerCase()) {
            warn.style.display = 'block';
            wmsg.textContent = `Room "${s.room}" is already booked at this time.`;
            return;
        }
    }
    warn.style.display = 'none';
}

function setView(v) {
    document.getElementById('tableView').style.display = v === 'table' ? 'block' : 'none';
    document.getElementById('gridView').style.display  = v === 'grid'  ? 'block' : 'none';
}

function filterTeacher() {
    const val = document.getElementById('filterTeacher').value;
    document.querySelectorAll('#schedTable tbody tr[data-teacher]').forEach(r => {
        r.style.display = (!val || r.dataset.teacher === val) ? '' : 'none';
    });
}
</script>
<?php include '../includes/footer.php'; ?>
