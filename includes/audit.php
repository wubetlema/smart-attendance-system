<?php
/**
 * Centralized Audit Logger
 *
 * Every important action in the system goes through Audit::log().
 * Records who, what, when, where, old value, new value, reason.
 *
 * Usage:
 *   Audit::log('MARK_ATTENDANCE', 'attendance', $attendanceId, [
 *       'old' => ['status' => 'absent'],
 *       'new' => ['status' => 'present'],
 *       'reason' => 'QR check-in',
 *   ]);
 *
 * Predefined action constants for consistency across modules.
 */

class Audit {

    // ── Auth ──────────────────────────────────────────────────────────────────
    const LOGIN                   = 'LOGIN';
    const LOGOUT                  = 'LOGOUT';
    const LOGIN_FAILED            = 'LOGIN_FAILED';
    const LOGIN_BLOCKED           = 'LOGIN_BLOCKED';
    const PASSWORD_CHANGED        = 'PASSWORD_CHANGED';
    const PASSWORD_RESET          = 'PASSWORD_RESET';
    const FORCE_LOGOUT            = 'FORCE_LOGOUT';

    // ── User Management ────────────────────────────────────────────────────────
    const CREATE_USER             = 'CREATE_USER';
    const UPDATE_USER             = 'UPDATE_USER';
    const DELETE_USER             = 'DELETE_USER';
    const DEACTIVATE_USER         = 'DEACTIVATE_USER';
    const ACTIVATE_USER           = 'ACTIVATE_USER';
    const CSV_IMPORT_USERS        = 'CSV_IMPORT_USERS';

    // ── Class Management ──────────────────────────────────────────────────────
    const CREATE_CLASS            = 'CREATE_CLASS';
    const UPDATE_CLASS            = 'UPDATE_CLASS';
    const DELETE_CLASS            = 'DELETE_CLASS';
    const ENROLL_STUDENT          = 'ENROLL_STUDENT';
    const DROP_STUDENT            = 'DROP_STUDENT';
    const TRANSFER_STUDENT        = 'TRANSFER_STUDENT';

    // ── Session Management ────────────────────────────────────────────────────
    const CREATE_SESSION          = 'CREATE_SESSION';
    const CLOSE_SESSION           = 'CLOSE_SESSION';
    const AUTO_CREATE_SESSION     = 'AUTO_CREATE_SESSION';

    // ── Attendance ────────────────────────────────────────────────────────────
    const MARK_ATTENDANCE         = 'MARK_ATTENDANCE';
    const EDIT_ATTENDANCE         = 'EDIT_ATTENDANCE';
    const DELETE_ATTENDANCE       = 'DELETE_ATTENDANCE';
    const QR_CHECKIN              = 'QR_CHECKIN';
    const FACE_CHECKIN            = 'FACE_CHECKIN';
    const FACE_REGISTER           = 'FACE_REGISTER';
    const FACE_VERIFY_FAILED      = 'FACE_VERIFY_FAILED';
    const OFFLINE_SYNC            = 'OFFLINE_SYNC';
    const LOCATION_REJECTED       = 'LOCATION_REJECTED';

    // ── Correction Workflow ───────────────────────────────────────────────────
    const CORRECTION_REQUESTED    = 'CORRECTION_REQUESTED';
    const CORRECTION_APPROVED     = 'CORRECTION_APPROVED';
    const CORRECTION_REJECTED     = 'CORRECTION_REJECTED';
    const CORRECTION_CANCELLED    = 'CORRECTION_CANCELLED';
    const CORRECTION_ADMIN_OVERRIDE = 'CORRECTION_ADMIN_OVERRIDE';

    // ── Leave Management ──────────────────────────────────────────────────────
    const LEAVE_REQUESTED         = 'LEAVE_REQUESTED';
    const LEAVE_APPROVED          = 'LEAVE_APPROVED';
    const LEAVE_REJECTED          = 'LEAVE_REJECTED';

    // ── Reports ───────────────────────────────────────────────────────────────
    const GENERATE_REPORT         = 'GENERATE_REPORT';
    const EXPORT_CSV              = 'EXPORT_CSV';

    // ── System ────────────────────────────────────────────────────────────────
    const BACKUP_DATABASE         = 'BACKUP_DATABASE';
    const RESTORE_DATABASE        = 'RESTORE_DATABASE';
    const SETTINGS_CHANGED        = 'SETTINGS_CHANGED';

