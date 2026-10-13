<?php
require_once '../includes/auth_check.php';
requireRole('teacher');
require_once '../config/db.php';
require_once '../includes/functions.php';
$user = currentUser();

$msg = ''; $msgType = 'success';

// ── POST Actions ──────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    verifyCsrf();
    $action = $_POST['action'];

    if ($action === 'cancel' || $action === 'reschedule') {
        $scheduleId  = (int)$_POST['schedule_id'];
        $classId     = (int)$_POST['class_id'];
        $eventDate   = trim($_POST['event_date'] ?? '');
        $reason      = trim($_POST['reason'] ?? '');

        // Verify teacher owns this class
        $chk = $conn->prepare("SELECT id FROM classes WHERE id=? AND teacher_id=?");
        $chk->bind_param('ii', $classId, $user['id']); $chk->execute();
        if (!$chk->get_result()->fetch_assoc()) {
            $msg = 'Access denied.'; $msgType = 'error';
            goto render;
        }

        $eventType   = $action === 'cancel' ? 'cancelled' : 'rescheduled';
        $newDate     = $action === 'reschedule' ? trim($_POST['new_date'] ?? '') : null;
        $newStart    = $action === 'reschedule' ? trim($_POST['new_start'] ?? '') : null;
        $newEnd      = $action === 'reschedule' ? trim($_POST['new_end'] ?? '') : null;
        $newRoom     = $action === 'reschedule' ? trim($_POST['new_room'] ?? '') : '';
        $notify      = 1;

        // Get original schedule
        $sched = $conn->prepare("SELECT * FROM schedules WHERE id=? AND class_id=?");
        $sched->bind_param('ii', $scheduleId, $classId); $sched->execute();
        $origSched = $sched->get_result()->fetch_assoc();
        if (!$origSched) { $msg = 'Schedule not found.'; $msgType = 'error'; goto render; }

        $ins = $conn->prepare("
            INSERT INTO class_events
                (schedule_id, class_id, event_date, original_day, original_start, original_end,
                 original_room, event_type, new_date, new_start, new_end, new_room,
                 reason, notify_students, created_by, created_by_role)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,'teacher')
        ");
        $ins->bind_param(
            'iisssssssssssii',
            $scheduleId, $classId, $eventDate,
            $origSched['day_of_week'], $origSched['start_time'], $origSched['end_time'],
            $origSched['room'], $eventType,
            $newDate, $newStart, $newEnd, $newRoom,
            $reason, $notify, $user['id']
        );

        if ($ins->execute()) {
            // Notify enrolled students
            $students = $conn->query("
                SELECT u.id, u.name FROM class_students cs
                JOIN users u ON cs.student_id=u.id
                WHERE cs.class_id=$classId AND cs.status='active'
            ")->fetch_all(MYSQLI_ASSOC);

            $className = $conn->query("SELECT name FROM classes WHERE id=$classId")->fetch_assoc()['name'];
            $origTime  = date('H:i', strtotime($origSched['start_time']));
            $origDay   = $origSched['day_of_week'];

            foreach ($students as $st) {
                $notifMsg = $eventType === 'cancelled'
                    ? "📢 Class CANCELLED: $className on $origDay at $origTime ($eventDate). Reason: $reason"
                    : "📢 Class RESCHEDULED: $className from $origDay $origTime to $newDate " . ($newStart ? date('H:i', strtotime($newStart)) : '') . ($newRoom ? " in $newRoom" : '') . ". Reason: $reason";
                $ns = $conn->prepare("INSERT INTO notifications (user_id, message) VALUES (?,?)");
                $ns->bind_param('is', $st['id'], $notifMsg); $ns->execute();
            }

            $msg = $eventType === 'cancelled'
                ? "Class cancelled. " . count($students) . " student(s) notified."
                : "Class rescheduled. " . count($students) . " student(s) notified.";
        } else {
            $msg = 'Error: '.$conn->error; $msgType = 'error';
        }
    }

    if ($action === 'delete_event') {
        $id = (int)$_POST['id'];
        $conn->query("DELETE FROM class_events WHERE id=$id AND created_by={$user['id']}");
        $msg = 'Event removed.';
    }
}

