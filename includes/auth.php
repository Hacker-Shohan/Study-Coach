<?php
require_once __DIR__ . '/db.php';

function startSession() {
    if (session_status() === PHP_SESSION_NONE) {
        session_set_cookie_params([
            'lifetime' => 86400 * 30,
            'path'     => '/',
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        session_start();
    }
}

function isLoggedIn(): bool {
    startSession();
    return isset($_SESSION['user_id']);
}

function requireLogin(): void {
    if (!isLoggedIn()) {
        header('Location: index.php?page=login');
        exit;
    }
}

function getCurrentUser(): ?array {
    if (!isLoggedIn()) return null;
    $db   = getDB();
    $stmt = $db->prepare("SELECT * FROM users WHERE id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    return $stmt->fetch() ?: null;
}

function registerUser(string $name, string $email, string $password): array {
    $db   = getDB();
    $stmt = $db->prepare("SELECT id FROM users WHERE email = ?");
    $stmt->execute([$email]);
    if ($stmt->fetch()) return ['success' => false, 'message' => 'Email already registered.'];

    $hash = password_hash($password, PASSWORD_BCRYPT);
    $db->prepare("INSERT INTO users (name, email, password_hash) VALUES (?, ?, ?)")
       ->execute([$name, $email, $hash]);
    $userId = (int)$db->lastInsertId();
    $db->prepare("INSERT INTO streaks (user_id) VALUES (?)")->execute([$userId]);
    return ['success' => true, 'user_id' => $userId];
}

function loginUser(string $email, string $password): array {
    $db   = getDB();
    $stmt = $db->prepare("SELECT * FROM users WHERE email = ?");
    $stmt->execute([$email]);
    $user = $stmt->fetch();
    if ($user && password_verify($password, $user['password_hash'])) {
        startSession();
        session_regenerate_id(true);
        $_SESSION['user_id']   = $user['id'];
        $_SESSION['user_name'] = $user['name'];
        return ['success' => true];
    }
    return ['success' => false, 'message' => 'Invalid email or password.'];
}

function logoutUser(): void {
    startSession();
    session_destroy();
    header('Location: index.php?page=login');
    exit;
}

function csrfToken(): string {
    startSession();
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function verifyCsrf(string $token): bool {
    startSession();
    return hash_equals($_SESSION['csrf_token'] ?? '', $token);
}
