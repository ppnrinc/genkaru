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
        $db->prepare('DELETE FROM products WHERE id = ? AND user_id = ?')->execute([$id, $userId]);
        flashSet('success', '作品を削除しました。');
        header('Location: ' . APP_URL . '/products.php');
        exit;
    }

    $id         = (int)($_POST['id'] ?? 0);
    $name       = trim($_POST['name'] ?? '');
    $laborTime  = (int)($_POST['labor_time'] ?? 0);
    $hourlyRate = (float)($_POST['hourly_rate'] ?? 0);
    $notes      = trim($_POST['notes'] ?? '');

    if ($name === '') {
        flashSet('error', '作品名を入力してください。');
        header('Location: ' . APP_URL . '/products.php');
        exit;
    }

    if ($id > 0) {
        $chk = $db->prepare('SELECT id FROM products WHERE id=? AND user_id=?');
        $chk->execute([$id, $userId]);
        if ($chk->fetch()) {
            $db->prepare('UPDATE products SET name=?,labor_time=?,hourly_rate=?,notes=?,updated_at=NOW() WHERE id=?')
               ->execute([$name,$laborTime,$hourlyRate,$notes,$id]);

            // Replace materials
            $db->prepare('DELETE FROM product_materials WHERE product_id=?')->execute([$id]);
            saveProductMaterials($db, $id, $_POST);
            flashSet('success', '作品を更新しました。');
        }
    } else {
        $db->prepare('INSERT INTO products (user_id,name,labor_time,hourly_rate,notes) VALUES (?,?,?,?,?)')
           ->execute([$userId,$name,$laborTime,$hourlyRate,$notes]);
        $newId = (int)$db->lastInsertId();
        saveProductMaterials($db, $newId, $_POST);
        flashSet('success', '作品を登録しました。');
    }

    header('Location: ' . APP_URL . '/products.php');
    exit;
}

function saveProductMaterials(PDO $db, int $productId, array $post): void {
    $matIds   = $post['mat_id']  ?? [];
    $matQtys  = $post['mat_qty'] ?? [];
    foreach ($matIds as $i => $matId) {
        $qty = (float)($matQtys[$i] ?? 0);
        if ((int)$matId > 0 && $qty > 0) {
            $db->prepare('INSERT INTO product_materials (product_id,material_id,quantity_used) VALUES (?,?,?)')
               ->execute([$productId, (int)$matId, $qty]);
        }
    }
}

// ── Load products ────────────────────────────────────────────────────────────
$stmt = $db->prepare('SELECT * FROM products WHERE user_id = ? ORDER BY name ASC');
$stmt->execute([$userId]);
$products = $stmt->fetchAll();

// Load materials list for form
$matStmt = $db->prepare('SELECT id, name, unit, purchase_price, total_quantity FROM materials WHERE user_id = ? ORDER BY name');
$matStmt->execute([$userId]);
$allMaterials = $matStmt->fetchAll();

// Edit target?
$editId      = (int)($_GET['edit'] ?? 0);
$editProduct = null;
$editPmRows  = [];
if ($editId > 0) {
    $es = $db->prepare('SELECT * FROM products WHERE id=? AND user_id=?');
    $es->execute([$editId, $userId]);
    $editProduct = $es->fetch() ?: null;
    if ($editProduct) {
        $pmStmt = $db->prepare('SELECT pm.*, m.name AS mat_name, m.unit FROM product_materials pm JOIN materials m ON pm.material_id=m.id WHERE pm.product_id=?');
        $pmStmt->execute([$editId]);
        $editPmRows = $pmStmt->fetchAll();
    }
}

$pageTitle    = '作品管理';
$current      = 'products';
$headerAction = '<button class="btn btn-primary btn-sm" onclick="openModal(\'addModal\')">＋ 作品を追加</button>';

// Precompute material JSON for JS cost calculation
$matsJson = json_encode(array_column($allMaterials, null, 'id'));

include __DIR__ . '/includes/header.php';
?>

