<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

requireLogin();
$user   = currentUser();
$db     = getDb();
$userId = $user['id'];

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) { header('Location: ' . APP_URL . '/quotes.php'); exit; }

$stmt = $db->prepare('SELECT * FROM quotes WHERE id=? AND user_id=?');
$stmt->execute([$id, $userId]);
$quote = $stmt->fetch();
if (!$quote) { header('Location: ' . APP_URL . '/quotes.php'); exit; }

$itemStmt = $db->prepare('SELECT qi.*, p.name AS product_name FROM quote_items qi LEFT JOIN products p ON qi.product_id=p.id WHERE qi.quote_id=? ORDER BY qi.id');
$itemStmt->execute([$id]);
$items = $itemStmt->fetchAll();

$settings = getUserSettings($db, $userId);
$brandName = $settings['brand_name'] ?: $user['name'];
$taxRate   = (float)$settings['tax_rate'];
$incTax    = (bool)$settings['include_tax'];

$subtotal = array_sum(array_map(fn($r) => $r['unit_price'] * $r['quantity'], $items));
$tax      = $subtotal * ($taxRate / 100);
$total    = $incTax ? $subtotal : $subtotal + $tax;
?>
<!DOCTYPE html>
<html lang="ja">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>見積書 <?= e($quote['quote_number']) ?></title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link href="https://fonts.googleapis.com/css2?family=Noto+Sans+JP:wght@400;500;600;700&display=swap" rel="stylesheet">
  <style>
    *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
    :root {
      --pink: #C2185B; --pink-soft: #FCE4EC; --border: #E0E0E0;
      --text: #212121; --text-sub: #757575; --green: #2E7D32;
    }
    body {
      font-family: 'Noto Sans JP', 'Hiragino Kaku Gothic ProN', sans-serif;
      color: var(--text); font-size: 13px; line-height: 1.6;
      background: #F5F5F5;
    }
    .page-wrap {
      max-width: 800px; margin: 20px auto;
      background: #fff; padding: 48px 52px;
      box-shadow: 0 2px 12px rgba(0,0,0,.12);
    }

    /* Top bar (no-print) */
    .top-bar {
      max-width: 800px; margin: 0 auto 0;
      background: var(--pink); padding: 10px 20px;
      display: flex; align-items: center; justify-content: space-between;
    }
    .top-bar a { color: #fff; text-decoration: none; font-size: 0.88rem; }
    .top-bar button {
      background: #fff; color: var(--pink); border: none;
      padding: 7px 18px; border-radius: 6px; font-size: 0.88rem;
      font-weight: 700; cursor: pointer; font-family: inherit;
    }

    /* Header */
    .doc-header {
      display: flex; justify-content: space-between; align-items: flex-start;
      margin-bottom: 32px; padding-bottom: 20px;
      border-bottom: 3px solid var(--pink);
    }
    .doc-title { font-size: 2rem; font-weight: 900; color: var(--pink); letter-spacing: .04em; }
    .brand-block { text-align: right; }
    .brand-name { font-size: 1rem; font-weight: 700; }
    .brand-sub  { font-size: 0.8rem; color: var(--text-sub); margin-top: 2px; }

    /* Meta */
    .meta-grid {
      display: grid; grid-template-columns: 1fr 1fr; gap: 24px; margin-bottom: 28px;
    }
    .meta-block h4 { font-size: 0.75rem; color: var(--text-sub); margin-bottom: 4px; text-transform: uppercase; letter-spacing: .06em; }
    .meta-block p  { font-size: 0.95rem; font-weight: 600; }
    .meta-block .sub { font-size: 0.8rem; color: var(--text-sub); font-weight: 400; }

    .client-box {
      border-bottom: 2px solid var(--text); padding-bottom: 6px; margin-bottom: 28px;
    }
    .client-box .label { font-size: 0.75rem; color: var(--text-sub); }
    .client-box .name  { font-size: 1.15rem; font-weight: 700; margin-top: 2px; }

    /* Items table */
    .items-table { width: 100%; border-collapse: collapse; margin-bottom: 24px; }
    .items-table th {
      background: var(--pink); color: #fff; font-size: 0.82rem;
      padding: 9px 12px; text-align: left; font-weight: 600;
    }
    .items-table th:last-child, .items-table td:last-child { text-align: right; }
    .items-table td {
      padding: 10px 12px; border-bottom: 1px solid var(--border);
      font-size: 0.88rem; vertical-align: middle;
    }
    .items-table tr:last-child td { border-bottom: none; }
    .items-table .item-num { color: var(--text-sub); font-size: 0.8rem; }

    /* Totals */
    .totals { width: 260px; margin-left: auto; margin-bottom: 32px; }
    .totals table { width: 100%; border-collapse: collapse; }
    .totals td { padding: 6px 8px; font-size: 0.88rem; }
    .totals td:last-child { text-align: right; font-variant-numeric: tabular-nums; }
    .totals .total-row { border-top: 2px solid var(--text); }
    .totals .total-row td { font-size: 1.05rem; font-weight: 700; padding-top: 10px; }

    /* Notes */
    .notes-box { border: 1px solid var(--border); border-radius: 6px; padding: 14px 16px; margin-bottom: 32px; }
    .notes-box h4 { font-size: 0.78rem; color: var(--text-sub); margin-bottom: 6px; }
    .notes-box p  { font-size: 0.88rem; white-space: pre-wrap; }

    /* Footer */
    .doc-footer {
      border-top: 1px solid var(--border); padding-top: 16px;
      display: flex; justify-content: space-between; align-items: center;
      color: var(--text-sub); font-size: 0.78rem;
    }
    .valid-note { color: var(--text-sub); font-size: 0.78rem; }

    @media print {
      body { background: #fff; }
      .top-bar { display: none; }
      .page-wrap { margin: 0; box-shadow: none; padding: 32px 40px; }
      @page { margin: 12mm 14mm; }
    }
    @media (max-width: 600px) {
      .page-wrap { padding: 24px 18px; margin: 0; }
      .meta-grid { grid-template-columns: 1fr; gap: 12px; }
      .totals { width: 100%; }
    }
  </style>
</head>
<body>

<!-- Top bar (hidden on print) -->
<div class="top-bar no-print">
  <a href="<?= APP_URL ?>/quotes.php">← 見積書一覧へ</a>
  <button onclick="window.print()">🖨 印刷 / PDF保存</button>
</div>

<div class="page-wrap">

  <!-- Header -->
  <div class="doc-header">
    <div>
      <div class="doc-title">見 積 書</div>
      <div style="font-size:0.85rem;color:var(--text-sub);margin-top:6px;">
        No.&nbsp;<strong><?= e($quote['quote_number']) ?></strong>
      </div>
    </div>
    <div class="brand-block">
      <div class="brand-name"><?= e($brandName) ?></div>
      <div class="brand-sub">発行日：<?= e(date('Y年n月j日', strtotime($quote['created_at']))) ?></div>
      <?php if ($quote['valid_until']): ?>
      <div class="brand-sub">有効期限：<?= e(date('Y年n月j日', strtotime($quote['valid_until']))) ?></div>
      <?php endif; ?>
    </div>
  </div>

  <!-- Client -->
  <div class="client-box">
    <div class="label">御見積先</div>
    <div class="name"><?= e($quote['client_name'] ?: '　') ?> 様</div>
  </div>

  <!-- Items -->
  <table class="items-table">
    <thead>
      <tr>
        <th style="width:36px;">No.</th>
        <th>品名・内容</th>
        <th style="width:80px;text-align:right;">単価</th>
        <th style="width:60px;text-align:right;">数量</th>
        <th style="width:100px;">金額</th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($items as $i => $item): ?>
      <tr>
        <td class="item-num" style="text-align:center;"><?= $i + 1 ?></td>
        <td><?= e($item['item_name']) ?></td>
        <td style="text-align:right;"><?= formatJpy((float)$item['unit_price']) ?></td>
        <td style="text-align:right;"><?= number_format($item['quantity']) ?></td>
        <td style="text-align:right;font-weight:600;"><?= formatJpy((float)$item['unit_price'] * $item['quantity']) ?></td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>

  <!-- Totals -->
  <div class="totals">
    <table>
      <tr><td>小計</td><td><?= formatJpy($subtotal) ?></td></tr>
      <tr><td>消費税（<?= number_format($taxRate, 0) ?>%）</td><td><?= formatJpy($tax) ?></td></tr>
      <tr class="total-row">
        <td><strong>合計（税<?= $incTax ? '込' : '別' ?>）</strong></td>
        <td><strong><?= formatJpy($total) ?></strong></td>
      </tr>
    </table>
  </div>

  <!-- Notes -->
  <?php if ($quote['notes']): ?>
  <div class="notes-box">
    <h4>備考</h4>
    <p><?= e($quote['notes']) ?></p>
  </div>
  <?php endif; ?>

  <!-- Footer -->
  <div class="doc-footer">
    <div><?= e($brandName) ?></div>
    <?php if ($quote['valid_until']): ?>
    <div class="valid-note">この見積書は <?= e(date('Y年n月j日', strtotime($quote['valid_until']))) ?> まで有効です。</div>
    <?php endif; ?>
  </div>

</div>
</body>
</html>
