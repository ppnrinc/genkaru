<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

requireLogin();
$user   = currentUser();
$db     = getDb();
$userId = $user['id'];
$settings = getUserSettings($db, $userId);

// ── CRUD ────────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        $db->prepare('DELETE FROM quotes WHERE id=? AND user_id=?')->execute([$id, $userId]);
        flashSet('success', '見積書を削除しました。');
        header('Location: ' . APP_URL . '/quotes.php');
        exit;
    }

    if ($action === 'status') {
        $id     = (int)($_POST['id'] ?? 0);
        $status = $_POST['status'] ?? 'draft';
        if (in_array($status, ['draft','sent','accepted','declined'])) {
            $db->prepare('UPDATE quotes SET status=?,updated_at=NOW() WHERE id=? AND user_id=?')->execute([$status,$id,$userId]);
        }
        header('Location: ' . APP_URL . '/quotes.php');
        exit;
    }

    // Add or Edit
    $id          = (int)($_POST['id'] ?? 0);
    $clientName  = trim($_POST['client_name'] ?? '');
    $validUntil  = trim($_POST['valid_until'] ?? '') ?: null;
    $notes       = trim($_POST['notes'] ?? '');

    // Collect line items
    $itemProductIds = $_POST['item_product_id']  ?? [];
    $itemNames      = $_POST['item_name']         ?? [];
    $itemMarkupRates= $_POST['item_markup_rate']  ?? [];
    $itemMarkupTypes= $_POST['item_markup_type']  ?? [];
    $itemUnitPrices = $_POST['item_unit_price']   ?? [];
    $itemQtys       = $_POST['item_quantity']     ?? [];

    if (count($itemNames) === 0 || !array_filter($itemNames)) {
        flashSet('error', '少なくとも1品目を追加してください。');
        header('Location: ' . APP_URL . '/quotes.php');
        exit;
    }

    if ($id > 0) {
        $chk = $db->prepare('SELECT id FROM quotes WHERE id=? AND user_id=?');
        $chk->execute([$id, $userId]);
        if ($chk->fetch()) {
            $db->prepare('UPDATE quotes SET client_name=?,valid_until=?,notes=?,updated_at=NOW() WHERE id=?')
               ->execute([$clientName,$validUntil,$notes,$id]);
            $db->prepare('DELETE FROM quote_items WHERE quote_id=?')->execute([$id]);
            saveQuoteItems($db, $id, $itemProductIds, $itemNames, $itemMarkupRates, $itemMarkupTypes, $itemUnitPrices, $itemQtys);
            flashSet('success', '見積書を更新しました。');
        }
    } else {
        $quoteNumber = generateQuoteNumber($db, $userId);
        $db->prepare('INSERT INTO quotes (user_id,quote_number,client_name,valid_until,notes) VALUES (?,?,?,?,?)')
           ->execute([$userId,$quoteNumber,$clientName,$validUntil,$notes]);
        $newId = (int)$db->lastInsertId();
        saveQuoteItems($db, $newId, $itemProductIds, $itemNames, $itemMarkupRates, $itemMarkupTypes, $itemUnitPrices, $itemQtys);
        flashSet('success', '見積書 ' . $quoteNumber . ' を作成しました。');
    }

    header('Location: ' . APP_URL . '/quotes.php');
    exit;
}

function saveQuoteItems(PDO $db, int $quoteId, array $pids, array $names, array $mRates, array $mTypes, array $prices, array $qtys): void {
    foreach ($names as $i => $name) {
        $name  = trim($name);
        $qty   = (int)($qtys[$i] ?? 1);
        $price = (float)($prices[$i] ?? 0);
        if ($name === '' || $qty <= 0) continue;

        $db->prepare('INSERT INTO quote_items (quote_id,product_id,item_name,unit_cost,markup_rate,markup_type,unit_price,quantity) VALUES (?,?,?,?,?,?,?,?)')
           ->execute([
               $quoteId,
               (int)($pids[$i] ?? 0) ?: null,
               $name,
               0, // unit_cost stored for reference but price is explicit
               (float)($mRates[$i] ?? 0),
               $mTypes[$i] ?? 'over_cost',
               $price,
               $qty,
           ]);
    }
}

// ── Load quotes ──────────────────────────────────────────────────────────────
$stmt = $db->prepare('SELECT * FROM quotes WHERE user_id=? ORDER BY created_at DESC');
$stmt->execute([$userId]);
$quotes = $stmt->fetchAll();

