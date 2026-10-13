<?php
require_once '../includes/auth_check.php';
requireRole('student');
require_once '../config/db.php';
require_once '../includes/functions.php';
require_once '../includes/attendance_calculator.php';
$user = currentUser();

$msg = ''; $msgType = 'success';

// ── Full profile with hierarchy ───────────────────────────────────────────────
$stmt = $conn->prepare("
    SELECT u.*,
           ca.name AS campus_name,
           col.name AS college_name,
           d.name AS dept_name, d.short_name AS dept_short,
           p.name AS program_name, p.degree_type,
           b.batch_name, b.entry_year
    FROM users u
    LEFT JOIN campuses ca   ON u.campus_id     = ca.id
    LEFT JOIN colleges col  ON u.college_id    = col.id
    LEFT JOIN departments d ON u.department_id = d.id
    LEFT JOIN programs p    ON u.program_id    = p.id
    LEFT JOIN batches b     ON u.batch_id      = b.id
    WHERE u.id = ?
");
$stmt->bind_param('i', $user['id']);
$stmt->execute();
$profile = $stmt->get_result()->fetch_assoc();

// ── Handle POST actions ───────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    verifyCsrf();

    // ── Update profile info ───────────────────────────────────────────────────
    if ($_POST['action'] === 'update_profile') {
        $name  = trim($_POST['name']  ?? '');
        $email = trim($_POST['email'] ?? '');
        $phone = trim($_POST['phone'] ?? '');
        $photo = $profile['photo'] ?? '';

        if (strlen($name) < 2) {
            $msg = 'Name must be at least 2 characters.'; $msgType = 'error';
        } elseif ($email && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $msg = 'Invalid email address.'; $msgType = 'error';
        } else {
            // Photo upload
            if (!empty($_FILES['photo']['name'])) {
                $ext = strtolower(pathinfo($_FILES['photo']['name'], PATHINFO_EXTENSION));
                if (!in_array($ext, ['jpg','jpeg','png','webp'])) {
                    $msg = 'Photo must be JPG, PNG, or WebP.'; $msgType = 'error';
                } elseif ($_FILES['photo']['size'] > 3 * 1024 * 1024) {
                    $msg = 'Photo must be under 3 MB.'; $msgType = 'error';
                } else {
                    $dir = '../assets/uploads/';
                    if (!is_dir($dir)) mkdir($dir, 0755, true);
                    $fname = 'student_' . $user['id'] . '_' . time() . '.' . $ext;
                    if (move_uploaded_file($_FILES['photo']['tmp_name'], $dir . $fname)) {
                        // Delete old photo
                        if ($photo && file_exists($dir . $photo)) @unlink($dir . $photo);
                        $photo = $fname;
                    }
                }
            }

            if (!$msg) {
                $upd = $conn->prepare("UPDATE users SET name=?, email=?, phone=?, photo=? WHERE id=?");
                $upd->bind_param('ssssi', $name, $email, $phone, $photo, $user['id']);
                if ($upd->execute()) {
                    $_SESSION['name'] = $name;
                    $msg = 'Profile updated successfully.';
                    // Refresh profile
                    $profile['name'] = $name; $profile['email'] = $email;
                    $profile['phone'] = $phone; $profile['photo'] = $photo;
                } else {
                    $msg = 'Error updating profile.'; $msgType = 'error';
                }
            }
        }

    // ── Change password ───────────────────────────────────────────────────────
    } elseif ($_POST['action'] === 'change_password') {
        $current  = $_POST['current_password']  ?? '';
        $new      = $_POST['new_password']       ?? '';
        $confirm  = $_POST['confirm_password']   ?? '';

        $row = $conn->prepare("SELECT password FROM users WHERE id=?");
        $row->bind_param('i', $user['id']); $row->execute();
        $pwRow = $row->get_result()->fetch_assoc();

        $errors = [];
        if (!password_verify($current, $pwRow['password']))        $errors[] = 'Current password is incorrect.';
        if (strlen($new) < 10)                                     $errors[] = 'New password must be at least 10 characters.';
        if (!preg_match('/[A-Z]/', $new))                          $errors[] = 'Must contain at least one uppercase letter.';
        if (!preg_match('/[0-9]/', $new))                          $errors[] = 'Must contain at least one number.';
        if (!preg_match('/[^A-Za-z0-9]/', $new))                   $errors[] = 'Must contain at least one special character.';
        if ($new === $current)                                     $errors[] = 'New password must differ from current.';
        if ($new !== $confirm)                                     $errors[] = 'Passwords do not match.';

        if ($errors) {
            $msg = implode(' ', $errors); $msgType = 'error';
        } else {
            $hash = password_hash($new, PASSWORD_BCRYPT, ['cost' => 12]);
            $upd  = $conn->prepare("UPDATE users SET password=?, is_first_login=0, force_password_reset=0, last_password_change=NOW() WHERE id=?");
            $upd->bind_param('si', $hash, $user['id']);
            $upd->execute();
            $_SESSION['is_first_login'] = false;
            $msg = 'Password changed successfully.';
        }
    }
}

