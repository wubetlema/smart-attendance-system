<?php
/**
 * Secure File Upload Handler
 *
 * Centralised service used by:
 *   - student/correction_request.php (evidence upload)
 *   - teacher/leaves.php             (leave evidence)
 *   - student/leaves.php             (leave evidence)
 *
 * Security properties:
 *   1. Validates actual MIME type via finfo — not just extension
 *   2. Generates random filenames — no user-controlled path
 *   3. Stores files OUTSIDE public web root (assets/secure_uploads/)
 *   4. Serves files through a controller (api/serve_file.php) that checks auth
 *   5. Enforces size limit (5MB default)
 *   6. Prevents PHP execution in upload directory via .htaccess
 */
class FileUpload {

    // Allowed MIME types → safe extensions
    private static array $allowed = [
        'image/jpeg'      => 'jpg',
        'image/png'       => 'png',
        'image/webp'      => 'webp',
        'application/pdf' => 'pdf',
    ];

    private static int $maxBytes = 5 * 1024 * 1024; // 5 MB

    /**
     * Handle a file upload from $_FILES[$fieldName].
     *
     * @param string $fieldName   $_FILES key
     * @param string $category    Subdirectory: 'evidence', 'leaves', 'photos'
     * @param int    $userId      Owner user ID (recorded for access control)
     * @return array ['success'=>bool, 'path'=>string, 'message'=>string]
     */
    public static function handle(string $fieldName, string $category, int $userId): array {
        if (empty($_FILES[$fieldName]['name']) || $_FILES[$fieldName]['error'] === UPLOAD_ERR_NO_FILE) {
            return ['success' => false, 'path' => '', 'message' => 'No file uploaded.'];
        }

        $file  = $_FILES[$fieldName];
        $error = $file['error'];

        if ($error !== UPLOAD_ERR_OK) {
            $errors = [
                UPLOAD_ERR_INI_SIZE   => 'File exceeds server upload limit.',
                UPLOAD_ERR_FORM_SIZE  => 'File exceeds form upload limit.',
                UPLOAD_ERR_PARTIAL    => 'File was only partially uploaded.',
                UPLOAD_ERR_NO_TMP_DIR => 'Server temporary directory missing.',
                UPLOAD_ERR_CANT_WRITE => 'Server failed to write file.',
                UPLOAD_ERR_EXTENSION  => 'Upload blocked by server extension.',
            ];
            return ['success' => false, 'path' => '', 'message' => $errors[$error] ?? 'Upload failed.'];
        }

        // Size check
        if ($file['size'] > self::$maxBytes) {
            return ['success' => false, 'path' => '', 'message' => 'File is too large. Maximum is 5 MB.'];
        }

        // ── Validate MIME type via finfo (not extension, not client MIME) ────
        $finfo    = new \finfo(FILEINFO_MIME_TYPE);
        $mimeType = $finfo->file($file['tmp_name']);

        if (!array_key_exists($mimeType, self::$allowed)) {
            return [
                'success' => false,
                'path'    => '',
                'message' => "File type '$mimeType' is not allowed. Upload JPG, PNG, WebP, or PDF only.",
            ];
        }

        $ext = self::$allowed[$mimeType];

        // Extra check: PDF must start with %PDF magic bytes
        if ($mimeType === 'application/pdf') {
            $handle  = fopen($file['tmp_name'], 'rb');
            $magic   = fread($handle, 4);
            fclose($handle);
            if ($magic !== '%PDF') {
                return ['success' => false, 'path' => '', 'message' => 'Invalid PDF file.'];
            }
        }

        // ── Secure storage directory ──────────────────────────────────────────
        // Store OUTSIDE web root to prevent direct URL access
        $uploadRoot = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'secure_uploads';
        $uploadDir  = $uploadRoot . DIRECTORY_SEPARATOR . $category;

        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0750, true);
            // Write .htaccess to block all direct access
            file_put_contents(
                $uploadRoot . DIRECTORY_SEPARATOR . '.htaccess',
                "Deny from all\n<FilesMatch \"\\.php$\">\nDeny from all\n</FilesMatch>\n"
            );
        }

        // ── Generate random filename — no user-controlled path ─────────────────
        $filename = bin2hex(random_bytes(16)) . '_' . $userId . '.' . $ext;
        $destPath = $uploadDir . DIRECTORY_SEPARATOR . $filename;

        if (!move_uploaded_file($file['tmp_name'], $destPath)) {
            return ['success' => false, 'path' => '', 'message' => 'Failed to save file.'];
        }

        // Return the relative path stored in DB (category/filename)
        return [
            'success' => true,
            'path'    => $category . '/' . $filename,
            'message' => 'File uploaded successfully.',
        ];
    }

    /**
     * Delete a previously uploaded file by its stored path.
     */
    public static function delete(string $storedPath): void {
        if (empty($storedPath)) return;
        $full = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'secure_uploads'
               . DIRECTORY_SEPARATOR . ltrim(str_replace('/', DIRECTORY_SEPARATOR, $storedPath), DIRECTORY_SEPARATOR);
        if (file_exists($full)) @unlink($full);
    }

    /**
     * Build the URL to serve a file through the secure controller.
     */
    public static function url(string $storedPath): string {
        return '/attendance-system/api/serve_file.php?path=' . urlencode($storedPath);
    }
}
