<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

requireLogin();
$user   = currentUser();
$db     = getDb();
$userId = $user['id'];

// ── CRUD ────────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        $db->prepare('DELETE FROM events WHERE id=? AND user_id=?')->execute([$id, $userId]);
        flashSet('success', 'イベントを削除しました。');
        header('Location: ' . APP_URL . '/events.php');
        exit;
    }

    $id           = (int)($_POST['id'] ?? 0);
    $name         = trim($_POST['name'] ?? '');
    $eventDate    = $_POST['event_date'] ?? '';
    $location     = trim($_POST['location'] ?? '');
    $boothFee     = (float)($_POST['booth_fee'] ?? 0);
    $currency     = strtoupper(trim($_POST['currency'] ?? 'JPY'));
    $exchangeRate = (float)($_POST['exchange_rate'] ?? 1.0);
    $notes        = trim($_POST['notes'] ?? '');

    if ($name === '' || $eventDate === '') {
        flashSet('error', 'イベント名と日付を入力してください。');
        header('Location: ' . APP_URL . '/events.php');
        exit;
    }

    if ($id > 0) {
        $chk = $db->prepare('SELECT id FROM events WHERE id=? AND user_id=?');
        $chk->execute([$id, $userId]);
        if ($chk->fetch()) {
            $db->prepare('UPDATE events SET name=?,event_date=?,location=?,booth_fee=?,currency=?,exchange_rate=?,notes=?,updated_at=NOW() WHERE id=?')
               ->execute([$name,$eventDate,$location,$boothFee,$currency,$exchangeRate,$notes,$id]);
            saveSales($db, $id, $_POST);
            flashSet('success', 'イベントを更新しました。');
        }
    } else {
        $db->prepare('INSERT INTO events (user_id,name,event_date,location,booth_fee,currency,exchange_rate,notes) VALUES (?,?,?,?,?,?,?,?)')
           ->execute([$userId,$name,$eventDate,$location,$boothFee,$currency,$exchangeRate,$notes]);
        $newId = (int)$db->lastInsertId();
        saveSales($db, $newId, $_POST);
        flashSet('success', 'イベントを登録しました。');
    }

    header('Location: ' . APP_URL . '/events.php');
    exit;
}

function saveSales(PDO $db, int $eventId, array $post): void {
    $db->prepare('DELETE FROM event_sales WHERE event_id=?')->execute([$eventId]);
    $pids  = $post['sale_product_id']    ?? [];
    $prices= $post['sale_selling_price'] ?? [];
    $qtys  = $post['sale_quantity']      ?? [];
    foreach ($pids as $i => $pid) {
        $price = (float)($prices[$i] ?? 0);
        $qty   = (int)($qtys[$i] ?? 0);
        if ((int)$pid > 0 && $qty > 0) {
            $db->prepare('INSERT INTO event_sales (event_id,product_id,selling_price,quantity_sold) VALUES (?,?,?,?)')
               ->execute([$eventId, (int)$pid, $price, $qty]);
        }
    }
}

// ── Load events ──────────────────────────────────────────────────────────────
$stmt = $db->prepare('SELECT * FROM events WHERE user_id=? ORDER BY event_date DESC');
$stmt->execute([$userId]);
$events = $stmt->fetchAll();

// Load all user products for the sales form
$prodStmt = $db->prepare('SELECT id, name FROM products WHERE user_id=? ORDER BY name');
$prodStmt->execute([$userId]);
$allProducts = $prodStmt->fetchAll();

// Edit target?
$editId      = (int)($_GET['id'] ?? $_GET['edit'] ?? 0);
$editEvent   = null;
$editSales   = [];
if ($editId > 0) {
    $es = $db->prepare('SELECT * FROM events WHERE id=? AND user_id=?');
    $es->execute([$editId, $userId]);
    $editEvent = $es->fetch() ?: null;
    if ($editEvent) {
        $ss = $db->prepare('SELECT * FROM event_sales WHERE event_id=?');
        $ss->execute([$editId]);
        $editSales = $ss->fetchAll();
    }
}

$pageTitle    = 'イベント管理';
$current      = 'events';
$headerAction = '<button class="btn btn-primary btn-sm" onclick="openModal(\'addModal\')">＋ イベントを追加</button>';

$currencies = ['JPY'=>'円（JPY）','USD'=>'ドル（USD）','EUR'=>'ユーロ（EUR）','GBP'=>'ポンド（GBP）','AUD'=>'豪ドル（AUD）','CNY'=>'人民元（CNY）','KRW'=>'ウォン（KRW）','TWD'=>'台湾ドル（TWD）','HKD'=>'香港ドル（HKD）'];

include __DIR__ . '/includes/header.php';
?>

