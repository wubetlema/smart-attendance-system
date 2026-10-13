<?php
/**
 * Setup Check API — called by admin dashboard on load
 * Returns warnings for unconfigured items:
 *   - Face encryption key missing
 *   - SMTP not configured
 *   - Cron not set up
 *
 * GET /api/setup_check.php → JSON array of warnings
 */
session_start();
require_once '../config/db.php';
require_once '../includes/auth_check.php';
require_once '../includes/functions.php';

header('Content-Type: application/json');
requireRole('admin');

$warnings = [];

// 1. Face encryption key
$faceKey = getSetting('face_encryption_key', '');
if (empty($faceKey)) {
    $warnings[] = [
        'type'    => 'critical',
        'icon'    => '🔐',
        'title'   => 'Face Encryption Key Not Set',
        'message' => 'Face descriptors are stored unencrypted. Generate a key before students register their face.',
        'action'  => ['label' => 'Fix Now', 'url' => '/attendance-system/admin/settings.php?tab=biometric'],
    ];
}

// 2. SMTP
$smtpHost = getSetting('smtp_host', '');
$smtpUser = getSetting('smtp_user', '');
$smtpPass = getSetting('smtp_pass', '');
if (!$smtpHost || !$smtpUser || !$smtpPass) {
    $warnings[] = [
        'type'    => 'warning',
        'icon'    => '📧',
        'title'   => 'SMTP Not Configured',
        'message' => 'Email notifications are disabled. Configure SMTP to send emails to students and teachers.',
        'action'  => ['label' => 'Configure SMTP', 'url' => '/attendance-system/admin/settings.php?tab=email'],
    ];
}

// 3. Auto-session cron
$autoEnabled = getSetting('auto_session_enabled', '1') === '1';
if ($autoEnabled) {
    // Check if cron has run recently — look for auto-created sessions in last 24h
    // If auto-session is enabled but no sessions have been auto-created in the last 3 days of classes, warn
    $recentAutoSessions = (int)$conn->query("
        SELECT COUNT(*) c FROM attendance_sessions
        WHERE auto_created=1
        AND created_at > DATE_SUB(NOW(), INTERVAL 7 DAY)
    ")->fetch_assoc()['c'];

    $hasSchedules = (int)$conn->query("SELECT COUNT(*) c FROM schedules")->fetch_assoc()['c'];

    if ($hasSchedules > 0 && $recentAutoSessions === 0) {
        $warnings[] = [
            'type'    => 'warning',
            'icon'    => '⏰',
            'title'   => 'Auto-Session Cron Not Running',
            'message' => 'Auto-session is enabled but no sessions have been auto-created. Set up the Windows Task Scheduler or Linux cron.',
            'action'  => ['label' => 'Setup Instructions', 'url' => '/attendance-system/admin/settings.php?tab=cron'],
        ];
    }
}

echo json_encode(['warnings' => $warnings, 'count' => count($warnings)]);