<div class="card">
  <div class="card-title">🎨 登録作品一覧</div>
  <?php if (empty($products)): ?>
    <div class="empty-state">
      <div class="empty-icon">🎨</div>
      <p>まだ作品が登録されていません。<br>「＋ 作品を追加」から登録してください。</p>
    </div>
  <?php else: ?>
    <div class="table-wrap">
      <table>
        <thead>
          <tr>
            <th>作品名</th>
            <th class="num">材料費</th>
            <th class="num">制作時間</th>
            <th class="num">人件費</th>
            <th class="num">総原価</th>
            <th class="num">推奨価格 ×2</th>
            <th>操作</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($products as $p):
            $matCost   = getProductCost($db, (int)$p['id']);
            $laborCost = ($p['labor_time'] / 60) * $p['hourly_rate'];
            $totalCost = $matCost + $laborCost;
            $recPrice  = $totalCost * 2;
          ?>
          <tr>
            <td>
              <strong><?= e($p['name']) ?></strong>
              <?php if ($p['notes']): ?>
                <div style="font-size:0.78rem;color:var(--text-sub);"><?= e($p['notes']) ?></div>
              <?php endif; ?>
            </td>
            <td class="num"><?= formatJpy($matCost) ?></td>
            <td class="num"><?= $p['labor_time'] ?>分</td>
            <td class="num"><?= formatJpy($laborCost) ?></td>
            <td class="num"><strong><?= formatJpy($totalCost) ?></strong></td>
            <td class="num" style="color:var(--pink);font-weight:700;"><?= formatJpy($recPrice) ?></td>
            <td>
              <div class="td-actions">
                <a href="?edit=<?= $p['id'] ?>" class="btn btn-secondary btn-sm">編集</a>
                <form method="post" onsubmit="return confirmDelete(this)">
                  <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
                  <input type="hidden" name="action" value="delete">
                  <input type="hidden" name="id" value="<?= $p['id'] ?>">
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
      <span class="modal-title">作品を追加</span>
      <button class="modal-close">✕</button>
    </div>
    <form method="post" id="addForm">
      <div class="modal-body">
        <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
        <input type="hidden" name="action" value="add">

        <div class="form-group">
          <label class="form-label">作品名 <span class="required">*</span></label>
          <input type="text" name="name" class="form-control" required>
        </div>

        <div class="form-row">
          <div class="form-group">
            <label class="form-label">制作時間（分）</label>
            <input type="number" name="labor_time" class="form-control calc-trigger" min="0" step="1" value="0" data-form="add">
          </div>
          <div class="form-group">
            <label class="form-label">時給（円）</label>
            <input type="number" name="hourly_rate" class="form-control calc-trigger" min="0" step="10" value="0" data-form="add">
          </div>
        </div>

        <hr class="divider">
        <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:10px;">
          <strong style="font-size:0.9rem;">使用材料</strong>
          <button type="button" class="btn btn-secondary btn-sm" onclick="addMaterialRow('addMatRows','add')">＋ 材料を追加</button>
        </div>
        <?php if (empty($allMaterials)): ?>
          <p style="color:var(--text-sub);font-size:0.85rem;">まず材料管理で材料を登録してください。</p>
        <?php endif; ?>
        <div id="addMatRows"></div>

        <div class="cost-summary" id="addCostSummary" style="display:none;">
          <table>
            <tbody>
              <tr><td>材料費合計</td><td class="num" id="addMatCost">¥0</td></tr>
              <tr><td>人件費</td><td class="num" id="addLaborCost">¥0</td></tr>
              <tr class="total-row"><td><strong>総原価</strong></td><td class="num" id="addTotalCost"><strong>¥0</strong></td></tr>
              <tr><td>推奨販売価格（×2）</td><td class="num" id="addRecPrice" style="color:var(--pink);font-weight:700;">¥0</td></tr>
            </tbody>
          </table>
        </div>

        <div class="form-group" style="margin-top:16px;">
          <label class="form-label">メモ</label>
          <input type="text" name="notes" class="form-control" placeholder="素材・サイズなど">
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
<?php if ($editProduct): ?>
<div class="modal-backdrop open" id="editModal">
  <div class="modal modal-wide">
    <div class="modal-header">
      <span class="modal-title">作品を編集</span>
      <button class="modal-close" onclick="location.href='<?= APP_URL ?>/products.php'">✕</button>
    </div>
    <form method="post" id="editForm">
      <div class="modal-body">
        <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
        <input type="hidden" name="action" value="edit">
        <input type="hidden" name="id" value="<?= $editProduct['id'] ?>">

        <div class="form-group">
          <label class="form-label">作品名 <span class="required">*</span></label>
          <input type="text" name="name" class="form-control" value="<?= e($editProduct['name']) ?>" required>
        </div>

        <div class="form-row">
          <div class="form-group">
            <label class="form-label">制作時間（分）</label>
            <input type="number" name="labor_time" class="form-control calc-trigger" min="0" step="1" value="<?= (int)$editProduct['labor_time'] ?>" data-form="edit">
          </div>
          <div class="form-group">
            <label class="form-label">時給（円）</label>
            <input type="number" name="hourly_rate" class="form-control calc-trigger" min="0" step="10" value="<?= (int)$editProduct['hourly_rate'] ?>" data-form="edit">
          </div>
        </div>

        <hr class="divider">
        <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:10px;">
          <strong style="font-size:0.9rem;">使用材料</strong>
          <button type="button" class="btn btn-secondary btn-sm" onclick="addMaterialRow('editMatRows','edit')">＋ 材料を追加</button>
        </div>
        <div id="editMatRows">
          <?php foreach ($editPmRows as $pmRow): ?>
          <div class="material-row">
            <select name="mat_id[]" class="form-control mat-select" onchange="recalcCost('edit')">
              <?php foreach ($allMaterials as $m): ?>
                <option value="<?= $m['id'] ?>" <?= $m['id'] == $pmRow['material_id'] ? 'selected' : '' ?>><?= e($m['name']) ?> (<?= e($m['unit']) ?>)</option>
              <?php endforeach; ?>
            </select>
            <input type="number" name="mat_qty[]" class="form-control mat-qty" value="<?= $pmRow['quantity_used'] ?>" min="0" step="0.001" style="width:100px;" onchange="recalcCost('edit')" placeholder="使用量">
            <button type="button" class="remove-row-btn" onclick="this.closest('.material-row').remove();recalcCost('edit')">✕</button>
          </div>
          <?php endforeach; ?>
        </div>

        <div class="cost-summary" id="editCostSummary">
          <table>
            <tbody>
              <tr><td>材料費合計</td><td class="num" id="editMatCost">¥0</td></tr>
              <tr><td>人件費</td><td class="num" id="editLaborCost">¥0</td></tr>
              <tr class="total-row"><td><strong>総原価</strong></td><td class="num" id="editTotalCost"><strong>¥0</strong></td></tr>
              <tr><td>推奨販売価格（×2）</td><td class="num" id="editRecPrice" style="color:var(--pink);font-weight:700;">¥0</td></tr>
            </tbody>
          </table>
        </div>

        <div class="form-group" style="margin-top:16px;">
          <label class="form-label">メモ</label>
          <input type="text" name="notes" class="form-control" value="<?= e($editProduct['notes'] ?? '') ?>">
        </div>
      </div>
      <div class="modal-footer">
        <a href="<?= APP_URL ?>/products.php" class="btn btn-secondary">キャンセル</a>
        <button type="submit" class="btn btn-primary">更新する</button>
      </div>
    </form>
  </div>