<div class="card">
  <div class="card-title">📅 イベント一覧</div>
  <?php if (empty($events)): ?>
    <div class="empty-state">
      <div class="empty-icon">📅</div>
      <p>まだイベントが登録されていません。</p>
    </div>
  <?php else: ?>
    <div class="table-wrap">
      <table>
        <thead>
          <tr>
            <th>イベント名</th>
            <th>日付</th>
            <th>場所</th>
            <th>通貨</th>
            <th class="num">出店料</th>
            <th class="num">売上（円）</th>
            <th class="num">原価（円）</th>
            <th class="num">実質利益（円）</th>
            <th>操作</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($events as $ev):
            $stats = getEventStats($db, (int)$ev['id']);
          ?>
          <tr>
            <td><strong><?= e($ev['name']) ?></strong>
              <?php if ($ev['notes']): ?>
                <div style="font-size:0.78rem;color:var(--text-sub);"><?= e($ev['notes']) ?></div>
              <?php endif; ?>
            </td>
            <td><?= e($ev['event_date']) ?></td>
            <td><?= e($ev['location'] ?: '−') ?></td>
            <td>
              <span class="tag <?= $ev['currency']==='JPY'?'tag-jpy':($ev['currency']==='USD'?'tag-usd':($ev['currency']==='EUR'?'tag-eur':'tag-other')) ?>">
                <?= e($ev['currency']) ?>
              </span>
              <?php if ($ev['currency'] !== 'JPY'): ?>
                <div style="font-size:0.75rem;color:var(--text-sub);">×<?= $ev['exchange_rate'] ?></div>
              <?php endif; ?>
            </td>
            <td class="num"><?= formatCurrency((float)$ev['booth_fee'], $ev['currency']) ?></td>
            <td class="num"><?= formatJpy($stats['revenue']) ?></td>
            <td class="num"><?= formatJpy($stats['cost']) ?></td>
            <td class="num <?= $stats['profit'] >= 0 ? 'profit-pos' : 'profit-neg' ?>">
              <?= formatJpy($stats['profit']) ?>
            </td>
            <td>
              <div class="td-actions">
                <a href="?edit=<?= $ev['id'] ?>" class="btn btn-secondary btn-sm">編集</a>
                <form method="post" onsubmit="return confirmDelete(this)">
                  <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
                  <input type="hidden" name="action" value="delete">
                  <input type="hidden" name="id" value="<?= $ev['id'] ?>">
                  <button type="submit" class="btn btn-danger btn-sm">削除</button>
                </form>
              </div>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</div>

<!-- Add Modal -->
<div class="modal-backdrop" id="addModal">
  <div class="modal modal-wide">
    <div class="modal-header">
      <span class="modal-title">イベントを追加</span>
      <button class="modal-close">✕</button>
    </div>
    <form method="post">
      <div class="modal-body">
        <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
        <input type="hidden" name="action" value="add">

        <div class="form-row">
          <div class="form-group" style="grid-column:1/-1;">
            <label class="form-label">イベント名 <span class="required">*</span></label>
            <input type="text" name="name" class="form-control" required placeholder="例：〇〇マルシェ 2024秋">
          </div>
          <div class="form-group">
            <label class="form-label">開催日 <span class="required">*</span></label>
            <input type="date" name="event_date" class="form-control" required value="<?= date('Y-m-d') ?>">
          </div>
          <div class="form-group">
            <label class="form-label">場所</label>
            <input type="text" name="location" class="form-control" placeholder="例：東京・渋谷">
          </div>
        </div>
        <div class="form-row">
          <div class="form-group">
            <label class="form-label">出店料</label>
            <input type="number" name="booth_fee" class="form-control" min="0" step="1" value="0">
          </div>
          <div class="form-group">
            <label class="form-label">通貨</label>
            <select name="currency" class="form-control" id="addCurrency" onchange="toggleRate('add')">
              <?php foreach ($currencies as $code => $label): ?>
                <option value="<?= e($code) ?>"><?= e($label) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="form-group" id="addRateGroup" style="display:none;">
            <label class="form-label">為替レート（1外貨 = ? 円）</label>
            <input type="number" name="exchange_rate" class="form-control" min="0.0001" step="0.0001" value="1">
          </div>
        </div>
        <div class="form-group">
          <label class="form-label">メモ</label>
          <input type="text" name="notes" class="form-control">
        </div>

        <hr class="divider">
        <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:10px;">
          <strong style="font-size:0.9rem;">販売記録</strong>
          <button type="button" class="btn btn-secondary btn-sm" onclick="addSaleRow('addSalesRows')">＋ 作品を追加</button>
        </div>
        <?php if (empty($allProducts)): ?>
          <p style="color:var(--text-sub);font-size:0.85rem;">まず作品管理で作品を登録してください。</p>
        <?php endif; ?>
        <div id="addSalesRows"></div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" onclick="closeModal('addModal')">キャンセル</button>
        <button type="submit" class="btn btn-primary">登録する</button>
      </div>
    </form>
  </div>
</div>

