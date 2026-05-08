<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

requireLogin();
$user   = currentUser();
$db     = getDb();
$userId = $user['id'];

// ── Event profit comparison ───────────────────────────────────────────────────
$evStmt = $db->prepare('SELECT * FROM events WHERE user_id=? ORDER BY event_date DESC');
$evStmt->execute([$userId]);
$events = $evStmt->fetchAll();

$eventStats = [];
foreach ($events as $ev) {
    $stats = getEventStats($db, (int)$ev['id']);
    $eventStats[] = array_merge($ev, $stats);
}

// ── Product sales ranking ─────────────────────────────────────────────────────
// Sum all event sales per product
$rankStmt = $db->prepare('
    SELECT p.name, p.id,
           SUM(es.quantity_sold) AS total_qty,
           SUM(es.selling_price * es.quantity_sold * ev.exchange_rate) AS total_revenue
    FROM event_sales es
    JOIN events ev ON es.event_id = ev.id
    JOIN products p ON es.product_id = p.id
    WHERE ev.user_id = ?
    GROUP BY p.id, p.name
    ORDER BY total_qty DESC
    LIMIT 10
');
$rankStmt->execute([$userId]);
$salesRanking = $rankStmt->fetchAll();

// ── Product profit margin ranking ─────────────────────────────────────────────
$marginRanking = [];
$prodStmt = $db->prepare('SELECT * FROM products WHERE user_id=? ORDER BY name');
$prodStmt->execute([$userId]);
$prods = $prodStmt->fetchAll();

foreach ($prods as $p) {
    $cost = getProductCost($db, (int)$p['id']);
    $laborCost = ($p['labor_time'] / 60) * $p['hourly_rate'];
    $totalCost = $cost + $laborCost;

    // Average selling price from all sales
    $avgStmt = $db->prepare('
        SELECT AVG(es.selling_price) AS avg_price
        FROM event_sales es
        JOIN events ev ON es.event_id = ev.id
        WHERE es.product_id = ? AND ev.user_id = ?
    ');
    $avgStmt->execute([$p['id'], $userId]);
    $avgPrice = (float)($avgStmt->fetchColumn() ?? 0);

    if ($avgPrice > 0) {
        $margin = (($avgPrice - $totalCost) / $avgPrice) * 100;
        $marginRanking[] = [
            'name'       => $p['name'],
            'cost'       => $totalCost,
            'avg_price'  => $avgPrice,
            'margin'     => $margin,
        ];
    }
}

usort($marginRanking, fn($a, $b) => $b['margin'] <=> $a['margin']);
$marginRanking = array_slice($marginRanking, 0, 10);

$pageTitle = 'レポート';
$current   = 'reports';

include __DIR__ . '/includes/header.php';
?>

<!-- Event comparison -->
<div class="card">
  <div class="card-title">📊 イベント別利益比較</div>
  <?php if (empty($eventStats)): ?>
    <div class="empty-state">
      <div class="empty-icon">📅</div>
      <p>まだイベントが登録されていません。</p>
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
            <th class="num">販売数</th>
            <th class="num">売上（円）</th>
            <th class="num">材料費（円）</th>
            <th class="num">出店料（円）</th>
            <th class="num">実質利益（円）</th>
            <th class="num">利益率</th>
          </tr>
        </thead>
        <tbody>
          <?php
          $totalRevenue = 0; $totalProfit = 0; $totalQty = 0;
          foreach ($eventStats as $ev):
            $margin = $ev['revenue'] > 0 ? ($ev['profit'] / $ev['revenue']) * 100 : 0;
            $totalRevenue += $ev['revenue'];
            $totalProfit  += $ev['profit'];
            $totalQty     += $ev['qty'];
          ?>
          <tr>
            <td><a href="<?= APP_URL ?>/events.php?edit=<?= $ev['id'] ?>"><?= e($ev['name']) ?></a></td>
            <td><?= e($ev['event_date']) ?></td>
            <td><?= e($ev['location'] ?: '−') ?></td>
            <td class="num"><?= number_format($ev['qty']) ?></td>
            <td class="num"><?= formatJpy($ev['revenue']) ?></td>
            <td class="num"><?= formatJpy($ev['cost']) ?></td>
            <td class="num"><?= formatJpy($ev['booth_fee']) ?></td>
            <td class="num <?= $ev['profit'] >= 0 ? 'profit-pos' : 'profit-neg' ?>"><?= formatJpy($ev['profit']) ?></td>
            <td class="num <?= $margin >= 0 ? 'profit-pos' : 'profit-neg' ?>"><?= number_format($margin, 1) ?>%</td>
          </tr>
          <?php endforeach; ?>
        </tbody>
        <tfoot>
          <tr style="background:var(--pink-soft);font-weight:700;">
            <td colspan="3">合計</td>
            <td class="num"><?= number_format($totalQty) ?></td>
            <td class="num"><?= formatJpy($totalRevenue) ?></td>
            <td class="num"></td>
            <td class="num"></td>
            <td class="num <?= $totalProfit >= 0 ? 'profit-pos' : 'profit-neg' ?>"><?= formatJpy($totalProfit) ?></td>
            <td class="num <?= $totalRevenue > 0 && $totalProfit/$totalRevenue*100 >= 0 ? 'profit-pos' : 'profit-neg' ?>">
              <?= $totalRevenue > 0 ? number_format($totalProfit / $totalRevenue * 100, 1) . '%' : '−' ?>
            </td>
          </tr>
        </tfoot>
      </table>
    </div>
  <?php endif; ?>
</div>

<div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(360px,1fr));gap:20px;">

<!-- Sales ranking -->
<div class="card">
  <div class="card-title">🏆 作品別 売れ筋ランキング</div>
  <?php if (empty($salesRanking)): ?>
    <div class="empty-state" style="padding:24px;">
      <p style="font-size:0.85rem;">販売データがありません。</p>
    </div>
  <?php else: ?>
    <table>
      <thead>
        <tr>
          <th>順位</th>
          <th>作品名</th>
          <th class="num">販売数</th>
          <th class="num">売上（円）</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($salesRanking as $i => $r): ?>
        <tr>
          <td>
            <span class="rank-badge <?= $i===0?'rank-1':($i===1?'rank-2':($i===2?'rank-3':'rank-other')) ?>">
              <?= $i + 1 ?>
            </span>
          </td>
          <td><?= e($r['name']) ?></td>
          <td class="num"><?= number_format($r['total_qty']) ?></td>
          <td class="num"><?= formatJpy((float)$r['total_revenue']) ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>
</div>

<!-- Margin ranking -->
<div class="card">
  <div class="card-title">💎 作品別 利益率ランキング</div>
  <?php if (empty($marginRanking)): ?>
    <div class="empty-state" style="padding:24px;">
      <p style="font-size:0.85rem;">販売データがありません。</p>
    </div>
  <?php else: ?>
    <table>
      <thead>
        <tr>
          <th>順位</th>
          <th>作品名</th>
          <th class="num">平均価格</th>
          <th class="num">原価</th>
          <th class="num">利益率</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($marginRanking as $i => $r): ?>
        <tr>
          <td>
            <span class="rank-badge <?= $i===0?'rank-1':($i===1?'rank-2':($i===2?'rank-3':'rank-other')) ?>">
              <?= $i + 1 ?>
            </span>
          </td>
          <td><?= e($r['name']) ?></td>
          <td class="num"><?= formatJpy($r['avg_price']) ?></td>
          <td class="num"><?= formatJpy($r['cost']) ?></td>
          <td class="num <?= $r['margin'] >= 0 ? 'profit-pos' : 'profit-neg' ?>"><?= number_format($r['margin'], 1) ?>%</td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>
</div>

</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
