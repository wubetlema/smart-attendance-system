<?php
/**
 * Session Manager — multi-device session tracking, force-logout, device history
 */
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/../config/db.php';

// ── Device Detection ──────────────────────────────────────────────────────────
function detectDevice($ua) {
    $ua = strtolower($ua);
    $type = 'desktop';
    if (preg_match('/mobile|android|iphone|ipod|blackberry|opera mini|iemobile/', $ua)) $type = 'mobile';
    elseif (preg_match('/ipad|tablet|kindle|playbook/', $ua)) $type = 'tablet';

    $browser = 'Unknown';
    if (str_contains($ua, 'edg/'))         $browser = 'Edge';
    elseif (str_contains($ua, 'opr/'))     $browser = 'Opera';
    elseif (str_contains($ua, 'chrome/'))  $browser = 'Chrome';
    elseif (str_contains($ua, 'firefox/')) $browser = 'Firefox';
    elseif (str_contains($ua, 'safari/'))  $browser = 'Safari';

    $os = 'Unknown';
    if (str_contains($ua, 'windows'))      $os = 'Windows';
    elseif (str_contains($ua, 'android'))  $os = 'Android';
    elseif (str_contains($ua, 'iphone') || str_contains($ua, 'ipad')) $os = 'iOS';
    elseif (str_contains($ua, 'mac'))      $os = 'macOS';
    elseif (str_contains($ua, 'linux'))    $os = 'Linux';

    return compact('type', 'browser', 'os');
}

