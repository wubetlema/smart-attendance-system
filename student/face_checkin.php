<?php
require_once '../includes/auth_check.php';
requireRole('student');
require_once '../config/db.php';
require_once '../includes/functions.php';
$user = currentUser();

// Get active sessions for enrolled classes (open or late state only)
$stmt = $conn->prepare("
    SELECT s.id, c.name AS class_name, c.subject, s.status,
           s.open_at, s.present_until, s.late_until, s.expires_at
    FROM attendance_sessions s
    JOIN classes c ON s.class_id = c.id
    JOIN class_students cs ON c.id = cs.class_id
    WHERE cs.student_id = ?
      AND s.status IN ('open','late')
      AND s.late_until > NOW()
    ORDER BY s.open_at DESC
");
$stmt->bind_param('i', $user['id']);
$stmt->execute();
$activeSessions = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// Already marked in any of these sessions?
$markedSessionIds = [];
if (!empty($activeSessions)) {
    $ids = implode(',', array_column($activeSessions, 'id'));
    $marked = $conn->query("
        SELECT session_id FROM attendance
        WHERE student_id = {$user['id']} AND session_id IN ($ids)
    ")->fetch_all(MYSQLI_ASSOC);
    $markedSessionIds = array_column($marked, 'session_id');
}

// Stored face descriptor
$stmt = $conn->prepare("SELECT face_descriptor FROM users WHERE id=?");
$stmt->bind_param('i', $user['id']);
$stmt->execute();
$faceData = $stmt->get_result()->fetch_assoc();
$hasFace  = !empty($faceData['face_descriptor']);

include '../includes/header.php';
?>

<h1>Face Recognition Check-in</h1>

<!-- Primary method reminder -->
<div class="alert alert-info" style="display:flex;align-items:center;gap:.75rem">
    <span style="font-size:1.4rem">💡</span>
    <span>
        <strong>QR Check-in is the primary method.</strong>
        Use face check-in only when QR is not available.
        <a href="/attendance-system/student/qr_checkin.php" class="btn btn-primary btn-sm" style="margin-left:.75rem">Switch to QR →</a>
    </span>
</div>

<?php if (empty($activeSessions)): ?>
<div class="alert alert-info">No open attendance sessions found for your classes right now.</div>
<?php else: ?>

<!-- Session Selection -->
<div class="card">
    <h3>Select Your Class Session</h3>
    <div style="display:grid;gap:.75rem">
    <?php foreach ($activeSessions as $s):
        $isLate    = $s['status'] === 'late';
        $isMarked  = in_array($s['id'], $markedSessionIds);
        $closesAt  = date('H:i', strtotime($s['late_until']));
        $lateFrom  = date('H:i', strtotime($s['present_until']));
    ?>
    <div style="padding:1rem;border-radius:10px;border:1.5px solid <?= $isMarked ? '#86efac' : ($isLate ? '#fde68a' : '#c7d2fe') ?>;background:<?= $isMarked ? '#f0fdf4' : ($isLate ? '#fffbeb' : '#f5f3ff') ?>">
        <div style="display:flex;justify-content:space-between;align-items:flex-start;flex-wrap:wrap;gap:.5rem">
            <div>
                <strong><?= htmlspecialchars($s['class_name']) ?></strong>
                <span class="text-muted"> — <?= htmlspecialchars($s['subject']) ?></span>
                <br>
                <small class="text-muted">
                    <?php if ($isLate): ?>
                        ⏰ <span style="color:#d97706;font-weight:600">Late Period</span> — closes at <?= $closesAt ?>
                    <?php else: ?>
                        ✅ <span style="color:#059669;font-weight:600">Open</span> — on-time until <?= $lateFrom ?>, closes at <?= $closesAt ?>
                    <?php endif; ?>
                </small>
            </div>
            <div>
                <?php if ($isMarked): ?>
                <span class="badge" style="background:#dcfce7;color:#15803d">✓ Already Marked</span>
                <?php else: ?>
                <button class="btn btn-primary btn-sm" onclick="selectSession(<?= $s['id'] ?>, '<?= htmlspecialchars($s['class_name'],ENT_QUOTES) ?>')">
                    Check In Here
                </button>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <?php endforeach; ?>
    </div>
</div>

<?php endif; ?>

<!-- Face Check-in Pipeline Panel (hidden until session selected) -->
<div id="checkinPanel" style="display:none">
    <div class="card">
        <h3 id="panelTitle">Face Check-in</h3>

        <!-- Step Indicator -->
        <div style="display:flex;gap:0;margin-bottom:1.5rem;border-radius:8px;overflow:hidden;border:1px solid #e2e8f0">
            <?php
            $steps = [
                ['icon'=>'📷','label'=>'Camera'],
                ['icon'=>'😐','label'=>'Face Detect'],
                ['icon'=>'👁️','label'=>'Liveness'],
                ['icon'=>'🔍','label'=>'Verify'],
                ['icon'=>'📋','label'=>'Enroll Check'],
                ['icon'=>'⏱️','label'=>'Session'],
                ['icon'=>'✅','label'=>'Done'],
            ];
            foreach ($steps as $i => $step):
            ?>
            <div class="pipeline-step" id="step-<?= $i ?>"
                 style="flex:1;padding:.5rem .25rem;text-align:center;font-size:.72rem;font-weight:600;
                        background:#f8fafc;color:#94a3b8;border-right:1px solid #e2e8f0;transition:all .3s">
                <div style="font-size:1rem"><?= $step['icon'] ?></div>
                <?= $step['label'] ?>
            </div>
            <?php endforeach; ?>
        </div>

        <!-- Camera -->
        <div style="display:flex;flex-direction:column;align-items:center;gap:1rem">
            <div style="position:relative">
                <video id="video" width="400" height="300" autoplay muted playsinline
                       style="border-radius:12px;border:2px solid #6366f1;background:#000;max-width:100%"></video>
                <!-- Liveness overlay -->
                <div id="livenessOverlay" style="display:none;position:absolute;inset:0;border-radius:12px;
                     background:rgba(99,102,241,.15);display:flex;align-items:center;justify-content:center">
                    <div style="background:rgba(0,0,0,.75);color:#fff;padding:.75rem 1.25rem;border-radius:10px;text-align:center;font-weight:600">
                        <div style="font-size:2rem" id="livenessIcon">👁️</div>
                        <div id="livenessInstruction">Follow the instruction</div>
                    </div>
                </div>
                <canvas id="canvas" width="400" height="300" style="display:none"></canvas>
            </div>

            <div id="statusBox" class="alert alert-info" style="width:100%;max-width:400px">
                Loading face recognition models...
            </div>

            <div style="display:flex;gap:.75rem;flex-wrap:wrap;justify-content:center">
                <button id="startBtn"   class="btn btn-primary"   style="display:none" onclick="startPipeline()">Start Face Scan</button>
                <button id="retryBtn"   class="btn btn-secondary" style="display:none" onclick="resetPipeline()">Try Again</button>
                <button id="cancelBtn"  class="btn btn-secondary" onclick="cancelCheckin()">Cancel</button>
            </div>
        </div>
    </div>
</div>

<!-- Face Registration (if no face on file) -->
<?php if (!$hasFace): ?>
<div class="card" style="border-left:4px solid #f59e0b">
    <h3>⚠️ Register Your Face First</h3>
    <p>You must register your face before using face check-in.</p>
    <div style="display:flex;flex-direction:column;align-items:center;gap:1rem">
        <video id="regVideo" width="400" height="300" autoplay muted playsinline
               style="border-radius:12px;border:2px solid #f59e0b;background:#000;max-width:100%"></video>
        <div id="regStatus" class="alert alert-info" style="width:100%;max-width:400px">Loading models...</div>
        <div style="display:flex;gap:.75rem">
            <button id="registerBtn" class="btn btn-primary" style="display:none" onclick="captureRegistration()">Capture & Register</button>
            <button id="retakeBtn"   class="btn btn-secondary" style="display:none" onclick="retakeRegistration()">Retake</button>
        </div>
    </div>
    <div class="alert alert-info" style="margin-top:1rem;font-size:.88rem">
        💡 <strong>Tips for a good registration:</strong>
        Face the camera directly · Good lighting · Remove glasses if possible · Stay still
    </div>
</div>
<?php endif; ?>

<script src="https://cdn.jsdelivr.net/npm/face-api.js@0.22.2/dist/face-api.min.js"></script>
<script>
// ── Constants ────────────────────────────────────────────────────────────────
const MODEL_URL        = 'https://cdn.jsdelivr.net/npm/@vladmandic/face-api/model';
const LIVENESS_TIMEOUT = 8000;   // ms per liveness challenge
const BLINK_THRESHOLD  = 0.22;   // EAR threshold for blink detection

// ── State ─────────────────────────────────────────────────────────────────────
let currentSessionId = null;
let cameraStream     = null;
let modelsLoaded     = false;
let pipelineRunning  = false;

// ── Helpers ───────────────────────────────────────────────────────────────────
function setStatus(msg, type = 'info') {
    const box = document.getElementById('statusBox');
    box.textContent = msg;
    box.className   = `alert alert-${type}`;
}

function setStep(idx, state) {
    // state: 'active' | 'done' | 'error' | ''
    const colors = {
        active: ['#ede9fe', '#6366f1'],
        done:   ['#dcfce7', '#15803d'],
        error:  ['#fee2e2', '#dc2626'],
        '':     ['#f8fafc', '#94a3b8'],
    };
    const [bg, color] = colors[state] || colors[''];
    const el = document.getElementById(`step-${idx}`);
    if (el) { el.style.background = bg; el.style.color = color; }
}

function resetSteps() {
    for (let i = 0; i < 7; i++) setStep(i, '');
}

// ── Session Selection ─────────────────────────────────────────────────────────
function selectSession(id, name) {
    currentSessionId = id;
    document.getElementById('panelTitle').textContent = `Face Check-in — ${name}`;
    document.getElementById('checkinPanel').style.display = 'block';
    document.getElementById('checkinPanel').scrollIntoView({ behavior: 'smooth' });
    initCamera();
}

function cancelCheckin() {
    stopCamera();
    document.getElementById('checkinPanel').style.display = 'none';
    currentSessionId = null;
    resetSteps();
}

// ── Camera ────────────────────────────────────────────────────────────────────
async function initCamera() {
    setStatus('Loading face recognition models...', 'info');
    setStep(0, 'active');

    try {
        if (!modelsLoaded) {
            await Promise.all([
                faceapi.nets.tinyFaceDetector.loadFromUri(MODEL_URL),
                faceapi.nets.faceLandmark68Net.loadFromUri(MODEL_URL),
                faceapi.nets.faceRecognitionNet.loadFromUri(MODEL_URL),
            ]);
            modelsLoaded = true;
        }

        cameraStream = await navigator.mediaDevices.getUserMedia({
            video: { width: 400, height: 300, facingMode: 'user' }
        });
        document.getElementById('video').srcObject = cameraStream;

        setStep(0, 'done');
        setStatus('Camera ready. Click "Start Face Scan" to begin.', 'info');
        document.getElementById('startBtn').style.display = 'inline-block';
    } catch (e) {
        setStep(0, 'error');
        setStatus('Camera error: ' + e.message, 'error');
    }
}

function stopCamera() {
    if (cameraStream) { cameraStream.getTracks().forEach(t => t.stop()); cameraStream = null; }
}

// ── Full Pipeline ─────────────────────────────────────────────────────────────
async function startPipeline() {
    if (pipelineRunning) return;
    pipelineRunning = true;
    document.getElementById('startBtn').style.display  = 'none';
    document.getElementById('retryBtn').style.display  = 'none';
    resetSteps();

    try {
        // ── Step 1: Detect face ───────────────────────────────────────────────
        setStep(1, 'active');
        setStatus('Detecting your face...', 'info');

        const video = document.getElementById('video');
        let detection = await faceapi
            .detectSingleFace(video, new faceapi.TinyFaceDetectorOptions({ inputSize: 416, scoreThreshold: 0.5 }))
            .withFaceLandmarks()
            .withFaceDescriptor();

        if (!detection) {
            setStep(1, 'error');
            setStatus('No face detected. Ensure good lighting and face the camera directly.', 'error');
            showRetry();
            return;
        }
        setStep(1, 'done');

        // ── Step 2: Liveness Check ─────────────────────────────────────────────
        setStep(2, 'active');
        const livenessOk = await runLivenessCheck(video);
        if (!livenessOk) {
            setStep(2, 'error');
            setStatus('Liveness check failed. Please try again with your real face.', 'error');
            showRetry();
            return;
        }
        setStep(2, 'done');
        hideLivenessOverlay();

        // ── Step 3: Capture final descriptor after liveness ────────────────────
        setStep(3, 'active');
        setStatus('Verifying identity...', 'info');

        detection = await faceapi
            .detectSingleFace(video, new faceapi.TinyFaceDetectorOptions({ inputSize: 416, scoreThreshold: 0.5 }))
            .withFaceLandmarks()
            .withFaceDescriptor();

        if (!detection) {
            setStep(3, 'error');
            setStatus('Face lost after liveness check. Please try again.', 'error');
            showRetry();
            return;
        }

        const descriptor = Array.from(detection.descriptor);

        // ── Steps 4-6: Send to server for full pipeline validation ─────────────
        setStep(3, 'active');
        setStep(4, 'active');
        setStep(5, 'active');
        setStatus('Running server-side verification...', 'info');

        const res = await fetch('/attendance-system/api/face_checkin.php', {
            method:  'POST',
            headers: { 'Content-Type': 'application/json' },
            body:    JSON.stringify({
                descriptor:   descriptor,
                session_id:   currentSessionId,
                liveness_passed: true,
            })
        });

        const data = await res.json();

        // Update pipeline steps based on server response
        if (data.steps) {
            if (data.steps.face_verified)    { setStep(3, 'done'); } else { setStep(3, 'error'); }
            if (data.steps.enrolled)         { setStep(4, 'done'); } else { setStep(4, 'error'); }
            if (data.steps.session_valid)    { setStep(5, 'done'); } else { setStep(5, 'error'); }
        }

        if (data.success) {
            setStep(6, 'done');
            setStatus(data.message, 'success');
            stopCamera();
            // Show success state, disable retry
            document.getElementById('retryBtn').style.display = 'none';
            document.getElementById('cancelBtn').textContent  = 'Close';
            // Reload after 2s to update marked status
            setTimeout(() => location.reload(), 2500);
        } else {
            setStatus(data.message, 'error');
            showRetry();
        }

    } catch (e) {
        setStatus('Unexpected error: ' + e.message, 'error');
        showRetry();
    } finally {
        pipelineRunning = false;
    }
}

// ── Liveness Detection ────────────────────────────────────────────────────────
// Challenge: detect a blink within the time window.
// Eye Aspect Ratio (EAR) drops sharply on blink.
async function runLivenessCheck(video) {
    return new Promise(async (resolve) => {
        showLivenessOverlay('👁️', 'Please BLINK naturally');

        let blinkDetected = false;
        let lastEAR       = null;
        let eyesClosed    = false;
        const start       = Date.now();

        const tick = async () => {
            if (Date.now() - start > LIVENESS_TIMEOUT) {
                resolve(false);
                return;
            }

            const det = await faceapi
                .detectSingleFace(video, new faceapi.TinyFaceDetectorOptions({ inputSize: 224, scoreThreshold: 0.4 }))
                .withFaceLandmarks();

            if (det) {
                const lm   = det.landmarks;
                const leftEye  = lm.getLeftEye();
                const rightEye = lm.getRightEye();
                const ear      = (eyeAspectRatio(leftEye) + eyeAspectRatio(rightEye)) / 2;

                if (ear < BLINK_THRESHOLD && !eyesClosed) {
                    // Eyes just closed
                    eyesClosed = true;
                } else if (ear >= BLINK_THRESHOLD && eyesClosed) {
                    // Eyes just opened — blink complete!
                    blinkDetected = true;
                    eyesClosed    = false;
                }
            }

            if (blinkDetected) {
                showLivenessOverlay('✅', 'Liveness confirmed!');
                await sleep(600);
                resolve(true);
            } else {
                const remaining = Math.ceil((LIVENESS_TIMEOUT - (Date.now() - start)) / 1000);
                updateLivenessInstruction(`Please BLINK naturally (${remaining}s)`);
                setTimeout(tick, 100);
            }
        };

        tick();
    });
}

// Eye Aspect Ratio: measures how open an eye is
// EAR = (||p2-p6|| + ||p3-p5||) / (2 * ||p1-p4||)
function eyeAspectRatio(eye) {
    const dist = (a, b) => Math.hypot(a.x - b.x, a.y - b.y);
    return (dist(eye[1], eye[5]) + dist(eye[2], eye[4])) / (2.0 * dist(eye[0], eye[3]));
}

function showLivenessOverlay(icon, instruction) {
    const overlay = document.getElementById('livenessOverlay');
    overlay.style.display = 'flex';
    document.getElementById('livenessIcon').textContent        = icon;
    document.getElementById('livenessInstruction').textContent = instruction;
}

function updateLivenessInstruction(text) {
    document.getElementById('livenessInstruction').textContent = text;
}

function hideLivenessOverlay() {
    document.getElementById('livenessOverlay').style.display = 'none';
}

function sleep(ms) { return new Promise(r => setTimeout(r, ms)); }

// ── Retry ─────────────────────────────────────────────────────────────────────
function showRetry() {
    document.getElementById('retryBtn').style.display = 'inline-block';
    pipelineRunning = false;
}

function resetPipeline() {
    resetSteps();
    hideLivenessOverlay();
    setStatus('Camera ready. Click "Start Face Scan" to begin.', 'info');
    document.getElementById('startBtn').style.display = 'inline-block';
    document.getElementById('retryBtn').style.display = 'none';
    pipelineRunning = false;
}

// ── Face Registration ─────────────────────────────────────────────────────────
<?php if (!$hasFace): ?>
let regStream = null;
let regDetection = null;

(async () => {
    const status = document.getElementById('regStatus');
    try {
        if (!modelsLoaded) {
            await Promise.all([
                faceapi.nets.tinyFaceDetector.loadFromUri(MODEL_URL),
                faceapi.nets.faceLandmark68Net.loadFromUri(MODEL_URL),
                faceapi.nets.faceRecognitionNet.loadFromUri(MODEL_URL),
            ]);
            modelsLoaded = true;
        }
        regStream = await navigator.mediaDevices.getUserMedia({ video: true });
        document.getElementById('regVideo').srcObject = regStream;
        status.textContent = 'Camera ready. Position your face and click Capture.';
        document.getElementById('registerBtn').style.display = 'inline-block';
    } catch(e) {
        status.textContent = 'Camera error: ' + e.message;
        status.className = 'alert alert-error';
    }
})();

// ── Face Registration — Secure Multi-Step Pipeline ───────────────────────────
<?php if (!$hasFace): ?>
let regStream  = null;
let regNonce   = null;
let minSamples = 3;
let samplesCollected = 0;
let livenessOk = false;

// Step 1: Init pipeline — get nonce from server
async function initRegistrationPipeline() {
    const status = document.getElementById('regStatus');
    status.textContent = 'Initializing secure registration...';
    status.className   = 'alert alert-info';

    try {
        const res  = await fetch('/attendance-system/api/save_face.php?action=init');
        const data = await res.json();
        if (!data.success) {
            status.textContent = data.message;
            status.className   = 'alert alert-error';
            return false;
        }
        regNonce   = data.nonce;
        minSamples = data.min_samples || 3;
        return true;
    } catch(e) {
        status.textContent = 'Server error initializing pipeline.';
        status.className   = 'alert alert-error';
        return false;
    }
}

// Step 2: Liveness check (blink detection)
async function runRegistrationLiveness(video) {
    const status = document.getElementById('regStatus');
    status.textContent = '👁️ Liveness check: please BLINK naturally...';

    return new Promise(async (resolve) => {
        let blinkDetected = false;
        let eyesClosed    = false;
        const start       = Date.now();
        const TIMEOUT     = 8000;

        const tick = async () => {
            if (Date.now() - start > TIMEOUT) { resolve(false); return; }
            const det = await faceapi.detectSingleFace(video, new faceapi.TinyFaceDetectorOptions({inputSize:224,scoreThreshold:.4})).withFaceLandmarks();
            if (det) {
                const lm = det.landmarks;
                const ear = (eyeAspectRatio(lm.getLeftEye()) + eyeAspectRatio(lm.getRightEye())) / 2;
                if (ear < 0.22 && !eyesClosed) eyesClosed = true;
                else if (ear >= 0.22 && eyesClosed) { blinkDetected = true; eyesClosed = false; }
            }
            if (blinkDetected) {
                const remaining = Math.ceil((TIMEOUT - (Date.now() - start)) / 1000);
                status.textContent = '✅ Liveness confirmed!';
                await new Promise(r => setTimeout(r, 500));
                resolve(true);
            } else {
                const remaining = Math.ceil((TIMEOUT - (Date.now() - start)) / 1000);
                status.textContent = `👁️ Liveness check: BLINK naturally (${remaining}s)...`;
                setTimeout(tick, 100);
            }
        };
        tick();
    });
}

function eyeAspectRatio(eye) {
    const d = (a,b) => Math.hypot(a.x-b.x, a.y-b.y);
    return (d(eye[1],eye[5]) + d(eye[2],eye[4])) / (2 * d(eye[0],eye[3]));
}

// Main registration flow
async function captureRegistration() {
    const status = document.getElementById('regStatus');
    const video  = document.getElementById('regVideo');
    const btn    = document.getElementById('registerBtn');
    btn.disabled = true;

    // Step 1: Init if not done
    if (!regNonce) {
        const ok = await initRegistrationPipeline();
        if (!ok) { btn.disabled = false; return; }
    }

    // Step 2: Liveness
    if (!livenessOk) {
        status.textContent = 'Starting liveness check...';
        livenessOk = await runRegistrationLiveness(video);
        if (!livenessOk) {
            status.textContent = 'Liveness check failed. Please try again.';
            status.className   = 'alert alert-error';
            btn.disabled = false;
            return;
        }

        // Notify server liveness passed
        await fetch('/attendance-system/api/save_face.php', {
            method: 'POST', headers: {'Content-Type':'application/json'},
            body: JSON.stringify({ action: 'liveness_ok', nonce: regNonce })
        });
    }

    // Step 3: Collect samples
    status.textContent = `Capturing sample ${samplesCollected + 1} of ${minSamples}... Hold still.`;
    status.className   = 'alert alert-info';

    const det = await faceapi
        .detectSingleFace(video, new faceapi.TinyFaceDetectorOptions({inputSize:416,scoreThreshold:.5}))
        .withFaceLandmarks().withFaceDescriptor();

    if (!det) {
        status.textContent = 'No face detected. Position yourself clearly and try again.';
        status.className   = 'alert alert-error';
        btn.disabled = false;
        return;
    }

    const res  = await fetch('/attendance-system/api/save_face.php', {
        method: 'POST', headers: {'Content-Type':'application/json'},
        body: JSON.stringify({ action: 'submit_sample', nonce: regNonce, descriptor: Array.from(det.descriptor) })
    });
    const data = await res.json();

    if (!data.success) {
        status.textContent = data.message;
        status.className   = 'alert alert-error';
        btn.disabled = false;
        return;
    }

    samplesCollected = data.sample_number;
    status.textContent = data.message;
    status.className   = 'alert alert-info';

    if (data.ready_to_finalize) {
        // Step 4: Finalize
        btn.textContent  = 'Finalizing...';
        const finRes  = await fetch('/attendance-system/api/save_face.php', {
            method: 'POST', headers: {'Content-Type':'application/json'},
            body: JSON.stringify({ action: 'finalize', nonce: regNonce })
        });
        const finData = await finRes.json();
        status.textContent = finData.message;
        status.className   = finData.success ? 'alert alert-success' : 'alert alert-error';
        if (finData.success) {
            if (regStream) regStream.getTracks().forEach(t => t.stop());
            btn.textContent = finData.pending_approval ? 'Awaiting Admin Approval' : 'Registered ✓';
            setTimeout(() => location.reload(), 2000);
        } else {
            btn.textContent = 'Try Again';
            btn.disabled    = false;
        }
    } else {
        btn.textContent = `Capture Sample ${samplesCollected + 1} of ${minSamples}`;
        btn.disabled    = false;
        await new Promise(r => setTimeout(r, 800));
        btn.disabled = false;
    }
}

function retakeRegistration() {
    samplesCollected = 0;
    livenessOk       = false;
    regNonce         = null;
    const btn        = document.getElementById('registerBtn');
    btn.textContent  = 'Start Registration';
    btn.onclick      = captureRegistration;
    btn.disabled     = false;
    document.getElementById('retakeBtn').style.display  = 'none';
    document.getElementById('regStatus').textContent    = 'Position your face and click to start.';
    document.getElementById('regStatus').className      = 'alert alert-info';
}

(async () => {
    const status = document.getElementById('regStatus');
    try {
        if (!modelsLoaded) {
            await Promise.all([
                faceapi.nets.tinyFaceDetector.loadFromUri(MODEL_URL),
                faceapi.nets.faceLandmark68Net.loadFromUri(MODEL_URL),
                faceapi.nets.faceRecognitionNet.loadFromUri(MODEL_URL),
            ]);
            modelsLoaded = true;
        }
        regStream = await navigator.mediaDevices.getUserMedia({ video: { facingMode: 'user' } });
        document.getElementById('regVideo').srcObject = regStream;
        status.textContent = 'Camera ready. Click "Start Registration" to begin the secure enrollment process.';
        const btn = document.getElementById('registerBtn');
        btn.style.display = 'inline-block';
        btn.textContent   = 'Start Registration';
        btn.onclick       = captureRegistration;
    } catch(e) {
        status.textContent = 'Camera error: ' + e.message;
        status.className   = 'alert alert-error';
    }
})();
<?php endif; ?>
<?php endif; ?>
</script>

<?php include '../includes/footer.php'; ?>