// Load quote totals
$quoteTotals = [];
foreach ($quotes as $q) {
    $is = $db->prepare('SELECT unit_price, quantity FROM quote_items WHERE quote_id=?');
    $is->execute([$q['id']]);
    $items = $is->fetchAll();
    $subtotal = array_sum(array_map(fn($r) => $r['unit_price'] * $r['quantity'], $items));
    $tax      = $subtotal * ((float)$settings['tax_rate'] / 100);
    $quoteTotals[$q['id']] = [
        'subtotal' => $subtotal,
        'tax'      => $tax,
        'total'    => $settings['include_tax'] ? $subtotal : $subtotal + $tax,
        'items'    => count($items),
    ];
}

// Products for the form
$prodStmt = $db->prepare('SELECT p.*, GROUP_CONCAT(pm.material_id) AS mat_ids FROM products p LEFT JOIN product_materials pm ON pm.product_id=p.id WHERE p.user_id=? GROUP BY p.id ORDER BY p.name');
$prodStmt->execute([$userId]);
$allProducts = $prodStmt->fetchAll();

// Build products cost data for JS
$prodCosts = [];
foreach ($allProducts as $p) {
    $matCost   = getProductCost($db, (int)$p['id']);
    $hr        = effectiveHourlyRate($p, $settings);
    $laborCost = ($p['labor_time'] / 60) * $hr;
    $totalCost = $matCost + $laborCost;
    $mRate     = effectiveMarkupRate($p, $settings);
    $mType     = effectiveMarkupType($p, $settings);
    $recPrice  = calcSellingPrice($totalCost, $mRate, $mType);
    $prodCosts[$p['id']] = [
        'name'       => $p['name'],
        'cost'       => $totalCost,
        'markup_rate'=> $mRate,
        'markup_type'=> $mType,
        'rec_price'  => $recPrice,
    ];
}

// Edit target?
$editId    = (int)($_GET['edit'] ?? 0);
$editQuote = null;
$editItems = [];
if ($editId > 0) {
    $eq = $db->prepare('SELECT * FROM quotes WHERE id=? AND user_id=?');
    $eq->execute([$editId, $userId]);
    $editQuote = $eq->fetch() ?: null;
    if ($editQuote) {
        $ei = $db->prepare('SELECT * FROM quote_items WHERE quote_id=? ORDER BY id');
        $ei->execute([$editId]);
        $editItems = $ei->fetchAll();
    }
}

// Pre-select product from URL (from products page shortcut)
$preProductId = (int)($_GET['product_id'] ?? 0);

$pageTitle    = '見積書';
$current      = 'quotes';
$headerAction = '<button class="btn btn-primary btn-sm" onclick="openModal(\'addModal\')">＋ 見積書を作成</button>';

include __DIR__ . '/includes/header.php';
?>

<div class="card">
  <div class="card-title">📄 見積書一覧</div>
  <?php if (empty($quotes)): ?>
    <div class="empty-state">
      <div class="empty-icon">📄</div>
      <p>まだ見積書がありません。<br>「＋ 見積書を作成」から作成してください。</p>
    </div>
  <?php else: ?>
    <div class="table-wrap">
      <table>
        <thead>
          <tr>
            <th>見積番号</th>
            <th>見積先</th>
            <th>品目数</th>
            <th class="num">小計</th>
            <th class="num">合計（税<?= $settings['include_tax'] ? '込' : '別' ?>）</th>
            <th>有効期限</th>
            <th>ステータス</th>
            <th>操作</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($quotes as $q):
            $tot = $quoteTotals[$q['id']];
          ?>
          <tr>
            <td><strong><?= e($q['quote_number']) ?></strong><div style="font-size:0.75rem;color:var(--text-sub);"><?= e(substr($q['created_at'],0,10)) ?></div></td>
            <td><?= e($q['client_name'] ?: '（未記入）') ?></td>
            <td class="num"><?= $tot['items'] ?>品目</td>
            <td class="num"><?= formatJpy($tot['subtotal']) ?></td>
            <td class="num" style="font-weight:700;"><?= formatJpy($tot['total']) ?></td>
            <td><?= $q['valid_until'] ? e($q['valid_until']) : '−' ?></td>
            <td>
              <span class="tag <?= e(quoteStatusClass($q['status'])) ?>"><?= e(quoteStatusLabel($q['status'])) ?></span>
            </td>
            <td>
              <div class="td-actions">
                <a href="quote_print.php?id=<?= $q['id'] ?>" target="_blank" class="btn btn-secondary btn-sm" title="印刷・表示">🖨</a>
                <a href="?edit=<?= $q['id'] ?>" class="btn btn-secondary btn-sm">編集</a>
                <form method="post" onsubmit="return confirmDelete(this)" style="display:inline;">
                  <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
                  <input type="hidden" name="action" value="delete">
                  <input type="hidden" name="id" value="<?= $q['id'] ?>">
                  <button type="submit" class="btn btn-danger btn-sm">削除</button>
                </form>
              </div>
              <!-- Status change -->
              <form method="post" style="margin-top:4px;display:flex;gap:4px;">
                <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
                <input type="hidden" name="action" value="status">
                <input type="hidden" name="id" value="<?= $q['id'] ?>">
                <select name="status" class="form-control" style="font-size:0.78rem;min-height:32px;padding:4px 8px;" onchange="this.form.submit()">
                  <?php foreach (['draft'=>'下書き','sent'=>'送付済み','accepted'=>'受注','declined'=>'辞退'] as $v=>$l): ?>
                    <option value="<?= $v ?>" <?= $q['status']===$v?'selected':'' ?>><?= $l ?></option>
                  <?php endforeach; ?>
                </select>
              </form>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</div>