<!-- Edit Modal -->
<?php if ($editEvent): ?>
<div class="modal-backdrop open" id="editModal">
  <div class="modal modal-wide">
    <div class="modal-header">
      <span class="modal-title">イベントを編集</span>
      <button class="modal-close" onclick="location.href='<?= APP_URL ?>/events.php'">✕</button>
    </div>
    <form method="post">
      <div class="modal-body">
        <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
        <input type="hidden" name="action" value="edit">
        <input type="hidden" name="id" value="<?= $editEvent['id'] ?>">

        <div class="form-row">
          <div class="form-group" style="grid-column:1/-1;">
            <label class="form-label">イベント名 <span class="required">*</span></label>
            <input type="text" name="name" class="form-control" value="<?= e($editEvent['name']) ?>" required>
          </div>
          <div class="form-group">
            <label class="form-label">開催日 <span class="required">*</span></label>
            <input type="date" name="event_date" class="form-control" value="<?= e($editEvent['event_date']) ?>" required>
          </div>
          <div class="form-group">
            <label class="form-label">場所</label>
            <input type="text" name="location" class="form-control" value="<?= e($editEvent['location'] ?? '') ?>">
          </div>
        </div>
        <div class="form-row">
          <div class="form-group">
            <label class="form-label">出店料</label>
            <input type="number" name="booth_fee" class="form-control" min="0" step="1" value="<?= $editEvent['booth_fee'] ?>">
          </div>
          <div class="form-group">
            <label class="form-label">通貨</label>
            <select name="currency" class="form-control" id="editCurrency" onchange="toggleRate('edit')">
              <?php foreach ($currencies as $code => $label): ?>
                <option value="<?= e($code) ?>" <?= $editEvent['currency']===$code?'selected':'' ?>><?= e($label) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="form-group" id="editRateGroup" style="<?= $editEvent['currency']!=='JPY'?'':'display:none;' ?>">
            <label class="form-label">為替レート（1外貨 = ? 円）</label>
            <input type="number" name="exchange_rate" class="form-control" min="0.0001" step="0.0001" value="<?= $editEvent['exchange_rate'] ?>">
          </div>
        </div>
        <div class="form-group">
          <label class="form-label">メモ</label>
          <input type="text" name="notes" class="form-control" value="<?= e($editEvent['notes'] ?? '') ?>">
        </div>

        <hr class="divider">
        <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:10px;">
          <strong style="font-size:0.9rem;">販売記録</strong>
          <button type="button" class="btn btn-secondary btn-sm" onclick="addSaleRow('editSalesRows')">＋ 作品を追加</button>
        </div>
        <div id="editSalesRows">
          <?php foreach ($editSales as $s): ?>
          <div class="material-row event-sales-table">
            <select name="sale_product_id[]" class="form-control">
              <?php foreach ($allProducts as $p): ?>
                <option value="<?= $p['id'] ?>" <?= $p['id']==$s['product_id']?'selected':'' ?>><?= e($p['name']) ?></option>
              <?php endforeach; ?>
            </select>
            <input type="number" name="sale_selling_price[]" value="<?= $s['selling_price'] ?>" min="0" step="1" placeholder="販売価格">
            <input type="number" name="sale_quantity[]" value="<?= $s['quantity_sold'] ?>" min="0" step="1" placeholder="販売数">
            <button type="button" class="remove-row-btn" onclick="this.closest('.material-row').remove()">✕</button>
          </div>
          <?php endforeach; ?>
        </div>
      </div>
      <div class="modal-footer">
        <a href="<?= APP_URL ?>/events.php" class="btn btn-secondary">キャンセル</a>
        <button type="submit" class="btn btn-primary">更新する</button>
      </div>
    </form>
  </div>
</div>
<?php endif; ?>

<?php
$prodsJson = json_encode($allProducts);
$pageScript = <<<JSCODE
const PRODUCTS = {$prodsJson};

function productOptionsHtml(selectedId) {
  return PRODUCTS.map(p =>
    `<option value="\${p.id}" \${p.id == selectedId ? 'selected' : ''}>\${escHtml(p.name)}</option>`
  ).join('');
}

function addSaleRow(containerId, productId, price, qty) {
  const container = document.getElementById(containerId);
  const row = document.createElement('div');
  row.className = 'material-row event-sales-table';
  row.innerHTML = `
    <select name="sale_product_id[]" class="form-control">\${productOptionsHtml(productId)}</select>
    <input type="number" name="sale_selling_price[]" value="\${price||''}" min="0" step="1" placeholder="販売価格（円）">
    <input type="number" name="sale_quantity[]" value="\${qty||''}" min="0" step="1" placeholder="販売数" style="width:80px;">
    <button type="button" class="remove-row-btn" onclick="this.closest('.material-row').remove()">✕</button>`;
  container.appendChild(row);
}

function toggleRate(prefix) {
  const sel   = document.getElementById(prefix + 'Currency');
  const group = document.getElementById(prefix + 'RateGroup');
  if (group) group.style.display = sel.value !== 'JPY' ? '' : 'none';
}
JSCODE;

include __DIR__ . '/includes/footer.php';
?>
