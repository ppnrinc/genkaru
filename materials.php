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
        $stmt = $db->prepare('DELETE FROM materials WHERE id = ? AND user_id = ?');
        $stmt->execute([$id, $userId]);
        flashSet('success', '材料を削除しました。');
        header('Location: ' . APP_URL . '/materials.php');
        exit;
    }

    $id            = (int)($_POST['id'] ?? 0);
    $name          = trim($_POST['name'] ?? '');
    $purchasePrice = (float)($_POST['purchase_price'] ?? 0);
    $totalQty      = (float)($_POST['total_quantity'] ?? 0);
    $remainingQty  = (float)($_POST['remaining_quantity'] ?? $totalQty);
    $unit          = trim($_POST['unit'] ?? 'g');
    $rakutenUrl    = trim($_POST['rakuten_url'] ?? '');
    $imageUrl      = trim($_POST['image_url'] ?? '');
    $notes         = trim($_POST['notes'] ?? '');

    if ($name === '') {
        flashSet('error', '材料名を入力してください。');
        header('Location: ' . APP_URL . '/materials.php');
        exit;
    }

    if ($id > 0) {
        // Update
        $check = $db->prepare('SELECT id FROM materials WHERE id = ? AND user_id = ?');
        $check->execute([$id, $userId]);
        if ($check->fetch()) {
            $db->prepare('UPDATE materials SET name=?,purchase_price=?,total_quantity=?,remaining_quantity=?,unit=?,rakuten_url=?,image_url=?,notes=?,updated_at=NOW() WHERE id=?')
               ->execute([$name,$purchasePrice,$totalQty,$remainingQty,$unit,$rakutenUrl,$imageUrl,$notes,$id]);
            flashSet('success', '材料を更新しました。');
        }
    } else {
        // Insert
        $db->prepare('INSERT INTO materials (user_id,name,purchase_price,total_quantity,remaining_quantity,unit,rakuten_url,image_url,notes) VALUES (?,?,?,?,?,?,?,?,?)')
           ->execute([$userId,$name,$purchasePrice,$totalQty,$remainingQty,$unit,$rakutenUrl,$imageUrl,$notes]);
        flashSet('success', '材料を登録しました。');
    }

    header('Location: ' . APP_URL . '/materials.php');
    exit;
}

// ── Load materials ───────────────────────────────────────────────────────────
$stmt = $db->prepare('SELECT * FROM materials WHERE user_id = ? ORDER BY name ASC');
$stmt->execute([$userId]);
$materials = $stmt->fetchAll();

// Edit target?
$editId  = (int)($_GET['edit'] ?? 0);
$editMat = null;
if ($editId > 0) {
    $es = $db->prepare('SELECT * FROM materials WHERE id = ? AND user_id = ?');
    $es->execute([$editId, $userId]);
    $editMat = $es->fetch() ?: null;
}

$pageTitle   = '材料管理';
$current     = 'materials';
$headerAction = '<button class="btn btn-primary btn-sm" onclick="openModal(\'addModal\')">＋ 材料を追加</button>';

include __DIR__ . '/includes/header.php';
?>

<!-- Search card -->
<div class="card">
  <div class="card-title">🔍 楽天で材料を検索</div>
  <div class="search-form">
    <input type="text" id="searchKeyword" class="form-control" placeholder="材料名を入力（例：レジン液、ビーズ、刺繍糸）">
    <button class="btn btn-primary" onclick="searchRakuten()">楽天で検索</button>
  </div>
  <div id="searchStatus" style="color:var(--text-sub);font-size:0.85rem;margin-bottom:8px;"></div>
  <div id="searchResults" class="search-results" style="display:none;"></div>
</div>