<!-- ── Add Modal ──────────────────────────────────────────────────────────── -->
<div class="modal-backdrop <?= ($editQuote || $preProductId) ? '' : '' ?>" id="addModal">
  <div class="modal modal-wide">
    <div class="modal-header">
      <span class="modal-title">見積書を作成</span>
      <button class="modal-close">✕</button>
    </div>
    <form method="post" id="addQuoteForm">
      <div class="modal-body">
        <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
        <input type="hidden" name="action" value="add">

        <div class="form-row">
          <div class="form-group">
            <label class="form-label">見積先（顧客名）</label>
            <input type="text" name="client_name" class="form-control" placeholder="例：田中様">
          </div>
          <div class="form-group">
            <label class="form-label">有効期限</label>
            <input type="date" name="valid_until" class="form-control">
          </div>
        </div>

        <hr class="divider">
        <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:12px;">
          <strong style="font-size:0.9rem;">品目</strong>
          <div style="display:flex;gap:8px;">
            <select id="addProductSelect" class="form-control" style="font-size:0.85rem;min-height:36px;" onchange="addProductRow('add')">
              <option value="">── 作品から追加 ──</option>
              <?php foreach ($allProducts as $p): ?>
                <option value="<?= $p['id'] ?>"><?= e($p['name']) ?></option>
              <?php endforeach; ?>
            </select>
            <button type="button" class="btn btn-secondary btn-sm" onclick="addCustomRow('add')">＋ カスタム</button>
          </div>
        </div>
        <div id="addQuoteItems"></div>

        <div class="cost-summary" id="addQuoteSummary" style="display:none;margin-top:12px;">
          <table>
            <tbody>
              <tr><td>小計</td><td class="num" id="addSubtotal">¥0</td></tr>
              <tr><td>消費税（<?= $settings['tax_rate'] ?>%）</td><td class="num" id="addTaxAmt">¥0</td></tr>
              <tr class="total-row"><td><strong>合計（税<?= $settings['include_tax'] ? '込' : '別' ?>）</strong></td><td class="num" id="addTotalAmt"><strong>¥0</strong></td></tr>
            </tbody>
          </table>
        </div>

        <div class="form-group" style="margin-top:16px;">
          <label class="form-label">備考</label>
          <textarea name="notes" class="form-control" rows="3" placeholder="納期・支払方法・特記事項など"></textarea>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" onclick="closeModal('addModal')">キャンセル</button>
        <button type="submit" class="btn btn-primary">見積書を作成</button>
      </div>
    </form>
  </div>
</div>

