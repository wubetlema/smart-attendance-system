<?php
if (session_status() === PHP_SESSION_NONE) session_start();
require_once $_SERVER['DOCUMENT_ROOT'] . '/attendance-system/includes/auth_check.php';
$role = $_SESSION['role'] ?? '';
$name = $_SESSION['name'] ?? '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !defined('SKIP_CSRF')) {
    verifyCsrf();
}
$currentPage = basename($_SERVER['PHP_SELF']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <meta name="theme-color" content="#4f46e5">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <title>SmartAttend</title>
    <link rel="stylesheet" href="/attendance-system/assets/style.css?v=<?= filemtime($_SERVER['DOCUMENT_ROOT'].'/attendance-system/assets/style.css') ?>">
    <link rel="manifest" href="/attendance-system/manifest.json">
</head>
<body class="<?= $role ?>-page">

<nav class="navbar">
    <div class="nav-brand">SmartAttend</div>

    <div class="nav-links" id="navLinks">
        <?php if ($role === 'admin'): ?>
            <a href="/attendance-system/admin/dashboard.php"   <?= $currentPage==='dashboard.php'?'class="active"':'' ?>>Dashboard</a>
            <a href="/attendance-system/admin/users.php"       <?= $currentPage==='users.php'?'class="active"':'' ?>>Users</a>
            <a href="/attendance-system/admin/departments.php" <?= $currentPage==='departments.php'?'class="active"':'' ?>>Depts</a>
            <a href="/attendance-system/admin/classes.php"     <?= $currentPage==='classes.php'?'class="active"':'' ?>>Courses</a>
            <a href="/attendance-system/admin/schedule.php"    <?= $currentPage==='schedule.php'?'class="active"':'' ?>>Schedule</a>
            <a href="/attendance-system/admin/analytics.php"   <?= $currentPage==='analytics.php'?'class="active"':'' ?>>Analytics</a>
            <a href="/attendance-system/admin/settings.php"    <?= $currentPage==='settings.php'?'class="active"':'' ?>>Settings</a>
            <a href="/attendance-system/admin/logs.php"        <?= $currentPage==='logs.php'?'class="active"':'' ?>>Logs</a>
            <a href="/attendance-system/admin/backup.php"      <?= $currentPage==='backup.php'?'class="active"':'' ?>>Backup</a>
        <?php elseif ($role === 'teacher'): ?>
            <a href="/attendance-system/teacher/dashboard.php"       <?= $currentPage==='dashboard.php'?'class="active"':'' ?>>Dashboard</a>
            <a href="/attendance-system/teacher/mark_attendance.php" <?= $currentPage==='mark_attendance.php'?'class="active"':'' ?>>Mark</a>
            <a href="/attendance-system/teacher/qr_generate.php"     <?= $currentPage==='qr_generate.php'?'class="active"':'' ?>>QR Code</a>
            <a href="/attendance-system/teacher/leaves.php"          <?= $currentPage==='leaves.php'?'class="active"':'' ?>>Leaves</a>
            <a href="/attendance-system/teacher/announcements.php"   <?= $currentPage==='announcements.php'?'class="active"':'' ?>>Announce</a>
            <a href="/attendance-system/teacher/reports.php"         <?= $currentPage==='reports.php'?'class="active"':'' ?>>Reports</a>
        <?php elseif ($role === 'student'): ?>
            <a href="/attendance-system/student/dashboard.php"    <?= $currentPage==='dashboard.php'?'class="active"':'' ?>>Dashboard</a>
            <a href="/attendance-system/student/qr_checkin.php"   <?= $currentPage==='qr_checkin.php'?'class="active"':'' ?>>QR Scan</a>
            <a href="/attendance-system/student/face_checkin.php" <?= $currentPage==='face_checkin.php'?'class="active"':'' ?>>Face</a>
            <a href="/attendance-system/student/my_attendance.php"<?= $currentPage==='my_attendance.php'?'class="active"':'' ?>>Attendance</a>
            <a href="/attendance-system/student/profile.php"      <?= $currentPage==='profile.php'?'class="active"':'' ?>>Profile</a>
            <?php
            if (isset($_SESSION['user_id'])) {
                require_once $_SERVER['DOCUMENT_ROOT'] . '/attendance-system/config/db.php';
                $nq = $conn->prepare("SELECT COUNT(*) c FROM notifications WHERE user_id=? AND is_read=0");
                $nq->bind_param('i', $_SESSION['user_id']); $nq->execute();
                $unread = $nq->get_result()->fetch_assoc()['c'];
            }
            ?>
            <a href="/attendance-system/student/my_attendance.php" style="position:relative">
                🔔<?php if (!empty($unread) && $unread > 0): ?>
                <span style="background:#dc2626;color:#fff;border-radius:50%;padding:1px 5px;font-size:.65rem;position:absolute;top:-4px;right:-6px;font-weight:700"><?= $unread ?></span>
                <?php endif; ?>
            </a>
        <?php endif; ?>

        <?php if ($role): ?>
        <span class="nav-user"><?= htmlspecialchars($name) ?></span>
        <a href="/attendance-system/auth/logout.php" class="btn-logout">Logout</a>
        <?php endif; ?>
    </div>

    <?php if ($role): ?>
    <button class="nav-toggle" id="navToggle" aria-label="Menu">☰</button>
    <?php endif; ?>
</nav>

<?php if ($role === 'student'): ?>
<!-- Mobile Bottom Navigation -->
<nav class="mobile-nav">
    <div class="mobile-nav-items">
        <a href="/attendance-system/student/dashboard.php" class="mobile-nav-item <?= $currentPage==='dashboard.php'?'active':'' ?>">
            <span class="nav-icon">🏠</span>Home
        </a>
        <a href="/attendance-system/student/my_attendance.php" class="mobile-nav-item <?= $currentPage==='my_attendance.php'?'active':'' ?>">
            <span class="nav-icon">📊</span>Attendance
        </a>
        <a href="/attendance-system/student/qr_checkin.php" class="mobile-nav-item qr-btn <?= $currentPage==='qr_checkin.php'?'active':'' ?>">
            <span class="nav-icon">📷</span>Scan
        </a>
        <a href="/attendance-system/student/face_checkin.php" class="mobile-nav-item <?= $currentPage==='face_checkin.php'?'active':'' ?>">
            <span class="nav-icon">👤</span>Face
        </a>
        <a href="/attendance-system/student/profile.php" class="mobile-nav-item <?= $currentPage==='profile.php'?'active':'' ?>">
            <span class="nav-icon">⚙️</span>Profile
        </a>
    </div>
</nav>
<?php endif; ?>

<div class="container">

<!-- Toast container -->
<div id="toast-container"></div>

<script>
// Mobile nav toggle
const toggle = document.getElementById('navToggle');
const links  = document.getElementById('navLinks');
if (toggle) {
    toggle.addEventListener('click', () => {
        links.classList.toggle('open');
        toggle.textContent = links.classList.contains('open') ? '✕' : '☰';
    });
    // Close on outside click
    document.addEventListener('click', e => {
        if (!toggle.contains(e.target) && !links.contains(e.target)) {
            links.classList.remove('open');
            toggle.textContent = '☰';
        }
    });
}

// Toast notification system
function showToast(message, type = 'info', duration = 3500) {
    const container = document.getElementById('toast-container');
    const toast = document.createElement('div');
    toast.className = `toast ${type}`;
    const icons = { success: '✅', error: '❌', info: 'ℹ️', warning: '⚠️' };
    toast.innerHTML = `<span>${icons[type] || ''}</span><span>${message}</span>`;
    container.appendChild(toast);
    setTimeout(() => {
        toast.style.opacity = '0';
        toast.style.transform = 'translateX(20px)';
        toast.style.transition = 'all .3s ease';
        setTimeout(() => toast.remove(), 300);
    }, duration);
}

// Auto-inject CSRF token into all forms
document.addEventListener('DOMContentLoaded', function() {
    const token = '<?= csrfToken() ?>';
    document.querySelectorAll('form[method="POST"], form[method="post"]').forEach(function(form) {
        if (!form.querySelector('input[name="csrf_token"]')) {
            const input = document.createElement('input');
            input.type = 'hidden'; input.name = 'csrf_token'; input.value = token;
            form.appendChild(input);
        }
    });
});

// Service worker sync result listener
if ('serviceWorker' in navigator) {
    navigator.serviceWorker.addEventListener('message', function(e) {
        if (e.data && e.data.type === 'SYNC_COMPLETE') {
            if (e.data.synced > 0) showToast(`${e.data.synced} offline record(s) synced`, 'success');
            if (e.data.conflicts > 0) showToast(`${e.data.conflicts} conflict(s) detected`, 'warning', 5000);
        }
    });
}
</script>
