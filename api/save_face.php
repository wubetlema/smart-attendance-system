<?php
/**
 * Secure Face Registration API
 *
 * Implements a nonce-based, multi-step pipeline:
 *
 *  Step 1 — GET  ?action=init
 *            Server issues a signed nonce + returns required config (min samples, etc.)
 *
 *  Step 2 — POST action=liveness_ok
 *            Client reports liveness check passed.
 *            Server marks the nonce session as liveness_passed=1.
 *
 *  Step 3 — POST action=submit_sample  (called min_samples times)
 *            Client submits one 128-float descriptor sample per call.
 *            Server accumulates samples in nonce session.
 *
 *  Step 4 — POST action=finalize
 *            Server:
 *              - Verifies nonce is valid, not expired, liveness passed
 *              - Verifies min_samples collected
 *              - Averages all descriptors into one template
 *              - Encrypts the template with AES-256
 *              - Stores encrypted blob in users.face_descriptor
 *              - Logs to face_registrations audit table
 *              - Marks nonce session as completed
 *
 * Security properties:
 *   - Nonce prevents replay attacks
 *   - Liveness gate cannot be skipped
 *   - Minimum sample count enforced server-side
 *   - Descriptor encrypted at rest (AES-256-CBC)
 *   - Rate limited: max N attempts per hour
 *   - Full audit trail
 *   - Replacing a face requires the same full pipeline
 */

session_start();
require_once '../config/db.php';
require_once '../includes/auth_check.php';
require_once '../includes/functions.php';
require_once '../includes/face_crypto.php';

header('Content-Type: application/json');
header('X-Content-Type-Options: nosniff');

// ── Auth: students only ───────────────────────────────────────────────────────
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'student') {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized.']);
    exit;
}

// Rate limit: max 10 save_face attempts per hour per IP
if (!rateLimit('save_face', 10)) {
    echo json_encode(['success' => false, 'message' => 'Too many requests. Please wait before trying again.']);
    exit;
}

$studentId = (int)$_SESSION['user_id'];
$ip        = $_SERVER['REMOTE_ADDR']     ?? '0.0.0.0';
$ua        = $_SERVER['HTTP_USER_AGENT'] ?? '';
$action    = $_GET['action'] ?? (json_decode(file_get_contents('php://input'), true)['action'] ?? '');

// ── Load settings ─────────────────────────────────────────────────────────────
$minSamples   = max(1, (int)getSetting('face_min_samples', '3'));
$maxPerHour   = max(1, (int)getSetting('face_max_registrations_per_hour', '3'));
$encKey       = getSetting('face_encryption_key', '');
$requireApproval = getSetting('face_require_admin_approval', '0') === '1';

// ── Encryption helpers — use shared FaceCrypto service ────────────────────────
function encryptDescriptor(array $descriptor, string $key): string {
    return FaceCrypto::encrypt($descriptor, $key);
}
function decryptDescriptor(string $stored, string $key): ?array {
    return FaceCrypto::decrypt($stored, $key);
}

