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
<html lang="en" data-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <meta name="theme-color" content="#4f46e5">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <title>SmartAttend</title>
    <link rel="stylesheet" href="/attendance-system/assets/style.css?v=<?= filemtime($_SERVER['DOCUMENT_ROOT'].'/attendance-system/assets/style.css') ?>">
    <link rel="manifest" href="/attendance-system/manifest.json">
    <script src="/attendance-system/assets/offline.js?v=<?= filemtime($_SERVER['DOCUMENT_ROOT'].'/attendance-system/assets/offline.js') ?>" defer></script>
</head>
<body class="<?= $role ?>-page">

<!-- Accessibility: Skip to main content -->
<a href="#main-content" class="skip-link">Skip to main content</a>

<nav class="navbar" role="navigation" aria-label="Main navigation">
    <a href="/attendance-system/index.php" class="nav-brand" aria-label="SmartAttend Home">📋 SmartAttend</a>

    <div class="nav-links" id="navLinks" role="menubar">
        <?php if ($role === 'admin'): ?>
            <a href="/attendance-system/admin/dashboard.php"   role="menuitem" <?= $currentPage==='dashboard.php'?'class="active" aria-current="page"':'' ?>>Dashboard</a>
            <a href="/attendance-system/admin/users.php"       role="menuitem" <?= $currentPage==='users.php'?'class="active" aria-current="page"':'' ?>>Users</a>
            <a href="/attendance-system/admin/departments.php" role="menuitem" <?= $currentPage==='departments.php'?'class="active" aria-current="page"':'' ?>>Depts</a>
            <a href="/attendance-system/admin/classes.php"     role="menuitem" <?= $currentPage==='classes.php'?'class="active" aria-current="page"':'' ?>>Courses</a>
            <a href="/attendance-system/admin/rooms.php"       role="menuitem" <?= $currentPage==='rooms.php'?'class="active" aria-current="page"':'' ?>>Rooms</a>
            <a href="/attendance-system/admin/schedule.php"    role="menuitem" <?= $currentPage==='schedule.php'?'class="active" aria-current="page"':'' ?>>Schedule</a>
            <a href="/attendance-system/admin/timetable_engine.php" role="menuitem" <?= $currentPage==='timetable_engine.php'?'class="active" aria-current="page"':'' ?>>Timetable</a>
            <a href="/attendance-system/admin/semesters.php"   role="menuitem" <?= $currentPage==='semesters.php'?'class="active" aria-current="page"':'' ?>>Terms</a>
            <a href="/attendance-system/admin/calendar.php"    role="menuitem" <?= $currentPage==='calendar.php'?'class="active" aria-current="page"':'' ?>>Calendar</a>
            <a href="/attendance-system/admin/alert_rules.php" role="menuitem" <?= $currentPage==='alert_rules.php'?'class="active" aria-current="page"':'' ?>>Alert Rules</a>
            <a href="/attendance-system/admin/alerts.php"      role="menuitem" <?= $currentPage==='alerts.php'?'class="active" aria-current="page"':'' ?>>Alerts</a>
            <a href="/attendance-system/admin/analytics.php"   role="menuitem" <?= $currentPage==='analytics.php'?'class="active" aria-current="page"':'' ?>>Analytics</a>
            <a href="/attendance-system/admin/settings.php"    role="menuitem" <?= $currentPage==='settings.php'?'class="active" aria-current="page"':'' ?>>Settings</a>
            <a href="/attendance-system/admin/logs.php"        role="menuitem" <?= $currentPage==='logs.php'?'class="active" aria-current="page"':'' ?>>Logs</a>
            <a href="/attendance-system/admin/backup.php"      role="menuitem" <?= $currentPage==='backup.php'?'class="active" aria-current="page"':'' ?>>Backup</a>
            <a href="/attendance-system/admin/sessions.php"    role="menuitem" <?= $currentPage==='sessions.php'?'class="active" aria-current="page"':'' ?>>Sessions</a>
        <?php elseif ($role === 'teacher'): ?>
            <a href="/attendance-system/teacher/dashboard.php"       role="menuitem" <?= $currentPage==='dashboard.php'?'class="active" aria-current="page"':'' ?>>Dashboard</a>
            <a href="/attendance-system/teacher/mark_attendance.php" role="menuitem" <?= $currentPage==='mark_attendance.php'?'class="active" aria-current="page"':'' ?>>Mark</a>
            <a href="/attendance-system/teacher/qr_generate.php"     role="menuitem" <?= $currentPage==='qr_generate.php'?'class="active" aria-current="page"':'' ?>>QR Code</a>
            <a href="/attendance-system/teacher/leaves.php"          role="menuitem" <?= $currentPage==='leaves.php'?'class="active" aria-current="page"':'' ?>>Leaves</a>
            <a href="/attendance-system/teacher/at_risk_students.php" role="menuitem" <?= $currentPage==='at_risk_students.php'?'class="active" aria-current="page"':'' ?>>At-Risk</a>
            <a href="/attendance-system/teacher/announcements.php"   role="menuitem" <?= $currentPage==='announcements.php'?'class="active" aria-current="page"':'' ?>>Announce</a>
            <a href="/attendance-system/teacher/class_events.php"    role="menuitem" <?= $currentPage==='class_events.php'?'class="active" aria-current="page"':'' ?>>Events</a>
            <a href="/attendance-system/teacher/reports.php"         role="menuitem" <?= $currentPage==='reports.php'?'class="active" aria-current="page"':'' ?>>Reports</a>
        <?php elseif ($role === 'student'): ?>
            <a href="/attendance-system/student/dashboard.php"    role="menuitem" <?= $currentPage==='dashboard.php'?'class="active" aria-current="page"':'' ?>>Dashboard</a>
            <a href="/attendance-system/student/qr_checkin.php"   role="menuitem" <?= $currentPage==='qr_checkin.php'?'class="active" aria-current="page"':'' ?>>QR Scan</a>
            <a href="/attendance-system/student/face_checkin.php" role="menuitem" <?= $currentPage==='face_checkin.php'?'class="active" aria-current="page"':'' ?>>Face</a>
            <a href="/attendance-system/student/my_attendance.php"role="menuitem" <?= $currentPage==='my_attendance.php'?'class="active" aria-current="page"':'' ?>>Attendance</a>
            <a href="/attendance-system/student/profile.php"      role="menuitem" <?= $currentPage==='profile.php'?'class="active" aria-current="page"':'' ?>>Profile</a>
            <?php
            if (isset($_SESSION['user_id'])) {
                require_once $_SERVER['DOCUMENT_ROOT'] . '/attendance-system/config/db.php';
                $nq = $conn->prepare("SELECT COUNT(*) c FROM notifications WHERE user_id=? AND is_read=0");
                $nq->bind_param('i', $_SESSION['user_id']); $nq->execute();
                $unread = $nq->get_result()->fetch_assoc()['c'];
            }
            ?>
            <a href="/attendance-system/student/my_attendance.php" role="menuitem" aria-label="Notifications<?= !empty($unread) && $unread > 0 ? ", $unread unread" : '' ?>" style="position:relative">
                🔔<?php if (!empty($unread) && $unread > 0): ?>
                <span aria-hidden="true" style="background:#dc2626;color:#fff;border-radius:50%;padding:1px 5px;font-size:.65rem;position:absolute;top:-4px;right:-6px;font-weight:700"><?= $unread ?></span>
                <?php endif; ?>
            </a>
        <?php endif; ?>

        <?php if ($role): ?>
        <span class="nav-user" aria-label="Logged in as <?= htmlspecialchars($name) ?>"><?= htmlspecialchars($name) ?></span>
        <a href="/attendance-system/auth/sessions.php" title="My Sessions" style="font-size:.85rem" role="menuitem">🖥️</a>

        <!-- 🔔 Notification Bell (all roles) -->
        <?php
        if (isset($_SESSION['user_id']) && isset($conn)) {
            $uid = (int)$_SESSION['user_id'];
            $nq = @$conn->prepare("SELECT COUNT(*) c FROM notifications WHERE user_id=? AND is_read=0");
            $bellCount = 0;
            if ($nq) {
                $nq->bind_param('i', $uid); $nq->execute();
                $bellCount = (int)$nq->get_result()->fetch_assoc()['c'];
            }
        }
        ?>
        <div style="position:relative;display:inline-block" id="notifBellWrap">
            <button id="notifBell"
                    onclick="toggleNotifDropdown()"
                    style="background:none;border:none;cursor:pointer;font-size:1.1rem;padding:.25rem .4rem;border-radius:8px;position:relative;line-height:1"
                    aria-label="Notifications<?= !empty($bellCount) && $bellCount>0?", $bellCount unread":'' ?>"
                    title="Notifications">
                🔔
                <span id="notifBadge" style="display:<?= !empty($bellCount)&&$bellCount>0?'flex':'none' ?>;
                    position:absolute;top:-3px;right:-4px;background:#dc2626;color:#fff;
                    border-radius:50%;min-width:16px;height:16px;font-size:.65rem;
                    font-weight:700;align-items:center;justify-content:center;padding:0 2px;
                    border:1.5px solid var(--nav-bg,#fff)">
                    <?= !empty($bellCount)&&$bellCount>0 ? min($bellCount,99) : '' ?>
                </span>
            </button>

            <!-- Dropdown -->
            <div id="notifDropdown" style="display:none;position:absolute;right:0;top:calc(100% + 8px);
                 width:320px;background:var(--card,#fff);border:1px solid var(--border,#e2e8f0);
                 border-radius:12px;box-shadow:0 8px 30px rgba(0,0,0,.15);z-index:9999;overflow:hidden">

                <!-- Header -->
                <div style="display:flex;justify-content:space-between;align-items:center;
                            padding:.7rem 1rem;border-bottom:1px solid var(--border,#e2e8f0)">
                    <strong style="font-size:.9rem">Notifications</strong>
                    <div style="display:flex;gap:.4rem">
                        <button onclick="markAllRead()" style="background:none;border:none;cursor:pointer;
                            font-size:.75rem;color:#6366f1;padding:0">Mark all read</button>
                        <a href="/attendance-system/notifications.php"
                           style="font-size:.75rem;color:#6366f1;text-decoration:none">View all</a>
                    </div>
                </div>

                <!-- Items -->
                <div id="notifItems" style="max-height:320px;overflow-y:auto">
                    <div style="padding:1.5rem;text-align:center;color:#94a3b8;font-size:.88rem">
                        Loading...
                    </div>
                </div>

                <!-- Footer -->
                <div style="padding:.6rem 1rem;border-top:1px solid var(--border,#e2e8f0);text-align:center">
                    <a href="/attendance-system/notifications.php"
                       style="font-size:.82rem;color:#6366f1;text-decoration:none;font-weight:600">
                        View all notifications →
                    </a>
                </div>
            </div>
        </div>

        <a href="/attendance-system/auth/logout.php" class="btn-logout" role="menuitem">Logout</a>
        <?php endif; ?>
    </div>

    <div style="display:flex;align-items:center;gap:.5rem">
        <!-- Dark mode toggle -->
        <button class="dark-toggle" id="darkToggle" aria-label="Toggle dark mode" title="Toggle dark mode">🌙</button>
        <?php if ($role): ?>
        <button class="nav-toggle" id="navToggle" aria-label="Toggle navigation menu" aria-expanded="false" aria-controls="navLinks">☰</button>
        <?php endif; ?>
    </div>
</nav>
        <?php if ($role === 'admin'): ?>
            <a href="/attendance-system/admin/dashboard.php"   <?= $currentPage==='dashboard.php'?'class="active"':'' ?>>Dashboard</a>
            <a href="/attendance-system/admin/users.php"       <?= $currentPage==='users.php'?'class="active"':'' ?>>Users</a>
            <a href="/attendance-system/admin/departments.php" <?= $currentPage==='departments.php'?'class="active"':'' ?>>Depts</a>
            <a href="/attendance-system/admin/classes.php"     <?= $currentPage==='classes.php'?'class="active"':'' ?>>Courses</a>
            <a href="/attendance-system/admin/rooms.php"       <?= $currentPage==='rooms.php'?'class="active"':'' ?>>Rooms</a>
            <a href="/attendance-system/admin/schedule.php"    <?= $currentPage==='schedule.php'?'class="active"':'' ?>>Schedule</a>
            <a href="/attendance-system/admin/timetable_engine.php" <?= $currentPage==='timetable_engine.php'?'class="active"':'' ?>>Timetable</a>
            <a href="/attendance-system/admin/semesters.php"   <?= $currentPage==='semesters.php'?'class="active"':'' ?>>Terms</a>
            <a href="/attendance-system/admin/calendar.php"    <?= $currentPage==='calendar.php'?'class="active"':'' ?>>Calendar</a>
            <a href="/attendance-system/admin/alert_rules.php" <?= $currentPage==='alert_rules.php'?'class="active"':'' ?>>Alert Rules</a>
            <a href="/attendance-system/admin/alerts.php"      <?= $currentPage==='alerts.php'?'class="active"':'' ?>>Alerts</a>
            <a href="/attendance-system/admin/analytics.php"   <?= $currentPage==='analytics.php'?'class="active"':'' ?>>Analytics</a>
            <a href="/attendance-system/admin/settings.php"    <?= $currentPage==='settings.php'?'class="active"':'' ?>>Settings</a>
            <a href="/attendance-system/admin/logs.php"        <?= $currentPage==='logs.php'?'class="active"':'' ?>>Logs</a>
            <a href="/attendance-system/admin/backup.php"      <?= $currentPage==='backup.php'?'class="active"':'' ?>>Backup</a>
            <a href="/attendance-system/admin/sessions.php"    <?= $currentPage==='sessions.php'?'class="active"':'' ?>>Sessions</a>
        <?php elseif ($role === 'teacher'): ?>
            <a href="/attendance-system/teacher/dashboard.php"       <?= $currentPage==='dashboard.php'?'class="active"':'' ?>>Dashboard</a>
            <a href="/attendance-system/teacher/mark_attendance.php" <?= $currentPage==='mark_attendance.php'?'class="active"':'' ?>>Mark</a>
            <a href="/attendance-system/teacher/qr_generate.php"     <?= $currentPage==='qr_generate.php'?'class="active"':'' ?>>QR Code</a>
            <a href="/attendance-system/teacher/leaves.php"          <?= $currentPage==='leaves.php'?'class="active"':'' ?>>Leaves</a>
            <a href="/attendance-system/teacher/at_risk_students.php" <?= $currentPage==='at_risk_students.php'?'class="active"':'' ?>>At-Risk</a>
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
<!-- Student Mobile Bottom Navigation -->
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
<?php elseif ($role === 'teacher'): ?>
<!-- Teacher Mobile Bottom Navigation -->
<nav class="mobile-nav">
    <div class="mobile-nav-items">
        <a href="/attendance-system/teacher/dashboard.php" class="mobile-nav-item <?= $currentPage==='dashboard.php'?'active':'' ?>">
            <span class="nav-icon">🏠</span>Home
        </a>
        <a href="/attendance-system/teacher/qr_generate.php" class="mobile-nav-item <?= $currentPage==='qr_generate.php'?'active':'' ?>">
            <span class="nav-icon">📷</span>QR
        </a>
        <a href="/attendance-system/teacher/mark_attendance.php" class="mobile-nav-item <?= $currentPage==='mark_attendance.php'?'active':'' ?>">
            <span class="nav-icon">✏️</span>Mark
        </a>
        <a href="/attendance-system/teacher/correction_requests.php" class="mobile-nav-item <?= $currentPage==='correction_requests.php'?'active':'' ?>" style="position:relative">
            <span class="nav-icon">📝</span>Fixes
            <?php
            if (isset($_SESSION['user_id']) && isset($conn)) {
                $tcid = (int)$_SESSION['user_id'];
                $tcp  = @$conn->query("SELECT COUNT(*) c FROM attendance_corrections cr JOIN classes c ON cr.class_id=c.id WHERE c.teacher_id=$tcid AND cr.status='pending'");
                $tcnt = $tcp ? (int)$tcp->fetch_assoc()['c'] : 0;
                if ($tcnt > 0) echo "<span style='position:absolute;top:2px;right:8px;background:#dc2626;color:#fff;border-radius:50%;min-width:14px;height:14px;font-size:.6rem;display:flex;align-items:center;justify-content:center;font-weight:700'>$tcnt</span>";
            }
            ?>
        </a>
        <a href="/attendance-system/teacher/reports.php" class="mobile-nav-item <?= $currentPage==='reports.php'?'active':'' ?>">
            <span class="nav-icon">📊</span>Reports
        </a>
    </div>
</nav>
<?php endif; ?>

<div class="container" id="main-content" role="main" tabindex="-1">

<!-- Toast container -->
<div id="toast-container" role="status" aria-live="polite" aria-atomic="false"></div>

<script>
// ===== Dark Mode =====
const darkToggle = document.getElementById('darkToggle');
const html = document.documentElement;
const saved = localStorage.getItem('theme') || 'light';
html.setAttribute('data-theme', saved);
darkToggle.textContent = saved === 'dark' ? '☀️' : '🌙';

darkToggle.addEventListener('click', () => {
    const current = html.getAttribute('data-theme');
    const next = current === 'dark' ? 'light' : 'dark';
    html.setAttribute('data-theme', next);
    localStorage.setItem('theme', next);
    darkToggle.textContent = next === 'dark' ? '☀️' : '🌙';
    darkToggle.setAttribute('aria-label', next === 'dark' ? 'Switch to light mode' : 'Switch to dark mode');
});

// ===== Mobile Nav Toggle =====
const toggle = document.getElementById('navToggle');
const links  = document.getElementById('navLinks');
if (toggle && links) {
    toggle.addEventListener('click', () => {
        const open = links.classList.toggle('open');
        toggle.textContent = open ? '✕' : '☰';
        toggle.setAttribute('aria-expanded', open);
    });
    document.addEventListener('click', e => {
        if (!toggle.contains(e.target) && !links.contains(e.target)) {
            links.classList.remove('open');
            toggle.textContent = '☰';
            toggle.setAttribute('aria-expanded', 'false');
        }
    });
    // Keyboard: close on Escape
    document.addEventListener('keydown', e => {
        if (e.key === 'Escape' && links.classList.contains('open')) {
            links.classList.remove('open');
            toggle.textContent = '☰';
            toggle.setAttribute('aria-expanded', 'false');
            toggle.focus();
        }
    });
}

// ===== Keyboard Navigation for menu items =====
document.querySelectorAll('[role="menubar"]').forEach(menu => {
    const items = menu.querySelectorAll('[role="menuitem"]');
    items.forEach((item, i) => {
        item.addEventListener('keydown', e => {
            if (e.key === 'ArrowRight') { e.preventDefault(); items[(i+1) % items.length].focus(); }
            if (e.key === 'ArrowLeft')  { e.preventDefault(); items[(i-1+items.length) % items.length].focus(); }
            if (e.key === 'Home') { e.preventDefault(); items[0].focus(); }
            if (e.key === 'End')  { e.preventDefault(); items[items.length-1].focus(); }
        });
    });
});

// ===== Toast System =====
function showToast(message, type = 'info', duration = 3500) {
    const container = document.getElementById('toast-container');
    const toast = document.createElement('div');
    toast.className = `toast ${type}`;
    toast.setAttribute('role', 'alert');
    const icons = { success: '✅', error: '❌', info: 'ℹ️', warning: '⚠️' };
    toast.innerHTML = `<span aria-hidden="true">${icons[type]||''}</span><span>${message}</span>`;
    container.appendChild(toast);
    setTimeout(() => {
        toast.style.opacity = '0';
        toast.style.transform = 'translateX(20px)';
        toast.style.transition = 'all .3s ease';
        setTimeout(() => toast.remove(), 300);
    }, duration);
}

// ===== CSRF auto-inject =====
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

// ===== Notification Bell =====
let notifOpen = false;
let notifPollInterval = null;

function toggleNotifDropdown() {
    notifOpen = !notifOpen;
    const dd = document.getElementById('notifDropdown');
    if (dd) {
        dd.style.display = notifOpen ? 'block' : 'none';
        if (notifOpen) loadNotifications();
    }
}

function loadNotifications() {
    fetch('/attendance-system/api/notifications.php?action=recent')
        .then(r => r.json())
        .then(data => {
            updateBadge(data.unread);
            const container = document.getElementById('notifItems');
            if (!container) return;

            if (!data.items || data.items.length === 0) {
                container.innerHTML = '<div style="padding:1.5rem;text-align:center;color:#94a3b8;font-size:.88rem">🔔 No notifications yet</div>';
                return;
            }

            const icons = {
                absent: '❌', late: '⏰', present: '✅', warning: '⚠️',
                approved: '✅', rejected: '❌', session: '📋',
                correction: '📝', backup: '💾', login: '🔐'
            };

            container.innerHTML = data.items.map(n => {
                const msg  = n.message || '';
                const time = timeAgoJS(n.created_at);
                let icon = '🔔';
                if (/absent/i.test(msg))     icon = '❌';
                else if (/late/i.test(msg))  icon = '⏰';
                else if (/present/i.test(msg)) icon = '✅';
                else if (/warning|risk/i.test(msg)) icon = '⚠️';
                else if (/approved/i.test(msg)) icon = '✅';
                else if (/rejected/i.test(msg)) icon = '❌';
                else if (/session/i.test(msg)) icon = '📋';
                else if (/correction/i.test(msg)) icon = '📝';

                const unreadStyle = !n.is_read
                    ? 'background:var(--hover-bg,#f8fafc);border-left:3px solid #6366f1'
                    : 'border-left:3px solid transparent';

                return `<div onclick="markRead(${n.id}, this)"
                            style="padding:.65rem 1rem;cursor:pointer;${unreadStyle};
                                   border-bottom:1px solid var(--border,#f1f5f9);
                                   transition:background .15s;display:flex;gap:.65rem;align-items:flex-start"
                            onmouseover="this.style.background='var(--hover-bg,#f1f5f9)'"
                            onmouseout="this.style.background='${!n.is_read?'var(--hover-bg,#f8fafc)':''}'"
                            data-id="${n.id}">
                    <span style="font-size:1rem;flex-shrink:0;margin-top:.1rem">${icon}</span>
                    <div style="flex:1;min-width:0">
                        <div style="font-size:.83rem;color:var(--text);${!n.is_read?'font-weight:600':''}
                                    ;overflow:hidden;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical">
                            ${escHtml(msg.length > 90 ? msg.substring(0,90)+'…' : msg)}
                        </div>
                        <div style="font-size:.72rem;color:#94a3b8;margin-top:.2rem">${time}</div>
                    </div>
                    ${!n.is_read ? '<span style="width:7px;height:7px;border-radius:50%;background:#6366f1;flex-shrink:0;margin-top:.35rem"></span>' : ''}
                </div>`;
            }).join('');
        })
        .catch(() => {});
}

function markRead(id, el) {
    // Optimistic UI update
    if (el) {
        el.style.background = '';
        el.style.borderLeft = '3px solid transparent';
        const badge = el.querySelector('span[style*="border-radius:50%"]');
        if (badge) badge.remove();
        const title = el.querySelector('div[style*="font-weight:600"]');
        if (title) title.style.fontWeight = '';
    }

    fetch('/attendance-system/api/notifications.php', {
        method: 'POST',
        headers: {'Content-Type':'application/x-www-form-urlencoded'},
        body: `action=mark_read&id=${id}&csrf_token=${encodeURIComponent(document.querySelector('[name=csrf_token]')?.value||'')}`
    }).then(r => r.json()).then(() => pollNotifCount());
}

function markAllRead() {
    fetch('/attendance-system/api/notifications.php', {
        method: 'POST',
        headers: {'Content-Type':'application/x-www-form-urlencoded'},
        body: `action=mark_all_read&csrf_token=${encodeURIComponent(document.querySelector('[name=csrf_token]')?.value||'')}`
    }).then(() => {
        updateBadge(0);
        const items = document.querySelectorAll('#notifItems [data-id]');
        items.forEach(el => {
            el.style.background = '';
            el.style.borderLeft = '3px solid transparent';
        });
    });
}

function updateBadge(count) {
    const badge = document.getElementById('notifBadge');
    if (!badge) return;
    if (count > 0) {
        badge.style.display = 'flex';
        badge.textContent = count > 99 ? '99+' : count;
    } else {
        badge.style.display = 'none';
    }
}

function pollNotifCount() {
    fetch('/attendance-system/api/notifications.php?action=count')
        .then(r => r.json())
        .then(data => updateBadge(data.unread))
        .catch(() => {});
}

// Close dropdown when clicking outside
document.addEventListener('click', function(e) {
    const wrap = document.getElementById('notifBellWrap');
    if (wrap && !wrap.contains(e.target)) {
        const dd = document.getElementById('notifDropdown');
        if (dd) dd.style.display = 'none';
        notifOpen = false;
    }
});

// Poll every 30 seconds for new notifications
pollNotifCount();
notifPollInterval = setInterval(pollNotifCount, 30000);

function timeAgoJS(datetime) {
    if (!datetime) return '';
    const diff = Math.floor((Date.now() - new Date(datetime.replace(' ','T')).getTime()) / 1000);
    if (diff < 60)    return 'Just now';
    if (diff < 3600)  return Math.floor(diff/60)   + 'm ago';
    if (diff < 86400) return Math.floor(diff/3600)  + 'h ago';
    return Math.floor(diff/86400) + 'd ago';
}

function escHtml(str) {
    const d = document.createElement('div');
    d.textContent = str;
    return d.innerHTML;
}

// ===== Service Worker sync listener =====
if ('serviceWorker' in navigator) {
    navigator.serviceWorker.addEventListener('message', function(e) {
        if (e.data && e.data.type === 'SYNC_COMPLETE') {
            if (e.data.synced > 0) showToast(`${e.data.synced} offline record(s) synced`, 'success');
            if (e.data.conflicts > 0) showToast(`${e.data.conflicts} conflict(s) — teacher already marked`, 'warning', 5000);
        }
    });
}
</script>