</div>
<?php endif; ?>

<?php
$matsJsonEscaped = json_encode($allMaterials);
$pageScript = <<<JSCODE
const MATS = {};
{$matsJsonEscaped}.forEach(m => { MATS[m.id] = m; });

function matOptionsHtml(selectedId) {
  return Object.values(MATS).map(m =>
    `<option value="\${m.id}" \${m.id == selectedId ? 'selected' : ''}>\${escHtml(m.name)} (\${escHtml(m.unit)})</option>`
  ).join('');
}

function addMaterialRow(containerId, formKey) {
  const container = document.getElementById(containerId);
  const row = document.createElement('div');
  row.className = 'material-row';
  row.innerHTML = `
    <select name="mat_id[]" class="form-control mat-select" onchange="recalcCost('\${formKey}')">
      \${matOptionsHtml(null)}
    </select>
    <input type="number" name="mat_qty[]" class="form-control mat-qty" min="0" step="0.001" style="width:100px;" onchange="recalcCost('\${formKey}')" placeholder="使用量" value="0">
    <button type="button" class="remove-row-btn" onclick="this.closest('.material-row').remove();recalcCost('\${formKey}')">✕</button>`;
  container.appendChild(row);
  recalcCost(formKey);
}

function recalcCost(formKey) {
  const rows = document.querySelectorAll(`#\${formKey}MatRows .material-row`);
  let matCost = 0;
  rows.forEach(row => {
    const matId = row.querySelector('.mat-select')?.value;
    const qty   = parseFloat(row.querySelector('.mat-qty')?.value) || 0;
    const mat   = MATS[matId];
    if (mat && mat.total_quantity > 0) {
      matCost += (mat.purchase_price / mat.total_quantity) * qty;
    }
  });

  const form = document.getElementById(formKey + 'Form') || document.querySelector(`[data-form="\${formKey}"]`)?.closest('form');
  const ltInput = form?.querySelector('[name="labor_time"]');
  const hrInput = form?.querySelector('[name="hourly_rate"]');
  const laborCost  = ((parseFloat(ltInput?.value) || 0) / 60) * (parseFloat(hrInput?.value) || 0);
  const totalCost  = matCost + laborCost;
  const recPrice   = totalCost * 2;

  const summary = document.getElementById(formKey + 'CostSummary');
  if (summary) {
    summary.style.display = 'block';
    document.getElementById(formKey + 'MatCost').textContent   = fmtJpy(matCost);
    document.getElementById(formKey + 'LaborCost').textContent = fmtJpy(laborCost);
    document.getElementById(formKey + 'TotalCost').innerHTML   = '<strong>' + fmtJpy(totalCost) + '</strong>';
    document.getElementById(formKey + 'RecPrice').textContent  = fmtJpy(recPrice);
  }
}

document.querySelectorAll('.calc-trigger').forEach(el => {
  el.addEventListener('input', () => recalcCost(el.dataset.form));
});

// Init edit form cost if any rows
recalcCost('edit');
JSCODE;

include __DIR__ . '/includes/footer.php';
?>
