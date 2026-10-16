<?php
/**
 * Face Descriptor Cryptography Service
 *
 * Single source of truth for all face descriptor encryption/decryption.
 * Used by:
 *   - api/save_face.php      (encrypt on registration)
 *   - api/verify_face.php    (decrypt on check-in via verify)
 *   - api/face_checkin.php   (decrypt on check-in)
 *
 * Algorithm: AES-256-CBC
 * Key derivation: SHA-256(raw_key) → 32-byte key
 * Storage format: base64(iv) . ':' . base64(ciphertext)
 * Legacy (no key): plain JSON array — detected by leading '['
 */

class FaceCrypto {

    /**
     * Encrypt a 128-float descriptor array.
     * If no key is configured, stores as plain JSON (degraded mode with audit warning).
     */
    public static function encrypt(array $descriptor, string $key): string {
        if (empty(trim($key))) {
            // No key — plain JSON (logs warning via caller)
            return json_encode($descriptor);
        }
        $iv         = random_bytes(16);
        $keyBytes   = hash('sha256', $key, true); // 32 bytes for AES-256
        $plaintext  = json_encode($descriptor);
        $ciphertext = openssl_encrypt(
            $plaintext, 'AES-256-CBC', $keyBytes, OPENSSL_RAW_DATA, $iv
        );
        if ($ciphertext === false) {
            // openssl failure — fall back to plain JSON rather than lose data
            error_log('FaceCrypto::encrypt openssl_encrypt failed');
            return json_encode($descriptor);
        }
        return base64_encode($iv) . ':' . base64_encode($ciphertext);
    }

    /**
     * Decrypt a stored face descriptor back to a 128-float array.
     * Handles both encrypted (iv:ct) and legacy plain JSON formats.
     * Returns null on any failure.
     */
    public static function decrypt(string $stored, string $key): ?array {
        $stored = trim($stored);

        // Legacy format: plain JSON array
        if (str_starts_with($stored, '[')) {
            $arr = json_decode($stored, true);
            return (is_array($arr) && count($arr) === 128) ? $arr : null;
        }

        // Encrypted format requires a key and the ':' separator
        if (empty(trim($key)) || !str_contains($stored, ':')) {
            error_log('FaceCrypto::decrypt: key missing or format invalid');
            return null;
        }

        $parts = explode(':', $stored, 2);
        if (count($parts) !== 2) return null;

        $iv         = base64_decode($parts[0], true);
        $ciphertext = base64_decode($parts[1], true);

        if ($iv === false || $ciphertext === false || strlen($iv) !== 16) {
            error_log('FaceCrypto::decrypt: invalid iv or ciphertext encoding');
            return null;
        }

        $keyBytes  = hash('sha256', $key, true);
        $plaintext = openssl_decrypt(
            $ciphertext, 'AES-256-CBC', $keyBytes, OPENSSL_RAW_DATA, $iv
        );

        if ($plaintext === false) {
            error_log('FaceCrypto::decrypt: openssl_decrypt failed (wrong key?)');
            return null;
        }

        $arr = json_decode($plaintext, true);
        return (is_array($arr) && count($arr) === 128) ? $arr : null;
    }

    /**
     * Compute Euclidean distance between two 128-float descriptors.
     * Both arrays must have exactly 128 elements.
     */
    public static function distance(array $a, array $b): float {
        if (count($a) !== 128 || count($b) !== 128) return PHP_FLOAT_MAX;
        $sum = 0.0;
        for ($i = 0; $i < 128; $i++) {
            $d    = ((float)($a[$i] ?? 0.0)) - ((float)($b[$i] ?? 0.0));
            $sum += $d * $d;
        }
        return sqrt($sum);
    }

    /**
     * Validate that a descriptor is a well-formed 128-float array.
     * All values must be finite floats in the range [-10, 10].
     */
    public static function validate(array $descriptor): bool {
        if (count($descriptor) !== 128) return false;
        foreach ($descriptor as $v) {
            if (!is_numeric($v)) return false;
            $f = (float)$v;
            if (!is_finite($f) || $f < -10.0 || $f > 10.0) return false;
        }
        return true;
    }

    /**
     * Average multiple descriptor samples into one template.
     * Used during multi-sample registration.
     */
    public static function average(array $samples): array {
        $n        = count($samples);
        $averaged = array_fill(0, 128, 0.0);
        foreach ($samples as $sample) {
            for ($i = 0; $i < 128; $i++) {
                $averaged[$i] += (float)($sample[$i] ?? 0.0);
            }
        }
        return array_map(fn($v) => round($v / $n, 8), $averaged);
    }

    /**
     * Generate a server-side liveness nonce tied to a session.
     * The client must present this nonce plus the correct face descriptor.
     * Stored in face_registration_sessions table for check-ins too.
     *
     * Returns the nonce string.
     */
    public static function issueLivenessNonce(int $userId, string $ip, \mysqli $conn): string {
        $nonce     = bin2hex(random_bytes(32));
        $expiresAt = date('Y-m-d H:i:s', time() + 120); // 2-minute window for check-in

        $stmt = $conn->prepare("
            INSERT INTO face_registration_sessions
                (user_id, nonce, ip_address, expires_at)
            VALUES (?,?,?,?)
        ");
        $stmt->bind_param('isss', $userId, $nonce, $ip, $expiresAt);
        $stmt->execute();
        return $nonce;
    }

    /**
     * Validate and consume a liveness nonce.
     * Marks the nonce as used so it cannot be replayed.
     * Returns true if the nonce was valid, unused, and not expired.
     */
    public static function consumeLivenessNonce(
        string  $nonce,
        int     $userId,
        \mysqli $conn
    ): bool {
        if (empty($nonce)) return false;

        $stmt = $conn->prepare("
            SELECT id, liveness_passed FROM face_registration_sessions
            WHERE nonce=? AND user_id=? AND completed=0 AND expires_at > NOW()
        ");
        $stmt->bind_param('si', $nonce, $userId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();

        if (!$row || !$row['liveness_passed']) return false;

        // Consume — mark as completed so it cannot be reused
        $conn->prepare("UPDATE face_registration_sessions SET completed=1 WHERE id=?")
             ->bind_param('i', $row['id']);
        $upd = $conn->prepare("UPDATE face_registration_sessions SET completed=1 WHERE id=?");
        $upd->bind_param('i', $row['id']);
        $upd->execute();

        return true;
    }
}