    // ── Security ──────────────────────────────────────────────────────────────
    const SUSPICIOUS_LOGIN        = 'SUSPICIOUS_LOGIN';
    const CSRF_FAILURE            = 'CSRF_FAILURE';
    const UNAUTHORIZED_ACCESS     = 'UNAUTHORIZED_ACCESS';
    const FACE_FAIL_STREAK        = 'FACE_FAIL_STREAK';

    // ── Category map ──────────────────────────────────────────────────────────
    private static array $categoryMap = [
        'LOGIN'                   => 'auth',
        'LOGOUT'                  => 'auth',
        'LOGIN_FAILED'            => 'auth',
        'LOGIN_BLOCKED'           => 'auth',
        'PASSWORD_CHANGED'        => 'auth',
        'PASSWORD_RESET'          => 'auth',
        'FORCE_LOGOUT'            => 'auth',
        'CREATE_USER'             => 'user_management',
        'UPDATE_USER'             => 'user_management',
        'DELETE_USER'             => 'user_management',
        'DEACTIVATE_USER'         => 'user_management',
        'ACTIVATE_USER'           => 'user_management',
        'CSV_IMPORT_USERS'        => 'user_management',
        'CREATE_CLASS'            => 'class_management',
        'UPDATE_CLASS'            => 'class_management',
        'DELETE_CLASS'            => 'class_management',
        'ENROLL_STUDENT'          => 'class_management',
        'DROP_STUDENT'            => 'class_management',
        'TRANSFER_STUDENT'        => 'class_management',
        'CREATE_SESSION'          => 'session_management',
        'CLOSE_SESSION'           => 'session_management',
        'AUTO_CREATE_SESSION'     => 'session_management',
        'MARK_ATTENDANCE'         => 'attendance',
        'EDIT_ATTENDANCE'         => 'attendance',
        'DELETE_ATTENDANCE'       => 'attendance',
        'QR_CHECKIN'              => 'attendance',
        'FACE_CHECKIN'            => 'attendance',
        'FACE_REGISTER'           => 'attendance',
        'FACE_VERIFY_FAILED'      => 'security',
        'OFFLINE_SYNC'            => 'attendance',
        'LOCATION_REJECTED'       => 'security',
        'CORRECTION_REQUESTED'    => 'correction',
        'CORRECTION_APPROVED'     => 'correction',
        'CORRECTION_REJECTED'     => 'correction',
        'CORRECTION_CANCELLED'    => 'correction',
        'CORRECTION_ADMIN_OVERRIDE'=> 'correction',
        'LEAVE_REQUESTED'         => 'attendance',
        'LEAVE_APPROVED'          => 'attendance',
        'LEAVE_REJECTED'          => 'attendance',
        'GENERATE_REPORT'         => 'report',
        'EXPORT_CSV'              => 'report',
        'BACKUP_DATABASE'         => 'system',
        'RESTORE_DATABASE'        => 'system',
        'SETTINGS_CHANGED'        => 'system',
        'SUSPICIOUS_LOGIN'        => 'security',
        'CSRF_FAILURE'            => 'security',
        'UNAUTHORIZED_ACCESS'     => 'security',
        'FACE_FAIL_STREAK'        => 'security',
    ];

    private static array $severityMap = [
        'LOGIN_FAILED'            => 'warning',
        'LOGIN_BLOCKED'           => 'warning',
        'FORCE_LOGOUT'            => 'warning',
        'DELETE_USER'             => 'warning',
        'DELETE_CLASS'            => 'warning',
        'DELETE_ATTENDANCE'       => 'warning',
        'RESTORE_DATABASE'        => 'critical',
        'SUSPICIOUS_LOGIN'        => 'critical',
        'CSRF_FAILURE'            => 'error',
        'UNAUTHORIZED_ACCESS'     => 'error',
        'FACE_VERIFY_FAILED'      => 'warning',
        'FACE_FAIL_STREAK'        => 'error',
        'LOCATION_REJECTED'       => 'warning',
    ];

