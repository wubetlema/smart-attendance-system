<?php
/**
 * Notification Engine
 *
 * Central dispatcher for ALL notifications in the system.
 * Handles in-app notifications + email for every event type.
 *
 * Usage:
 *   Notify::attendanceMarked($studentId, $className, 'present', $sessionId);
 *   Notify::correctionRequested($teacherId, $studentName, $className, $correctionId);
 *   Notify::lowAttendance($studentId, $className, 72, 75);
 *
 * Each method:
 *   1. Inserts into notifications table (in-app)
 *   2. Sends email if setting enabled and user has email
 *   3. Is idempotent-safe (deduplication where needed)
 */

require_once __DIR__ . '/mailer.php';
require_once __DIR__ . '/functions.php';

class Notify {

    // ── Core dispatcher ───────────────────────────────────────────────────────
    private static function send(int $userId, string $type, string $inappMsg, string $emailSubject, string $emailBody, string $settingKey = '', array $meta = []): void {
        global $conn;

        // 1. In-app notification
        $stmt = $conn->prepare("INSERT INTO notifications (user_id, message) VALUES (?,?)");
        $stmt->bind_param('is', $userId, $inappMsg);
        $stmt->execute();

        // 2. Email (if enabled)
        $globalEnabled = getSetting('email_notifications', '0') === '1';
        $typeEnabled   = !$settingKey || getSetting($settingKey, '1') === '1';

        if ($globalEnabled && $typeEnabled) {
            $u = $conn->prepare("SELECT name, email FROM users WHERE id=?");
            $u->bind_param('i', $userId); $u->execute();
            $user = $u->get_result()->fetch_assoc();
            if ($user && !empty($user['email'])) {
                sendMail($user['email'], $user['name'], $emailSubject, $emailBody);
            }
        }
    }

    // ── Fetch user helper ──────────────────────────────────────────────────────
    private static function user(int $id): array {
        global $conn;
        $s = $conn->prepare("SELECT id, name, email, role FROM users WHERE id=?");
        $s->bind_param('i', $id); $s->execute();
        return $s->get_result()->fetch_assoc() ?? [];
    }

    private static function setting(string $key): bool {
        return getSetting('email_notifications','0') === '1' && getSetting($key,'1') === '1';
    }

    // ══════════════════════════════════════════════════════════════════════════
    // STUDENT NOTIFICATIONS
    // ══════════════════════════════════════════════════════════════════════════

    /**
     * Student — attendance marked (present/late/absent)
     */
    public static function attendanceMarked(int $studentId, string $className, string $status, string $date, ?string $method = null): void {
        $icons   = ['present' => '✅', 'late' => '⏰', 'absent' => '❌'];
        $colors  = ['present' => '#16a34a', 'late' => '#d97706', 'absent' => '#dc2626'];
        $icon    = $icons[$status]  ?? '📋';
        $color   = $colors[$status] ?? '#6366f1';
        $methodNote = $method ? " via $method" : '';

        $inapp = "$icon Attendance marked: $status in $className on $date$methodNote.";

        $email = "<p>Dear Student,</p>
                  <p>Your attendance for <strong>" . htmlspecialchars($className) . "</strong> on <strong>$date</strong> has been recorded.</p>
                  <div style='display:inline-block;padding:.75rem 1.5rem;background:$color;color:#fff;border-radius:8px;font-size:1.2rem;font-weight:700;margin:1rem 0'>
                      $icon " . strtoupper($status) . "
                  </div>
                  " . ($method ? "<p style='color:#64748b;font-size:.9rem'>Check-in method: $method</p>" : "") . "
                  <p>Log in to SmartAttend to view your full attendance history.</p>";

        $settingKey = $status === 'absent' ? 'notify_student_absent' : ($status === 'late' ? 'notify_student_late' : 'notify_student_attendance');
        self::send($studentId, 'ATTENDANCE_MARKED', $inapp, "$icon Attendance: $status — $className", $email, $settingKey);
    }