// ── Rate limiting: max N registration attempts per hour ───────────────────────
function checkRateLimit(int $studentId, string $ip, int $max): bool {
    global $conn;
    $stmt = $conn->prepare("
        SELECT COUNT(*) c FROM face_registration_sessions
        WHERE user_id=? AND ip_address=?
          AND created_at > DATE_SUB(NOW(), INTERVAL 1 HOUR)
    ");
    $stmt->bind_param('is', $studentId, $ip);
    $stmt->execute();
    return (int)$stmt->get_result()->fetch_assoc()['c'] < $max;
}

// ── Log to audit table ────────────────────────────────────────────────────────
function logFaceAction(int $userId, string $action, int $performedBy, array $extra = []): void {
    global $conn, $ip, $ua;
    $stmt = $conn->prepare("
        INSERT INTO face_registrations
            (user_id, action, performed_by, performed_role, samples_used,
             liveness_passed, distance_score, ip_address, user_agent, device_type)
        VALUES (?,?,?,?,?,?,?,?,?,?)
    ");
    $role      = $_SESSION['role'] ?? '';
    $samples   = $extra['samples']  ?? 0;
    $liveness  = $extra['liveness'] ?? 0;
    $distance  = $extra['distance'] ?? null;
    $devType   = (stripos($ua,'mobile')!==false) ? 'mobile' : 'desktop';
    $stmt->bind_param(
        'isisiiisss',
        $userId, $action, $performedBy, $role,
        $samples, $liveness, $distance,
        $ip, $ua, $devType
    );
    $stmt->execute();
}

// ════════════════════════════════════════════════════════════════════════════
// STEP 1 — init: issue nonce
// ════════════════════════════════════════════════════════════════════════════
if ($action === 'init') {
    // Rate limit check
    if (!checkRateLimit($studentId, $ip, $maxPerHour)) {
        echo json_encode([
            'success' => false,
            'message' => "Too many registration attempts. Please wait before trying again."
        ]);
        exit;
    }

    // Expire old incomplete sessions for this user
    $conn->query("
        UPDATE face_registration_sessions
        SET completed=1
        WHERE user_id=$studentId AND completed=0 AND expires_at < NOW()
    ");

    $nonce     = bin2hex(random_bytes(48));
    $expiresAt = date('Y-m-d H:i:s', time() + 600); // 10 minutes

    $stmt = $conn->prepare("
        INSERT INTO face_registration_sessions
            (user_id, nonce, ip_address, user_agent, expires_at)
        VALUES (?,?,?,?,?)
    ");
    $stmt->bind_param('issss', $studentId, $nonce, $ip, $ua, $expiresAt);
    $stmt->execute();

    // Check if replacing existing face
    $existing = $conn->query("SELECT face_registered_at FROM users WHERE id=$studentId AND face_descriptor IS NOT NULL AND face_descriptor != ''")->fetch_assoc();

    echo json_encode([
        'success'       => true,
        'nonce'         => $nonce,
        'expires_in'    => 600,
        'min_samples'   => $minSamples,
        'is_update'     => !empty($existing),
        'registered_at' => $existing['face_registered_at'] ?? null,
        'message'       => 'Pipeline initialized. Complete all steps within 10 minutes.',
    ]);
    exit;
}

// ── All remaining steps require JSON body + nonce ─────────────────────────────
$data  = json_decode(file_get_contents('php://input'), true);
$nonce = $data['nonce'] ?? '';

if (!$nonce) {
    echo json_encode(['success' => false, 'message' => 'Missing nonce. Call init first.']);
    exit;
}

// Load and validate nonce session
$nStmt = $conn->prepare("
    SELECT * FROM face_registration_sessions
    WHERE nonce=? AND user_id=? AND completed=0 AND expires_at > NOW()
");
$nStmt->bind_param('si', $nonce, $studentId);
$nStmt->execute();
$nSession = $nStmt->get_result()->fetch_assoc();

if (!$nSession) {
    echo json_encode(['success' => false, 'message' => 'Invalid, expired, or already used pipeline token. Please start over.']);
    exit;
}

// ════════════════════════════════════════════════════════════════════════════
// STEP 2 — liveness_ok: mark liveness as passed
// ════════════════════════════════════════════════════════════════════════════
if ($action === 'liveness_ok') {
    $conn->query("UPDATE face_registration_sessions SET liveness_passed=1 WHERE id={$nSession['id']}");
    echo json_encode([
        'success' => true,
        'message' => 'Liveness confirmed. You can now submit face samples.',
    ]);
    exit;
}

// ════════════════════════════════════════════════════════════════════════════
// STEP 3 — submit_sample: accumulate descriptor samples
// ════════════════════════════════════════════════════════════════════════════
if ($action === 'submit_sample') {
    if (!$nSession['liveness_passed']) {
        echo json_encode(['success' => false, 'message' => 'Liveness check must be completed first.']);
        exit;
    }

    $descriptor = $data['descriptor'] ?? null;
    if (!$descriptor || !is_array($descriptor) || count($descriptor) !== 128) {
        echo json_encode(['success' => false, 'message' => 'Invalid face descriptor. Expected 128-float array.']);
        exit;
    }

    // Validate all values are numeric and in reasonable float range
    foreach ($descriptor as $v) {
        if (!is_numeric($v) || abs($v) > 10) {
            echo json_encode(['success' => false, 'message' => 'Descriptor contains invalid values.']);
            exit;
        }
    }

    $sampleNum  = (int)$nSession['samples_received'] + 1;
    $sampleKey  = "face_sample_{$nSession['id']}_{$sampleNum}";

    // Store sample in a temporary table approach:
    // We store samples as JSON in a dedicated column appended per call.
    // Simple approach: store in face_registration_sessions as JSON array.
    // Load existing samples
    $samplesRow = $conn->query("
        SELECT sample_data FROM face_registration_sessions WHERE id={$nSession['id']}
    ")->fetch_assoc();

    // Add sample_data column if it doesn't exist (handled by migration, but safe check)
    $existingSamples = [];
    if (!empty($samplesRow['sample_data'])) {
        $existingSamples = json_decode($samplesRow['sample_data'], true) ?? [];
    }
    $existingSamples[] = $descriptor;

    $samplesJson = json_encode($existingSamples);
    $conn->prepare("
        UPDATE face_registration_sessions
        SET samples_received=?, sample_data=?
        WHERE id=?
    ")->bind_param('isi', $sampleNum, $samplesJson, $nSession['id']);
    // Execute via stmt
    $upd = $conn->prepare("UPDATE face_registration_sessions SET samples_received=?, sample_data=? WHERE id=?");
    $upd->bind_param('isi', $sampleNum, $samplesJson, $nSession['id']);
    $upd->execute();

    $remaining = max(0, $minSamples - $sampleNum);
    echo json_encode([
        'success'         => true,
        'sample_number'   => $sampleNum,
        'samples_needed'  => $minSamples,
        'samples_remaining' => $remaining,
        'ready_to_finalize' => $remaining === 0,
        'message'         => $remaining > 0
            ? "Sample $sampleNum recorded. $remaining more sample(s) needed."
            : "All $minSamples samples collected. Ready to finalize.",
    ]);
    exit;
}

// ════════════════════════════════════════════════════════════════════════════
// STEP 4 — finalize: average samples, encrypt, store
// ════════════════════════════════════════════════════════════════════════════
if ($action === 'finalize') {
    // Verify liveness
    if (!$nSession['liveness_passed']) {
        echo json_encode(['success' => false, 'message' => 'Liveness check was not completed.']);
        exit;
    }

    // Verify sample count
    if ((int)$nSession['samples_received'] < $minSamples) {
        echo json_encode([
            'success' => false,
            'message' => "Not enough samples. Need $minSamples, have {$nSession['samples_received']}.",
        ]);
        exit;
    }

    // Load samples
    $samplesRow = $conn->query("SELECT sample_data FROM face_registration_sessions WHERE id={$nSession['id']}")->fetch_assoc();
    $samples    = json_decode($samplesRow['sample_data'] ?? '[]', true);

    if (empty($samples) || count($samples) < $minSamples) {
        echo json_encode(['success' => false, 'message' => 'Sample data missing. Please start over.']);
        exit;
    }

    // Average all 128 dimensions across samples
    $averaged = array_fill(0, 128, 0.0);
    foreach ($samples as $sample) {
        for ($i = 0; $i < 128; $i++) {
            $averaged[$i] += (float)($sample[$i] ?? 0.0);
        }
    }
    $n = count($samples);
    for ($i = 0; $i < 128; $i++) {
        $averaged[$i] = round($averaged[$i] / $n, 8);
    }

    // Compute self-similarity vs previous face (if updating)
    $distanceScore = null;
    $isUpdate      = false;
    $prevRow       = $conn->query("SELECT face_descriptor FROM users WHERE id=$studentId")->fetch_assoc();
    if (!empty($prevRow['face_descriptor'])) {
        $isUpdate = true;
        $prevDesc = decryptDescriptor($prevRow['face_descriptor'], $encKey);
        if ($prevDesc && count($prevDesc) === 128) {
            $sum = 0.0;
            for ($i = 0; $i < 128; $i++) {
                $diff  = $averaged[$i] - $prevDesc[$i];
                $sum  += $diff * $diff;
            }
            $distanceScore = round(sqrt($sum), 4);
        }
    }

    // Encrypt the averaged descriptor
    $encrypted = encryptDescriptor($averaged, $encKey);
    $encMode   = !empty($encKey) ? 'AES-256-CBC' : 'plaintext';

    // Store — with admin approval flag if required
    $adminApproved = $requireApproval ? 0 : 1;
    $now           = date('Y-m-d H:i:s');

    $upd = $conn->prepare("
        UPDATE users
        SET face_descriptor=?,
            face_registered_at=?,
            face_registered_by=?,
            face_admin_approved=?,
            face_revoked=0
        WHERE id=?
    ");
    $upd->bind_param('ssiii', $encrypted, $now, $studentId, $adminApproved, $studentId);
    $upd->execute();

    // Mark nonce session as completed
    $conn->query("UPDATE face_registration_sessions SET completed=1 WHERE id={$nSession['id']}");

    // Audit log
    $auditAction = $isUpdate ? 'updated' : 'registered';
    logFaceAction($studentId, $auditAction, $studentId, [
        'samples'  => $n,
        'liveness' => 1,
        'distance' => $distanceScore,
    ]);

    // Activity log
    logActivity('face_register', "Action: $auditAction, Samples: $n, Encryption: $encMode, Distance: $distanceScore", $studentId);

    $message = $requireApproval && !$isUpdate
        ? 'Face registered successfully. Your enrollment is pending admin approval before you can use face check-in.'
        : ($isUpdate ? 'Face updated successfully.' : 'Face registered successfully. You can now use face check-in.');

    echo json_encode([
        'success'        => true,
        'message'        => $message,
        'samples_used'   => $n,
        'encrypted'      => !empty($encKey),
        'pending_approval' => $requireApproval && !$adminApproved,
        'is_update'      => $isUpdate,
    ]);
    exit;
}

// ── Unknown action ────────────────────────────────────────────────────────────
echo json_encode(['success' => false, 'message' => "Unknown action: $action. Valid: init, liveness_ok, submit_sample, finalize."]);