    /**
     * Log an audit event.
     *
     * @param string   $action     One of the Audit::* constants
     * @param string   $targetType e.g. 'attendance', 'user', 'class'
     * @param int|null $targetId   ID of the record being acted on
     * @param array    $context    Keys: old, new, reason, description, user_id
     */
    public static function log(
        string $action,
        string $targetType = '',
        ?int   $targetId   = null,
        array  $context    = []
    ): void {
        global $conn;
        if (!isset($conn)) return;

        // Resolve actor
        $userId   = $context['user_id'] ?? ($_SESSION['user_id'] ?? null);
        $userName = '';
        $userRole = $_SESSION['role'] ?? '';

        if ($userId) {
            $u = $conn->prepare("SELECT name, role FROM users WHERE id=?");
            $u->bind_param('i', $userId);
            $u->execute();
            $uRow = $u->get_result()->fetch_assoc();
            $userName = $uRow['name'] ?? '';
            $userRole = $uRow['role'] ?? $userRole;
        }

        // Device info
        $ua      = $_SERVER['HTTP_USER_AGENT'] ?? '';
        $ip      = $_SERVER['REMOTE_ADDR']     ?? '';
        $device  = self::detectDevice($ua);

        $category    = self::$categoryMap[$action]  ?? 'system';
        $severity    = self::$severityMap[$action]   ?? 'info';
        $description = $context['description']       ?? '';
        $reason      = $context['reason']             ?? '';
        $old         = isset($context['old']) ? json_encode($context['old']) : null;
        $new         = isset($context['new']) ? json_encode($context['new']) : null;

        $stmt = $conn->prepare("
            INSERT INTO audit_log
                (user_id, user_name, user_role, action, category, target_type, target_id,
                 old_value, new_value, reason, description, ip_address, user_agent,
                 device_type, browser, severity)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
        ");
        $stmt->bind_param(
            'isssssijjssssss s',
            $userId, $userName, $userRole, $action, $category,
            $targetType, $targetId, $old, $new, $reason, $description,
            $ip, $ua, $device['type'], $device['browser'], $severity
        );
        // Use string binding for JSON
        $stmt = $conn->prepare("
            INSERT INTO audit_log
                (user_id, user_name, user_role, action, category, target_type, target_id,
                 old_value, new_value, reason, description, ip_address, user_agent,
                 device_type, browser, severity)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
        ");
        $stmt->bind_param(
            'issssssississss',
            $userId, $userName, $userRole, $action, $category,
            $targetType, $targetId, $old, $new, $reason, $description,
            $ip, $ua, $device['type'], $device['browser']
        );
        // Severity needs separate update due to ENUM
        if ($stmt->execute()) {
            $lid = $conn->insert_id;
            $conn->query("UPDATE audit_log SET severity='$severity' WHERE id=$lid");
        }
    }

    private static function detectDevice(string $ua): array {
        $ua = strtolower($ua);
        $type = 'desktop';
        if (preg_match('/mobile|android|iphone|ipod/', $ua))      $type = 'mobile';
        elseif (preg_match('/ipad|tablet/', $ua))                  $type = 'tablet';

        $browser = 'Unknown';
        if (str_contains($ua, 'edg/'))         $browser = 'Edge';
        elseif (str_contains($ua, 'chrome/'))  $browser = 'Chrome';
        elseif (str_contains($ua, 'firefox/')) $browser = 'Firefox';
        elseif (str_contains($ua, 'safari/'))  $browser = 'Safari';

        return ['type' => $type, 'browser' => $browser];
    }

    /**
     * Quick helpers for common actions
     */
    public static function loginOk(int $userId): void {
        self::log(self::LOGIN, 'user', $userId, ['description' => 'Successful login']);
    }

    public static function loginFailed(string $univId, string $ip): void {
        self::log(self::LOGIN_FAILED, 'user', null, [
            'description' => "Failed login attempt for ID: $univId",
            'reason'      => 'Invalid credentials',
            'user_id'     => null,
        ]);
    }

    public static function attendanceMarked(int $sessionId, int $studentId, string $status, string $method): void {
        self::log(self::MARK_ATTENDANCE, 'attendance', null, [
            'new'         => ['session_id' => $sessionId, 'student_id' => $studentId, 'status' => $status, 'method' => $method],
            'description' => "Attendance marked: $status via $method",
            'user_id'     => $studentId,
        ]);
    }

    public static function correctionApproved(int $correctionId, int $studentId, string $old, string $new, int $reviewerId): void {
        self::log(self::CORRECTION_APPROVED, 'attendance_correction', $correctionId, [
            'old'         => ['status' => $old],
            'new'         => ['status' => $new],
            'description' => "Correction approved: $old → $new",
            'user_id'     => $reviewerId,
        ]);
    }

    public static function correctionRejected(int $correctionId, int $reviewerId, string $notes): void {
        self::log(self::CORRECTION_REJECTED, 'attendance_correction', $correctionId, [
            'reason'      => $notes,
            'description' => 'Correction request rejected',
            'user_id'     => $reviewerId,
        ]);
    }
}
