<?php
/**
 * Alert Engine — processes alert rules and fires notifications
 * Called by: cron job, or triggered after attendance marking
 */
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/mailer.php';
require_once __DIR__ . '/functions.php';

/**
 * Run all active alert rules and create alert instances where conditions are met.
 * Safe to call frequently — deduplicates within 24h per student/class/rule.
 */
function runAlertEngine() {
    global $conn;

    $rules = $conn->query("SELECT * FROM alert_rules WHERE is_active=1")->fetch_all(MYSQLI_ASSOC);

    foreach ($rules as $rule) {
        switch ($rule['rule_type']) {
            case 'attendance_low':
                checkLowAttendance($rule);
                break;
            case 'absence_streak':
                checkAbsenceStreak($rule);
                break;
            case 'late_streak':
                checkLateStreak($rule);
                break;
        }
    }

    // Run escalation check
    processEscalations();
}

/**
 * Check alerts for a specific student after attendance is marked.
 * Called automatically from markAttendance() in functions.php
 */
function checkStudentAlerts($studentId, $classId) {
    global $conn;

    $rules = $conn->query("SELECT * FROM alert_rules WHERE is_active=1")->fetch_all(MYSQLI_ASSOC);

    foreach ($rules as $rule) {
        switch ($rule['rule_type']) {
            case 'attendance_low':
                checkLowAttendanceForStudent($rule, $studentId, $classId);
                break;
            case 'absence_streak':
                checkAbsenceStreakForStudent($rule, $studentId, $classId);
                break;
        }
    }
}

