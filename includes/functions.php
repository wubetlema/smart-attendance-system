<?php
require_once __DIR__ . '/../config/db.php';

function generateQRToken() {
    return bin2hex(random_bytes(32));
}

function createSession($class_id, $teacher_id, $method = 'qr', $minutes = 15) {
    global $conn;
    $token = generateQRToken();
    $expires = date('Y-m-d H:i:s', strtotime("+{$minutes} minutes"));
    $stmt = $conn->prepare("INSERT INTO attendance_sessions (class_id, teacher_id, qr_token, method, expires_at) VALUES (?,?,?,?,?)");
    $stmt->bind_param('iisss', $class_id, $teacher_id, $token, $method, $expires);
    $stmt->execute();
    return ['id' => $conn->insert_id, 'token' => $token, 'expires_at' => $expires];
}

function markAttendance($session_id, $student_id, $status = 'present', $method = 'qr') {
    global $conn;
    // Check session is still valid
    $stmt = $conn->prepare("SELECT s.id, c.name AS class_name, c.subject FROM attendance_sessions s JOIN classes c ON s.class_id=c.id WHERE s.id=? AND s.expires_at > NOW()");
    $stmt->bind_param('i', $session_id);
    $stmt->execute();
    $session = $stmt->get_result()->fetch_assoc();
    if (!$session) return ['success' => false, 'message' => 'Session expired'];

    $stmt = $conn->prepare("INSERT IGNORE INTO attendance (session_id, student_id, status, method) VALUES (?,?,?,?)");
    $stmt->bind_param('iiss', $session_id, $student_id, $status, $method);
    $stmt->execute();

    if ($conn->affected_rows > 0) {
        // Send email notification if student has email
        $su = $conn->prepare("SELECT name, email FROM users WHERE id=?");
        $su->bind_param('i', $student_id);
        $su->execute();
        $student = $su->get_result()->fetch_assoc();
        if ($student && $student['email']) {
            require_once __DIR__ . '/mailer.php';
            notifyAttendance($student['email'], $student['name'], $session['class_name'], $status, date('d M Y'));
        }
        return ['success' => true, 'message' => 'Attendance marked'];
    }
    return ['success' => false, 'message' => 'Already marked'];
}

