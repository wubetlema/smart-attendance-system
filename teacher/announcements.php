<?php
require_once '../includes/auth_check.php';
requireRole('teacher');
require_once '../config/db.php';
require_once '../includes/mailer.php';
$user = currentUser();

$msg = ''; $msgType = 'success';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // ── Class Schedule Notification ──────────────────────────────────────────
    if (isset($_POST['action']) && $_POST['action'] === 'schedule_notify') {
        $class_id  = (int)$_POST['notify_class_id'];
        $when      = $_POST['when_day']; // 'today' or 'tomorrow'
        $targetDate = ($when === 'tomorrow') ? date('Y-m-d', strtotime('+1 day')) : date('Y-m-d');
        $dayName    = date('l', strtotime($targetDate)); // e.g. "Monday"

        // Fetch schedule for this class on that day
        $sched = $conn->prepare("
            SELECT s.start_time, s.end_time, s.room, c.name AS class_name, c.subject
            FROM schedules s
            JOIN classes c ON s.class_id = c.id
            WHERE s.class_id = ? AND s.day_of_week = ?
            LIMIT 1
        ");
        $sched->bind_param('is', $class_id, $dayName);
        $sched->execute();
        $schedule = $sched->get_result()->fetch_assoc();

        if (!$schedule) {
            $msg = "No schedule found for this class on $dayName.";
            $msgType = 'error';
        } else {
            $startFmt = date('h:i A', strtotime($schedule['start_time']));
            $endFmt   = date('h:i A', strtotime($schedule['end_time']));
            $room     = $schedule['room'] ?: 'TBA';
            $label    = ucfirst($when); // Today / Tomorrow

            // Fetch enrolled students
            $students = $conn->query("
                SELECT u.id, u.name, u.email
                FROM class_students cs
                JOIN users u ON cs.student_id = u.id
                WHERE cs.class_id = $class_id
            ")->fetch_all(MYSQLI_ASSOC);

            $notifText = "📅 Class Reminder ({$label}): {$schedule['class_name']} — {$dayName}, {$startFmt}–{$endFmt}, Room: {$room}. Teacher: {$user['name']}";

            $emailBody = "<p>Dear Student,</p>
                <p>This is a reminder from <strong>{$user['name']}</strong> about your upcoming class:</p>
                <table style='border-collapse:collapse;width:100%;margin:1rem 0'>
                    <tr><td style='padding:.5rem;font-weight:600;width:120px'>Class</td><td style='padding:.5rem'>{$schedule['class_name']}</td></tr>
                    <tr style='background:#f8fafc'><td style='padding:.5rem;font-weight:600'>Subject</td><td style='padding:.5rem'>{$schedule['subject']}</td></tr>
                    <tr><td style='padding:.5rem;font-weight:600'>Day</td><td style='padding:.5rem'>{$dayName} ({$label})</td></tr>
                    <tr style='background:#f8fafc'><td style='padding:.5rem;font-weight:600'>Time</td><td style='padding:.5rem'>{$startFmt} – {$endFmt}</td></tr>
                    <tr><td style='padding:.5rem;font-weight:600'>Room</td><td style='padding:.5rem'>{$room}</td></tr>
                </table>
                <p>Please be on time. See you in class!</p>";

            $sent = 0;
            foreach ($students as $s) {
                // In-app notification
                $ns = $conn->prepare("INSERT INTO notifications (user_id, message) VALUES (?, ?)");
                $ns->bind_param('is', $s['id'], $notifText);
                $ns->execute();

                // Email notification
                if ($s['email']) {
                    sendMail($s['email'], $s['name'], "Class Reminder: {$schedule['class_name']} — {$label}", $emailBody);
                }
                $sent++;
            }
            $msg = "Schedule notification sent to $sent student(s) for {$schedule['class_name']} on {$dayName}.";
        }

    // ── General Announcement ─────────────────────────────────────────────────
    } else {
        $class_id = (int)$_POST['class_id'];
        $message  = trim($_POST['message']);
        $stmt = $conn->prepare("INSERT INTO announcements (teacher_id,class_id,message) VALUES (?,?,?)");
        $stmt->bind_param('iis', $user['id'], $class_id, $message);
        if ($stmt->execute()) {
            $students = $conn->query("SELECT student_id FROM class_students WHERE class_id=$class_id AND is_blocked=0")->fetch_all(MYSQLI_ASSOC);
            $cls = $conn->query("SELECT name FROM classes WHERE id=$class_id")->fetch_assoc();
            foreach ($students as $s) {
                $notif = "Announcement from {$user['name']} [{$cls['name']}]: $message";
                $ns = $conn->prepare("INSERT INTO notifications (user_id,message) VALUES (?,?)");
                $ns->bind_param('is', $s['student_id'], $notif); $ns->execute();
            }
            $msg = 'Announcement sent to all enrolled students.';
        }
    }
}

$stmt = $conn->prepare("SELECT * FROM classes WHERE teacher_id=? ORDER BY name");
$stmt->bind_param('i', $user['id']); $stmt->execute();
$classes = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// Classes that have a schedule for today or tomorrow (for the notify form)
$todayDay    = date('l');
$tomorrowDay = date('l', strtotime('+1 day'));
$stmt2 = $conn->prepare("
    SELECT DISTINCT c.id, c.name, c.subject
    FROM classes c
    JOIN schedules s ON s.class_id = c.id
    WHERE c.teacher_id = ? AND s.day_of_week IN (?, ?)
    ORDER BY c.name
");
$stmt2->bind_param('iss', $user['id'], $todayDay, $tomorrowDay);
$stmt2->execute();
$scheduledClasses = $stmt2->get_result()->fetch_all(MYSQLI_ASSOC);

$stmt = $conn->prepare("SELECT a.*, c.name AS class_name FROM announcements a JOIN classes c ON a.class_id=c.id WHERE a.teacher_id=? ORDER BY a.created_at DESC LIMIT 20");
$stmt->bind_param('i', $user['id']); $stmt->execute();
$announcements = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

include '../includes/header.php';
?>
<h1>Announcements</h1>
<?php if ($msg): ?><div class="alert alert-<?= $msgType ?>"><?= htmlspecialchars($msg) ?></div><?php endif; ?>

<!-- ── Schedule Reminder Notification ──────────────────────────────────── -->
<div class="card">
    <h3>📅 Notify Students — Class Schedule Reminder</h3>
    <p class="text-muted" style="margin-bottom:1rem">Send students a reminder with the class time, room, and day for today or tomorrow.</p>
    <?php if (empty($scheduledClasses)): ?>
        <p class="text-muted">No classes scheduled for today (<?= $todayDay ?>) or tomorrow (<?= $tomorrowDay ?>).</p>
    <?php else: ?>
    <form method="POST">
        <input type="hidden" name="action" value="schedule_notify">
        <div style="display:grid;grid-template-columns:1fr 1fr auto;gap:.75rem;align-items:end">
            <div class="form-group" style="margin:0">
                <label>Class</label>
                <select name="notify_class_id" required>
                    <option value="">Select Class</option>
                    <?php foreach ($scheduledClasses as $c): ?>
                    <option value="<?= $c['id'] ?>"><?= htmlspecialchars($c['name']) ?> — <?= htmlspecialchars($c['subject']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group" style="margin:0">
                <label>When</label>
                <select name="when_day" required>
                    <option value="today">Today (<?= $todayDay ?>)</option>
                    <option value="tomorrow">Tomorrow (<?= $tomorrowDay ?>)</option>
                </select>
            </div>
            <button type="submit" class="btn btn-primary">Send Reminder</button>
        </div>
    </form>
    <?php endif; ?>
</div>

<div class="card">
    <h3>Send Announcement</h3>
    <form method="POST">
        <div class="form-group"><label>Course</label>
            <select name="class_id" required>
                <option value="">Select Course</option>
                <?php foreach ($classes as $c): ?>
                <option value="<?= $c['id'] ?>"><?= htmlspecialchars($c['name']) ?></option>
                <?php endforeach; ?>
            </select></div>
        <div class="form-group"><label>Message</label>
            <textarea name="message" rows="3" required style="width:100%;padding:.6rem;border:1px solid var(--border);border-radius:7px" placeholder="Type your announcement..."></textarea></div>
        <button type="submit" class="btn btn-primary">Send to All Students</button>
    </form>
</div>

<div class="card">
    <h3>Sent Announcements</h3>
    <?php if (empty($announcements)): ?><p class="text-muted">No announcements yet.</p>
    <?php else: ?>
    <?php foreach ($announcements as $a): ?>
    <div style="border-bottom:1px solid var(--border);padding:.75rem 0">
        <span class="badge badge-teacher"><?= htmlspecialchars($a['class_name']) ?></span>
        <span class="text-muted" style="font-size:.8rem;margin-left:.5rem"><?= date('d M Y H:i', strtotime($a['created_at'])) ?></span>
        <p style="margin-top:.4rem"><?= htmlspecialchars($a['message']) ?></p>
    </div>
    <?php endforeach; ?>
    <?php endif; ?>
</div>
<?php include '../includes/footer.php'; ?>
