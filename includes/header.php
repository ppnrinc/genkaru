<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/functions.php';

$user  = currentUser();
$flash = flashGet();

$navItems = [
    'dashboard' => ['label' => 'ダッシュボード', 'icon' => '🏠', 'href' => '/dashboard.php'],
    'materials' => ['label' => '材料管理',       'icon' => '🧵', 'href' => '/materials.php'],
    'products'  => ['label' => '作品管理',       'icon' => '🎨', 'href' => '/products.php'],
    'events'    => ['label' => 'イベント管理',   'icon' => '📅', 'href' => '/events.php'],
    'reports'   => ['label' => 'レポート',       'icon' => '📊', 'href' => '/reports.php'],
];

$current  = $current  ?? 'dashboard';
$pageTitle = $pageTitle ?? 'げんかる！';
?>
<!DOCTYPE html>
<html lang="ja">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?= e($pageTitle) ?> - げんかる！</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link href="https://fonts.googleapis.com/css2?family=Noto+Sans+JP:wght@400;500;600;700;900&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="<?= APP_URL ?>/assets/css/style.css">
</head>
<body>
<div class="app-shell">

  <!-- Sidebar -->
  <aside class="sidebar" id="sidebar">
    <div class="sidebar-brand">
      <div class="sidebar-logo">
        げんかる！
        <small>ハンドメイド原価計算</small>
      </div>
    </div>

    <nav class="sidebar-nav">
      <?php foreach ($navItems as $key => $item): ?>
      <a href="<?= APP_URL . e($item['href']) ?>" class="nav-item <?= $current === $key ? 'active' : '' ?>">
        <span class="nav-icon"><?= $item['icon'] ?></span>
        <?= e($item['label']) ?>
      </a>
      <?php endforeach; ?>
    </nav>

    <div class="sidebar-footer">
      <?php if ($user): ?>
      <div class="sidebar-user">
        <div class="user-avatar">
          <?php if ($user['avatar_url']): ?>
            <img src="<?= e($user['avatar_url']) ?>" alt="">
          <?php else: ?>
            <?= mb_substr($user['name'], 0, 1) ?>
          <?php endif; ?>
        </div>
        <div class="user-info">
          <div class="user-name"><?= e($user['name']) ?></div>
          <div class="user-provider"><?= $user['provider'] === 'google' ? 'Google' : 'X' ?> でログイン中</div>
        </div>
      </div>
      <a href="<?= APP_URL ?>/logout.php" class="logout-btn">
        <span>↩</span> ログアウト
      </a>
      <?php endif; ?>
    </div>
  </aside>

  <!-- Sidebar overlay (mobile) -->
  <div class="sidebar-overlay" id="sidebarOverlay"></div>

  <!-- Main content -->
  <main class="main-content">
    <header class="page-header">
      <div style="display:flex;align-items:center;gap:14px;">
        <button class="hamburger" id="hamburger" aria-label="メニュー">
          <span></span><span></span><span></span>
        </button>
        <h1 class="page-title"><?= e($pageTitle) ?></h1>
      </div>
      <?php if (!empty($headerAction)): ?>
        <?= $headerAction ?>
      <?php endif; ?>
    </header>

    <div class="page-body">
      <?php if ($flash): ?>
      <div class="flash flash-<?= e($flash['type']) ?>">
        <?= $flash['type'] === 'success' ? '✅' : ($flash['type'] === 'error' ? '❌' : '⚠️') ?>
        <?= e($flash['message']) ?>
      </div>
      <?php endif; ?>
