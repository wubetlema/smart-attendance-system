<?php
require_once '../includes/auth_check.php';
requireRole('admin');
require_once '../config/db.php';

$msg = ''; $msgType = 'success';

// ── Auto-Generate Timetable ──────────────────────────────────────────────────
if (isset($_POST['action']) && $_POST['action'] === 'auto_generate') {
    $termId = $_POST['term_id'] ? (int)$_POST['term_id'] : null;
    $result = autoGenerateTimetable($termId);
    $msg = $result['message'];
    $msgType = $result['success'] ? 'success' : 'error';
}

// ── Detect All Conflicts ─────────────────────────────────────────────────────
$conflicts = detectAllConflicts();

// ── Get Unscheduled Classes ──────────────────────────────────────────────────
$unscheduled = $conn->query("
    SELECT c.id, c.name, c.subject, c.course_code, u.name AS teacher_name, c.max_students
    FROM classes c
    JOIN users u ON c.teacher_id=u.id
    LEFT JOIN schedules s ON s.class_id=c.id
    WHERE s.id IS NULL AND c.is_active=1
    ORDER BY c.name
")->fetch_all(MYSQLI_ASSOC);

// ── Get Terms ─────────────────────────────────────────────────────────────────
$terms = $conn->query("SELECT id, name, academic_year FROM academic_terms ORDER BY start_date DESC")->fetch_all(MYSQLI_ASSOC);
$currentTerm = $conn->query("SELECT id FROM academic_terms WHERE is_current=1 LIMIT 1")->fetch_assoc();

// ── Get Rooms ─────────────────────────────────────────────────────────────────
$rooms = $conn->query("SELECT id, name, building, capacity, type FROM rooms WHERE is_active=1 ORDER BY name")->fetch_all(MYSQLI_ASSOC);

include '../includes/header.php';
?>
<h1>🤖 Smart Timetable Engine</h1>
<p class="text-muted">Auto-generate optimal schedules with conflict detection and resolution.</p>

<?php if ($msg): ?><div class="alert alert-<?= $msgType ?>"><?= htmlspecialchars($msg) ?></div><?php endif; ?>

<!-- Stats Overview -->
<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:1rem;margin-bottom:1.5rem">
    <?php
    $stats = [
        'total' => $conn->query("SELECT COUNT(*) c FROM schedules")->fetch_assoc()['c'],
        'unscheduled' => count($unscheduled),
        'conflicts' => count($conflicts),
        'rooms' => count($rooms)
    ];
    ?>
    <div class="stat-card">
        <div class="stat-number"><?= $stats['total'] ?></div>
        <div class="stat-label">Scheduled Classes</div>
    </div>
    <div class="stat-card" style="border-color:<?= $stats['unscheduled']>0?'#d97706':'#16a34a' ?>">
        <div class="stat-number" style="color:<?= $stats['unscheduled']>0?'#d97706':'#16a34a' ?>"><?= $stats['unscheduled'] ?></div>
        <div class="stat-label">Unscheduled</div>
    </div>
    <div class="stat-card" style="border-color:<?= $stats['conflicts']>0?'#dc2626':'#16a34a' ?>">
        <div class="stat-number" style="color:<?= $stats['conflicts']>0?'#dc2626':'#16a34a' ?>"><?= $stats['conflicts'] ?></div>
        <div class="stat-label">Conflicts</div>
    </div>
    <div class="stat-card">
        <div class="stat-number"><?= $stats['rooms'] ?></div>
        <div class="stat-label">Available Rooms</div>
    </div>
</div>

<!-- Auto-Generate -->
<div class="card" style="border-left:4px solid #6366f1">
    <h3>🚀 Auto-Generate Timetable</h3>
    <p class="text-muted">Automatically schedule all unscheduled classes with optimal time slots and room assignments.</p>
    <form method="POST" onsubmit="return confirm('Generate timetable for <?= $stats['unscheduled'] ?> unscheduled class(es)? Existing schedules will not be affected.')">
        <input type="hidden" name="action" value="auto_generate">
        <div style="display:flex;gap:.75rem;align-items:end;flex-wrap:wrap">
            <div class="form-group" style="margin:0">
                <label>Academic Term (optional)</label>
                <select name="term_id">
                    <option value="">All Terms</option>
                    <?php foreach ($terms as $t): ?>
                    <option value="<?= $t['id'] ?>" <?= ($currentTerm['id']??'')==$t['id']?'selected':'' ?>><?= htmlspecialchars($t['name']) ?> (<?= $t['academic_year'] ?>)</option>
                    <?php endforeach; ?>
                </select>
            </div>
            <button type="submit" class="btn btn-primary" <?= $stats['unscheduled']==0?'disabled':'' ?>>
                Generate Timetable (<?= $stats['unscheduled'] ?> classes)
            </button>
            <a href="schedule.php" class="btn btn-secondary">View Full Schedule</a>
        </div>
    </form>
</div>

<!-- Conflicts -->
<?php if (!empty($conflicts)): ?>
<div class="card" style="border-left:4px solid #dc2626">
    <h3>⚠️ Detected Conflicts (<?= count($conflicts) ?>)</h3>
    <div style="display:grid;gap:.75rem;margin-top:.75rem">
    <?php foreach ($conflicts as $c): ?>
    <div style="padding:.75rem 1rem;background:#fef2f2;border-radius:8px">
        <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:1rem;flex-wrap:wrap">
            <div style="flex:1">
                <strong style="color:#b91c1c"><?= htmlspecialchars($c['type']) ?></strong>
                <p style="margin:.3rem 0 0"><?= htmlspecialchars($c['message']) ?></p>
                <small class="text-muted">
                    <?= htmlspecialchars($c['day']) ?>, <?= $c['start_time'] ?> – <?= $c['end_time'] ?>
                    <?php if ($c['room']): ?> | Room: <?= htmlspecialchars($c['room']) ?><?php endif; ?>
                </small>
            </div>
            <div style="display:flex;gap:.3rem">
                <?php foreach ($c['schedule_ids'] as $sid): ?>
                <form method="POST" action="schedule.php" style="display:inline">
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="id" value="<?= $sid ?>">
                    <button class="btn btn-danger btn-sm" onclick="return confirm('Delete this schedule entry?')">Delete</button>
                </form>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
    <?php endforeach; ?>
    </div>
</div>
<?php endif; ?>

<!-- Unscheduled Classes -->
<?php if (!empty($unscheduled)): ?>
<div class="card">
    <h3>📋 Unscheduled Classes (<?= count($unscheduled) ?>)</h3>
    <p class="text-muted">These classes need to be scheduled. Use auto-generate or add manually.</p>
    <div style="overflow-x:auto">
    <table class="table">
        <thead><tr><th>Code</th><th>Class</th><th>Subject</th><th>Teacher</th><th>Max Students</th><th>Action</th></tr></thead>
        <tbody>
        <?php foreach ($unscheduled as $u): ?>
        <tr>
            <td><code><?= htmlspecialchars($u['course_code'] ?? '-') ?></code></td>
            <td><?= htmlspecialchars($u['name']) ?></td>
            <td><?= htmlspecialchars($u['subject']) ?></td>
            <td><?= htmlspecialchars($u['teacher_name']) ?></td>
            <td><?= $u['max_students'] ?></td>
            <td>
                <button class="btn btn-primary btn-sm" onclick="suggestSlot(<?= $u['id'] ?>,'<?= htmlspecialchars($u['name'],ENT_QUOTES) ?>')">
                    Suggest Slot
                </button>
            </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
</div>
<?php endif; ?>

<!-- Suggestion Modal -->
<div id="suggestionModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:999;align-items:center;justify-content:center;overflow-y:auto">
    <div class="card" style="width:90%;max-width:700px;margin:2rem auto;max-height:90vh;overflow-y:auto">
        <h3>💡 Suggested Time Slots</h3>
        <p id="suggestClassName" class="text-muted"></p>
        <div id="suggestionContent"></div>
        <button class="btn btn-secondary" onclick="closeSuggestion()">Close</button>
    </div>
</div>

<script>
const rooms = <?= json_encode($rooms) ?>;

async function suggestSlot(classId, className) {
    document.getElementById('suggestClassName').textContent = 'For: ' + className;
    document.getElementById('suggestionContent').innerHTML = '<p>Loading suggestions...</p>';
    document.getElementById('suggestionModal').style.display = 'flex';

    const res = await fetch('timetable_engine.php?suggest=1&class_id=' + classId);
    const data = await res.json();

    if (!data.success) {
        document.getElementById('suggestionContent').innerHTML = '<p class="text-muted">' + data.message + '</p>';
        return;
    }

    let html = '<div style="display:grid;gap:.75rem;margin-top:.75rem">';
    data.suggestions.forEach(s => {
        html += `<div style="padding:.75rem;background:var(--hover-bg);border-radius:8px;display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:.75rem">
            <div>
                <strong>${s.day}</strong> ${s.start_time} – ${s.end_time}
                <br><small class="text-muted">Room: ${s.room_name} (${s.room_building}) | Capacity: ${s.room_capacity}</small>
            </div>
            <form method="POST" action="schedule.php" style="display:inline">
                <input type="hidden" name="action" value="add">
                <input type="hidden" name="class_id" value="${classId}">
                <input type="hidden" name="day_of_week" value="${s.day}">
                <input type="hidden" name="start_time" value="${s.start_time}">
                <input type="hidden" name="end_time" value="${s.end_time}">
                <input type="hidden" name="room" value="${s.room_name}">
                <button class="btn btn-primary btn-sm">Schedule Here</button>
            </form>
        </div>`;
    });
    html += '</div>';
    document.getElementById('suggestionContent').innerHTML = html;
}

function closeSuggestion() {
    document.getElementById('suggestionModal').style.display = 'none';
}
</script>

<?php
// ── AJAX: Suggest Slots ──────────────────────────────────────────────────────
if (isset($_GET['suggest'])) {
    header('Content-Type: application/json');
    $classId = (int)$_GET['class_id'];

    $class = $conn->query("SELECT c.*, u.name AS teacher_name FROM classes c JOIN users u ON c.teacher_id=u.id WHERE c.id=$classId")->fetch_assoc();
    if (!$class) {
        echo json_encode(['success' => false, 'message' => 'Class not found']);
        exit;
    }

    $suggestions = suggestOptimalSlots($classId, $class);
    echo json_encode(['success' => true, 'suggestions' => $suggestions]);
    exit;
}

// ── Helper: Auto-Generate Timetable ──────────────────────────────────────────
function autoGenerateTimetable($termId) {
    global $conn;

    $termWhere = $termId ? "AND c.term_id=$termId" : '';
    $unscheduled = $conn->query("
        SELECT c.id, c.name, c.teacher_id, c.max_students
        FROM classes c
        LEFT JOIN schedules s ON s.class_id=c.id
        WHERE s.id IS NULL AND c.is_active=1 $termWhere
        ORDER BY c.max_students DESC
    ")->fetch_all(MYSQLI_ASSOC);

    if (empty($unscheduled)) {
        return ['success' => false, 'message' => 'No unscheduled classes found.'];
    }

    $scheduled = 0; $failed = 0;
    foreach ($unscheduled as $class) {
        $suggestions = suggestOptimalSlots($class['id'], $class);
        if (empty($suggestions)) {
            $failed++;
            continue;
        }

        // Pick first suggestion
        $slot = $suggestions[0];
        $stmt = $conn->prepare("INSERT INTO schedules (class_id,day_of_week,start_time,end_time,room) VALUES (?,?,?,?,?)");
        $stmt->bind_param('issss', $class['id'], $slot['day'], $slot['start_time'], $slot['end_time'], $slot['room_name']);
        if ($stmt->execute()) $scheduled++;
        else $failed++;
    }

    return [
        'success' => true,
        'message' => "Auto-generated timetable: $scheduled scheduled, $failed failed (no available slots)."
    ];
}

// ── Helper: Suggest Optimal Slots ────────────────────────────────────────────
function suggestOptimalSlots($classId, $class) {
    global $conn;

    $teacherId = $class['teacher_id'];
    $maxStudents = $class['max_students'] ?? 50;

    // Get available rooms with sufficient capacity
    $rooms = $conn->query("SELECT id, name, building, capacity FROM rooms WHERE is_active=1 AND capacity >= $maxStudents ORDER BY capacity ASC")->fetch_all(MYSQLI_ASSOC);
    if (empty($rooms)) $rooms = $conn->query("SELECT id, name, building, capacity FROM rooms WHERE is_active=1 ORDER BY capacity DESC LIMIT 3")->fetch_all(MYSQLI_ASSOC);

    $days = ['Monday','Tuesday','Wednesday','Thursday','Friday'];
    $timeSlots = [
        ['08:00:00','09:30:00'],
        ['09:45:00','11:15:00'],
        ['11:30:00','13:00:00'],
        ['14:00:00','15:30:00'],
        ['15:45:00','17:15:00']
    ];

    $suggestions = [];

    foreach ($days as $day) {
        foreach ($timeSlots as $slot) {
            [$start, $end] = $slot;

            // Check teacher availability
            $teacherBusy = $conn->query("
                SELECT s.id FROM schedules s
                JOIN classes c ON s.class_id=c.id
                WHERE c.teacher_id=$teacherId
                AND s.day_of_week='$day'
                AND s.start_time < '$end' AND s.end_time > '$start'
            ")->num_rows > 0;

            if ($teacherBusy) continue;

            // Find available room
            foreach ($rooms as $room) {
                $roomBusy = $conn->query("
                    SELECT s.id FROM schedules s
                    WHERE s.room='{$room['name']}'
                    AND s.day_of_week='$day'
                    AND s.start_time < '$end' AND s.end_time > '$start'
                ")->num_rows > 0;

                if (!$roomBusy) {
                    $suggestions[] = [
                        'day' => $day,
                        'start_time' => substr($start,0,5),
                        'end_time' => substr($end,0,5),
                        'room_name' => $room['name'],
                        'room_building' => $room['building'],
                        'room_capacity' => $room['capacity']
                    ];
                    break; // Found a room, move to next slot
                }
            }

            if (count($suggestions) >= 5) break 2; // Limit to 5 suggestions
        }
    }

    return $suggestions;
}

// ── Helper: Detect All Conflicts ─────────────────────────────────────────────
function detectAllConflicts() {
    global $conn;

    $conflicts = [];

    // Teacher conflicts
    $teacherConflicts = $conn->query("
        SELECT
            s1.id AS id1, s2.id AS id2,
            s1.day_of_week, s1.start_time, s1.end_time,
            c1.name AS class1, c2.name AS class2,
            u.name AS teacher
        FROM schedules s1
        JOIN schedules s2 ON s1.id < s2.id
            AND s1.day_of_week = s2.day_of_week
            AND s1.start_time < s2.end_time
            AND s1.end_time > s2.start_time
        JOIN classes c1 ON s1.class_id=c1.id
        JOIN classes c2 ON s2.class_id=c2.id
        JOIN users u ON c1.teacher_id=u.id
        WHERE c1.teacher_id = c2.teacher_id
    ")->fetch_all(MYSQLI_ASSOC);

    foreach ($teacherConflicts as $tc) {
        $conflicts[] = [
            'type' => 'Teacher Conflict',
            'message' => "{$tc['teacher']} is scheduled for both '{$tc['class1']}' and '{$tc['class2']}'",
            'day' => $tc['day_of_week'],
            'start_time' => substr($tc['start_time'],0,5),
            'end_time' => substr($tc['end_time'],0,5),
            'room' => null,
            'schedule_ids' => [$tc['id1'], $tc['id2']]
        ];
    }

    // Room conflicts
    $roomConflicts = $conn->query("
        SELECT
            s1.id AS id1, s2.id AS id2,
            s1.day_of_week, s1.start_time, s1.end_time, s1.room,
            c1.name AS class1, c2.name AS class2
        FROM schedules s1
        JOIN schedules s2 ON s1.id < s2.id
            AND s1.day_of_week = s2.day_of_week
            AND s1.start_time < s2.end_time
            AND s1.end_time > s2.start_time
            AND s1.room = s2.room
        JOIN classes c1 ON s1.class_id=c1.id
        JOIN classes c2 ON s2.class_id=c2.id
        WHERE s1.room IS NOT NULL AND s1.room != ''
    ")->fetch_all(MYSQLI_ASSOC);

    foreach ($roomConflicts as $rc) {
        $conflicts[] = [
            'type' => 'Room Conflict',
            'message' => "Room '{$rc['room']}' is double-booked for '{$rc['class1']}' and '{$rc['class2']}'",
            'day' => $rc['day_of_week'],
            'start_time' => substr($rc['start_time'],0,5),
            'end_time' => substr($rc['end_time'],0,5),
            'room' => $rc['room'],
            'schedule_ids' => [$rc['id1'], $rc['id2']]
        ];
    }

    return $conflicts;
}

include '../includes/footer.php';
?>
