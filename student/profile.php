<?php
require_once '../includes/auth_check.php';
requireRole('student');
require_once '../config/db.php';
$user = currentUser();

$msg = '';
$msgType = 'success';

// Fetch full profile
$stmt = $conn->prepare("SELECT name, email, university_id, phone, photo FROM users WHERE id=?");
$stmt->bind_param('i', $user['id']);
$stmt->execute();
$profile = $stmt->get_result()->fetch_assoc();

// Handle profile update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {

    if ($_POST['action'] === 'update_profile') {
        $name  = trim($_POST['name']);
        $email = trim($_POST['email']);
        $phone = trim($_POST['phone']);
        $photo = $profile['photo'];

        // Handle photo upload
        if (!empty($_FILES['photo']['name'])) {
            $ext     = strtolower(pathinfo($_FILES['photo']['name'], PATHINFO_EXTENSION));
            $allowed = ['jpg','jpeg','png','gif'];
            if (!in_array($ext, $allowed)) {
                $msg = 'Only JPG, PNG, GIF allowed.';
                $msgType = 'error';
            } else {
                $uploadDir = '../assets/uploads/';
                if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);
                $filename = 'student_' . $user['id'] . '_' . time() . '.' . $ext;
                if (move_uploaded_file($_FILES['photo']['tmp_name'], $uploadDir . $filename)) {
                    $photo = $filename;
                }
            }
        }

        if (!$msg) {
            $stmt = $conn->prepare("UPDATE users SET name=?, email=?, phone=?, photo=? WHERE id=?");
            $stmt->bind_param('ssssi', $name, $email, $phone, $photo, $user['id']);
            if ($stmt->execute()) {
                $_SESSION['name'] = $name;
                $msg = 'Profile updated successfully.';
                $profile['name']  = $name;
                $profile['email'] = $email;
                $profile['phone'] = $phone;
                $profile['photo'] = $photo;
            }
        }
    }

    if ($_POST['action'] === 'change_password') {
        $current = $_POST['current_password'];
        $new     = $_POST['new_password'];
        $confirm = $_POST['confirm_password'];

        $stmt = $conn->prepare("SELECT password FROM users WHERE id=?");
        $stmt->bind_param('i', $user['id']);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();

        if (!password_verify($current, $row['password'])) {
            $msg = 'Current password is incorrect.';
            $msgType = 'error';
        } elseif ($new !== $confirm) {
            $msg = 'New passwords do not match.';
            $msgType = 'error';
        } elseif (strlen($new) < 6) {
            $msg = 'Password must be at least 6 characters.';
            $msgType = 'error';
        } else {
            $hash = password_hash($new, PASSWORD_DEFAULT);
            $stmt = $conn->prepare("UPDATE users SET password=? WHERE id=?");
            $stmt->bind_param('si', $hash, $user['id']);
            $stmt->execute();
            $msg = 'Password changed successfully.';
        }
    }
}

include '../includes/header.php';
?>
<h1>My Profile</h1>

<?php if ($msg): ?>
<div class="alert alert-<?= $msgType ?>"><?= htmlspecialchars($msg) ?></div>
<?php endif; ?>

<div style="display:grid;grid-template-columns:1fr 1fr;gap:1.5rem;flex-wrap:wrap">

<!-- Profile Info -->
<div class="card">
    <h3>Edit Profile</h3>
    <form method="POST" enctype="multipart/form-data">
        <input type="hidden" name="action" value="update_profile">

        <div style="text-align:center;margin-bottom:1rem">
            <?php if ($profile['photo']): ?>
                <img src="/attendance-system/assets/uploads/<?= htmlspecialchars($profile['photo']) ?>"
                     style="width:100px;height:100px;border-radius:50%;object-fit:cover;border:3px solid var(--primary)">
            <?php else: ?>
                <div style="width:100px;height:100px;border-radius:50%;background:#e2e8f0;display:flex;align-items:center;justify-content:center;margin:0 auto;font-size:2.5rem">👤</div>
            <?php endif; ?>
        </div>

        <div class="form-group">
            <label>Profile Photo</label>
            <input type="file" name="photo" accept="image/*">
        </div>
        <div class="form-group">
            <label>Full Name</label>
            <input type="text" name="name" value="<?= htmlspecialchars($profile['name']) ?>" required>
        </div>
        <div class="form-group">
            <label>University ID</label>
            <input type="text" value="<?= htmlspecialchars($profile['university_id'] ?? '') ?>" disabled style="background:#f1f5f9">
        </div>
        <div class="form-group">
            <label>Email</label>
            <input type="email" name="email" value="<?= htmlspecialchars($profile['email'] ?? '') ?>" placeholder="your@email.com">
        </div>
        <div class="form-group">
            <label>Phone</label>
            <input type="text" name="phone" value="<?= htmlspecialchars($profile['phone'] ?? '') ?>" placeholder="+251...">
        </div>
        <button type="submit" class="btn btn-primary">Save Changes</button>
    </form>
</div>

<!-- Change Password -->
<div class="card">
    <h3>Change Password</h3>
    <form method="POST" autocomplete="off">
        <input type="hidden" name="action" value="change_password">
        <div class="form-group">
            <label>Current Password</label>
            <input type="password" name="current_password" required autocomplete="new-password">
        </div>
        <div class="form-group">
            <label>New Password</label>
            <input type="password" name="new_password" required autocomplete="new-password" placeholder="Min 6 characters">
        </div>
        <div class="form-group">
            <label>Confirm New Password</label>
            <input type="password" name="confirm_password" required autocomplete="new-password">
        </div>
        <button type="submit" class="btn btn-primary">Change Password</button>
    </form>
</div>

</div>
<?php include '../includes/footer.php'; ?>
