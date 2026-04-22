<?php
require_once '../includes/auth_check.php';
requireRole('admin');
require_once '../config/db.php';

if (isset($_GET['action']) && $_GET['action'] === 'backup') {
    $tables = $conn->query("SHOW TABLES")->fetch_all(MYSQLI_NUM);
    $sql = "-- SmartAttend Database Backup\n-- Generated: " . date('Y-m-d H:i:s') . "\n\n";
    $sql .= "SET FOREIGN_KEY_CHECKS=0;\n\n";

    foreach ($tables as $table) {
        $t = $table[0];
        $sql .= "DROP TABLE IF EXISTS `$t`;\n";
        $create = $conn->query("SHOW CREATE TABLE `$t`")->fetch_assoc();
        $sql .= $create['Create Table'] . ";\n\n";

        $rows = $conn->query("SELECT * FROM `$t`");
        while ($row = $rows->fetch_assoc()) {
            $vals = array_map(fn($v) => $v === null ? 'NULL' : "'" . $conn->real_escape_string($v) . "'", array_values($row));
            $sql .= "INSERT INTO `$t` VALUES (" . implode(',', $vals) . ");\n";
        }
        $sql .= "\n";
    }

    $sql .= "SET FOREIGN_KEY_CHECKS=1;\n";

    header('Content-Type: application/sql');
    header('Content-Disposition: attachment; filename="smartattend_backup_' . date('Ymd_His') . '.sql"');
    echo $sql;
    exit;
}

// List backup files
$backupDir = __DIR__ . '/backups/';
if (!is_dir($backupDir)) mkdir($backupDir, 0755, true);
$files = glob($backupDir . '*.sql') ?: [];

include '../includes/header.php';
?>
<h1>Database Backup</h1>

<div class="card">
    <h3>Create Backup</h3>
    <p class="text-muted" style="margin-bottom:1rem">Downloads a full SQL backup of all tables and data.</p>
    <a href="?action=backup" class="btn btn-primary">Download Backup Now</a>
</div>

<div class="card">
    <h3>Restore from SQL File</h3>
    <p class="text-muted" style="margin-bottom:1rem">Upload a previously downloaded .sql backup file to restore data.</p>
    <?php if (isset($restoreMsg)): ?>
        <div class="alert alert-<?= $restoreType ?>"><?= htmlspecialchars($restoreMsg) ?></div>
    <?php endif; ?>
    <form method="POST" enctype="multipart/form-data">
        <div class="form-group">
            <label>Select .sql file</label>
            <input type="file" name="sql_file" accept=".sql" required>
        </div>
        <div class="alert alert-error" style="margin-bottom:1rem">
            ⚠️ Warning: Restoring will overwrite existing data. Make a backup first.
        </div>
        <button type="submit" name="restore" class="btn btn-danger" onclick="return confirm('This will overwrite all data. Are you sure?')">Restore Database</button>
    </form>
</div>

<?php
// Handle restore
if (isset($_POST['restore']) && !empty($_FILES['sql_file']['tmp_name'])) {
    $sqlContent = file_get_contents($_FILES['sql_file']['tmp_name']);
    $queries = array_filter(array_map('trim', explode(";\n", $sqlContent)));
    $errors = 0;
    foreach ($queries as $q) {
        if ($q && !$conn->query($q)) $errors++;
    }
    $restoreMsg  = $errors === 0 ? 'Database restored successfully.' : "Restored with $errors error(s).";
    $restoreType = $errors === 0 ? 'success' : 'error';
    echo "<script>document.querySelector('.alert-error + button') && null</script>";
    echo "<div class='alert alert-$restoreType' style='margin-top:1rem'>$restoreMsg</div>";
}
?>
<?php include '../includes/footer.php'; ?>
