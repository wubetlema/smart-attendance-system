<?php
/**
 * Notifications Inbox — shared by all roles (admin, teacher, student)
 * Accessible at /attendance-system/notifications.php
 */
require_once 'includes/auth_check.php';
requireLogin();
require_once 'config/db.php';
require_once 'includes/functions.php';

$userId = (int)$_SESSION['user_id'];
$role   = $_SESSION['role'];

// ── Actions ───────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'mark_read') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id) {
            $conn->prepare("UPDATE notifications SET is_read=1 WHERE id=? AND user_id=?")
                 ->bind_param('ii', $id, $userId) && true;
            $s = $conn->prepare("UPDATE notifications SET is_read=1 WHERE id=? AND user_id=?");
            $s->bind_param('ii', $id, $userId);
            $s->execute();
        }
        header('Location: notifications.php');
        exit;

    } elseif ($action === 'mark_all_read') {
        $conn->query("UPDATE notifications SET is_read=1 WHERE user_id=$userId AND is_read=0");
        header('Location: notifications.php');
        exit;

    } elseif ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id) {
            $s = $conn->prepare("DELETE FROM notifications WHERE id=? AND user_id=?");
            $s->bind_param('ii', $id, $userId);
            $s->execute();
        }
        header('Location: notifications.php');
        exit;

    } elseif ($action === 'delete_all_read') {
        $conn->query("DELETE FROM notifications WHERE user_id=$userId AND is_read=1");
        header('Location: notifications.php');
        exit;
    }
}

