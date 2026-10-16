<?php
/**
 * Security Headers + PHP Error Config
 *
 * Included automatically by includes/auth_check.php so every page gets it.
 * Set SMARTATTEND_DEV=1 in environment for development mode.
 */

// ── PHP error display ─────────────────────────────────────────────────────────
$devMode = (getenv('SMARTATTEND_DEV') === '1') || (php_sapi_name() === 'cli');

if (!$devMode) {
    @ini_set('display_errors', '0');
    @ini_set('display_startup_errors', '0');
    @ini_set('log_errors', '1');
    $logDir = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'logs';
    if (!is_dir($logDir)) @mkdir($logDir, 0750, true);
    @ini_set('error_log', $logDir . DIRECTORY_SEPARATOR . 'php_errors.log');
    error_reporting(E_ALL);
} else {
    @ini_set('display_errors', '1');
    @ini_set('display_startup_errors', '1');
    error_reporting(E_ALL);
}

// ── Security headers ──────────────────────────────────────────────────────────
if (php_sapi_name() !== 'cli' && !headers_sent()) {
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: SAMEORIGIN');
    header('X-XSS-Protection: 1; mode=block');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('Permissions-Policy: camera=(self), microphone=(), geolocation=(self)');
    // CSP: allow same-origin scripts, CDN for face-api/qrcode/chart.js, no inline eval
    header(
        "Content-Security-Policy: " .
        "default-src 'self'; " .
        "script-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net https://unpkg.com https://cdnjs.cloudflare.com; " .
        "style-src 'self' 'unsafe-inline' https://fonts.googleapis.com; " .
        "font-src 'self' https://fonts.gstatic.com; " .
        "img-src 'self' data: blob:; " .
        "media-src 'self' blob:; " .
        "connect-src 'self'; " .
        "frame-ancestors 'none';"
    );
}

/**
 * Rate limit any endpoint by key (IP + action).
 * Returns true if the request is allowed, false if blocked.
 *
 * @param string $action    Unique action key (e.g. 'face_checkin', 'api_save_face')
 * @param int    $maxPerMin Max requests per minute per IP
 */
function rateLimit(string $action, int $maxPerMin = 10): bool {
    global $conn;
    if (!isset($conn) || !$conn) return true; // If no DB, allow

    $ip      = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    $key     = $action . ':' . $ip;
    $window  = date('Y-m-d H:i'); // 1-minute window

    // Use login_attempts table as a generic rate-limit store
    // (action field stores our key)
    $count = $conn->prepare("
        SELECT COUNT(*) c FROM login_attempts
        WHERE university_id = ? AND attempted_at > DATE_SUB(NOW(), INTERVAL 1 MINUTE)
    ");
    $count->bind_param('s', $key);
    $count->execute();
    $n = (int)$count->get_result()->fetch_assoc()['c'];

    if ($n >= $maxPerMin) {
        http_response_code(429);
        return false;
    }

    // Record this attempt
    $ins = $conn->prepare("INSERT INTO login_attempts (ip_address, university_id) VALUES (?,?)");
    $ins->bind_param('ss', $ip, $key);
    $ins->execute();

    return true;
}
