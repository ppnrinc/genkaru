<?php
// config.example.php — Copy to config.php and fill in your values.
// NEVER commit config.php to git.

// ── Database ────────────────────────────────────────────────────────────────
define('DB_HOST', 'localhost');
define('DB_NAME', 'your_database_name');
define('DB_USER', 'your_database_user');
define('DB_PASS', 'your_database_password');
define('DB_CHARSET', 'utf8mb4');

// ── Application ─────────────────────────────────────────────────────────────
define('APP_URL', 'https://yourdomain.com');  // No trailing slash
define('APP_NAME', 'げんかる！');
define('SESSION_NAME', 'genkaru_session');

// ── Google OAuth 2.0 ────────────────────────────────────────────────────────
// https://console.cloud.google.com/ → APIs & Services → Credentials
define('GOOGLE_CLIENT_ID', 'your_google_client_id');
define('GOOGLE_CLIENT_SECRET', 'your_google_client_secret');
define('GOOGLE_REDIRECT_URI', APP_URL . '/oauth/google.php');

// ── X (Twitter) OAuth 2.0 ───────────────────────────────────────────────────
// https://developer.twitter.com/ → Projects & Apps → Keys and Tokens
define('X_CLIENT_ID', 'your_x_client_id');
define('X_CLIENT_SECRET', 'your_x_client_secret');
define('X_REDIRECT_URI', APP_URL . '/oauth/x.php');

// ── Rakuten API ─────────────────────────────────────────────────────────────
// https://webservice.rakuten.co.jp/
define('RAKUTEN_APP_ID', 'your_rakuten_app_id');
define('RAKUTEN_AFFILIATE_ID', 'your_rakuten_affiliate_id');  // Leave empty if not set yet

// ── Amazon (Phase 2) ────────────────────────────────────────────────────────
define('AMAZON_ASSOCIATE_TAG', '');

// ── Access Whitelist ────────────────────────────────────────────────────────
// Google: allowed email addresses
define('WHITELIST_GOOGLE_EMAILS', [
    'testuser@gmail.com',
]);

// X: allowed usernames (without @)
define('WHITELIST_X_USERNAMES', [
    'xusername',
]);