<!-- Materials list -->
<div class="card">
  <div class="card-title">🧵 登録材料一覧</div>
  <?php if (empty($materials)): ?>
    <div class="empty-state">
      <div class="empty-icon">🧵</div>
      <p>材料がまだ登録されていません。<br>楽天検索か手動入力で追加してください。</p>
    </div>
  <?php else: ?>
    <div class="table-wrap">
      <table>
        <thead>
          <tr>
            <th></th>
            <th>材料名</th>
            <th class="num">購入価格</th>
            <th class="num">内容量</th>
            <th class="num">残量</th>
            <th>単位</th>
            <th class="num">単価</th>
            <th>購入リンク</th>
            <th>操作</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($materials as $m):
            $unitPrice = $m['total_quantity'] > 0 ? ($m['purchase_price'] / $m['total_quantity']) : 0;
          ?>
          <tr>
            <td>
              <?php if ($m['image_url']): ?>
                <img src="<?= e($m['image_url']) ?>" style="width:40px;height:40px;object-fit:cover;border-radius:6px;" alt="">
              <?php else: ?>
                <div style="width:40px;height:40px;background:var(--bg);border-radius:6px;display:flex;align-items:center;justify-content:center;font-size:1.2rem;">🧵</div>
              <?php endif; ?>
            </td>
            <td><strong><?= e($m['name']) ?></strong>
              <?php if ($m['notes']): ?>
                <div style="font-size:0.78rem;color:var(--text-sub);"><?= e($m['notes']) ?></div>
              <?php endif; ?>
            </td>
            <td class="num"><?= formatJpy((float)$m['purchase_price']) ?></td>
            <td class="num"><?= number_format((float)$m['total_quantity'], 1) ?></td>
            <td class="num"><?= number_format((float)$m['remaining_quantity'], 1) ?></td>
            <td><?= e($m['unit']) ?></td>
            <td class="num"><?= $unitPrice > 0 ? formatJpy($unitPrice) . '/' . e($m['unit']) : '−' ?></td>
            <td>
              <div style="display:flex;gap:4px;flex-wrap:wrap;">
                <?php if ($m['rakuten_url']): ?>
                  <a href="<?= e($m['rakuten_url']) ?>" target="_blank" rel="noopener" class="btn btn-rakuten btn-sm">楽天で買う</a>
                <?php endif; ?>
              </div>
            </td>
            <td>
              <div class="td-actions">
                <a href="?edit=<?= $m['id'] ?>" class="btn btn-secondary btn-sm">編集</a>
                <form method="post" onsubmit="return confirmDelete(this)">
                  <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
                  <input type="hidden" name="action" value="delete">
                  <input type="hidden" name="id" value="<?= $m['id'] ?>">
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
<div class="modal-backdrop" id="addModal" <?= $editMat ? '' : '' ?>>
  <div class="modal">
    <div class="modal-header">
      <span class="modal-title">材料を追加</span>
      <button class="modal-close">✕</button>
    </div>
    <form method="post">
      <div class="modal-body">
        <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
        <input type="hidden" name="action" value="add">
        <input type="hidden" id="addImageUrl" name="image_url" value="">
        <input type="hidden" id="addRakutenUrl" name="rakuten_url" value="">

        <div class="form-group">
          <label class="form-label">材料名 <span class="required">*</span></label>
          <input type="text" name="name" id="addName" class="form-control" required>
        </div>
        <div class="form-row">
          <div class="form-group">
            <label class="form-label">購入価格（円）</label>
            <input type="number" name="purchase_price" id="addPrice" class="form-control" min="0" step="1" value="0">
          </div>
          <div class="form-group">
            <label class="form-label">内容量</label>
            <input type="number" name="total_quantity" id="addQty" class="form-control" min="0" step="0.001" value="0">
          </div>
          <div class="form-group">
            <label class="form-label">残量</label>
            <input type="number" name="remaining_quantity" id="addRemQty" class="form-control" min="0" step="0.001" value="0">
          </div>
          <div class="form-group">
            <label class="form-label">単位</label>
            <select name="unit" id="addUnit" class="form-control">
              <option value="g">g</option>
              <option value="ml">ml</option>
              <option value="cm">cm</option>
              <option value="m">m</option>
              <option value="個">個</option>
              <option value="枚">枚</option>
              <option value="本">本</option>
              <option value="セット">セット</option>
            </select>
          </div>
        </div>
        <div class="form-group">
          <label class="form-label">メモ</label>
          <input type="text" name="notes" class="form-control" placeholder="色・品番など">
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" onclick="closeModal('addModal')">キャンセル</button>
        <button type="submit" class="btn btn-primary">登録する</button>
      </div>
    </form>
  </div>
</div>