// ── AJAX: unread count endpoint ───────────────────────────────────────────────
if (isset($_GET['json'])) {
    header('Content-Type: application/json');
    $count = (int)$conn->query("SELECT COUNT(*) c FROM notifications WHERE user_id=$userId AND is_read=0")->fetch_assoc()['c'];
    $recent = $conn->query("
        SELECT id, message, is_read, created_at
        FROM notifications
        WHERE user_id=$userId
        ORDER BY created_at DESC
        LIMIT 5
    ")->fetch_all(MYSQLI_ASSOC);
    echo json_encode(['unread' => $count, 'recent' => $recent]);
    exit;
}

// ── Filters ───────────────────────────────────────────────────────────────────
$filter    = $_GET['filter'] ?? 'all'; // all | unread | read
$search    = trim($_GET['search'] ?? '');
$page      = max(1, (int)($_GET['page'] ?? 1));
$perPage   = 20;
$offset    = ($page - 1) * $perPage;

$where = "WHERE user_id=$userId";
if ($filter === 'unread') $where .= " AND is_read=0";
if ($filter === 'read')   $where .= " AND is_read=1";
if ($search) {
    $esc = $conn->real_escape_string($search);
    $where .= " AND message LIKE '%$esc%'";
}

$total = (int)$conn->query("SELECT COUNT(*) c FROM notifications $where")->fetch_assoc()['c'];
$pages = max(1, ceil($total / $perPage));

$notifications = $conn->query("
    SELECT * FROM notifications
    $where
    ORDER BY created_at DESC
    LIMIT $perPage OFFSET $offset
")->fetch_all(MYSQLI_ASSOC);

$unreadCount = (int)$conn->query("SELECT COUNT(*) c FROM notifications WHERE user_id=$userId AND is_read=0")->fetch_assoc()['c'];

// Mark fetched unread ones as read automatically if viewing them
if ($filter !== 'read' && !empty($notifications)) {
    $conn->query("UPDATE notifications SET is_read=1 WHERE user_id=$userId AND is_read=0 AND id IN (" .
        implode(',', array_column($notifications, 'id')) . ")");
}

include 'includes/header.php';
?>

<div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:.75rem;margin-bottom:1.25rem">
    <div>
        <h1 style="margin:0">🔔 Notifications</h1>
        <p class="text-muted" style="margin:.25rem 0 0">
            <?= $unreadCount > 0 ? "<strong style='color:#dc2626'>$unreadCount unread</strong>" : "All caught up" ?>
            · <?= $total ?> total
        </p>
    </div>
    <div style="display:flex;gap:.5rem;flex-wrap:wrap">
        <?php if ($unreadCount > 0): ?>
        <form method="POST" style="display:inline">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="mark_all_read">
            <button class="btn btn-secondary btn-sm">✓ Mark All Read</button>
        </form>
        <?php endif; ?>
        <form method="POST" style="display:inline" onsubmit="return confirm('Delete all read notifications?')">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="delete_all_read">
            <button class="btn btn-sm" style="background:#fee2e2;color:#b91c1c;border:none">🗑 Clear Read</button>
        </form>
    </div>
</div>

<!-- Filters + Search -->
<div class="card" style="padding:.75rem 1rem;margin-bottom:1rem">
    <div style="display:flex;gap:.75rem;align-items:center;flex-wrap:wrap">
        <!-- Filter tabs -->
        <div style="display:flex;gap:.3rem">
            <?php foreach (['all'=>'All','unread'=>'Unread','read'=>'Read'] as $f=>$l): ?>
            <a href="?filter=<?= $f ?><?= $search?"&search=".urlencode($search):'' ?>"
               class="btn btn-sm <?= $filter===$f?'btn-primary':'btn-secondary' ?>">
                <?= $l ?>
                <?php if ($f==='unread' && $unreadCount > 0): ?>
                <span style="background:#dc2626;color:#fff;border-radius:10px;padding:0 5px;font-size:.7rem;margin-left:.2rem"><?= $unreadCount ?></span>
                <?php endif; ?>
            </a>
            <?php endforeach; ?>
        </div>

        <!-- Search -->
        <form method="GET" style="display:flex;gap:.4rem;flex:1;min-width:200px">
            <input type="hidden" name="filter" value="<?= htmlspecialchars($filter) ?>">
            <input type="text" name="search" value="<?= htmlspecialchars($search) ?>"
                   placeholder="Search notifications..." style="flex:1">
            <button type="submit" class="btn btn-secondary btn-sm">Search</button>
            <?php if ($search): ?>
            <a href="?filter=<?= $filter ?>" class="btn btn-secondary btn-sm">✕</a>
            <?php endif; ?>
        </form>
    </div>
</div>

<!-- Notifications List -->
<?php if (empty($notifications)): ?>
<div class="card" style="text-align:center;padding:3rem">
    <div style="font-size:3rem;margin-bottom:.75rem">🔔</div>
    <h3 style="color:var(--text-muted)">
        <?= $filter==='unread' ? 'No unread notifications' : 'No notifications found' ?>
    </h3>
    <p class="text-muted">
        <?= $search ? "No results for \"".htmlspecialchars($search)."\"" : "You're all caught up!" ?>
    </p>
</div>
<?php else: ?>
<div style="display:flex;flex-direction:column;gap:.5rem">
<?php foreach ($notifications as $n):
    // Detect notification type from message for icon
    $msg = $n['message'];
    $icon = '🔔';
    $borderColor = '#e2e8f0';
    $bgColor = 'var(--card)';

    if (preg_match('/absent|Absent|ABSENT/', $msg))         { $icon = '❌'; $borderColor = '#fca5a5'; $bgColor = '#fff5f5'; }
    elseif (preg_match('/late|Late|LATE/', $msg))            { $icon = '⏰'; $borderColor = '#fde68a'; $bgColor = '#fffbeb'; }
    elseif (preg_match('/present|Present|PRESENT/', $msg))   { $icon = '✅'; $borderColor = '#86efac'; $bgColor = '#f0fdf4'; }
    elseif (preg_match('/warning|Warning|risk|Risk/', $msg)) { $icon = '⚠️'; $borderColor = '#fca5a5'; $bgColor = '#fff5f5'; }
    elseif (preg_match('/approved|Approved/', $msg))         { $icon = '✅'; $borderColor = '#86efac'; $bgColor = '#f0fdf4'; }
    elseif (preg_match('/rejected|Rejected/', $msg))         { $icon = '❌'; $borderColor = '#fca5a5'; $bgColor = '#fff5f5'; }
    elseif (preg_match('/session|Session/', $msg))           { $icon = '📋'; $borderColor = '#c7d2fe'; $bgColor = '#f5f3ff'; }
    elseif (preg_match('/backup|Backup/', $msg))             { $icon = '💾'; $borderColor = '#a5f3fc'; $bgColor = '#f0fdff'; }
    elseif (preg_match('/login|Login|suspicious/', $msg))    { $icon = '🔐'; $borderColor = '#fca5a5'; $bgColor = '#fff5f5'; }
    elseif (preg_match('/correction|Correction/', $msg))     { $icon = '📝'; $borderColor = '#fde68a'; $bgColor = '#fffbeb'; }

    $isUnread = !$n['is_read'];
    $timeAgo  = timeAgo($n['created_at']);
?>
<div style="display:flex;gap:1rem;padding:1rem 1.1rem;border-radius:10px;
            border:1.5px solid <?= $isUnread ? $borderColor : '#e2e8f0' ?>;
            background:<?= $isUnread ? $bgColor : 'var(--card)' ?>;
            transition:background .2s;
            <?= $isUnread ? 'box-shadow:0 1px 4px rgba(0,0,0,.06)' : 'opacity:.8' ?>">

    <!-- Icon -->
    <div style="font-size:1.4rem;flex-shrink:0;margin-top:.1rem"><?= $icon ?></div>

    <!-- Content -->
    <div style="flex:1;min-width:0">
        <div style="font-size:.92rem;color:var(--text);<?= $isUnread?'font-weight:500':'' ?>;word-break:break-word">
            <?= htmlspecialchars($msg) ?>
        </div>
        <div style="font-size:.78rem;color:var(--text-muted);margin-top:.3rem;display:flex;align-items:center;gap:.75rem;flex-wrap:wrap">
            <span>🕐 <?= $timeAgo ?></span>
            <span><?= date('d M Y, H:i', strtotime($n['created_at'])) ?></span>
            <?php if ($isUnread): ?>
            <span style="background:#3b82f6;color:#fff;border-radius:10px;padding:.05rem .45rem;font-size:.7rem;font-weight:700">NEW</span>
            <?php endif; ?>
        </div>
    </div>

    <!-- Actions -->
    <div style="display:flex;gap:.3rem;align-items:flex-start;flex-shrink:0">
        <form method="POST" style="display:inline">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="delete">
            <input type="hidden" name="id" value="<?= $n['id'] ?>">
            <button class="btn btn-sm" style="background:transparent;color:#94a3b8;border:1px solid #e2e8f0;padding:.2rem .45rem"
                    title="Delete" onclick="return confirm('Delete this notification?')">🗑</button>
        </form>
    </div>
</div>
<?php endforeach; ?>
</div>

<!-- Pagination -->
<?php if ($pages > 1): ?>
<div style="display:flex;justify-content:center;gap:.4rem;margin-top:1.25rem;flex-wrap:wrap">
    <?php
    $qBase = http_build_query(['filter'=>$filter,'search'=>$search]);
    for ($p = 1; $p <= $pages; $p++):
    ?>
    <a href="?<?= $qBase ?>&page=<?= $p ?>"
       class="btn btn-sm <?= $p===$page?'btn-primary':'btn-secondary' ?>">
        <?= $p ?>
    </a>
    <?php endfor; ?>
</div>
<?php endif; ?>
<?php endif; ?>

<?php include 'includes/footer.php'; ?>

<?php
// ── Helper: time ago ──────────────────────────────────────────────────────────
function timeAgo(string $datetime): string {
    $diff = time() - strtotime($datetime);
    if ($diff < 60)     return 'Just now';
    if ($diff < 3600)   return floor($diff/60)   . 'm ago';
    if ($diff < 86400)  return floor($diff/3600)  . 'h ago';
    if ($diff < 604800) return floor($diff/86400) . 'd ago';
    return date('d M', strtotime($datetime));
}
?>