render:
// ── My schedules ──────────────────────────────────────────────────────────────
$schedules = $conn->query("
    SELECT s.*, c.id AS class_id, c.name AS class_name, c.subject
    FROM schedules s
    JOIN classes c ON s.class_id=c.id
    WHERE c.teacher_id={$user['id']}
    ORDER BY FIELD(s.day_of_week,'Monday','Tuesday','Wednesday','Thursday','Friday','Saturday','Sunday'),
             s.start_time
")->fetch_all(MYSQLI_ASSOC);

// ── Recent events ─────────────────────────────────────────────────────────────
$recentEvents = $conn->query("
    SELECT ce.*, c.name AS class_name
    FROM class_events ce
    JOIN classes c ON ce.class_id=c.id
    WHERE ce.created_by={$user['id']}
    ORDER BY ce.created_at DESC
    LIMIT 20
")->fetch_all(MYSQLI_ASSOC);

include '../includes/header.php';
?>

<h1>📅 Class Events — Cancel / Reschedule</h1>
<p class="text-muted">Cancel or reschedule a specific class occurrence. All enrolled students will be notified automatically.</p>

<?php if ($msg): ?><div class="alert alert-<?= $msgType ?>"><?= htmlspecialchars($msg) ?></div><?php endif; ?>

<div style="display:grid;grid-template-columns:1fr 1.4fr;gap:1.5rem;align-items:start">

<!-- Form -->
<div class="card">
    <h3>Create Class Event</h3>
    <form method="POST" id="eventForm">
        <?= csrfField() ?>
        <input type="hidden" name="action" id="eventAction" value="cancel">

        <div class="form-group">
            <label>Class / Schedule</label>
            <select name="schedule_id" id="scheduleSelect" required onchange="fillClassId(this)">
                <option value="">Select scheduled class...</option>
                <?php foreach ($schedules as $s): ?>
                <option value="<?= $s['id'] ?>"
                        data-class="<?= $s['class_id'] ?>"
                        data-day="<?= htmlspecialchars($s['day_of_week']) ?>"
                        data-start="<?= htmlspecialchars($s['start_time']) ?>"
                        data-end="<?= htmlspecialchars($s['end_time']) ?>"
                        data-room="<?= htmlspecialchars($s['room']??'') ?>">
                    <?= htmlspecialchars($s['day_of_week']) ?>
                    <?= substr($s['start_time'],0,5) ?>–<?= substr($s['end_time'],0,5) ?>
                    · <?= htmlspecialchars($s['class_name']) ?>
                    <?= $s['room'] ? '· '.$s['room'] : '' ?>
                </option>
                <?php endforeach; ?>
            </select>
        </div>

        <input type="hidden" name="class_id" id="classIdHidden">

        <div class="form-group">
            <label>Date of Affected Class</label>
            <input type="date" name="event_date" required min="<?= date('Y-m-d', strtotime('-30 days')) ?>">
            <small class="text-muted">Which specific date is being cancelled or rescheduled?</small>
        </div>

        <div class="form-group">
            <label>Event Type</label>
            <div style="display:flex;gap:.75rem">
                <label style="cursor:pointer;display:flex;align-items:center;gap:.4rem;flex:1;padding:.65rem;border:1.5px solid #e2e8f0;border-radius:8px" id="cancelLabel">
                    <input type="radio" name="event_type_radio" value="cancel" checked onchange="toggleEventType('cancel')">
                    ❌ Cancel Class
                </label>
                <label style="cursor:pointer;display:flex;align-items:center;gap:.4rem;flex:1;padding:.65rem;border:1.5px solid #e2e8f0;border-radius:8px" id="reschedLabel">
                    <input type="radio" name="event_type_radio" value="reschedule" onchange="toggleEventType('reschedule')">
                    🔄 Reschedule
                </label>
            </div>
        </div>

        <!-- Reschedule fields -->
        <div id="reschedFields" style="display:none">
            <div class="form-group">
                <label>New Date</label>
                <input type="date" name="new_date" id="newDate">
            </div>
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:.75rem">
                <div class="form-group" style="margin:0">
                    <label>New Start Time</label>
                    <input type="time" name="new_start" id="newStart">
                </div>
                <div class="form-group" style="margin:0">
                    <label>New End Time</label>
                    <input type="time" name="new_end" id="newEnd">
                </div>
            </div>
            <div class="form-group">
                <label>New Room (optional)</label>
                <input type="text" name="new_room" placeholder="Leave blank to keep same room">
            </div>
        </div>

        <div class="form-group">
            <label>Reason <span style="color:#dc2626">*</span></label>
            <textarea name="reason" rows="3" required minlength="5"
                      placeholder="e.g. Teacher sick leave, University event, Room maintenance..."
                      style="width:100%;padding:.65rem;border:1.5px solid var(--border);border-radius:8px;resize:vertical"></textarea>
        </div>

        <!-- Preview -->
        <div id="previewBox" style="display:none;background:#f8fafc;border-radius:8px;padding:.75rem;margin-bottom:1rem;font-size:.88rem">
            <strong>Student notification preview:</strong>
            <div id="previewText" style="color:#475569;margin-top:.3rem"></div>
        </div>

        <button type="submit" class="btn btn-primary btn-full" id="submitBtn">❌ Cancel This Class</button>
    </form>
</div>

<!-- Recent events -->
<div class="card">
    <h3>Recent Class Events</h3>
    <?php if (empty($recentEvents)): ?>
    <p class="text-muted">No class events yet.</p>
    <?php else: ?>
    <?php
    $typeColors = [
        'cancelled'   => ['#fee2e2','#b91c1c','❌'],
        'rescheduled' => ['#fef3c7','#b45309','🔄'],
        'makeup'      => ['#dcfce7','#15803d','✅'],
        'room_change' => ['#dbeafe','#1e40af','📍'],
    ];
    foreach ($recentEvents as $ev):
        [$bg,$fg,$icon] = $typeColors[$ev['event_type']] ?? ['#f1f5f9','#475569','📋'];
    ?>
    <div style="padding:.85rem 1rem;border-radius:9px;border:1.5px solid <?= $bg ?>;background:<?= $bg ?>22;margin-bottom:.65rem">
        <div style="display:flex;justify-content:space-between;align-items:flex-start;flex-wrap:wrap;gap:.4rem">
            <div>
                <span class="badge" style="background:<?= $bg ?>;color:<?= $fg ?>"><?= $icon ?> <?= ucfirst($ev['event_type']) ?></span>
                <strong style="margin-left:.4rem"><?= htmlspecialchars($ev['class_name']) ?></strong>
                <br>
                <small class="text-muted">
                    <?= date('d M Y', strtotime($ev['event_date'])) ?>
                    (<?= $ev['original_day'] ?> <?= substr($ev['original_start'],0,5) ?>)
                    <?php if ($ev['event_type'] === 'rescheduled' && $ev['new_date']): ?>
                    → <?= date('d M Y', strtotime($ev['new_date'])) ?>
                    <?= $ev['new_start'] ? date('H:i',strtotime($ev['new_start'])) : '' ?>
                    <?= $ev['new_room'] ? "· {$ev['new_room']}" : '' ?>
                    <?php endif; ?>
                </small>
            </div>
            <form method="POST" style="display:inline" onsubmit="return confirm('Remove this event?')">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="delete_event">
                <input type="hidden" name="id" value="<?= $ev['id'] ?>">
                <button class="btn btn-sm" style="background:#fee2e2;color:#b91c1c;border:none">Delete</button>
            </form>
        </div>
        <?php if ($ev['reason']): ?>
        <div style="font-size:.82rem;color:#64748b;margin-top:.35rem">Reason: <?= htmlspecialchars($ev['reason']) ?></div>
        <?php endif; ?>
        <div style="font-size:.75rem;color:#94a3b8;margin-top:.2rem">
            Created <?= date('d M H:i', strtotime($ev['created_at'])) ?>
            · Notifications: <?= $ev['notification_sent'] ? 'Sent' : 'Pending' ?>
        </div>
    </div>
    <?php endforeach; ?>
    <?php endif; ?>
</div>
</div>

<script>
function fillClassId(sel) {
    const opt = sel.options[sel.selectedIndex];
    document.getElementById('classIdHidden').value = opt.dataset.class || '';
    updatePreview();
}

function toggleEventType(type) {
    const fields = document.getElementById('reschedFields');
    const btn    = document.getElementById('submitBtn');
    const action = document.getElementById('eventAction');

    if (type === 'reschedule') {
        fields.style.display = 'block';
        btn.textContent = '🔄 Reschedule This Class';
        action.value = 'reschedule';
        document.querySelectorAll('[name=new_date],[name=new_start],[name=new_end]').forEach(el => el.required = true);
    } else {
        fields.style.display = 'none';
        btn.textContent = '❌ Cancel This Class';
        action.value = 'cancel';
        document.querySelectorAll('[name=new_date],[name=new_start],[name=new_end]').forEach(el => el.required = false);
    }
    updatePreview();
}

function updatePreview() {
    const sel    = document.getElementById('scheduleSelect');
    const opt    = sel?.options[sel.selectedIndex];
    const evDate = document.querySelector('[name=event_date]')?.value;
    const reason = document.querySelector('[name=reason]')?.value;
    const type   = document.querySelector('[name=event_type_radio]:checked')?.value;
    const preview = document.getElementById('previewBox');
    const previewText = document.getElementById('previewText');

    if (!opt?.dataset.class || !evDate) { preview.style.display='none'; return; }

    const day   = opt.dataset.day;
    const start = opt.dataset.start?.substring(0,5);
    const className = opt.text.split('·')[1]?.trim() || '';

    let msg = '';
    if (type === 'cancel') {
        msg = `📢 Class CANCELLED: ${className} on ${day} at ${start} (${evDate}).`;
    } else {
        const newDate  = document.getElementById('newDate')?.value || '?';
        const newStart = document.getElementById('newStart')?.value || '';
        msg = `📢 Class RESCHEDULED: ${className} from ${day} ${start} to ${newDate} ${newStart}.`;
    }
    if (reason) msg += ` Reason: ${reason}`;

    previewText.textContent = msg;
    preview.style.display = 'block';
}

document.querySelectorAll('[name=event_date],[name=reason],[name=new_date],[name=new_start]')
    .forEach(el => el.addEventListener('input', updatePreview));
</script>

<?php include '../includes/footer.php'; ?>
