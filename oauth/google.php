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
if (!hash_equals($_SESSION['google_state'] ?? '', $state)) {
    header('Location: ' . APP_URL . '/login.php?error=state');
    exit;
}
unset($_SESSION['google_state']);

$verifier = $_SESSION['pkce_verifier'] ?? '';
unset($_SESSION['pkce_verifier']);

// Exchange code for tokens
$tokenResponse = httpPost('https://oauth2.googleapis.com/token', [
    'code'          => $code,
    'client_id'     => GOOGLE_CLIENT_ID,
    'client_secret' => GOOGLE_CLIENT_SECRET,
    'redirect_uri'  => GOOGLE_REDIRECT_URI,
    'grant_type'    => 'authorization_code',
    'code_verifier' => $verifier,
]);

$tokenData = json_decode($tokenResponse, true);

if (empty($tokenData['access_token'])) {
    header('Location: ' . APP_URL . '/login.php?error=token');
    exit;
}

// Fetch user info
$userResponse = httpGet('https://www.googleapis.com/oauth2/v2/userinfo', [
    'Authorization: Bearer ' . $tokenData['access_token'],
]);

$userInfo = json_decode($userResponse, true);

if (empty($userInfo['id'])) {
    header('Location: ' . APP_URL . '/login.php?error=userinfo');
    exit;
}

$email     = strtolower($userInfo['email'] ?? '');
$name      = $userInfo['name'] ?? $email;
$oauthId   = $userInfo['id'];
$avatarUrl = $userInfo['picture'] ?? '';

// Whitelist check
if (!isWhitelisted('google', $email, '')) {
    header('Location: ' . APP_URL . '/login.php?error=whitelist');
    exit;
}

$db = getDb();
if (!loginUser($db, 'google', $oauthId, $email, $name, $avatarUrl)) {
    header('Location: ' . APP_URL . '/login.php?error=inactive');
    exit;
}

header('Location: ' . APP_URL . '/dashboard.php');
exit;
