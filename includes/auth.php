<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/db.php';

function startAppSession(): void {
    if (session_status() === PHP_SESSION_NONE) {
        session_name(SESSION_NAME);
        session_set_cookie_params([
            'lifetime' => 0,
            'path'     => '/',
            'secure'   => isset($_SERVER['HTTPS']),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        session_start();
    }
}

function isLoggedIn(): bool {
    startAppSession();
    return !empty($_SESSION['user_id']);
}

function requireLogin(): void {
    if (!isLoggedIn()) {
        header('Location: ' . APP_URL . '/login.php');
        exit;
    }
}

function currentUser(): ?array {
    if (!isLoggedIn()) return null;
    return [
        'id'         => $_SESSION['user_id'],
        'name'       => $_SESSION['user_name'],
        'email'      => $_SESSION['user_email'] ?? '',
        'avatar_url' => $_SESSION['user_avatar'] ?? '',
        'provider'   => $_SESSION['user_provider'],
    ];
}

function isWhitelisted(string $provider, string $email, string $username): bool {
    if ($provider === 'google') {
        return in_array(strtolower($email), array_map('strtolower', WHITELIST_GOOGLE_EMAILS), true);
    }
    if ($provider === 'x') {
        return in_array(strtolower($username), array_map('strtolower', WHITELIST_X_USERNAMES), true);
    }
    return false;
}

function loginUser(PDO $db, string $provider, string $oauthId, string $email, string $name, string $avatarUrl): bool {
    $stmt = $db->prepare('SELECT id, is_active FROM users WHERE oauth_provider = ? AND oauth_id = ?');
    $stmt->execute([$provider, $oauthId]);
    $user = $stmt->fetch();

    if ($user) {
        if (!$user['is_active']) return false;
        $db->prepare('UPDATE users SET email=?, name=?, avatar_url=?, updated_at=NOW() WHERE id=?')
           ->execute([$email, $name, $avatarUrl, $user['id']]);
        $userId = $user['id'];
    } else {
        $db->prepare('INSERT INTO users (oauth_provider, oauth_id, email, name, avatar_url) VALUES (?,?,?,?,?)')
           ->execute([$provider, $oauthId, $email, $name, $avatarUrl]);
        $userId = (int)$db->lastInsertId();
    }

    startAppSession();
    session_regenerate_id(true);
    $_SESSION['user_id']       = $userId;
    $_SESSION['user_name']     = $name;
    $_SESSION['user_email']    = $email;
    $_SESSION['user_avatar']   = $avatarUrl;
    $_SESSION['user_provider'] = $provider;
    return true;
}

function logout(): void {
    startAppSession();
    $_SESSION = [];
    session_destroy();
}

function generateOauthState(): string {
    startAppSession();
    $state = bin2hex(random_bytes(16));
    $_SESSION['oauth_state'] = $state;
    return $state;
}

function validateOauthState(string $state): bool {
    startAppSession();
    $valid = isset($_SESSION['oauth_state']) && hash_equals($_SESSION['oauth_state'], $state);
    unset($_SESSION['oauth_state']);
    return $valid;
}

function generatePkce(): array {
    $verifier  = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    $challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
    return ['verifier' => $verifier, 'challenge' => $challenge];
}

function storePkceVerifier(string $verifier): void {
    startAppSession();
    $_SESSION['pkce_verifier'] = $verifier;
}

function getPkceVerifier(): ?string {
    startAppSession();
    $v = $_SESSION['pkce_verifier'] ?? null;
    unset($_SESSION['pkce_verifier']);
    return $v;
}
