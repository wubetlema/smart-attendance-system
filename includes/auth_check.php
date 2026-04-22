<?php
if (session_status() === PHP_SESSION_NONE) session_start();

function requireLogin() {
    if (!isset($_SESSION['user_id'])) {
        header('Location: /attendance-system/auth/login.php');
        exit;
    }
}

function requireRole(...$roles) {
    requireLogin();
    if (!in_array($_SESSION['role'], $roles)) {
        header('Location: /attendance-system/auth/login.php?error=unauthorized');
        exit;
    }
}

function currentUser() {
    return [
        'id'   => $_SESSION['user_id'] ?? null,
        'name' => $_SESSION['name'] ?? '',
        'role' => $_SESSION['role'] ?? '',
    ];
}

// CSRF token generation
function csrfToken() {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

// CSRF token HTML field
function csrfField() {
    return '<input type="hidden" name="csrf_token" value="' . csrfToken() . '">';
}

// CSRF validation
function verifyCsrf() {
    $token = $_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (!hash_equals($_SESSION['csrf_token'] ?? '', $token)) {
        http_response_code(403);
        die('<div style="font-family:sans-serif;padding:2rem;color:#dc2626">
            <h2>403 - Invalid Request</h2>
            <p>CSRF token mismatch. Please go back and try again.</p>
            <a href="javascript:history.back()">Go Back</a>
        </div>');
    }
}
