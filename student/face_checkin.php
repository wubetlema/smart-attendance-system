<?php
require_once '../includes/auth_check.php';
requireRole('student');
require_once '../config/db.php';
require_once '../includes/functions.php';
$user = currentUser();

// Get active sessions for classes this student is enrolled in
$stmt = $conn->prepare("
    SELECT s.id, s.qr_token, c.name AS class_name, c.subject, s.expires_at
    FROM attendance_sessions s
    JOIN classes c ON s.class_id = c.id
    JOIN class_students cs ON c.id = cs.class_id
    WHERE cs.student_id = ? AND s.expires_at > NOW()
    ORDER BY s.created_at DESC
");
$stmt->bind_param('i', $user['id']);
$stmt->execute();
$activeSessions = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// Get stored face descriptor
$stmt = $conn->prepare("SELECT face_descriptor FROM users WHERE id=?");
$stmt->bind_param('i', $user['id']);
$stmt->execute();
$faceData = $stmt->get_result()->fetch_assoc();

include '../includes/header.php';
?>
<h1>Face Recognition Check-in</h1>

<?php if (empty($activeSessions)): ?>
<div class="alert alert-info">No active attendance sessions found for your classes right now.</div>
<?php else: ?>

<div class="card">
    <h3>Active Sessions</h3>
    <table class="table">
        <thead><tr><th>Class</th><th>Subject</th><th>Expires</th><th>Action</th></tr></thead>
        <tbody>
        <?php foreach ($activeSessions as $s): ?>
        <tr>
            <td><?= htmlspecialchars($s['class_name']) ?></td>
            <td><?= htmlspecialchars($s['subject']) ?></td>
            <td><?= $s['expires_at'] ?></td>
            <td><button class="btn btn-primary btn-sm" onclick="startFaceCheckin(<?= $s['id'] ?>)">Check In</button></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>

<div class="card" id="facePanel" style="display:none">
    <h3>Face Verification</h3>
    <div style="display:flex;flex-direction:column;align-items:center;gap:1rem">
        <video id="video" width="400" height="300" autoplay muted style="border-radius:8px;border:2px solid #ddd"></video>
        <canvas id="canvas" width="400" height="300" style="display:none"></canvas>
        <div id="faceStatus" class="alert alert-info">Loading face recognition models...</div>
        <button id="captureBtn" class="btn btn-primary" style="display:none" onclick="captureFace()">Verify & Check In</button>
    </div>
</div>

<?php endif; ?>

<?php if (!$faceData['face_descriptor']): ?>
<div class="card">
    <h3>Register Your Face</h3>
    <p>You need to register your face first before using face check-in.</p>
    <div style="display:flex;flex-direction:column;align-items:center;gap:1rem">
        <video id="regVideo" width="400" height="300" autoplay muted style="border-radius:8px;border:2px solid #ddd"></video>
        <div id="regStatus" class="alert alert-info">Loading models...</div>
        <button id="registerBtn" class="btn btn-secondary" style="display:none" onclick="registerFace()">Register My Face</button>
    </div>
</div>
<?php endif; ?>

<!-- face-api.js CDN -->
<script src="https://cdn.jsdelivr.net/npm/face-api.js@0.22.2/dist/face-api.min.js"></script>
<script>
const storedDescriptor = <?= $faceData['face_descriptor'] ? $faceData['face_descriptor'] : 'null' ?>;
let currentSessionId = null;
let stream = null;

const MODEL_URL = 'https://cdn.jsdelivr.net/npm/@vladmandic/face-api/model';

async function loadModels() {
    await faceapi.nets.tinyFaceDetector.loadFromUri(MODEL_URL);
    await faceapi.nets.faceLandmark68Net.loadFromUri(MODEL_URL);
    await faceapi.nets.faceRecognitionNet.loadFromUri(MODEL_URL);
    return true;
}

async function startFaceCheckin(sessionId) {
    currentSessionId = sessionId;
    document.getElementById('facePanel').style.display = 'block';
    document.getElementById('facePanel').scrollIntoView({behavior:'smooth'});
    const status = document.getElementById('faceStatus');

    try {
        status.textContent = 'Loading models...';
        await loadModels();
        stream = await navigator.mediaDevices.getUserMedia({ video: true });
        document.getElementById('video').srcObject = stream;
        status.textContent = 'Position your face in the camera and click Verify.';
        status.className = 'alert alert-info';
        document.getElementById('captureBtn').style.display = 'inline-block';
    } catch(e) {
        status.textContent = 'Camera error: ' + e.message;
        status.className = 'alert alert-error';
    }
}

async function captureFace() {
    const status = document.getElementById('faceStatus');
    const video = document.getElementById('video');
    status.textContent = 'Detecting face...';
    status.className = 'alert alert-info';

    const detection = await faceapi.detectSingleFace(video, new faceapi.TinyFaceDetectorOptions())
        .withFaceLandmarks().withFaceDescriptor();

    if (!detection) {
        status.textContent = 'No face detected. Please position your face clearly and try again.';
        status.className = 'alert alert-error';
        return;
    }

    status.textContent = 'Verifying with server...';

    // Send descriptor to PHP for server-side verification — cannot be tampered by browser
    const descriptor = Array.from(detection.descriptor);
    try {
        const res = await fetch('/attendance-system/api/verify_face.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({
                descriptor: descriptor,
                session_id: currentSessionId
            })
        });
        const data = await res.json();

        status.textContent = data.message;
        status.className = data.success ? 'alert alert-success' : 'alert alert-error';

        if (data.success && stream) {
            stream.getTracks().forEach(t => t.stop());
        }
    } catch(e) {
        status.textContent = 'Server error. Please try again.';
        status.className = 'alert alert-error';
    }
}

// Face registration
async function registerFace() {
    const status = document.getElementById('regStatus');
    const video = document.getElementById('regVideo');
    status.textContent = 'Detecting face...';

    const detection = await faceapi.detectSingleFace(video, new faceapi.TinyFaceDetectorOptions())
        .withFaceLandmarks().withFaceDescriptor();

    if (!detection) {
        status.textContent = 'No face detected. Please try again.';
        status.className = 'alert alert-error';
        return;
    }

    const descriptor = Array.from(detection.descriptor);
    const res = await fetch('/attendance-system/api/save_face.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({ descriptor })
    });
    const data = await res.json();
    status.textContent = data.message;
    status.className = data.success ? 'alert alert-success' : 'alert alert-error';
    if (data.success) setTimeout(() => location.reload(), 1500);
}

// Start registration camera if needed
<?php if (!$faceData['face_descriptor']): ?>
(async () => {
    const status = document.getElementById('regStatus');
    try {
        await loadModels();
        const s = await navigator.mediaDevices.getUserMedia({ video: true });
        document.getElementById('regVideo').srcObject = s;
        status.textContent = 'Position your face and click Register.';
        document.getElementById('registerBtn').style.display = 'inline-block';
    } catch(e) {
        status.textContent = 'Camera error: ' + e.message;
        status.className = 'alert alert-error';
    }
})();
<?php endif; ?>
</script>
<?php include '../includes/footer.php'; ?>
