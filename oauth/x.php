<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';

startAppSession();

$code  = $_GET['code']  ?? '';
$state = $_GET['state'] ?? '';
$error = $_GET['error'] ?? '';

if ($error || !$code) {
    header('Location: ' . APP_URL . '/login.php?error=cancelled');
    exit;
}

// Validate state
if (!hash_equals($_SESSION['x_state'] ?? '', $state)) {
    header('Location: ' . APP_URL . '/login.php?error=state');
    exit;
}
unset($_SESSION['x_state']);

$verifier = $_SESSION['x_pkce_verifier'] ?? '';
unset($_SESSION['x_pkce_verifier']);

// Exchange code for tokens (X uses Basic auth for confidential clients)
$credentials = base64_encode(X_CLIENT_ID . ':' . X_CLIENT_SECRET);

$tokenResponse = httpPost('https://api.twitter.com/2/oauth2/token', [
    'code'          => $code,
    'grant_type'    => 'authorization_code',
    'redirect_uri'  => X_REDIRECT_URI,
    'code_verifier' => $verifier,
], [
    'Authorization: Basic ' . $credentials,
    'Content-Type: application/x-www-form-urlencoded',
]);

$tokenData = json_decode($tokenResponse, true);

if (empty($tokenData['access_token'])) {
    header('Location: ' . APP_URL . '/login.php?error=token');
    exit;
}

// Fetch user info
$userResponse = httpGet('https://api.twitter.com/2/users/me?user.fields=profile_image_url,name,username', [
    'Authorization: Bearer ' . $tokenData['access_token'],
]);

$userInfo = json_decode($userResponse, true);

if (empty($userInfo['data']['id'])) {
    header('Location: ' . APP_URL . '/login.php?error=userinfo');
    exit;
}

$oauthId   = $userInfo['data']['id'];
$username  = $userInfo['data']['username'] ?? '';
$name      = $userInfo['data']['name'] ?? '@' . $username;
$avatarUrl = $userInfo['data']['profile_image_url'] ?? '';

// Whitelist check
if (!isWhitelisted('x', '', $username)) {
    header('Location: ' . APP_URL . '/login.php?error=whitelist');
    exit;
}

$db = getDb();
if (!loginUser($db, 'x', $oauthId, '', $name, $avatarUrl)) {
    header('Location: ' . APP_URL . '/login.php?error=inactive');
    exit;
}

header('Location: ' . APP_URL . '/dashboard.php');
exit;
