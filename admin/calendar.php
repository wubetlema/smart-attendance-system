<?php
require_once '../includes/auth_check.php';
requireRole('admin');
require_once '../config/db.php';

$msg = ''; $msgType = 'success';
$user = currentUser();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];

    if ($action === 'add') {
        $termId     = $_POST['term_id'] ? (int)$_POST['term_id'] : null;
        $title      = trim($_POST['title']);
        $desc       = trim($_POST['description'] ?? '');
        $eventDate  = $_POST['event_date'];
        $endDate    = $_POST['end_date'] ?: null;
        $type       = $_POST['event_type'];
        $affects    = isset($_POST['affects_attendance']) ? 1 : 0;
        $createdBy  = $user['id'];

        $stmt = $conn->prepare("INSERT INTO academic_calendar (term_id,title,description,event_date,end_date,event_type,affects_attendance,created_by) VALUES (?,?,?,?,?,?,?,?)");
        $stmt->bind_param('isssssii', $termId,$title,$desc,$eventDate,$endDate,$type,$affects,$createdBy);
        $msg = $stmt->execute() ? 'Event added.' : 'Error: '.$conn->error;
        if (!$stmt->execute()) $msgType = 'error';

    } elseif ($action === 'edit') {
        $id         = (int)$_POST['id'];
        $termId     = $_POST['term_id'] ? (int)$_POST['term_id'] : null;
        $title      = trim($_POST['title']);
        $desc       = trim($_POST['description'] ?? '');
        $eventDate  = $_POST['event_date'];
        $endDate    = $_POST['end_date'] ?: null;
        $type       = $_POST['event_type'];
        $affects    = isset($_POST['affects_attendance']) ? 1 : 0;

        $stmt = $conn->prepare("UPDATE academic_calendar SET term_id=?,title=?,description=?,event_date=?,end_date=?,event_type=?,affects_attendance=? WHERE id=?");
        $stmt->bind_param('isssssii', $termId,$title,$desc,$eventDate,$endDate,$type,$affects,$id);
        $msg = $stmt->execute() ? 'Event updated.' : 'Error: '.$conn->error;
        if (!$stmt->execute()) $msgType = 'error';

    } elseif ($action === 'delete') {
        $id = (int)$_POST['id'];
        $conn->query("DELETE FROM academic_calendar WHERE id=$id");
        $msg = 'Event deleted.';
    }
}

$terms = $conn->query("SELECT id,name,academic_year FROM academic_terms ORDER BY start_date DESC")->fetch_all(MYSQLI_ASSOC);
$currentTerm = $conn->query("SELECT id FROM academic_terms WHERE is_current=1 LIMIT 1")->fetch_assoc();

$filterTerm = isset($_GET['term']) ? (int)$_GET['term'] : ($currentTerm['id'] ?? 0);
$filterType = $_GET['type'] ?? '';