// ── Create Session Record on Login ────────────────────────────────────────────
function createSessionRecord($userId) {
    global $conn;
    if (!$conn || $conn->connect_errno) require_once __DIR__ . '/../config/db.php';

    // Check table exists — skip gracefully if migration hasn't run yet
    $tableCheck = $conn->query("SHOW TABLES LIKE 'user_sessions'");
    if (!$tableCheck || $tableCheck->num_rows === 0) {
        // Table doesn't exist yet — generate a token and return without DB write
        $token = bin2hex(random_bytes(32));
        $_SESSION['session_token'] = $token;
        return $token;
    }

    $token     = bin2hex(random_bytes(32));
    $phpSessId = session_id();
    $ip        = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    $ua        = $_SERVER['HTTP_USER_AGENT'] ?? '';
    $device    = detectDevice($ua);

    $stmt = $conn->prepare("
        INSERT INTO user_sessions (user_id, session_token, php_session_id, ip_address, user_agent, device_type, browser, os)
        VALUES (?,?,?,?,?,?,?,?)
    ");
    $stmt->bind_param('isssssss', $userId, $token, $phpSessId, $ip, $ua, $device['type'], $device['browser'], $device['os']);
    $stmt->execute();

    // Log to device history
    logDeviceEvent($userId, 'login', $token, $ip, $ua, $device);

    // Store token in session and cookie (30-day persistent)
    $_SESSION['session_token'] = $token;
    setcookie('sa_session_token', $token, time() + 86400 * 30, '/', '', false, true);

    return $token;
}

// ── Validate Session on Every Request ────────────────────────────────────────
function validateSession() {
    global $conn;

    if (!isset($_SESSION['user_id'])) return true; // not logged in, skip

    $token = $_SESSION['session_token'] ?? ($_COOKIE['sa_session_token'] ?? null);
    if (!$token) return true;

    // Ensure DB connection — may not be loaded yet depending on call order
    if (!$conn || $conn->connect_errno) {
        require_once __DIR__ . '/../config/db.php';
    }
    if (!$conn || $conn->connect_errno) return true; // DB unavailable, skip silently

    // Check the table exists before querying (graceful degradation on fresh installs)
    $tableCheck = $conn->query("SHOW TABLES LIKE 'user_sessions'");
    if (!$tableCheck || $tableCheck->num_rows === 0) return true;

    $stmt = $conn->prepare("SELECT id, force_logout, is_active FROM user_sessions WHERE session_token=? AND user_id=?");
    $stmt->bind_param('si', $token, $_SESSION['user_id']);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();

    if (!$row || !$row['is_active']) return true; // session not found, let normal auth handle it

    if ($row['force_logout']) {
        // This session was force-killed — log out immediately
        $ip = $_SERVER['REMOTE_ADDR'] ?? '';
        $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
        logDeviceEvent($_SESSION['user_id'], 'force_logout', $token, $ip, $ua, detectDevice($ua));

        $conn->query("UPDATE user_sessions SET is_active=0 WHERE session_token='".  $conn->real_escape_string($token)."'");
        session_destroy();
        setcookie('sa_session_token', '', time() - 3600, '/');
        header('Location: /attendance-system/auth/login.php?error=force_logout');
        exit;
    }

    // Update last activity
    $conn->query("UPDATE user_sessions SET last_activity=NOW() WHERE session_token='".  $conn->real_escape_string($token)."'");
    return true;
}

// ── Terminate a Specific Session (force logout) ───────────────────────────────
function forceLogoutSession($sessionId, $performedBy) {
    global $conn;

    $stmt = $conn->prepare("SELECT user_id, session_token, ip_address, user_agent FROM user_sessions WHERE id=?");
    $stmt->bind_param('i', $sessionId); $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    if (!$row) return false;

    $conn->query("UPDATE user_sessions SET force_logout=1, is_active=0 WHERE id=$sessionId");
    logDeviceEvent($row['user_id'], 'force_logout', $row['session_token'], $row['ip_address'], $row['user_agent'], detectDevice($row['user_agent']));

    return true;
}

// ── Terminate All Other Sessions for a User ───────────────────────────────────
function forceLogoutAllOther($userId, $keepToken) {
    global $conn;

    $esc = $conn->real_escape_string($keepToken);
    $sessions = $conn->query("SELECT id, session_token, ip_address, user_agent FROM user_sessions WHERE user_id=$userId AND session_token!='$esc' AND is_active=1")->fetch_all(MYSQLI_ASSOC);

    foreach ($sessions as $s) {
        $conn->query("UPDATE user_sessions SET force_logout=1, is_active=0 WHERE id={$s['id']}");
        logDeviceEvent($userId, 'force_logout', $s['session_token'], $s['ip_address'], $s['user_agent'], detectDevice($s['user_agent']));
    }

    return count($sessions);
}

// ── Terminate Session on Logout ───────────────────────────────────────────────
function terminateCurrentSession() {
    global $conn;

    $token = $_SESSION['session_token'] ?? ($_COOKIE['sa_session_token'] ?? null);
    if (!$token) return;

    $ip = $_SERVER['REMOTE_ADDR'] ?? '';
    $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
    logDeviceEvent($_SESSION['user_id'] ?? 0, 'logout', $token, $ip, $ua, detectDevice($ua));

    $esc = $conn->real_escape_string($token);
    $conn->query("UPDATE user_sessions SET is_active=0, force_logout=0 WHERE session_token='$esc'");
    setcookie('sa_session_token', '', time() - 3600, '/');
}

// ── Get Active Sessions for a User ───────────────────────────────────────────
function getActiveSessions($userId) {
    global $conn;
    return $conn->query("
        SELECT *, session_token = '" . $conn->real_escape_string($_SESSION['session_token'] ?? '') . "' AS is_current
        FROM user_sessions
        WHERE user_id=$userId AND is_active=1
        ORDER BY last_activity DESC
    ")->fetch_all(MYSQLI_ASSOC);
}

// ── Get Device History for a User ────────────────────────────────────────────
function getDeviceHistory($userId, $limit = 30) {
    global $conn;
    return $conn->query("
        SELECT * FROM device_history WHERE user_id=$userId
        ORDER BY created_at DESC LIMIT $limit
    ")->fetch_all(MYSQLI_ASSOC);
}

// ── Count Active Sessions ─────────────────────────────────────────────────────
function countActiveSessions($userId) {
    global $conn;
    if (!$conn || $conn->connect_errno) require_once __DIR__ . '/../config/db.php';
    $tableCheck = $conn->query("SHOW TABLES LIKE 'user_sessions'");
    if (!$tableCheck || $tableCheck->num_rows === 0) return 1; // pretend 1 session — no multi-session warning
    return (int)$conn->query("SELECT COUNT(*) c FROM user_sessions WHERE user_id=$userId AND is_active=1")->fetch_assoc()['c'];
}

// ── Log Device Event ──────────────────────────────────────────────────────────
function logDeviceEvent($userId, $eventType, $token, $ip, $ua, $device) {
    global $conn;
    $stmt = $conn->prepare("
        INSERT INTO device_history (user_id, ip_address, user_agent, device_type, browser, os, event_type, session_token)
        VALUES (?,?,?,?,?,?,?,?)
    ");
    $stmt->bind_param('isssssss', $userId, $ip, $ua, $device['type'], $device['browser'], $device['os'], $eventType, $token);
    $stmt->execute();
}

// ── Device icon helper ────────────────────────────────────────────────────────
function deviceIcon($type) {
    return match($type) {
        'mobile'  => '📱',
        'tablet'  => '📟',
        'desktop' => '🖥️',
        default   => '💻'
    };
}