<!-- Edit Modal -->
<?php if ($editMat): ?>
<div class="modal-backdrop open" id="editModal">
  <div class="modal">
    <div class="modal-header">
      <span class="modal-title">材料を編集</span>
      <button class="modal-close" onclick="location.href='<?= APP_URL ?>/materials.php'">✕</button>
    </div>
    <form method="post">
      <div class="modal-body">
        <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
        <input type="hidden" name="action" value="edit">
        <input type="hidden" name="id" value="<?= $editMat['id'] ?>">
        <input type="hidden" name="image_url" value="<?= e($editMat['image_url'] ?? '') ?>">
        <input type="hidden" name="rakuten_url" value="<?= e($editMat['rakuten_url'] ?? '') ?>">

        <div class="form-group">
          <label class="form-label">材料名 <span class="required">*</span></label>
          <input type="text" name="name" class="form-control" value="<?= e($editMat['name']) ?>" required>
        </div>
        <div class="form-row">
          <div class="form-group">
            <label class="form-label">購入価格（円）</label>
            <input type="number" name="purchase_price" class="form-control" min="0" step="1" value="<?= (int)$editMat['purchase_price'] ?>">
          </div>
          <div class="form-group">
            <label class="form-label">内容量</label>
            <input type="number" name="total_quantity" class="form-control" min="0" step="0.001" value="<?= $editMat['total_quantity'] ?>">
          </div>
          <div class="form-group">
            <label class="form-label">残量</label>
            <input type="number" name="remaining_quantity" class="form-control" min="0" step="0.001" value="<?= $editMat['remaining_quantity'] ?>">
          </div>
          <div class="form-group">
            <label class="form-label">単位</label>
            <select name="unit" class="form-control">
              <?php foreach (['g','ml','cm','m','個','枚','本','セット'] as $u): ?>
                <option value="<?= e($u) ?>" <?= $editMat['unit'] === $u ? 'selected' : '' ?>><?= e($u) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
        </div>
        <div class="form-group">
          <label class="form-label">メモ</label>
          <input type="text" name="notes" class="form-control" value="<?= e($editMat['notes'] ?? '') ?>">
        </div>
      </div>
      <div class="modal-footer">
        <a href="<?= APP_URL ?>/materials.php" class="btn btn-secondary">キャンセル</a>
        <button type="submit" class="btn btn-primary">更新する</button>
      </div>
    </form>
  </div>
</div>
<?php endif; ?>

<?php
$pageScript = <<<'JS'
const CSRF = document.querySelector('input[name="csrf_token"]')?.value || '';

async function searchRakuten() {
  const kw = document.getElementById('searchKeyword').value.trim();
  if (!kw) { alert('キーワードを入力してください'); return; }

  const status = document.getElementById('searchStatus');
  const results = document.getElementById('searchResults');
  status.innerHTML = '<span class="spinner"></span> 検索中...';
  results.style.display = 'none';
  results.innerHTML = '';

  try {
    const res = await fetch('/api/rakuten.php?keyword=' + encodeURIComponent(kw));
    const data = await res.json();

    status.textContent = data.items?.length ? `${data.items.length}件見つかりました` : '見つかりませんでした';
    if (!data.items?.length) return;

    results.style.display = 'grid';
    data.items.forEach(item => {
      const el = document.createElement('div');
      el.className = 'search-item';
      el.innerHTML = `
        <img src="${item.image || ''}" alt="" onerror="this.style.display='none'">
        <div class="search-item-info">
          <div class="search-item-name">${escHtml(item.name)}</div>
          <div class="search-item-price">¥${item.price.toLocaleString()}</div>
          <div class="search-item-shop">${escHtml(item.shop)}</div>
        </div>`;
      el.addEventListener('click', () => fillAddForm(item));
      results.appendChild(el);
    });
  } catch(e) {
    status.textContent = '検索エラーが発生しました';
  }
}

function fillAddForm(item) {
  document.getElementById('addName').value = item.name;
  document.getElementById('addPrice').value = item.price;
  document.getElementById('addImageUrl').value = item.image || '';
  document.getElementById('addRakutenUrl').value = item.affUrl || item.url || '';
  openModal('addModal');
}

function escHtml(s) {
  return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

document.getElementById('searchKeyword')?.addEventListener('keydown', e => {
  if (e.key === 'Enter') searchRakuten();
});
JS;
include __DIR__ . '/includes/footer.php';
?>