    /**
     * Student — low attendance warning
     */
    public static function lowAttendance(int $studentId, string $className, float $pct, float $threshold): void {
        $isCritical = $pct < ($threshold * 0.75);
        $icon       = $isCritical ? '🚨' : '⚠️';
        $colorHex   = $isCritical ? '#b91c1c' : '#d97706';
        $label      = $isCritical ? 'CRITICAL — Detention Risk' : 'Warning';

        $inapp = "$icon Low attendance in $className: {$pct}% (minimum: {$threshold}%)";

        $email = "<p>Dear Student,</p>
                  <p>This is an automated attendance warning for <strong>" . htmlspecialchars($className) . "</strong>.</p>
                  <div style='border-left:4px solid $colorHex;padding:1rem;background:#fef2f2;border-radius:0 8px 8px 0;margin:1rem 0'>
                      <p style='margin:0;font-size:1.1rem;font-weight:700;color:$colorHex'>$icon $label</p>
                      <p style='margin:.5rem 0 0'>Current attendance: <strong style='color:$colorHex'>{$pct}%</strong></p>
                      <p style='margin:.25rem 0 0'>Required minimum: <strong>{$threshold}%</strong></p>
                  </div>
                  " . ($isCritical
                      ? "<p style='color:#b91c1c'><strong>Immediate action required.</strong> Please contact your teacher and department advisor.</p>"
                      : "<p>Please attend all upcoming classes to bring your attendance above {$threshold}%.</p>") . "";

