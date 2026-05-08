<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

requireLogin();
$user = currentUser();
$db   = getDb();

$userId = $user['id'];

// Counts
$matCount  = (int)$db->prepare('SELECT COUNT(*) FROM materials WHERE user_id = ?')->execute([$userId]) ? $db->query("SELECT COUNT(*) FROM materials WHERE user_id = $userId")->fetchColumn() : 0;
$stmtMat   = $db->prepare('SELECT COUNT(*) FROM materials WHERE user_id = ?');
$stmtMat->execute([$userId]);
$matCount  = (int)$stmtMat->fetchColumn();

$stmtProd  = $db->prepare('SELECT COUNT(*) FROM products WHERE user_id = ?');
$stmtProd->execute([$userId]);
$prodCount = (int)$stmtProd->fetchColumn();

$stmtEv    = $db->prepare('SELECT COUNT(*) FROM events WHERE user_id = ?');
$stmtEv->execute([$userId]);
$evCount   = (int)$stmtEv->fetchColumn();

// Recent events (last 5)
$stmtRecent = $db->prepare('SELECT * FROM events WHERE user_id = ? ORDER BY event_date DESC LIMIT 5');
$stmtRecent->execute([$userId]);
$recentEvents = $stmtRecent->fetchAll();

// Cumulative profit
$totalProfit = 0.0;
$allEvents = $db->prepare('SELECT id FROM events WHERE user_id = ?');
$allEvents->execute([$userId]);
foreach ($allEvents->fetchAll() as $ev) {
    $stats = getEventStats($db, (int)$ev['id']);
    $totalProfit += $stats['profit'];
}

$pageTitle   = 'ダッシュボード';
$current     = 'dashboard';
include __DIR__ . '/includes/header.php';
?>

<div class="stats-grid">
  <div class="stat-card">
    <div class="stat-label">🧵 登録材料数</div>
    <div class="stat-value"><?= $matCount ?><small style="font-size:1rem"> 種</small></div>
  </div>
  <div class="stat-card">
    <div class="stat-label">🎨 登録作品数</div>
    <div class="stat-value"><?= $prodCount ?><small style="font-size:1rem"> 点</small></div>
  </div>
  <div class="stat-card">
    <div class="stat-label">📅 イベント数</div>
    <div class="stat-value"><?= $evCount ?><small style="font-size:1rem"> 回</small></div>
  </div>
  <div class="stat-card">
    <div class="stat-label">💰 通算利益</div>
    <div class="stat-value <?= $totalProfit >= 0 ? 'positive' : 'negative' ?>"><?= formatJpy($totalProfit) ?></div>
  </div>
</div>

<div class="card">
  <div class="card-title">📋 直近のイベント</div>
  <?php if (empty($recentEvents)): ?>
    <div class="empty-state">
      <div class="empty-icon">📅</div>
      <p>まだイベントが登録されていません</p>
      <a href="<?= APP_URL ?>/events.php" class="btn btn-primary">イベントを追加する</a>
    </div>
  <?php else: ?>
    <div class="table-wrap">
      <table>
        <thead>
          <tr>
            <th>イベント名</th>
            <th>日付</th>
            <th>場所</th>
            <th class="num">売上</th>
            <th class="num">原価</th>
            <th class="num">出店料</th>
            <th class="num">実質利益</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($recentEvents as $ev):
            $stats = getEventStats($db, (int)$ev['id']);
          ?>
          <tr>
            <td><a href="<?= APP_URL ?>/events.php?id=<?= $ev['id'] ?>"><?= e($ev['name']) ?></a></td>
            <td><?= e($ev['event_date']) ?></td>
            <td><?= e($ev['location'] ?? '−') ?></td>
            <td class="num"><?= formatJpy($stats['revenue']) ?></td>
            <td class="num"><?= formatJpy($stats['cost']) ?></td>
            <td class="num"><?= formatJpy($stats['booth_fee']) ?></td>
            <td class="num <?= $stats['profit'] >= 0 ? 'profit-pos' : 'profit-neg' ?>">
              <?= formatJpy($stats['profit']) ?>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php if ($evCount > 5): ?>
      <div style="margin-top:12px;text-align:right;">
        <a href="<?= APP_URL ?>/events.php" class="btn btn-secondary btn-sm">すべて見る →</a>
      </div>
    <?php endif; ?>
  <?php endif; ?>
</div>

<?php if ($prodCount === 0 && $matCount === 0): ?>
<div class="card" style="border:2px dashed var(--pink-light);background:var(--pink-soft);">
  <div class="card-title">🚀 はじめよう！</div>
  <p style="color:var(--text-sub);margin-bottom:20px;font-size:0.9rem;">
    げんかる！へようこそ。まずは材料と作品を登録して、原価を把握しましょう。
  </p>
  <div style="display:flex;gap:12px;flex-wrap:wrap;">
    <a href="<?= APP_URL ?>/materials.php" class="btn btn-primary">🧵 材料を登録する</a>
    <a href="<?= APP_URL ?>/products.php" class="btn btn-secondary">🎨 作品を登録する</a>
  </div>
</div>
<?php endif; ?>

<?php include __DIR__ . '/includes/footer.php'; ?>
