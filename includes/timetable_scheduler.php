<?php
/**
 * Timetable Auto-Session Scheduler
 *
 * Checks the schedules table for classes that start within the next
 * [window] minutes and have no attendance session yet for today.
 * Creates sessions automatically and optionally notifies teachers.
 *
 * Called by:
 *   - api/auto_session.php  (teacher dashboard polling, every 60s)
 *   - A cron job:  * * * * * php /path/to/attendance-system/api/auto_session.php >> /dev/null 2>&1
 *
 * Settings (from settings table, with defaults):
 *   auto_session_enabled     1
 *   auto_session_window_min  5     (create session N minutes before class starts)
 *   auto_present_minutes     10    (how long students are "present" not "late")
 *   auto_late_minutes        20    (session closes this many minutes after start)
 *   auto_session_method      qr
 */

require_once __DIR__ . '/../config/db.php';

function runTimetableScheduler(): array {
    global $conn;

    // ── Read settings ─────────────────────────────────────────────────────────
    $enabled = (bool)getAutoSetting('auto_session_enabled', '1');
    if (!$enabled) return ['skipped' => true, 'reason' => 'Auto-session is disabled in settings.'];

    $windowMin     = max(1,  (int)getAutoSetting('auto_session_window_min', '5'));
    $presentMins   = max(1,  (int)getAutoSetting('auto_present_minutes',    '10'));
    $lateMins      = max(2,  (int)getAutoSetting('auto_late_minutes',       '20'));
    $method        = getAutoSetting('auto_session_method', 'qr');

    $today         = date('l');   // e.g. "Monday"
    $nowTs         = time();
    $windowEnd     = date('H:i:s', $nowTs + $windowMin * 60);
    $nowTime       = date('H:i:s');
    $todayDate     = date('Y-m-d');

    // ── Find schedules starting within the window ─────────────────────────────
    $schedules = $conn->query("
        SELECT s.id AS schedule_id, s.class_id, s.start_time, s.end_time, s.room,
               c.teacher_id, c.name AS class_name, c.subject,
               u.name AS teacher_name, u.email AS teacher_email
        FROM schedules s
        JOIN classes c ON s.class_id = c.id
        JOIN users u ON c.teacher_id = u.id
        WHERE s.day_of_week = '$today'
          AND s.start_time BETWEEN '$nowTime' AND '$windowEnd'
          AND c.is_active = 1
    ")->fetch_all(MYSQLI_ASSOC);

    if (empty($schedules)) {
        return ['created' => 0, 'message' => 'No classes starting in the next ' . $windowMin . ' minutes.'];
    }

    $created = 0; $skipped = 0; $log = [];

    foreach ($schedules as $sch) {
        // Check if a session already exists for this class today
        $exists = $conn->query("
            SELECT id FROM attendance_sessions
            WHERE class_id = {$sch['class_id']}
              AND DATE(open_at) = '$todayDate'
            LIMIT 1
        ")->fetch_assoc();

        if ($exists) {
            $skipped++;
            $log[] = "Skipped [{$sch['class_name']}] — session already exists today.";
            continue;
        }

        // Compute time windows
        $openAt       = "$todayDate {$sch['start_time']}";
        $presentUntil = date('Y-m-d H:i:s', strtotime($openAt) + $presentMins * 60);
        $lateUntil    = date('Y-m-d H:i:s', strtotime($openAt) + $lateMins    * 60);

        $token = bin2hex(random_bytes(32));

        $stmt = $conn->prepare("
            INSERT INTO attendance_sessions
                (class_id, teacher_id, qr_token, method, status,
                 open_at, present_until, late_until, expires_at,
                 auto_created, schedule_id, room)
            VALUES (?,?,?,?,'not_started',?,?,?,?,1,?,?)
        ");
        $stmt->bind_param(
            'iisssssssis',
            $sch['class_id'],
            $sch['teacher_id'],
            $token,
            $method,
            $openAt,
            $presentUntil,
            $lateUntil,
            $lateUntil,
            $sch['schedule_id'],
            $sch['room']
        );

        if ($stmt->execute()) {
            $sessionId = $conn->insert_id;

            // Generate first dynamic QR token immediately
            generateInitialQrToken($sessionId);

            // In-app notification for teacher
            $notifMsg = "📋 Auto-session created: {$sch['class_name']} — {$sch['subject']}"
                      . " starts at " . substr($sch['start_time'], 0, 5)
                      . ($sch['room'] ? " in {$sch['room']}" : "")
                      . ". Open your QR page to begin.";

            $ns = $conn->prepare("INSERT INTO notifications (user_id, message) VALUES (?,?)");
            $ns->bind_param('is', $sch['teacher_id'], $notifMsg);
            $ns->execute();

            // Email teacher if email available
            if ($sch['teacher_email']) {
                require_once __DIR__ . '/mailer.php';
                $subject = "SmartAttend: Session ready — {$sch['class_name']}";
                $body    = "<p>Dear <strong>{$sch['teacher_name']}</strong>,</p>
                            <p>An attendance session has been automatically created for:</p>
                            <ul>
                                <li><strong>Class:</strong> {$sch['class_name']} — {$sch['subject']}</li>
                                <li><strong>Time:</strong> " . substr($sch['start_time'],0,5) . " – " . substr($sch['end_time'],0,5) . "</li>
                                " . ($sch['room'] ? "<li><strong>Room:</strong> {$sch['room']}</li>" : "") . "
                                <li><strong>Present window:</strong> $presentMins minutes</li>
                                <li><strong>Session closes:</strong> $lateMins minutes after start</li>
                            </ul>
                            <p>Open your teacher dashboard or QR page to manage attendance.</p>";
                sendMail($sch['teacher_email'], $sch['teacher_name'], $subject, $body);
            }

            $created++;
            $log[] = "Created session #{$sessionId} for [{$sch['class_name']}] at {$sch['start_time']}.";
        } else {
            $log[] = "Failed to create session for [{$sch['class_name']}]: " . $conn->error;
        }
    }

    return [
        'created' => $created,
        'skipped' => $skipped,
        'log'     => $log,
        'message' => "Created $created session(s), skipped $skipped (already existed).",
    ];
}

function generateInitialQrToken(int $sessionId): void {
    global $conn;
    $token      = bin2hex(random_bytes(32));
    $validFrom  = date('Y-m-d H:i:s');
    $validUntil = date('Y-m-d H:i:s', time() + 30);
    $stmt = $conn->prepare("INSERT INTO dynamic_qr_tokens (session_id, token, valid_from, valid_until) VALUES (?,?,?,?)");
    $stmt->bind_param('isss', $sessionId, $token, $validFrom, $validUntil);
    $stmt->execute();
}

function getAutoSetting(string $key, string $default = ''): string {
    global $conn;
    $stmt = $conn->prepare("SELECT setting_value FROM settings WHERE setting_key=?");
    $stmt->bind_param('s', $key);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    return $row ? $row['setting_value'] : $default;
}
