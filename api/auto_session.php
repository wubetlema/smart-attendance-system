<?php
/**
 * Auto-Session Trigger Endpoint
 *
 * Can be called two ways:
 *   1. Teacher dashboard polling (every 60s via JS fetch) — requires login
 *   2. Cron job (no browser):
 *      * * * * * php /var/www/html/attendance-system/api/auto_session.php --cron
 *
 * Returns JSON with created/skipped counts and a log of actions.
 */

$isCron = (PHP_SAPI === 'cli') || in_array('--cron', $argv ?? []);

if (!$isCron) {
    session_start();
    require_once '../includes/auth_check.php';
    // Any authenticated user can poll (teacher/admin); result is read-only for students
    requireLogin();
}

require_once '../config/db.php';
require_once '../includes/timetable_scheduler.php';

if (!$isCron) {
    header('Content-Type: application/json');
    header('Cache-Control: no-cache');
}

$result = runTimetableScheduler();

if ($isCron) {
    echo "[" . date('Y-m-d H:i:s') . "] Auto-session: {$result['message']}\n";
    if (!empty($result['log'])) {
        foreach ($result['log'] as $line) echo "  → $line\n";
    }
} else {
    echo json_encode($result);
}