$where = [];
if ($filterTerm) $where[] = "term_id=$filterTerm";
if ($filterType) $where[] = "event_type='" . $conn->real_escape_string($filterType) . "'";
$whereSQL = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$events = $conn->query("
    SELECT c.*, t.name AS term_name, u.name AS creator_name
    FROM academic_calendar c
    LEFT JOIN academic_terms t ON c.term_id=t.id
    LEFT JOIN users u ON c.created_by=u.id
    $whereSQL
    ORDER BY c.event_date ASC
")->fetch_all(MYSQLI_ASSOC);

$editEvent = null;
if (isset($_GET['edit'])) {
    $eid = (int)$_GET['edit'];
    $stmt = $conn->prepare("SELECT * FROM academic_calendar WHERE id=?");
    $stmt->bind_param('i', $eid); $stmt->execute();
    $editEvent = $stmt->get_result()->fetch_assoc();
}

include '../includes/header.php';
?>
<h1>Academic Calendar</h1>
<?php if ($msg): ?><div class="alert alert-<?= $msgType ?>"><?= htmlspecialchars($msg) ?></div><?php endif; ?>

<!-- Add/Edit Form -->
<div class="card">
    <h3><?= $editEvent ? 'Edit Event' : 'Add Calendar Event' ?></h3>
    <form method="POST">
        <input type="hidden" name="action" value="<?= $editEvent ? 'edit' : 'add' ?>">
        <?php if ($editEvent): ?><input type="hidden" name="id" value="<?= $editEvent['id'] ?>"><?php endif; ?>
        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:.75rem">
            <div class="form-group" style="margin:0;grid-column:span 2"><label>Event Title</label>
                <input type="text" name="title" required value="<?= htmlspecialchars($editEvent['title'] ?? '') ?>" placeholder="e.g. Mid-term Exams, Winter Break"></div>
            <div class="form-group" style="margin:0"><label>Term (optional)</label>
                <select name="term_id">
                    <option value="">-- No specific term --</option>
                    <?php foreach ($terms as $t): ?>
                    <option value="<?= $t['id'] ?>" <?= ($editEvent['term_id']??'')==$t['id']?'selected':'' ?>><?= htmlspecialchars($t['name']) ?> (<?= $t['academic_year'] ?>)</option>
                    <?php endforeach; ?>
                </select></div>
            <div class="form-group" style="margin:0"><label>Event Type</label>
                <select name="event_type" required>
                    <?php foreach (['holiday','exam','registration','orientation','break','deadline','other'] as $type): ?>
                    <option value="<?= $type ?>" <?= ($editEvent['event_type']??'')===$type?'selected':'' ?>><?= ucfirst($type) ?></option>
                    <?php endforeach; ?>
                </select></div>
            <div class="form-group" style="margin:0"><label>Start Date</label>
                <input type="date" name="event_date" required value="<?= $editEvent['event_date'] ?? '' ?>"></div>
            <div class="form-group" style="margin:0"><label>End Date (optional)</label>
                <input type="date" name="end_date" value="<?= $editEvent['end_date'] ?? '' ?>"></div>
            <div class="form-group" style="margin:0;grid-column:span 2"><label>Description</label>
                <textarea name="description" rows="2" style="width:100%;padding:.6rem;border:1px solid var(--border);border-radius:7px" placeholder="Additional details..."><?= htmlspecialchars($editEvent['description'] ?? '') ?></textarea></div>
            <div class="form-group" style="margin:0;display:flex;align-items:flex-end">
                <label style="display:flex;align-items:center;gap:.5rem;cursor:pointer;padding-bottom:.4rem">
                    <input type="checkbox" name="affects_attendance" <?= ($editEvent['affects_attendance']??0)?'checked':'' ?>>
                    Affects Attendance (e.g. holiday, no classes)
                </label>
            </div>
        </div>
        <div style="margin-top:1rem;display:flex;gap:.75rem">
            <button type="submit" class="btn btn-primary"><?= $editEvent ? 'Save Changes' : 'Add Event' ?></button>
            <?php if ($editEvent): ?><a href="calendar.php" class="btn btn-secondary">Cancel</a><?php endif; ?>
        </div>
    </form>
</div>

<!-- Filters -->
<div class="card">
    <form method="GET" style="display:flex;gap:.75rem;flex-wrap:wrap;align-items:center">
        <select name="term" onchange="this.form.submit()">
            <option value="">All Terms</option>
            <?php foreach ($terms as $t): ?>
            <option value="<?= $t['id'] ?>" <?= $filterTerm==$t['id']?'selected':'' ?>><?= htmlspecialchars($t['name']) ?> (<?= $t['academic_year'] ?>)</option>
            <?php endforeach; ?>
        </select>
        <select name="type" onchange="this.form.submit()">
            <option value="">All Types</option>
            <?php foreach (['holiday','exam','registration','orientation','break','deadline','other'] as $type): ?>
            <option value="<?= $type ?>" <?= $filterType===$type?'selected':'' ?>><?= ucfirst($type) ?></option>
            <?php endforeach; ?>
        </select>
        <a href="calendar.php" class="btn btn-secondary btn-sm">Clear Filters</a>
    </form>
</div>

<!-- Calendar View -->
<div class="card">
    <h3>Upcoming Events (<?= count($events) ?>)</h3>
    <?php if (empty($events)): ?>
        <p class="text-muted">No events scheduled.</p>
    <?php else: ?>
    <div style="display:grid;gap:.75rem">
    <?php
    $typeColors = [
        'holiday'=>['#fee2e2','#b91c1c'],
        'exam'=>['#fef3c7','#b45309'],
        'registration'=>['#dbeafe','#1e40af'],
        'orientation'=>['#dcfce7','#15803d'],
        'break'=>['#fce7f3','#be185d'],
        'deadline'=>['#fed7aa','#c2410c'],
        'other'=>['#f1f5f9','#64748b']
    ];
    foreach ($events as $e):
        $c = $typeColors[$e['event_type']] ?? $typeColors['other'];
        $isMultiDay = $e['end_date'] && $e['end_date'] !== $e['event_date'];
        $isPast = strtotime($e['event_date']) < strtotime(date('Y-m-d'));
    ?>
    <div style="border-left:4px solid <?= $c[1] ?>;padding:.75rem 1rem;background:<?= $isPast?'var(--hover-bg)':'var(--card-bg)' ?>;border-radius:8px;<?= $isPast?'opacity:.7':'' ?>">
        <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:1rem;flex-wrap:wrap">
            <div style="flex:1">
                <div style="display:flex;align-items:center;gap:.5rem;margin-bottom:.3rem">
                    <span class="badge" style="background:<?= $c[0] ?>;color:<?= $c[1] ?>"><?= ucfirst($e['event_type']) ?></span>
                    <?php if ($e['affects_attendance']): ?>
                    <span class="badge" style="background:#fef3c7;color:#b45309">No Classes</span>
                    <?php endif; ?>
                    <?php if ($isPast): ?>
                    <span class="badge" style="background:#f1f5f9;color:#64748b">Past</span>
                    <?php endif; ?>
                </div>
                <h4 style="margin:.2rem 0"><?= htmlspecialchars($e['title']) ?></h4>
                <div class="text-muted" style="font-size:.85rem;margin-top:.3rem">
                    📅 <?= date('d M Y', strtotime($e['event_date'])) ?>
                    <?php if ($isMultiDay): ?>
                        – <?= date('d M Y', strtotime($e['end_date'])) ?>
                    <?php endif; ?>
                    <?php if ($e['term_name']): ?>
                        &nbsp;|&nbsp; <?= htmlspecialchars($e['term_name']) ?>
                    <?php endif; ?>
                </div>
                <?php if ($e['description']): ?>
                <p style="margin-top:.5rem;font-size:.9rem"><?= nl2br(htmlspecialchars($e['description'])) ?></p>
                <?php endif; ?>
            </div>
            <div style="display:flex;gap:.3rem;flex-wrap:wrap">
                <a href="?edit=<?= $e['id'] ?>" class="btn btn-secondary btn-sm">Edit</a>
                <form method="POST" style="display:inline" onsubmit="return confirm('Delete event?')">
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="id" value="<?= $e['id'] ?>">
                    <button class="btn btn-danger btn-sm">Delete</button>
                </form>
            </div>
        </div>
    </div>
    <?php endforeach; ?>
    </div>
    <?php endif; ?>
</div>
<?php include '../includes/footer.php'; ?>