<!-- ── Edit Modal ─────────────────────────────────────────────────────────── -->
<?php if ($editQuote): ?>
<div class="modal-backdrop open" id="editModal">
  <div class="modal modal-wide">
    <div class="modal-header">
      <span class="modal-title">見積書を編集：<?= e($editQuote['quote_number']) ?></span>
      <button class="modal-close" onclick="location.href='<?= APP_URL ?>/quotes.php'">✕</button>
    </div>
    <form method="post" id="editQuoteForm">
      <div class="modal-body">
        <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
        <input type="hidden" name="action" value="edit">
        <input type="hidden" name="id" value="<?= $editQuote['id'] ?>">

        <div class="form-row">
          <div class="form-group">
            <label class="form-label">見積先（顧客名）</label>
            <input type="text" name="client_name" class="form-control" value="<?= e($editQuote['client_name']) ?>">
          </div>
          <div class="form-group">
            <label class="form-label">有効期限</label>
            <input type="date" name="valid_until" class="form-control" value="<?= e($editQuote['valid_until'] ?? '') ?>">
          </div>
        </div>

        <hr class="divider">
        <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:12px;">
          <strong style="font-size:0.9rem;">品目</strong>
          <div style="display:flex;gap:8px;">
            <select id="editProductSelect" class="form-control" style="font-size:0.85rem;min-height:36px;" onchange="addProductRow('edit')">
              <option value="">── 作品から追加 ──</option>
              <?php foreach ($allProducts as $p): ?>
                <option value="<?= $p['id'] ?>"><?= e($p['name']) ?></option>
              <?php endforeach; ?>
            </select>
            <button type="button" class="btn btn-secondary btn-sm" onclick="addCustomRow('edit')">＋ カスタム</button>
          </div>
        </div>
        <div id="editQuoteItems">
          <?php foreach ($editItems as $item): ?>
          <?php $this_pid = (int)($item['product_id'] ?? 0); ?>
          <div class="quote-item-row">
            <input type="hidden" name="item_product_id[]" value="<?= $this_pid ?>">
            <div class="quote-item-grid">
              <div class="form-group" style="margin:0;">
                <input type="text" name="item_name[]" class="form-control" value="<?= e($item['item_name']) ?>" placeholder="品名" required oninput="recalcQuote('edit')">
              </div>
              <div class="form-group" style="margin:0;">
                <div style="display:flex;gap:6px;align-items:center;">
                  <input type="number" name="item_unit_price[]" class="form-control item-price" value="<?= $item['unit_price'] ?>" min="0" step="1" placeholder="単価" oninput="recalcQuote('edit')">
                  <span style="white-space:nowrap;font-size:0.85rem;">円 ×</span>
                  <input type="number" name="item_quantity[]" class="form-control item-qty" value="<?= $item['quantity'] ?>" min="1" step="1" style="width:70px;" oninput="recalcQuote('edit')">
                  <span style="white-space:nowrap;font-size:0.85rem;">点</span>
                  <button type="button" class="remove-row-btn" onclick="this.closest('.quote-item-row').remove();recalcQuote('edit')">✕</button>
                </div>
              </div>
              <?php if ($this_pid && isset($prodCosts[$this_pid])): $pc=$prodCosts[$this_pid]; ?>
              <div style="grid-column:1/-1;font-size:0.78rem;color:var(--text-sub);padding:2px 4px;background:var(--bg);border-radius:4px;">
                原価: <?= formatJpy($pc['cost']) ?> ／ 推奨: <?= formatJpy($pc['rec_price']) ?>（<?= e(markupLabel($pc['markup_type'],$pc['markup_rate'])) ?>）
              </div>
              <?php endif; ?>
            </div>
            <input type="hidden" name="item_markup_rate[]" value="<?= $item['markup_rate'] ?>">
            <input type="hidden" name="item_markup_type[]" value="<?= e($item['markup_type']) ?>">
          </div>
          <?php endforeach; ?>
        </div>

        <div class="cost-summary" id="editQuoteSummary" style="margin-top:12px;">
          <table>
            <tbody>
              <tr><td>小計</td><td class="num" id="editSubtotal">¥0</td></tr>
              <tr><td>消費税（<?= $settings['tax_rate'] ?>%）</td><td class="num" id="editTaxAmt">¥0</td></tr>
              <tr class="total-row"><td><strong>合計</strong></td><td class="num" id="editTotalAmt"><strong>¥0</strong></td></tr>
            </tbody>
          </table>
        </div>

        <div class="form-group" style="margin-top:16px;">
          <label class="form-label">備考</label>
          <textarea name="notes" class="form-control" rows="3"><?= e($editQuote['notes'] ?? '') ?></textarea>
        </div>
      </div>
      <div class="modal-footer">
        <a href="<?= APP_URL ?>/quotes.php" class="btn btn-secondary">キャンセル</a>
        <button type="submit" class="btn btn-primary">更新する</button>
      </div>
    </form>
  </div>
</div>
<?php endif; ?>

<?php
$prodCostsJson = json_encode($prodCosts);
$taxRate       = (float)$settings['tax_rate'];
$includeTax    = (bool)$settings['include_tax'];
$preProductIdJ = $preProductId;

$pageScript = <<<JSCODE
const PROD_COSTS  = {$prodCostsJson};
const TAX_RATE    = {$taxRate};
const INCLUDE_TAX = {$includeTax};
const PRE_PRODUCT = {$preProductIdJ};

