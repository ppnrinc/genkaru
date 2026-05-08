<?php
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/includes/auth.php';

if (isLoggedIn()) {
    header('Location: ' . APP_URL . '/dashboard.php');
    exit;
}

// Build Google OAuth URL
$gState  = generateOauthState();
$gPkce   = generatePkce();
storePkceVerifier($gPkce['verifier']);
startAppSession();
$_SESSION['google_state'] = $gState;

$googleUrl = 'https://accounts.google.com/o/oauth2/v2/auth?' . http_build_query([
    'client_id'             => GOOGLE_CLIENT_ID,
    'redirect_uri'          => GOOGLE_REDIRECT_URI,
    'response_type'         => 'code',
    'scope'                 => 'openid email profile',
    'state'                 => $gState,
    'code_challenge'        => $gPkce['challenge'],
    'code_challenge_method' => 'S256',
    'access_type'           => 'offline',
    'prompt'                => 'select_account',
]);

// Build X OAuth URL
$xState = bin2hex(random_bytes(16));
$xPkce  = generatePkce();
$_SESSION['x_state']          = $xState;
$_SESSION['x_pkce_verifier']  = $xPkce['verifier'];

$xUrl = 'https://twitter.com/i/oauth2/authorize?' . http_build_query([
    'response_type'         => 'code',
    'client_id'             => X_CLIENT_ID,
    'redirect_uri'          => X_REDIRECT_URI,
    'scope'                 => 'tweet.read users.read',
    'state'                 => $xState,
    'code_challenge'        => $xPkce['challenge'],
    'code_challenge_method' => 'S256',
]);
?>
<!DOCTYPE html>
<html lang="ja">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>ログイン - げんかる！</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link href="https://fonts.googleapis.com/css2?family=Noto+Sans+JP:wght@400;600;700;900&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="<?= APP_URL ?>/assets/css/style.css">
</head>
<body>
<div class="login-page">
  <div class="login-card">
    <div class="login-logo">げんかる！</div>
    <p class="login-subtitle">
      ハンドメイド作家のための<br>
      原価計算・イベント利益管理ツール
    </p>

    <a href="<?= htmlspecialchars($googleUrl, ENT_QUOTES) ?>" class="oauth-btn">
      <svg width="20" height="20" viewBox="0 0 24 24">
        <path fill="#4285F4" d="M22.56 12.25c0-.78-.07-1.53-.2-2.25H12v4.26h5.92c-.26 1.37-1.04 2.53-2.21 3.31v2.77h3.57c2.08-1.92 3.28-4.74 3.28-8.09z"/>
        <path fill="#34A853" d="M12 23c2.97 0 5.46-.98 7.28-2.66l-3.57-2.77c-.98.66-2.23 1.06-3.71 1.06-2.86 0-5.29-1.93-6.16-4.53H2.18v2.84C3.99 20.53 7.7 23 12 23z"/>
        <path fill="#FBBC05" d="M5.84 14.09c-.22-.66-.35-1.36-.35-2.09s.13-1.43.35-2.09V7.07H2.18C1.43 8.55 1 10.22 1 12s.43 3.45 1.18 4.93l3.66-2.84z"/>
        <path fill="#EA4335" d="M12 5.38c1.62 0 3.06.56 4.21 1.64l3.15-3.15C17.45 2.09 14.97 1 12 1 7.7 1 3.99 3.47 2.18 7.07l3.66 2.84c.87-2.6 3.3-4.53 6.16-4.53z"/>
      </svg>
      Googleでログイン
    </a>

    <a href="<?= htmlspecialchars($xUrl, ENT_QUOTES) ?>" class="oauth-btn">
      <svg width="20" height="20" viewBox="0 0 24 24" fill="currentColor">
        <path d="M18.244 2.25h3.308l-7.227 8.26 8.502 11.24H16.17l-5.214-6.817L4.99 21.75H1.68l7.73-8.835L1.254 2.25H8.08l4.713 6.231zm-1.161 17.52h1.833L7.084 4.126H5.117z"/>
      </svg>
      Xでログイン
    </a>

    <p class="login-note">
      本サービスは招待制テスト運用中です。<br>
      ホワイトリストに登録済みのアカウントのみログインできます。
    </p>
  </div>
</div>
</body>
</html>