// ── Attendance summary ────────────────────────────────────────────────────────
$overall      = AttendanceCalculator::studentOverall($user['id']);
$classSummary = AttendanceCalculator::studentSummary($user['id']);

// ── Face registration status ──────────────────────────────────────────────────
$hasFace = !empty($profile['face_descriptor']);

// ── Login history (last 5) ────────────────────────────────────────────────────
$loginHistory = $conn->query("
    SELECT browser, os, device_type, ip_address, created_at, event_type
    FROM device_history
    WHERE user_id={$user['id']}
    ORDER BY created_at DESC LIMIT 5
")->fetch_all(MYSQLI_ASSOC);

include '../includes/header.php';
?>

<h1>My Profile</h1>

<?php if ($msg): ?>
<div class="alert alert-<?= $msgType ?>"><?= htmlspecialchars($msg) ?></div>
<?php endif; ?>

<!-- ── Profile header card ───────────────────────────────────────────────── -->
<div class="card" style="background:linear-gradient(135deg,#1e1b4b,#312e81);color:#fff;border:none;margin-bottom:1.5rem">
    <div style="display:flex;align-items:center;gap:1.5rem;flex-wrap:wrap">
        <div style="position:relative;flex-shrink:0">
            <?php if (!empty($profile['photo'])): ?>
            <img src="/attendance-system/assets/uploads/<?= htmlspecialchars($profile['photo']) ?>"
                 style="width:90px;height:90px;border-radius:50%;object-fit:cover;border:3px solid rgba(255,255,255,.4)">
            <?php else: ?>
            <div style="width:90px;height:90px;border-radius:50%;background:rgba(255,255,255,.15);display:flex;align-items:center;justify-content:center;font-size:2.2rem">👤</div>
            <?php endif; ?>
            <div style="position:absolute;bottom:2px;right:2px;width:18px;height:18px;border-radius:50%;background:<?= $hasFace?'#16a34a':'#d97706' ?>;border:2px solid #fff" title="<?= $hasFace?'Face registered':'Face not registered' ?>"></div>
        </div>
        <div style="flex:1">
            <h2 style="margin:0;color:#fff;font-size:1.4rem"><?= htmlspecialchars($profile['name']) ?></h2>
            <code style="background:rgba(255,255,255,.15);padding:.2rem .6rem;border-radius:6px;font-size:.85rem;margin-top:.3rem;display:inline-block">
                <?= htmlspecialchars($profile['university_id'] ?? '') ?>
            </code>
        </div>
        <div style="text-align:right;font-size:.85rem;opacity:.85">
            <?php if ($profile['program_name']): ?>
            <div style="font-weight:600"><?= htmlspecialchars(($profile['degree_type']??'').' '.$profile['program_name']) ?></div>
            <?php endif; ?>
            <?php if ($profile['dept_name']): ?>
            <div><?= htmlspecialchars($profile['dept_name']) ?></div>
            <?php endif; ?>
            <?php if ($profile['college_name']): ?>
            <div style="opacity:.7"><?= htmlspecialchars($profile['college_name']) ?></div>
            <?php endif; ?>
            <?php if ($profile['year_of_study']): ?>
            <div>Year <?= $profile['year_of_study'] ?><?= $profile['section']?' · Section '.$profile['section']:'' ?></div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- ── Overall attendance ────────────────────────────────────────────────── -->
<?php $ost = $overall['status']; ?>
<div class="stats-grid" style="margin-bottom:1.5rem">
    <div class="stat-card" style="border-color:<?= $ost['color'] ?>">
        <div class="stat-number" style="color:<?= $ost['color'] ?>"><?= $overall['pct'] !== null ? $overall['pct'].'%' : '–' ?></div>
        <div class="stat-label">Overall Attendance</div>
    </div>
    <div class="stat-card" style="border-color:#16a34a">
        <div class="stat-number" style="color:#16a34a"><?= $overall['present'] ?></div>
        <div class="stat-label">Present</div>
    </div>
    <div class="stat-card" style="border-color:#d97706">
        <div class="stat-number" style="color:#d97706"><?= $overall['late'] ?></div>
        <div class="stat-label">Late</div>
    </div>
    <div class="stat-card" style="border-color:#dc2626">
        <div class="stat-number" style="color:#dc2626"><?= $overall['absent'] ?></div>
        <div class="stat-label">Absent</div>
    </div>
</div>

<div style="display:grid;grid-template-columns:1fr 1fr;gap:1.5rem">

<!-- Left: Edit profile + face status -->
<div>

<div class="card">
    <h3>Edit Profile</h3>
    <form method="POST" enctype="multipart/form-data">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="update_profile">

        <!-- Photo upload -->
        <div style="display:flex;align-items:center;gap:1rem;margin-bottom:1.25rem">
            <div id="photoPreviewWrap">
                <?php if (!empty($profile['photo'])): ?>
                <img id="photoPreview" src="/attendance-system/assets/uploads/<?= htmlspecialchars($profile['photo']) ?>"
                     style="width:72px;height:72px;border-radius:50%;object-fit:cover;border:2px solid var(--primary)">
                <?php else: ?>
                <div id="photoPreview" style="width:72px;height:72px;border-radius:50%;background:#e2e8f0;display:flex;align-items:center;justify-content:center;font-size:2rem">👤</div>
                <?php endif; ?>
            </div>
            <div style="flex:1">
                <label style="font-size:.85rem;font-weight:600">Profile Photo</label>
                <input type="file" name="photo" accept="image/jpeg,image/png,image/webp" onchange="previewPhoto(this)">
                <small class="text-muted">JPG, PNG, WebP · Max 3 MB</small>
            </div>
        </div>

        <div class="form-group">
            <label>Full Name</label>
            <input type="text" name="name" value="<?= htmlspecialchars($profile['name']) ?>" required>
        </div>
        <div class="form-group">
            <label>University ID</label>
            <input type="text" value="<?= htmlspecialchars($profile['university_id'] ?? '') ?>" disabled style="background:var(--bg);opacity:.7">
            <small class="text-muted">Cannot be changed. Contact admin if incorrect.</small>
        </div>
        <div class="form-group">
            <label>Email Address</label>
            <input type="email" name="email" value="<?= htmlspecialchars($profile['email'] ?? '') ?>" placeholder="your@email.com">
        </div>
        <div class="form-group">
            <label>Phone Number</label>
            <input type="tel" name="phone" value="<?= htmlspecialchars($profile['phone'] ?? '') ?>" placeholder="+251...">
        </div>
        <button type="submit" class="btn btn-primary btn-full">Save Changes</button>
    </form>
</div>

<!-- Academic information (read-only) -->
<div class="card">
    <h3>Academic Information</h3>
    <table style="width:100%;border-collapse:collapse;font-size:.9rem">
    <?php
    $fields = [
        'Department'    => $profile['dept_name'] ?? '–',
        'Program'       => ($profile['degree_type']??'').' '.($profile['program_name']??'–'),
        'Year of Study' => $profile['year_of_study'] ? 'Year '.$profile['year_of_study'] : '–',
        'Semester'      => $profile['semester'] ?? '–',
        'Section'       => $profile['section'] ?? '–',
        'Batch'         => $profile['batch_name'] ?? '–',
        'College'       => $profile['college_name'] ?? '–',
        'Campus'        => $profile['campus_name'] ?? '–',
    ];
    foreach ($fields as $label => $value): ?>
    <tr style="border-bottom:1px solid var(--border)">
        <td style="padding:.55rem 0;color:var(--text-muted);font-weight:600;width:45%"><?= $label ?></td>
        <td style="padding:.55rem 0"><?= htmlspecialchars($value) ?></td>
    </tr>
    <?php endforeach; ?>
    </table>
    <p class="text-muted" style="margin-top:.75rem;font-size:.8rem">Academic details are managed by the admin. Contact your department to update them.</p>
</div>

<!-- Face registration -->
<div class="card" style="border-left:4px solid <?= $hasFace?'#16a34a':'#d97706' ?>">
    <h3><?= $hasFace ? '✅ Face Registration' : '⚠️ Face Not Registered' ?></h3>
    <?php if ($hasFace): ?>
    <p class="text-muted">Your face is registered. You can use face check-in.</p>
    <?php if (!empty($profile['face_admin_approved']) && !$profile['face_revoked']): ?>
    <p style="font-size:.82rem;color:#16a34a">✅ Approved and active.</p>
    <?php elseif ($profile['face_revoked']): ?>
    <p style="font-size:.82rem;color:#dc2626">🚫 Revoked by admin. Contact your department.</p>
    <?php else: ?>
    <p style="font-size:.82rem;color:#d97706">⏳ Pending admin approval.</p>
    <?php endif; ?>
    <div style="display:flex;gap:.5rem;flex-wrap:wrap;margin-top:.75rem">
        <a href="face_checkin.php" class="btn btn-primary btn-sm">Use Face Check-in</a>
        <a href="face_checkin.php" class="btn btn-secondary btn-sm">Update Face</a>
        <button class="btn btn-sm btn-danger" onclick="confirmDeleteFace()">🗑️ Delete My Face Data</button>
    </div>

    <!-- Delete face confirmation -->
    <div id="deleteFacePanel" style="display:none;margin-top:1rem;padding:1rem;background:#fef2f2;border-radius:8px;border:1px solid #fca5a5">
        <p style="color:#b91c1c;font-size:.88rem;margin-bottom:.75rem">
            ⚠️ This will permanently delete your face data. You will need to register again to use face check-in.
            Enter your password to confirm.
        </p>
        <div style="display:flex;gap:.5rem;flex-wrap:wrap">
            <input type="password" id="deleteFacePassword" placeholder="Enter your password" style="flex:1;min-width:160px">
            <button class="btn btn-danger btn-sm" onclick="deleteFace()">Confirm Delete</button>
            <button class="btn btn-secondary btn-sm" onclick="document.getElementById('deleteFacePanel').style.display='none'">Cancel</button>
        </div>
        <div id="deleteFaceStatus" style="margin-top:.5rem;font-size:.85rem"></div>
    </div>
    <?php else: ?>
    <p class="text-muted">Register your face to enable face recognition check-in.</p>
    <a href="face_checkin.php" class="btn btn-primary btn-sm">Register Face Now</a>
    <?php endif; ?>
</div>

</div><!-- end left col -->

<!-- Right: Password + courses + login history -->
<div>

<!-- Change password -->
<div class="card">
    <h3>Change Password</h3>
    <form method="POST" autocomplete="off" id="pwForm">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="change_password">
        <div class="form-group">
            <label>Current Password</label>
            <input type="password" name="current_password" required autocomplete="current-password">
        </div>
        <div class="form-group">
            <label>New Password</label>
            <input type="password" name="new_password" id="newPw" required autocomplete="new-password"
                   placeholder="Min 10 chars, uppercase, number, symbol"
                   oninput="checkPw(this.value)">
            <div style="height:5px;background:var(--border);border-radius:4px;margin-top:.4rem;overflow:hidden">
                <div id="strengthBar" style="height:100%;width:0;border-radius:4px;transition:width .3s,background .3s"></div>
            </div>
            <div id="strengthText" style="font-size:.78rem;color:var(--text-muted);margin-top:.2rem">Enter a password</div>
            <div style="margin-top:.5rem;display:flex;flex-direction:column;gap:.15rem">
                <div class="pw-req" id="req-len"  style="font-size:.78rem;color:var(--text-muted)">○ At least 10 characters</div>
                <div class="pw-req" id="req-upper"style="font-size:.78rem;color:var(--text-muted)">○ One uppercase letter</div>
                <div class="pw-req" id="req-num"  style="font-size:.78rem;color:var(--text-muted)">○ One number</div>
                <div class="pw-req" id="req-sym"  style="font-size:.78rem;color:var(--text-muted)">○ One special character</div>
            </div>
        </div>
        <div class="form-group">
            <label>Confirm New Password</label>
            <input type="password" name="confirm_password" id="confirmPw" required autocomplete="new-password"
                   oninput="checkMatch()">
            <div id="matchText" style="font-size:.78rem;margin-top:.2rem"></div>
        </div>
        <button type="submit" class="btn btn-primary btn-full">Change Password</button>
        <div style="text-align:center;margin-top:.75rem">
            <a href="/attendance-system/auth/change_password.php" style="font-size:.85rem;color:var(--text-muted)">
                Use dedicated password change page
            </a>
        </div>
    </form>
</div>

<!-- Enrolled courses with attendance -->
<?php if (!empty($classSummary)): ?>
<div class="card">
    <h3>My Courses & Attendance</h3>
    <div style="display:flex;flex-direction:column;gap:.65rem">
    <?php foreach ($classSummary as $cs):
        $st = $cs['status'];
    ?>
    <div style="padding:.75rem;border-radius:8px;border:1px solid var(--border)">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:.35rem">
            <div style="font-size:.88rem">
                <?php if ($cs['course_code']): ?>
                <code style="font-size:.75rem;margin-right:.3rem"><?= htmlspecialchars($cs['course_code']) ?></code>
                <?php endif; ?>
                <strong><?= htmlspecialchars($cs['class_name']) ?></strong>
            </div>
            <span style="font-weight:700;color:<?= $st['color'] ?>;font-size:.9rem"><?= $cs['pct_display'] ?></span>
        </div>
        <div style="height:6px;background:var(--border);border-radius:3px;overflow:hidden">
            <div style="height:100%;width:<?= min(100, $cs['pct']??0) ?>%;background:<?= $st['color'] ?>;border-radius:3px"></div>
        </div>
        <small class="text-muted">
            <?= $cs['present'] ?> present · <?= $cs['late'] ?> late · <?= $cs['absent'] ?> absent
            <?= $st['class'] !== 'good' ? ' · <span style="color:'.$st['color'].'">'.$st['icon'].' '.$st['label'].'</span>' : '' ?>
        </small>
    </div>
    <?php endforeach; ?>
    </div>
    <a href="my_attendance.php" class="btn btn-secondary btn-sm" style="margin-top:.75rem">View Full History →</a>
</div>
<?php endif; ?>

<!-- Login history -->
<?php if (!empty($loginHistory)): ?>
<div class="card">
    <h3>Recent Login Activity</h3>
    <div style="display:flex;flex-direction:column;gap:.4rem">
    <?php foreach ($loginHistory as $lh):
        $evColors = ['login'=>['#dcfce7','#15803d'],'logout'=>['#f1f5f9','#475569'],'force_logout'=>['#fee2e2','#b91c1c'],'password_change'=>['#dbeafe','#1e40af']];
        [$evBg,$evFg] = $evColors[$lh['event_type']] ?? ['#f1f5f9','#475569'];
    ?>
    <div style="display:flex;justify-content:space-between;align-items:center;padding:.5rem 0;border-bottom:1px solid var(--border);font-size:.82rem">
        <div>
            <span style="background:<?= $evBg ?>;color:<?= $evFg ?>;padding:.15rem .5rem;border-radius:10px;font-size:.72rem;font-weight:700"><?= ucfirst(str_replace('_',' ',$lh['event_type'])) ?></span>
            <span class="text-muted" style="margin-left:.4rem"><?= htmlspecialchars($lh['browser'].' · '.$lh['device_type']) ?></span>
            <br><code style="font-size:.72rem"><?= htmlspecialchars($lh['ip_address']) ?></code>
        </div>
        <span class="text-muted"><?= date('d M H:i', strtotime($lh['created_at'])) ?></span>
    </div>
    <?php endforeach; ?>
    </div>
    <a href="/attendance-system/auth/sessions.php" class="btn btn-secondary btn-sm" style="margin-top:.5rem">Manage Sessions →</a>
</div>
<?php endif; ?>

</div><!-- end right col -->
</div><!-- end grid -->

<script>
function previewPhoto(input) {
    if (!input.files || !input.files[0]) return;
    const reader = new FileReader();
    reader.onload = e => {
        const wrap = document.getElementById('photoPreviewWrap');
        wrap.innerHTML = `<img id="photoPreview" src="${e.target.result}"
            style="width:72px;height:72px;border-radius:50%;object-fit:cover;border:2px solid var(--primary)">`;
    };
    reader.readAsDataURL(input.files[0]);
}

function checkPw(pw) {
    const reqs = {
        'req-len':   pw.length >= 10,
        'req-upper': /[A-Z]/.test(pw),
        'req-num':   /[0-9]/.test(pw),
        'req-sym':   /[^A-Za-z0-9]/.test(pw),
    };
    let score = Object.values(reqs).filter(Boolean).length;
    Object.entries(reqs).forEach(([id, met]) => {
        const el = document.getElementById(id);
        el.textContent = (met ? '✓ ' : '○ ') + el.textContent.replace(/^[✓○] /, '');
        el.style.color  = met ? '#16a34a' : 'var(--text-muted)';
    });
    const levels = [
        { pct:'25%', bg:'#dc2626', text:'Weak' },
        { pct:'50%', bg:'#f97316', text:'Fair' },
        { pct:'75%', bg:'#eab308', text:'Good' },
        { pct:'100%',bg:'#16a34a', text:'Strong ✓' },
    ];
    const l = levels[Math.min(score-1, 3)];
    const bar   = document.getElementById('strengthBar');
    const label = document.getElementById('strengthText');
    bar.style.width      = pw.length ? (l?.pct ?? '0') : '0';
    bar.style.background = l?.bg ?? '#e2e8f0';
    label.textContent    = pw.length ? (l?.text ?? '') : 'Enter a password';
    label.style.color    = l?.bg ?? 'var(--text-muted)';
    checkMatch();
}

function checkMatch() {
    const pw   = document.getElementById('newPw').value;
    const conf = document.getElementById('confirmPw').value;
    const el   = document.getElementById('matchText');
    if (!conf) { el.textContent = ''; return; }
    if (pw === conf) { el.textContent = '✓ Passwords match'; el.style.color = '#16a34a'; }
    else             { el.textContent = '✗ Passwords do not match'; el.style.color = '#dc2626'; }
}

function confirmDeleteFace() {
    document.getElementById('deleteFacePanel').style.display = 'block';
    document.getElementById('deleteFacePassword').focus();
}

async function deleteFace() {
    const pw  = document.getElementById('deleteFacePassword').value;
    const st  = document.getElementById('deleteFaceStatus');
    if (!pw) { st.textContent = 'Enter your password.'; st.style.color='#dc2626'; return; }

    st.textContent = 'Deleting...'; st.style.color = '#64748b';
    try {
        const res  = await fetch('/attendance-system/api/delete_face.php', {
            method: 'POST', headers: {'Content-Type':'application/json'},
            body: JSON.stringify({ password: pw })
        });
        const data = await res.json();
        st.textContent = data.message;
        st.style.color = data.success ? '#16a34a' : '#dc2626';
        if (data.success) setTimeout(() => location.reload(), 2000);
    } catch(e) {
        st.textContent = 'Server error. Please try again.'; st.style.color = '#dc2626';
    }
}
</script>

<?php include '../includes/footer.php'; ?>