// ── Low Attendance (full scan) ────────────────────────────────────────────────
function checkLowAttendance($rule) {
    global $conn;
    $threshold = (float)$rule['threshold_value'];

    $rows = $conn->query("
        SELECT
            cs.student_id,
            s.class_id,
            u.name AS student_name,
            u.email,
            c.name AS class_name,
            c.teacher_id,
            COUNT(a.id) AS total,
            SUM(a.status='present') AS present,
            ROUND(SUM(a.status='present') / COUNT(a.id) * 100, 1) AS pct
        FROM class_students cs
        JOIN users u ON cs.student_id=u.id
        JOIN classes c ON cs.class_id=c.id
        JOIN attendance_sessions s ON s.class_id=cs.class_id
        JOIN attendance a ON a.session_id=s.id AND a.student_id=cs.student_id
        GROUP BY cs.student_id, s.class_id
        HAVING total >= 3 AND pct < $threshold
    ")->fetch_all(MYSQLI_ASSOC);

    foreach ($rows as $row) {
        if (alertAlreadyFired($rule['id'], $row['student_id'], $row['class_id'])) continue;
        _fireLowAttendanceAlerts($rule, $row);
    }
}

// ── Low Attendance (single student, called after marking) ─────────────────────
function checkLowAttendanceForStudent($rule, $studentId, $classId) {
    global $conn;
    $threshold = (float)$rule['threshold_value'];

    $stmt = $conn->prepare("
        SELECT
            cs.student_id,
            cs.class_id,
            u.name AS student_name,
            u.email,
            c.name AS class_name,
            c.teacher_id,
            COUNT(a.id) AS total,
            SUM(a.status='present') AS present,
            ROUND(SUM(a.status='present') / COUNT(a.id) * 100, 1) AS pct
        FROM class_students cs
        JOIN users u ON cs.student_id=u.id
        JOIN classes c ON cs.class_id=c.id
        JOIN attendance_sessions s ON s.class_id=cs.class_id
        JOIN attendance a ON a.session_id=s.id AND a.student_id=cs.student_id
        WHERE cs.student_id=? AND cs.class_id=?
        GROUP BY cs.student_id, cs.class_id
        HAVING total >= 3 AND pct < ?
    ");
    $stmt->bind_param('iid', $studentId, $classId, $threshold);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();

    if (!$row || alertAlreadyFired($rule['id'], $studentId, $classId)) return;
    _fireLowAttendanceAlerts($rule, $row);
}

// ── Shared: fire student + teacher alerts for low attendance ──────────────────
function _fireLowAttendanceAlerts($rule, $row) {
    global $conn;
    $threshold = (float)$rule['threshold_value'];
    $pct       = (float)$row['pct'];

    // ── Student alert: detention risk warning ────────────────────────────────
    $isDetentionRisk = $pct < 50;
    $studentTitle    = $isDetentionRisk
        ? "⚠️ Detention Risk: {$row['class_name']}"
        : "Low Attendance Warning: {$row['class_name']}";
    $studentMsg      = $isDetentionRisk
        ? "You are at risk of being detained in {$row['class_name']}. Your attendance is {$pct}%, which is critically below the required {$threshold}%. Immediate action is required — contact your teacher."
        : "Your attendance in {$row['class_name']} is {$pct}%, below the required {$threshold}%. You may be at risk of detention if this continues. Please attend all upcoming classes.";

    $meta = json_encode(['pct' => $pct, 'total' => $row['total'], 'present' => $row['present'], 'threshold' => $threshold]);
    $priority = $isDetentionRisk ? 'critical' : $rule['priority'];

    // Override rule priority for critical cases
    $effectiveRule = $rule;
    $effectiveRule['priority'] = $priority;

    fireAlert($effectiveRule, $row['student_id'], $row['class_id'], $studentTitle, $studentMsg, $meta, $row);

    // ── Teacher alert: low attendance student notification ───────────────────
    if (!empty($row['teacher_id'])) {
        $teacherTitle = "Low Attendance Student: {$row['class_name']}";
        $teacherMsg   = "{$row['student_name']} has {$pct}% attendance in {$row['class_name']}" .
                        ($isDetentionRisk ? " — DETENTION RISK." : " (below {$threshold}% threshold).");

        // Deduplicate teacher notification separately
        $teacherRuleKey = $rule['id'] * 100000 + $row['student_id']; // synthetic key
        if (!alertAlreadyFiredForTeacher($row['teacher_id'], $row['student_id'], $row['class_id'])) {
            $teacherRow = ['student_name' => 'Teacher', 'email' => null]; // teacher email fetched inside
            $te = $conn->prepare("SELECT name, email FROM users WHERE id=?");
            $te->bind_param('i', $row['teacher_id']); $te->execute();
            $teacherUser = $te->get_result()->fetch_assoc();

            // In-app notification to teacher
            $ns = $conn->prepare("INSERT INTO notifications (user_id, message) VALUES (?, ?)");
            $notifMsg = "📊 {$teacherMsg}";
            $ns->bind_param('is', $row['teacher_id'], $notifMsg);
            $ns->execute();

            // Email to teacher if enabled
            if ($rule['notify_email'] && $teacherUser && $teacherUser['email']) {
                $body = "<p>Dear <strong>" . htmlspecialchars($teacherUser['name']) . "</strong>,</p>
                         <p>This is an automated attendance alert for your class <strong>" . htmlspecialchars($row['class_name']) . "</strong>:</p>
                         <table style='border-collapse:collapse;width:100%;margin:1rem 0'>
                             <tr><td style='padding:.5rem;font-weight:600'>Student</td><td style='padding:.5rem'>" . htmlspecialchars($row['student_name']) . "</td></tr>
                             <tr style='background:#f8fafc'><td style='padding:.5rem;font-weight:600'>Attendance</td><td style='padding:.5rem;color:" . ($isDetentionRisk ? '#b91c1c' : '#d97706') . ";font-weight:700'>{$pct}%</td></tr>
                             <tr><td style='padding:.5rem;font-weight:600'>Required</td><td style='padding:.5rem'>{$threshold}%</td></tr>
                             <tr style='background:#f8fafc'><td style='padding:.5rem;font-weight:600'>Status</td><td style='padding:.5rem'>" . ($isDetentionRisk ? '<span style=\"color:#b91c1c;font-weight:700\">⚠️ DETENTION RISK</span>' : 'Below threshold') . "</td></tr>
                         </table>
                         <p>Please review this student's attendance and take appropriate action.</p>";
                sendMail($teacherUser['email'], $teacherUser['name'], "Attendance Alert — {$row['class_name']}", $body);
            }
        }
    }
}

// ── Consecutive Absences (full scan) ─────────────────────────────────────────
function checkAbsenceStreak($rule) {
    global $conn;
    $streak = (int)$rule['threshold_days'];

    // For each student/class, check if their last N sessions are all absent
    $rows = $conn->query("
        SELECT
            a.student_id,
            s.class_id,
            u.name AS student_name,
            u.email,
            c.name AS class_name,
            c.teacher_id
        FROM attendance a
        JOIN attendance_sessions s ON a.session_id=s.id
        JOIN users u ON a.student_id=u.id
        JOIN classes c ON s.class_id=c.id
        GROUP BY a.student_id, s.class_id
    ")->fetch_all(MYSQLI_ASSOC);

    foreach ($rows as $row) {
        $streak_count = getConsecutiveAbsences($row['student_id'], $row['class_id']);
        if ($streak_count < $streak) continue;
        if (alertAlreadyFired($rule['id'], $row['student_id'], $row['class_id'])) continue;
        _fireAbsenceStreakAlerts($rule, $row, $streak_count);
    }
}

// ── Consecutive Absences (single student, called after marking) ───────────────
function checkAbsenceStreakForStudent($rule, $studentId, $classId) {
    global $conn;
    $streak = (int)$rule['threshold_days'];

    $streak_count = getConsecutiveAbsences($studentId, $classId);
    if ($streak_count < $streak) return;
    if (alertAlreadyFired($rule['id'], $studentId, $classId)) return;

    $stmt = $conn->prepare("
        SELECT u.name AS student_name, u.email, c.name AS class_name, c.teacher_id
        FROM users u, classes c
        WHERE u.id=? AND c.id=?
    ");
    $stmt->bind_param('ii', $studentId, $classId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    if (!$row) return;

    $row['student_id'] = $studentId;
    $row['class_id']   = $classId;
    _fireAbsenceStreakAlerts($rule, $row, $streak_count);
}

// ── Get consecutive absences count (last N sessions) ─────────────────────────
function getConsecutiveAbsences($studentId, $classId) {
    global $conn;

    // Get last 10 sessions for this class, check how many leading ones are absent
    $stmt = $conn->prepare("
        SELECT a.status
        FROM attendance a
        JOIN attendance_sessions s ON a.session_id=s.id
        WHERE a.student_id=? AND s.class_id=?
        ORDER BY a.marked_at DESC
        LIMIT 10
    ");
    $stmt->bind_param('ii', $studentId, $classId);
    $stmt->execute();
    $records = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

    $streak = 0;
    foreach ($records as $r) {
        if ($r['status'] === 'absent') $streak++;
        else break;
    }
    return $streak;
}

// ── Shared: fire student + teacher alerts for absence streak ──────────────────
function _fireAbsenceStreakAlerts($rule, $row, $streakCount) {
    global $conn;

    // ── Student alert ────────────────────────────────────────────────────────
    $title   = "⚠️ {$streakCount} Consecutive Absences: {$row['class_name']}";
    $message = "You have missed {$streakCount} consecutive classes in {$row['class_name']}. " .
               "You are at risk of being detained. Please contact your teacher immediately and provide a valid reason for your absences.";
    $meta    = json_encode(['streak' => $streakCount]);

    fireAlert($rule, $row['student_id'], $row['class_id'], $title, $message, $meta, $row);

    // ── Teacher alert ────────────────────────────────────────────────────────
    if (!empty($row['teacher_id'])) {
        if (alertAlreadyFiredForTeacher($row['teacher_id'], $row['student_id'], $row['class_id'])) return;

        $teacherMsg = "🚨 {$row['student_name']} has missed {$streakCount} consecutive classes in {$row['class_name']}. Please follow up.";
        $ns = $conn->prepare("INSERT INTO notifications (user_id, message) VALUES (?, ?)");
        $ns->bind_param('is', $row['teacher_id'], $teacherMsg);
        $ns->execute();

        if ($rule['notify_email']) {
            $te = $conn->prepare("SELECT name, email FROM users WHERE id=?");
            $te->bind_param('i', $row['teacher_id']); $te->execute();
            $teacherUser = $te->get_result()->fetch_assoc();
            if ($teacherUser && $teacherUser['email']) {
                $body = "<p>Dear <strong>" . htmlspecialchars($teacherUser['name']) . "</strong>,</p>
                         <p><strong>" . htmlspecialchars($row['student_name']) . "</strong> has been absent for <strong style='color:#b91c1c'>{$streakCount} consecutive sessions</strong> in <strong>" . htmlspecialchars($row['class_name']) . "</strong>.</p>
                         <p>Please follow up with this student. They have been notified automatically.</p>";
                sendMail($teacherUser['email'], $teacherUser['name'], "🚨 Consecutive Absences — {$row['class_name']}", $body);
            }
        }
    }
}

// ── Late Streak ───────────────────────────────────────────────────────────────
function checkLateStreak($rule) {
    global $conn;

    $streak = (int)$rule['threshold_days'];

    $rows = $conn->query("
        SELECT
            a.student_id,
            s.class_id,
            u.name AS student_name,
            u.email,
            c.name AS class_name,
            c.teacher_id,
            COUNT(*) AS late_count
        FROM attendance a
        JOIN attendance_sessions s ON a.session_id=s.id
        JOIN users u ON a.student_id=u.id
        JOIN classes c ON s.class_id=c.id
        WHERE a.status='late'
        GROUP BY a.student_id, s.class_id
        HAVING late_count >= $streak
    ")->fetch_all(MYSQLI_ASSOC);

    foreach ($rows as $row) {
        if (alertAlreadyFired($rule['id'], $row['student_id'], $row['class_id'])) continue;

        $title   = "Repeated Late Arrivals: {$row['class_name']}";
        $message = "You have been late {$row['late_count']} times in {$row['class_name']}. Please make an effort to arrive on time.";
        $meta    = json_encode(['late_count' => $row['late_count']]);

        fireAlert($rule, $row['student_id'], $row['class_id'], $title, $message, $meta, $row);
    }
}

// ── Deduplication ─────────────────────────────────────────────────────────────
function alertAlreadyFired($ruleId, $userId, $classId) {
    global $conn;
    $stmt = $conn->prepare("SELECT id FROM alerts WHERE rule_id=? AND user_id=? AND class_id=? AND created_at > DATE_SUB(NOW(), INTERVAL 24 HOUR) LIMIT 1");
    $stmt->bind_param('iii', $ruleId, $userId, $classId);
    $stmt->execute();
    return $stmt->get_result()->num_rows > 0;
}

// Teacher notification deduplication (24h window per student/class)
function alertAlreadyFiredForTeacher($teacherId, $studentId, $classId) {
    global $conn;
    $stmt = $conn->prepare("
        SELECT id FROM notifications
        WHERE user_id=?
        AND created_at > DATE_SUB(NOW(), INTERVAL 24 HOUR)
        AND message LIKE CONCAT('%student_id:', ?, '%class_id:', ?, '%')
        LIMIT 1
    ");
    $stmt->bind_param('iii', $teacherId, $studentId, $classId);
    $stmt->execute();
    return $stmt->get_result()->num_rows > 0;
}

// ── Fire Alert ────────────────────────────────────────────────────────────────
function fireAlert($rule, $userId, $classId, $title, $message, $meta, $row) {
    global $conn;

    $priority = $rule['priority'];

    // Insert alert record
    $stmt = $conn->prepare("INSERT INTO alerts (rule_id,user_id,class_id,title,message,priority,status,email_sent,sms_sent,inapp_sent,metadata) VALUES (?,?,?,?,?,?,?,?,?,?,?)");
    $status = 'pending';
    $emailSent = 0; $smsSent = 0; $inappSent = 0;
    $stmt->bind_param('iiissssiiis', $rule['id'],$userId,$classId,$title,$message,$priority,$status,$emailSent,$smsSent,$inappSent,$meta);
    $stmt->execute();
    $alertId = $conn->insert_id;

    // In-app notification
    if ($rule['notify_inapp']) {
        $ns = $conn->prepare("INSERT INTO notifications (user_id, message) VALUES (?, ?)");
        $notifMsg = "⚠️ $title: $message";
        $ns->bind_param('is', $userId, $notifMsg);
        $ns->execute();
        $conn->query("UPDATE alerts SET inapp_sent=1, status='sent' WHERE id=$alertId");
        logAlertAction($alertId, 'inapp_sent', 'In-app notification sent');
    }

    // Email notification
    if ($rule['notify_email'] && !empty($row['email'])) {
        $emailResult = sendAlertEmail($row['email'], $row['student_name'], $title, $message, $priority);
        if ($emailResult['success']) {
            $conn->query("UPDATE alerts SET email_sent=1, email_sent_at=NOW(), status='sent' WHERE id=$alertId");
            logAlertAction($alertId, 'email_sent', 'Email sent to '.$row['email']);
        }
    }

    // SMS notification
    if ($rule['notify_sms']) {
        $smsResult = sendAlertSMS($userId, $message);
        if ($smsResult['success']) {
            $conn->query("UPDATE alerts SET sms_sent=1, sms_sent_at=NOW() WHERE id=$alertId");
            logAlertAction($alertId, 'sms_sent', 'SMS sent');
        }
    }

    logAlertAction($alertId, 'created', "Alert fired for rule: {$rule['name']}");
}

// ── Escalation Processor ──────────────────────────────────────────────────────
function processEscalations() {
    global $conn;

    // Find unread alerts past their escalation window
    $alerts = $conn->query("
        SELECT a.*, r.escalate_after_hours, r.escalate_to_role, r.name AS rule_name,
               u.name AS student_name, c.name AS class_name, c.teacher_id
        FROM alerts a
        JOIN alert_rules r ON a.rule_id=r.id
        JOIN users u ON a.user_id=u.id
        LEFT JOIN classes c ON a.class_id=c.id
        WHERE a.status='sent'
        AND a.escalated=0
        AND r.escalate_to_role != 'none'
        AND a.created_at < DATE_SUB(NOW(), INTERVAL r.escalate_after_hours HOUR)
    ")->fetch_all(MYSQLI_ASSOC);

    foreach ($alerts as $alert) {
        $escalateTo = null;

        if ($alert['escalate_to_role'] === 'teacher' && $alert['teacher_id']) {
            $escalateTo = (int)$alert['teacher_id'];
        } elseif ($alert['escalate_to_role'] === 'admin') {
            $admin = $conn->query("SELECT id FROM users WHERE role='admin' LIMIT 1")->fetch_assoc();
            $escalateTo = $admin ? (int)$admin['id'] : null;
        }

        if (!$escalateTo) continue;

        // Notify escalation target
        $escalMsg = "🔴 ESCALATED: {$alert['student_name']} — {$alert['title']} (unread for {$alert['escalate_after_hours']}h)";
        $ns = $conn->prepare("INSERT INTO notifications (user_id, message) VALUES (?, ?)");
        $ns->bind_param('is', $escalateTo, $escalMsg);
        $ns->execute();

        // Mark as escalated
        $conn->query("UPDATE alerts SET escalated=1, escalated_at=NOW(), escalated_to=$escalateTo, status='escalated' WHERE id={$alert['id']}");
        logAlertAction($alert['id'], 'escalated', "Escalated to user ID $escalateTo ({$alert['escalate_to_role']})");
    }
}

// ── Email Helper ──────────────────────────────────────────────────────────────
function sendAlertEmail($toEmail, $toName, $title, $message, $priority) {
    $priorityColors = ['low'=>'#64748b','medium'=>'#d97706','high'=>'#c2410c','critical'=>'#b91c1c'];
    $color = $priorityColors[$priority] ?? '#64748b';
    $icon  = ['low'=>'ℹ️','medium'=>'⚠️','high'=>'🔶','critical'=>'🚨'][$priority] ?? '⚠️';

    $body = "<p>Dear <strong>" . htmlspecialchars($toName) . "</strong>,</p>
             <div style='border-left:4px solid $color;padding:.75rem 1rem;background:#f8fafc;border-radius:0 8px 8px 0;margin:1rem 0'>
                 <p style='margin:0;font-size:1.1rem;font-weight:600;color:$color'>$icon " . htmlspecialchars($title) . "</p>
                 <p style='margin:.5rem 0 0'>" . htmlspecialchars($message) . "</p>
             </div>
             <p>Please log in to SmartAttend to view your attendance details and take action.</p>";

    return sendMail($toEmail, $toName, "$icon $title", $body);
}

// ── SMS Helper (stub — integrate your SMS gateway here) ───────────────────────
function sendAlertSMS($userId, $message) {
    global $conn;

    $stmt = $conn->prepare("SELECT sms_phone, sms_enabled FROM users WHERE id=?");
    $stmt->bind_param('i', $userId); $stmt->execute();
    $user = $stmt->get_result()->fetch_assoc();

    if (!$user || !$user['sms_enabled'] || empty($user['sms_phone'])) {
        return ['success' => false, 'message' => 'SMS not enabled for user'];
    }

    $smsGateway = getSetting('sms_gateway', '');
    $smsApiKey  = getSetting('sms_api_key', '');

    if (!$smsGateway || !$smsApiKey) {
        return ['success' => false, 'message' => 'SMS gateway not configured'];
    }

    // Generic HTTP SMS gateway call — replace with your provider (Twilio, Africa's Talking, etc.)
    $payload = http_build_query([
        'api_key' => $smsApiKey,
        'to'      => $user['sms_phone'],
        'message' => substr($message, 0, 160),
    ]);

    $ctx = stream_context_create(['http' => ['method'=>'POST','header'=>'Content-Type: application/x-www-form-urlencoded','content'=>$payload,'timeout'=>5]]);
    $result = @file_get_contents($smsGateway, false, $ctx);

    return $result !== false
        ? ['success' => true, 'message' => 'SMS sent']
        : ['success' => false, 'message' => 'SMS gateway error'];
}

// ── Log Helper ────────────────────────────────────────────────────────────────
function logAlertAction($alertId, $action, $details = '', $performedBy = null) {
    global $conn;
    $stmt = $conn->prepare("INSERT INTO alert_logs (alert_id, action, details, performed_by) VALUES (?,?,?,?)");
    $stmt->bind_param('issi', $alertId, $action, $details, $performedBy);
    $stmt->execute();
}