function quoteItemHtml(fk, pid, name, price, qty, cost, recPrice, markupLabel, mRate, mType) {
  const costInfo = cost > 0
    ? `<div style="grid-column:1/-1;font-size:0.78rem;color:var(--text-sub);padding:2px 4px;background:var(--bg);border-radius:4px;">原価: ¥\${Math.round(cost).toLocaleString()} ／ 推奨: ¥\${Math.round(recPrice).toLocaleString()}（\${escHtml(markupLabel)}）</div>`
    : '';
  return `<div class="quote-item-row">
    <input type="hidden" name="item_product_id[]" value="\${pid || ''}">
    <input type="hidden" name="item_markup_rate[]" value="\${mRate || 0}">
    <input type="hidden" name="item_markup_type[]" value="\${mType || 'over_cost'}">
    <div class="quote-item-grid">
      <div class="form-group" style="margin:0;">
        <input type="text" name="item_name[]" class="form-control" value="\${escHtml(name)}" placeholder="品名" required oninput="recalcQuote('\${fk}')">
      </div>
      <div class="form-group" style="margin:0;">
        <div style="display:flex;gap:6px;align-items:center;">
          <input type="number" name="item_unit_price[]" class="form-control item-price" value="\${Math.round(price)}" min="0" step="1" placeholder="単価" oninput="recalcQuote('\${fk}')">
          <span style="white-space:nowrap;font-size:0.85rem;">円 ×</span>
          <input type="number" name="item_quantity[]" class="form-control item-qty" value="\${qty}" min="1" step="1" style="width:70px;" oninput="recalcQuote('\${fk}')">
          <span style="white-space:nowrap;font-size:0.85rem;">点</span>
          <button type="button" class="remove-row-btn" onclick="this.closest('.quote-item-row').remove();recalcQuote('\${fk}')">✕</button>
        </div>
      </div>
      \${costInfo}
    </div>
  </div>`;
}

function addProductRow(fk) {
  const sel = document.getElementById(fk + 'ProductSelect');
  const pid = parseInt(sel.value);
  if (!pid) return;
  const p = PROD_COSTS[pid];
  if (!p) return;
  const container = document.getElementById(fk + 'QuoteItems');
  const labelMap = { over_cost: `＋\${p.markup_rate}%`, multiplier: `×\${p.markup_rate}倍`, margin: `利益率\${p.markup_rate}%` };
  container.insertAdjacentHTML('beforeend', quoteItemHtml(fk, pid, p.name, p.rec_price, 1, p.cost, p.rec_price, labelMap[p.markup_type] || '', p.markup_rate, p.markup_type));
  sel.value = '';
  recalcQuote(fk);
}

function addCustomRow(fk) {
  const container = document.getElementById(fk + 'QuoteItems');
  container.insertAdjacentHTML('beforeend', quoteItemHtml(fk, '', '', 0, 1, 0, 0, '', 0, 'over_cost'));
  recalcQuote(fk);
}

function recalcQuote(fk) {
  const container = document.getElementById(fk + 'QuoteItems');
  let subtotal = 0;
  container.querySelectorAll('.quote-item-row').forEach(row => {
    const price = parseFloat(row.querySelector('.item-price')?.value) || 0;
    const qty   = parseInt(row.querySelector('.item-qty')?.value) || 1;
    subtotal += price * qty;
  });
  const tax   = subtotal * (TAX_RATE / 100);
  const total = INCLUDE_TAX ? subtotal : subtotal + tax;
  const summary = document.getElementById(fk + 'QuoteSummary');
  if (summary) {
    summary.style.display = 'block';
    document.getElementById(fk + 'Subtotal').textContent = fmtJpy(subtotal);
    document.getElementById(fk + 'TaxAmt').textContent   = fmtJpy(tax);
    document.getElementById(fk + 'TotalAmt').innerHTML   = '<strong>' + fmtJpy(total) + '</strong>';
  }
}

// Pre-populate from products page shortcut
if (PRE_PRODUCT && PROD_COSTS[PRE_PRODUCT]) {
  openModal('addModal');
  const p = PROD_COSTS[PRE_PRODUCT];
  const labelMap = { over_cost: `＋\${p.markup_rate}%`, multiplier: `×\${p.markup_rate}倍`, margin: `利益率\${p.markup_rate}%` };
  document.getElementById('addQuoteItems').insertAdjacentHTML('beforeend',
    quoteItemHtml('add', PRE_PRODUCT, p.name, p.rec_price, 1, p.cost, p.rec_price, labelMap[p.markup_type] || '', p.markup_rate, p.markup_type)
  );
  recalcQuote('add');
}

// Init edit totals
recalcQuote('edit');
JSCODE;

include __DIR__ . '/includes/footer.php';
?>