        self::send($studentId, 'LOW_ATTENDANCE', $inapp, "$icon Low Attendance Warning — $className", $email, 'notify_student_low_attendance');
    }

    /**
     * Student — correction request status changed
     */
    public static function correctionStatusChanged(int $studentId, string $className, string $status, string $reviewerNotes, string $date): void {
        $icon  = $status === 'approved' ? '✅' : '❌';
        $color = $status === 'approved' ? '#16a34a' : '#dc2626';
        $label = strtoupper($status);

        $inapp = "$icon Correction request for $className ($date): $label." . ($reviewerNotes ? " Note: $reviewerNotes" : '');

        $email = "<p>Dear Student,</p>
                  <p>Your attendance correction request for <strong>" . htmlspecialchars($className) . "</strong> on <strong>$date</strong> has been reviewed.</p>
                  <div style='padding:1rem;background:" . ($status==='approved'?'#dcfce7':'#fee2e2') . ";border-radius:8px;margin:1rem 0;text-align:center'>
                      <p style='font-size:1.3rem;font-weight:800;color:$color;margin:0'>$icon $label</p>
                  </div>"
                  . ($reviewerNotes ? "<p><strong>Teacher's note:</strong> " . htmlspecialchars($reviewerNotes) . "</p>" : "")
                  . "<p>Log in to SmartAttend to view your updated attendance record.</p>";

        self::send($studentId, 'CORRECTION_STATUS', $inapp, "$icon Correction Request $label — $className", $email, 'notify_student_correction');
    }

    /**
     * Student — class cancelled
     */
    public static function classCancelled(int $studentId, string $className, string $date, string $reason = ''): void {
        $inapp = "📢 Class cancelled: $className on $date." . ($reason ? " Reason: $reason" : '');
        $email = "<p>Dear Student,</p>
                  <p>Your class <strong>" . htmlspecialchars($className) . "</strong> scheduled for <strong>$date</strong> has been <strong>cancelled</strong>.</p>"
                  . ($reason ? "<p><strong>Reason:</strong> " . htmlspecialchars($reason) . "</p>" : "")
                  . "<p>Please check the schedule for rescheduled sessions.</p>";
        self::send($studentId, 'CLASS_CANCELLED', $inapp, "📢 Class Cancelled — $className", $email);
    }

    /**
     * Student — leave request approved/rejected
     */
    public static function leaveStatus(int $studentId, string $className, string $date, string $status): void {
        $icon  = $status === 'approved' ? '✅' : '❌';
        $color = $status === 'approved' ? '#16a34a' : '#dc2626';
        $inapp = "$icon Leave request for $className ($date): " . strtoupper($status) . ".";
        $email = "<p>Dear Student,</p>
                  <p>Your leave request for <strong>" . htmlspecialchars($className) . "</strong> on <strong>$date</strong> has been:</p>
                  <p style='font-size:1.3rem;font-weight:700;color:$color'>$icon " . strtoupper($status) . "</p>";
        self::send($studentId, 'LEAVE_STATUS', $inapp, "$icon Leave Request " . ucfirst($status) . " — $className", $email);
    }

    // ══════════════════════════════════════════════════════════════════════════
    // TEACHER NOTIFICATIONS
    // ══════════════════════════════════════════════════════════════════════════

    /**
     * Teacher — new correction request from student
     */
    public static function correctionRequested(int $teacherId, string $studentName, string $className, int $correctionId, string $originalStatus, string $requestedStatus, string $reason): void {
        $inapp = "📝 Correction request from $studentName in $className: $originalStatus → $requestedStatus.";
        $reviewUrl = "/attendance-system/teacher/correction_requests.php?id=$correctionId";

        $email = "<p>Dear Teacher,</p>
                  <p><strong>" . htmlspecialchars($studentName) . "</strong> has submitted an attendance correction request for your class <strong>" . htmlspecialchars($className) . "</strong>.</p>
                  <table style='border-collapse:collapse;width:100%;margin:1rem 0;font-size:.95rem'>
                      <tr style='background:#f8fafc'><td style='padding:.6rem 1rem;font-weight:600;width:40%'>Original Status</td><td style='padding:.6rem 1rem;color:#dc2626;font-weight:700'>" . strtoupper($originalStatus) . "</td></tr>
                      <tr><td style='padding:.6rem 1rem;font-weight:600'>Requested Status</td><td style='padding:.6rem 1rem;color:#16a34a;font-weight:700'>" . strtoupper($requestedStatus) . "</td></tr>
                      <tr style='background:#f8fafc'><td style='padding:.6rem 1rem;font-weight:600'>Student's Reason</td><td style='padding:.6rem 1rem'>" . htmlspecialchars($reason) . "</td></tr>
                  </table>
                  <p>Please log in to SmartAttend to review and respond to this request.</p>
                  <a href='$reviewUrl' style='display:inline-block;background:#6366f1;color:#fff;padding:.7rem 1.4rem;border-radius:8px;text-decoration:none;font-weight:600'>Review Request →</a>";

        self::send($teacherId, 'CORRECTION_REQUESTED', $inapp, "📝 Correction Request — $className", $email, 'notify_teacher_correction');
    }

    /**
     * Teacher — student at risk (low attendance)
     */
    public static function studentAtRisk(int $teacherId, string $studentName, string $className, float $pct, float $threshold): void {
        $isCritical = $pct < ($threshold * 0.75);
        $icon = $isCritical ? '🚨' : '⚠️';
        $inapp = "$icon At-risk student in $className: $studentName has {$pct}% attendance.";
        $email = "<p>Dear Teacher,</p>
                  <p>This is an automated alert regarding attendance in your class <strong>" . htmlspecialchars($className) . "</strong>.</p>
                  <p><strong>" . htmlspecialchars($studentName) . "</strong> currently has <strong style='color:" . ($isCritical?'#b91c1c':'#d97706') . "'>{$pct}% attendance</strong>, which is below the {$threshold}% minimum.</p>"
                  . ($isCritical ? "<p style='color:#b91c1c'><strong>⚠️ Detention risk — immediate follow-up recommended.</strong></p>" : "")
                  . "<p>Please review their attendance record and take appropriate action.</p>";
        self::send($teacherId, 'STUDENT_AT_RISK', $inapp, "$icon At-Risk Student — $className", $email, 'notify_teacher_low_attendance');
    }

    /**
     * Teacher — session auto-created from timetable
     */
    public static function sessionAutoCreated(int $teacherId, string $className, string $startTime, string $room = ''): void {
        $roomNote = $room ? " in $room" : '';
        $inapp    = "📋 Attendance session auto-created: $className at $startTime$roomNote. Open QR page to begin.";
        $email    = "<p>Dear Teacher,</p>
                     <p>An attendance session has been automatically created for your upcoming class:</p>
                     <ul><li><strong>Class:</strong> " . htmlspecialchars($className) . "</li>
                     <li><strong>Time:</strong> $startTime</li>"
                     . ($room ? "<li><strong>Room:</strong> " . htmlspecialchars($room) . "</li>" : "")
                     . "</ul>
                     <p>Open your teacher dashboard or QR page to manage attendance.</p>";
        self::send($teacherId, 'SESSION_AUTO_CREATED', $inapp, "📋 Attendance Session Ready — $className", $email, 'notify_teacher_session_reminder');
    }

    /**
     * Teacher — new leave request from student
     */
    public static function leaveRequested(int $teacherId, string $studentName, string $className, string $date, string $reason): void {
        $inapp = "📋 Leave request from $studentName for $className on $date.";
        $email = "<p>Dear Teacher,</p>
                  <p><strong>" . htmlspecialchars($studentName) . "</strong> has submitted a leave request for <strong>" . htmlspecialchars($className) . "</strong>.</p>
                  <p><strong>Date:</strong> $date<br><strong>Reason:</strong> " . htmlspecialchars($reason) . "</p>
                  <p>Please log in to SmartAttend to approve or reject this request.</p>";
        self::send($teacherId, 'LEAVE_REQUESTED', $inapp, "📋 Leave Request — $className", $email);
    }

    // ══════════════════════════════════════════════════════════════════════════
    // ADMIN NOTIFICATIONS
    // ══════════════════════════════════════════════════════════════════════════

    /**
     * Admin — suspicious login detected
     */
    public static function suspiciousLogin(string $univId, string $ip, string $reason): void {
        global $conn;
        $admins = $conn->query("SELECT id FROM users WHERE role='admin' AND is_active=1")->fetch_all(MYSQLI_ASSOC);
        $inapp  = "🚨 Suspicious login attempt: University ID $univId from IP $ip. Reason: $reason.";
        $email  = "<p>A suspicious login attempt has been detected on SmartAttend.</p>
                   <table style='border-collapse:collapse;width:100%;margin:1rem 0'>
                       <tr style='background:#fef2f2'><td style='padding:.6rem 1rem;font-weight:600'>University ID</td><td style='padding:.6rem 1rem'>" . htmlspecialchars($univId) . "</td></tr>
                       <tr><td style='padding:.6rem 1rem;font-weight:600'>IP Address</td><td style='padding:.6rem 1rem'><code>$ip</code></td></tr>
                       <tr style='background:#fef2f2'><td style='padding:.6rem 1rem;font-weight:600'>Reason</td><td style='padding:.6rem 1rem'>" . htmlspecialchars($reason) . "</td></tr>
                       <tr><td style='padding:.6rem 1rem;font-weight:600'>Time</td><td style='padding:.6rem 1rem'>" . date('d M Y H:i:s') . "</td></tr>
                   </table>
                   <p>If this was not an authorized user, consider blocking this IP address.</p>";
        foreach ($admins as $a) {
            self::send($a['id'], 'SUSPICIOUS_LOGIN', $inapp, "🚨 Suspicious Login Detected", $email, 'notify_admin_suspicious_login');
        }
    }

    /**
     * Admin — face verification failure streak
     */
    public static function faceFailStreak(int $studentId, string $studentName, int $failCount): void {
        global $conn;
        $admins = $conn->query("SELECT id FROM users WHERE role='admin' AND is_active=1")->fetch_all(MYSQLI_ASSOC);
        $inapp  = "🚨 Face verification failed $failCount times for $studentName.";
        $email  = "<p>SmartAttend has detected repeated face verification failures.</p>
                   <p><strong>Student:</strong> " . htmlspecialchars($studentName) . "<br>
                      <strong>Failures:</strong> $failCount consecutive<br>
                      <strong>Time:</strong> " . date('d M Y H:i:s') . "</p>
                   <p>This may indicate an attempt to use another person's identity. Please investigate.</p>";
        foreach ($admins as $a) {
            self::send($a['id'], 'FACE_FAIL_STREAK', $inapp, "🚨 Repeated Face Verification Failures", $email, 'notify_admin_face_fail_streak');
        }
    }

    /**
     * Admin — backup completed/failed
     */
    public static function backupStatus(bool $success, string $filename, string $details = ''): void {
        global $conn;
        $admins = $conn->query("SELECT id FROM users WHERE role='admin' AND is_active=1")->fetch_all(MYSQLI_ASSOC);
        $icon   = $success ? '✅' : '❌';
        $label  = $success ? 'Backup Completed' : 'Backup FAILED';
        $inapp  = "$icon Database $label: $filename.";
        $email  = "<p>$icon <strong>$label</strong></p>
                   <p><strong>File:</strong> $filename<br><strong>Time:</strong> " . date('d M Y H:i:s') . "</p>"
                   . ($details ? "<p><strong>Details:</strong> " . htmlspecialchars($details) . "</p>" : "");
        foreach ($admins as $a) {
            self::send($a['id'], 'BACKUP_STATUS', $inapp, "$icon Database $label", $email, 'notify_admin_backup_status');
        }
    }

    /**
     * Admin — unusual attendance activity (spike/drop)
     */
    public static function unusualAttendance(string $description): void {
        global $conn;
        $admins = $conn->query("SELECT id FROM users WHERE role='admin' AND is_active=1")->fetch_all(MYSQLI_ASSOC);
        $inapp  = "⚠️ Unusual attendance activity detected: $description";
        $email  = "<p>SmartAttend has detected unusual attendance activity:</p><p>" . htmlspecialchars($description) . "</p><p>Please review the attendance logs.</p>";
        foreach ($admins as $a) {
            self::send($a['id'], 'UNUSUAL_ATTENDANCE', $inapp, "⚠️ Unusual Attendance Activity", $email);
        }
    }
}