function getStudentAttendance($student_id) {
    global $conn;
    $stmt = $conn->prepare("
        SELECT a.status, a.method, a.marked_at,
               c.name AS class_name, c.subject,
               s.created_at AS session_date
        FROM attendance a
        JOIN attendance_sessions s ON a.session_id = s.id
        JOIN classes c ON s.class_id = c.id
        WHERE a.student_id = ?
        ORDER BY a.marked_at DESC
    ");
    $stmt->bind_param('i', $student_id);
    $stmt->execute();
    return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
}

function getClassAttendance($class_id) {
    global $conn;
    $stmt = $conn->prepare("
        SELECT u.name, u.student_id AS sid,
               a.status, a.method, a.marked_at,
               s.created_at AS session_date
        FROM attendance a
        JOIN users u ON a.student_id = u.id
        JOIN attendance_sessions s ON a.session_id = s.id
        WHERE s.class_id = ?
        ORDER BY s.created_at DESC, u.name ASC
    ");
    $stmt->bind_param('i', $class_id);
    $stmt->execute();
    return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
}

function logActivity($action, $details = '', $user_id = null) {
    global $conn;
    if (!$user_id && isset($_SESSION['user_id'])) $user_id = $_SESSION['user_id'];
    $ip   = $_SERVER['REMOTE_ADDR'] ?? '';
    $stmt = $conn->prepare("INSERT INTO activity_logs (user_id, action, details, ip_address) VALUES (?,?,?,?)");
    $stmt->bind_param('isss', $user_id, $action, $details, $ip);
    $stmt->execute();
}

function getSetting($key, $default = '') {
    global $conn;
    $stmt = $conn->prepare("SELECT setting_value FROM settings WHERE setting_key=?");
    $stmt->bind_param('s', $key);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    return $row ? $row['setting_value'] : $default;
}

// ===== Input Sanitization =====
function sanitize($value) {
    return htmlspecialchars(strip_tags(trim($value)), ENT_QUOTES, 'UTF-8');
}

function sanitizeInt($value, $default = 0) {
    return filter_var($value, FILTER_VALIDATE_INT) !== false ? (int)$value : $default;
}

function sanitizeEmail($value) {
    return filter_var(trim($value), FILTER_SANITIZE_EMAIL);
}

function sanitizeArray(array $data, array $fields) {
    $clean = [];
    foreach ($fields as $field) {
        $clean[$field] = isset($data[$field]) ? sanitize($data[$field]) : '';
    }
    return $clean;
}

// ===== Timestamp Validation =====

/**
 * Validate that an offline timestamp is not:
 * - In the future (clock manipulation)
 * - Too old (more than 2 hours — grace window)
 * - Malformed
 *
 * Returns ['valid' => bool, 'message' => string]
 */
function validateOfflineTimestamp($timestamp) {
    if (!$timestamp) {
        return ['valid' => false, 'message' => 'Missing timestamp.'];
    }

    $ts = strtotime($timestamp);
    if (!$ts) {
        return ['valid' => false, 'message' => 'Invalid timestamp format.'];
    }

    $now       = time();
    $maxFuture = 60;           // Allow 60 seconds clock drift
    $maxPast   = 2 * 3600;     // Max 2 hours old for offline records

    if ($ts > $now + $maxFuture) {
        return ['valid' => false, 'message' => 'Timestamp is in the future. Possible clock manipulation detected.'];
    }

    if ($ts < $now - $maxPast) {
        return ['valid' => false, 'message' => 'Offline record is too old (over 2 hours). Cannot sync.'];
    }

    return ['valid' => true, 'message' => 'Timestamp valid.'];
}

// ===== Server Priority Rules =====

/**
 * Priority order for attendance methods (higher = more trusted):
 *   manual (teacher)  = 4  — highest authority
 *   face              = 3
 *   qr                = 2
 *   offline_sync      = 1  — lowest, can be overridden
 *
 * Returns true if $newMethod can override $existingMethod.
 */
function canOverrideAttendance($existingMethod, $newMethod) {
    $priority = [
        'manual'       => 4,
        'face'         => 3,
        'qr'           => 2,
        'offline_sync' => 1,
    ];

    $existingPriority = $priority[$existingMethod] ?? 0;
    $newPriority      = $priority[$newMethod]      ?? 0;

    return $newPriority > $existingPriority;
}

/**
 * Mark attendance with server priority rules and timestamp validation.
 * Extends the base markAttendance() with override logic.
 *
 * @param int    $session_id
 * @param int    $student_id
 * @param string $status       present|absent|late
 * @param string $method       manual|face|qr|offline_sync
 * @param string $offlineAt    ISO timestamp from client (for offline records)
 * @param int    $markedBy     User ID of who is marking (teacher for manual)
 */
function markAttendanceWithPriority($session_id, $student_id, $status = 'present', $method = 'qr', $offlineAt = null, $markedBy = null) {
    global $conn;

    // 1. Validate offline timestamp if provided
    if ($offlineAt) {
        $tsCheck = validateOfflineTimestamp($offlineAt);
        if (!$tsCheck['valid']) {
            logActivity('attendance_rejected', "Reason: {$tsCheck['message']}, Session: $session_id", $student_id);
            return ['success' => false, 'message' => $tsCheck['message']];
        }
    }

    // 2. Check session validity (with 2hr grace for offline)
    $grace = ($method === 'offline_sync') ? "DATE_SUB(NOW(), INTERVAL 2 HOUR)" : "NOW()";
    $stmt  = $conn->prepare("SELECT s.id, c.name AS class_name, c.subject FROM attendance_sessions s JOIN classes c ON s.class_id=c.id WHERE s.id=? AND s.expires_at > $grace");
    $stmt->bind_param('i', $session_id);
    $stmt->execute();
    $session = $stmt->get_result()->fetch_assoc();

    if (!$session) {
        return ['success' => false, 'message' => 'Session expired or invalid.'];
    }

    // 3. Check if already marked
    $check = $conn->prepare("SELECT id, method, status FROM attendance WHERE session_id=? AND student_id=?");
    $check->bind_param('ii', $session_id, $student_id);
    $check->execute();
    $existing = $check->get_result()->fetch_assoc();

    if ($existing) {
        // 4. Apply server priority rules
        if (!canOverrideAttendance($existing['method'], $method)) {
            $msg = $existing['method'] === 'manual'
                ? "Already marked by teacher as '{$existing['status']}'. Teacher records take priority."
                : "Already marked via {$existing['method']} as '{$existing['status']}'. Cannot override with $method.";

            return [
                'success'          => false,
                'conflict'         => true,
                'message'          => $msg,
                'existing_status'  => $existing['status'],
                'existing_method'  => $existing['method']
            ];
        }

        // Higher priority method — update the existing record
        $editor = $markedBy ?? $_SESSION['user_id'] ?? null;
        $upd = $conn->prepare("UPDATE attendance SET status=?, method=?, edited_by=?, edited_at=NOW(), original_status=? WHERE id=?");
        $upd->bind_param('ssisi', $status, $method, $editor, $existing['status'], $existing['id']);
        $upd->execute();

        logActivity('attendance_override', "Session: $session_id, Student: $student_id, Old: {$existing['method']}/{$existing['status']}, New: $method/$status", $markedBy);

        return [
            'success'  => true,
            'override' => true,
            'message'  => "Attendance updated from '{$existing['status']}' ({$existing['method']}) to '$status' ($method)."
        ];
    }

    // 5. No existing record — insert fresh
    $stmt = $conn->prepare("INSERT INTO attendance (session_id, student_id, status, method) VALUES (?,?,?,?)");
    $stmt->bind_param('iiss', $session_id, $student_id, $status, $method);
    $stmt->execute();

    if ($conn->affected_rows > 0) {
        // Email notification
        $su = $conn->prepare("SELECT name, email FROM users WHERE id=?");
        $su->bind_param('i', $student_id);
        $su->execute();
        $student = $su->get_result()->fetch_assoc();
        if ($student && $student['email']) {
            require_once __DIR__ . '/mailer.php';
            notifyAttendance($student['email'], $student['name'], $session['class_name'], $status, date('d M Y'));
        }
        logActivity('attendance_marked', "Session: $session_id, Status: $status, Method: $method", $student_id);
        return ['success' => true, 'message' => 'Attendance marked successfully.'];
    }

    return ['success' => false, 'message' => 'Failed to save attendance.'];
}
