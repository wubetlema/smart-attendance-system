<?php
require_once '../includes/auth_check.php';
requireRole('student');
require_once '../config/db.php';
require_once '../includes/functions.php';
require_once '../includes/attendance_calculator.php';

$user = currentUser();

// ── Full user profile with hierarchy ─────────────────────────────────────────
$stmt = $conn->prepare("
    SELECT u.*,
           ca.name AS campus_name, ca.short_name AS campus_short,
           col.name AS college_name,
           d.name AS dept_name, d.short_name AS dept_short,
           p.name AS program_name, p.degree_type,
           b.batch_name, b.entry_year
    FROM users u
    LEFT JOIN campuses ca    ON u.campus_id     = ca.id
    LEFT JOIN colleges col   ON u.college_id    = col.id
    LEFT JOIN departments d  ON u.department_id = d.id
    LEFT JOIN programs p     ON u.program_id    = p.id
    LEFT JOIN batches b      ON u.batch_id      = b.id
    WHERE u.id = ?
");
$stmt->bind_param('i', $user['id']);
$stmt->execute();
$profile = $stmt->get_result()->fetch_assoc();

// ── Per-class attendance breakdown ────────────────────────────────────────────
$classSummaries = AttendanceCalculator::studentSummary($user['id']);

// ── Overall attendance aggregate ─────────────────────────────────────────────
$overall = AttendanceCalculator::studentOverall($user['id']);

// ── Thresholds for JS ─────────────────────────────────────────────────────────
$thresholds = AttendanceCalculator::thresholds();

// ── Next scheduled class (today) ─────────────────────────────────────────────
$today = date('l');
$nowTime = date('H:i:s');
$nextClass = $conn->query("
    SELECT s.start_time, s.end_time, s.room,
           c.name AS class_name, c.subject, u.name AS teacher
    FROM schedules s
    JOIN classes c ON s.class_id = c.id
    JOIN class_students cs ON cs.class_id = c.id
    JOIN users u ON c.teacher_id = u.id
    WHERE cs.student_id = {$user['id']}
      AND s.day_of_week  = '$today'
      AND s.end_time     > '$nowTime'
    ORDER BY s.start_time ASC
    LIMIT 1
")->fetch_assoc();

// ── Active sessions student can check into right now ─────────────────────────
$activeSessions = $conn->query("
    SELECT s.id, s.status, s.method, c.name AS class_name, c.subject,
           s.present_until, s.late_until,
           (SELECT COUNT(*) FROM attendance a WHERE a.session_id=s.id AND a.student_id={$user['id']}) AS already_marked
    FROM attendance_sessions s
    JOIN classes c ON s.class_id = c.id
    JOIN class_students cs ON cs.class_id = c.id
    WHERE cs.student_id = {$user['id']}
      AND s.status IN ('open','late')
      AND s.late_until > NOW()
    ORDER BY s.open_at DESC
")->fetch_all(MYSQLI_ASSOC);

// ── Unread notifications ───────────────────────────────────────────────────────
$notifications = $conn->query("
    SELECT * FROM notifications
    WHERE user_id = {$user['id']} AND is_read = 0
    ORDER BY created_at DESC LIMIT 5
")->fetch_all(MYSQLI_ASSOC);

// Mark them as read
if (!empty($notifications)) {
    $conn->query("UPDATE notifications SET is_read=1 WHERE user_id={$user['id']} AND is_read=0");
}

include '../includes/header.php';
?>

<!-- ── Quick action banner: active sessions ──────────────────────────────── -->
<?php foreach ($activeSessions as $as): if ($as['already_marked']) continue; ?>
<div class="alert" style="background:linear-gradient(135deg,#dcfce7,#d1fae5);border-left:4px solid #16a34a;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:.75rem">
    <div>
        <strong>📋 Attendance open now: <?= htmlspecialchars($as['class_name']) ?></strong>
        <span style="color:#15803d;font-weight:600;margin-left:.5rem">
            <?= $as['status'] === 'late' ? '⏰ Late period' : '✅ On time' ?>
        </span>
        <br>
        <small class="text-muted">
            Closes at <?= date('H:i', strtotime($as['late_until'])) ?>
            <?php if ($as['status'] === 'open'): ?>
             · On-time until <?= date('H:i', strtotime($as['present_until'])) ?>
            <?php endif; ?>
        </small>
    </div>
    <div style="display:flex;gap:.5rem">
        <?php if ($as['method'] !== 'manual'): ?>
        <a href="/attendance-system/student/qr_checkin.php" class="btn btn-primary btn-sm">📷 Check In (QR)</a>
        <?php endif; ?>
        <?php if ($as['method'] === 'face'): ?>
        <a href="/attendance-system/student/face_checkin.php" class="btn btn-secondary btn-sm">🤳 Face Check-in</a>
        <?php endif; ?>
    </div>
</div>
<?php endforeach; ?>

<h1>Student Dashboard</h1>

<!-- ── Profile card ──────────────────────────────────────────────────────── -->
<div class="card" style="background:linear-gradient(135deg,#1e1b4b,#312e81);color:#fff;border:none;margin-bottom:1.5rem">
    <div style="display:flex;align-items:center;gap:1.25rem;flex-wrap:wrap">
        <div style="width:60px;height:60px;border-radius:50%;background:rgba(255,255,255,.2);display:flex;align-items:center;justify-content:center;font-size:1.8rem;flex-shrink:0">
            <?php if (!empty($profile['photo'])): ?>
            <img src="/attendance-system/assets/uploads/<?= htmlspecialchars($profile['photo']) ?>"
                 style="width:60px;height:60px;border-radius:50%;object-fit:cover">
            <?php else: ?>👤<?php endif; ?>
        </div>
        <div style="flex:1">
            <h2 style="margin:0;color:#fff"><?= htmlspecialchars($profile['name']) ?></h2>
            <div style="opacity:.85;font-size:.9rem;margin-top:.25rem">
                <code style="background:rgba(255,255,255,.15);padding:.1rem .5rem;border-radius:6px"><?= htmlspecialchars($profile['university_id'] ?? '') ?></code>
            </div>
        </div>
        <div style="text-align:right;font-size:.85rem;opacity:.85">
            <?php if ($profile['program_name']): ?>
            <div><?= htmlspecialchars($profile['degree_type'].' '.$profile['program_name']) ?></div>
            <?php endif; ?>
            <?php if ($profile['dept_name']): ?>
            <div><?= htmlspecialchars($profile['dept_name']) ?></div>
            <?php endif; ?>
            <?php if ($profile['college_name']): ?>
            <div style="opacity:.7"><?= htmlspecialchars($profile['college_name']) ?></div>
            <?php endif; ?>
            <?php if ($profile['year_of_study'] || $profile['semester']): ?>
            <div style="margin-top:.25rem">
                <?= $profile['year_of_study'] ? 'Year '.$profile['year_of_study'] : '' ?>
                <?= $profile['semester'] ? ' · '.$profile['semester'] : '' ?>
                <?= $profile['section'] ? ' · Section '.$profile['section'] : '' ?>
            </div>
            <?php endif; ?>
            <?php if ($profile['batch_name']): ?>
            <div style="opacity:.7"><?= htmlspecialchars($profile['batch_name']) ?></div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- ── Overall stats ──────────────────────────────────────────────────────── -->
<?php $ost = $overall['status']; ?>
<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(140px,1fr));gap:1rem;margin-bottom:1.5rem">
    <div class="stat-card" style="border-color:<?= $ost['color'] ?>">
        <div class="stat-number" style="color:<?= $ost['color'] ?>"><?= $overall['pct'] !== null ? $overall['pct'].'%' : '–' ?></div>
        <div class="stat-label">Overall Attendance</div>
        <?php if ($overall['pct'] !== null): ?>
        <div style="margin-top:.4rem">
            <?= AttendanceCalculator::progressBar($overall['pct']) ?>
        </div>
        <?php endif; ?>
    </div>
    <div class="stat-card" style="border-color:#16a34a">
        <div class="stat-number" style="color:#16a34a"><?= $overall['present'] ?></div>
        <div class="stat-label">Present</div>
    </div>
    <div class="stat-card" style="border-color:#d97706">
        <div class="stat-number" style="color:#d97706"><?= $overall['late'] ?></div>
        <div class="stat-label">Late</div>
    </div>
    <div class="stat-card" style="border-color:#dc2626">
        <div class="stat-number" style="color:#dc2626"><?= $overall['absent'] ?></div>
        <div class="stat-label">Absent</div>
    </div>
    <div class="stat-card">
        <div class="stat-number"><?= $overall['total'] ?></div>
        <div class="stat-label">Total Sessions</div>
    </div>
</div>

<!-- ── Attendance by course ───────────────────────────────────────────────── -->
<div class="card">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:1rem;flex-wrap:wrap;gap:.5rem">
        <h3 style="margin:0">Attendance by Course</h3>
        <?= AttendanceCalculator::legend() ?>
    </div>

    <?php if (empty($classSummaries)): ?>
    <div style="text-align:center;padding:2rem;color:#94a3b8">
        <div style="font-size:2.5rem;margin-bottom:.75rem">📚</div>
        <p>You are not enrolled in any classes yet.</p>
    </div>
    <?php else: ?>

    <div style="display:grid;gap:.85rem">
    <?php foreach ($classSummaries as $cs):
        $st = $cs['status'];
    ?>
    <div style="padding:1rem 1.1rem;border-radius:10px;border:1.5px solid <?= $st['bg'] ?>;background:<?= $st['bg'] ?>18">
        <div style="display:flex;justify-content:space-between;align-items:flex-start;flex-wrap:wrap;gap:.5rem;margin-bottom:.6rem">
            <div>
                <div style="font-weight:600;font-size:.95rem">
                    <?php if ($cs['course_code']): ?>
                    <code style="background:var(--hover-bg);padding:.1rem .4rem;border-radius:5px;font-size:.78rem;margin-right:.4rem"><?= htmlspecialchars($cs['course_code']) ?></code>
                    <?php endif; ?>
                    <?= htmlspecialchars($cs['class_name']) ?>
                </div>
                <div style="color:#64748b;font-size:.85rem;margin-top:.15rem">
                    <?= htmlspecialchars($cs['subject']) ?>
                    <?php if ($cs['teacher_name']): ?>
                    · <span style="color:#6366f1"><?= htmlspecialchars($cs['teacher_name']) ?></span>
                    <?php endif; ?>
                    <?php if ($cs['credits']): ?>
                    · <span><?= $cs['credits'] ?> cr</span>
                    <?php endif; ?>
                </div>
            </div>
            <div style="text-align:right">
                <div style="font-size:1.3rem;font-weight:800;color:<?= $st['color'] ?>"><?= $cs['pct_display'] ?></div>
                <div style="font-size:.78rem;background:<?= $st['bg'] ?>;color:<?= $st['color'] ?>;padding:.15rem .5rem;border-radius:12px;display:inline-block;font-weight:600">
                    <?= $st['icon'] ?> <?= $st['label'] ?>
                </div>
            </div>
        </div>

        <?= AttendanceCalculator::progressBar($cs['pct'], 'margin-bottom:.5rem') ?>

        <div style="display:flex;gap:1.5rem;font-size:.82rem;color:#64748b;flex-wrap:wrap">
            <span>✅ Present: <strong style="color:#15803d"><?= $cs['present'] ?></strong></span>
            <span>⏰ Late: <strong style="color:#d97706"><?= $cs['late'] ?></strong></span>
            <span>❌ Absent: <strong style="color:#dc2626"><?= $cs['absent'] ?></strong></span>
            <span>📊 Total: <strong><?= $cs['total'] ?></strong></span>
            <?php if ($cs['last_seen']): ?>
            <span class="text-muted">Last: <?= date('d M', strtotime($cs['last_seen'])) ?></span>
            <?php endif; ?>
        </div>

        <?php if ($st['class'] === 'critical'): ?>
        <div style="margin-top:.6rem;padding:.5rem .75rem;background:#fee2e2;border-radius:6px;font-size:.82rem;color:#b91c1c">
            ⚠️ Critical — attendance is below <?= (int)$thresholds['warning'] ?>%. Contact your department advisor.
        </div>
        <?php elseif ($st['class'] === 'warning'): ?>
        <div style="margin-top:.6rem;padding:.5rem .75rem;background:#fef3c7;border-radius:6px;font-size:.82rem;color:#b45309">
            ⚠️ Warning — attendance is below <?= (int)$thresholds['good'] ?>%. Try to attend upcoming classes.
        </div>
        <?php endif; ?>
    </div>
    <?php endforeach; ?>
    </div>

    <?php endif; ?>
</div>

<!-- ── Next class today ───────────────────────────────────────────────────── -->
<?php if ($nextClass): ?>
<div class="card" style="border-left:4px solid #6366f1">
    <h3 style="margin:0 0 .5rem">📅 Next Class Today</h3>
    <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:.5rem">
        <div>
            <strong><?= htmlspecialchars($nextClass['class_name']) ?></strong>
            <span class="text-muted"> — <?= htmlspecialchars($nextClass['subject']) ?></span>
            <br>
            <small class="text-muted">
                🕐 <?= substr($nextClass['start_time'],0,5) ?> – <?= substr($nextClass['end_time'],0,5) ?>
                <?= $nextClass['room'] ? ' · 📍 '.$nextClass['room'] : '' ?>
                · 👤 <?= htmlspecialchars($nextClass['teacher']) ?>
            </small>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- ── Notifications ──────────────────────────────────────────────────────── -->
<?php if (!empty($notifications)): ?>
<div class="card">
    <h3>🔔 Recent Notifications</h3>
    <div style="display:flex;flex-direction:column;gap:.5rem">
    <?php foreach ($notifications as $n): ?>
    <div style="padding:.65rem .85rem;background:var(--hover-bg);border-radius:8px;font-size:.9rem">
        <?= htmlspecialchars($n['message']) ?>
        <span class="text-muted" style="float:right;font-size:.78rem"><?= date('d M H:i', strtotime($n['created_at'])) ?></span>
    </div>
    <?php endforeach; ?>
    </div>
</div>
<?php endif; ?>

<!-- ── Quick links ────────────────────────────────────────────────────────── -->
<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:.75rem;margin-top:1rem">
    <a href="/attendance-system/student/qr_checkin.php"     class="btn btn-primary"   style="text-align:center;padding:1rem">📷 QR Check-in</a>
    <a href="/attendance-system/student/face_checkin.php"   class="btn btn-secondary" style="text-align:center;padding:1rem">🤳 Face Check-in</a>
    <a href="/attendance-system/student/my_attendance.php"  class="btn btn-secondary" style="text-align:center;padding:1rem">📊 Full Record</a>
    <a href="/attendance-system/student/profile.php"        class="btn btn-secondary" style="text-align:center;padding:1rem">👤 My Profile</a>
</div>

<?php include '../includes/footer.php'; ?>
