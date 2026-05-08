<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

requireLogin();
$user   = currentUser();
$db     = getDb();
$userId = $user['id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    saveUserSettings($db, $userId, [
        'brand_name'          => trim($_POST['brand_name'] ?? ''),
        'default_hourly_rate' => (float)($_POST['default_hourly_rate'] ?? 0),
        'default_markup_rate' => (float)($_POST['default_markup_rate'] ?? 100),
        'default_markup_type' => in_array($_POST['default_markup_type'] ?? '', ['over_cost','multiplier','margin']) ? $_POST['default_markup_type'] : 'over_cost',
        'tax_rate'            => (float)($_POST['tax_rate'] ?? 10),
        'include_tax'         => isset($_POST['include_tax']) ? 1 : 0,
    ]);
    flashSet('success', '設定を保存しました。');
    header('Location: ' . APP_URL . '/settings.php');
    exit;
}

$settings  = getUserSettings($db, $userId);
$pageTitle = '設定';
$current   = 'settings';
include __DIR__ . '/includes/header.php';
?>

<div class="card" style="max-width:600px;">
  <div class="card-title">⚙️ 基本設定</div>
  <form method="post">
    <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">

    <div class="form-group">
      <label class="form-label">ブランド名 / 作家名</label>
      <input type="text" name="brand_name" class="form-control" value="<?= e($settings['brand_name']) ?>" placeholder="例：◯◯ handmade">
      <div class="form-hint">見積書のヘッダーに表示されます</div>
    </div>

    <hr class="divider">
    <div class="card-title" style="font-size:0.95rem;margin-bottom:14px;">💰 利益設定のデフォルト値</div>
    <p style="font-size:0.85rem;color:var(--text-sub);margin-bottom:16px;">
      作品ごとに上書き設定できます。何も設定しない作品はここで設定した値が使われます。
    </p>

    <div class="form-row">
      <div class="form-group">
        <label class="form-label">計算方法</label>
        <select name="default_markup_type" class="form-control" id="markupTypeSelect" onchange="updateMarkupHint()">
          <option value="over_cost"  <?= $settings['default_markup_type']==='over_cost'  ?'selected':'' ?>>上乗せ率（%）</option>
          <option value="multiplier" <?= $settings['default_markup_type']==='multiplier' ?'selected':'' ?>>掛け率（×倍）</option>
          <option value="margin"     <?= $settings['default_markup_type']==='margin'     ?'selected':'' ?>>利益率（%）</option>
        </select>
      </div>
      <div class="form-group">
        <label class="form-label" id="markupRateLabel">利益の値</label>
        <input type="number" name="default_markup_rate" id="markupRateInput" class="form-control"
               value="<?= $settings['default_markup_rate'] ?>" min="0" step="0.1">
        <div class="form-hint" id="markupHint"></div>
      </div>
    </div>

    <div class="cost-summary" id="markupPreview" style="margin-bottom:16px;">
      <table>
        <tbody>
          <tr><td>例：原価 ¥1,000 の場合の販売価格</td><td class="num" id="markupPreviewPrice" style="color:var(--pink);font-weight:700;"></td></tr>
        </tbody>
      </table>
    </div>

    <hr class="divider">
    <div class="card-title" style="font-size:0.95rem;margin-bottom:14px;">⏱️ デフォルト時給</div>

    <div class="form-group" style="max-width:240px;">
      <label class="form-label">時給（円）</label>
      <input type="number" name="default_hourly_rate" class="form-control" value="<?= (int)$settings['default_hourly_rate'] ?>" min="0" step="10">
      <div class="form-hint">作品ごとに時給が未設定の場合にこの値が使われます</div>
    </div>

    <hr class="divider">
    <div class="card-title" style="font-size:0.95rem;margin-bottom:14px;">🧾 消費税</div>

    <div class="form-row">
      <div class="form-group">
        <label class="form-label">消費税率（%）</label>
        <input type="number" name="tax_rate" class="form-control" value="<?= $settings['tax_rate'] ?>" min="0" max="100" step="0.1">
      </div>
      <div class="form-group" style="display:flex;align-items:center;padding-top:28px;">
        <label style="display:flex;align-items:center;gap:10px;cursor:pointer;font-size:0.9rem;font-weight:500;">
          <input type="checkbox" name="include_tax" value="1" <?= $settings['include_tax'] ? 'checked' : '' ?> style="width:18px;height:18px;accent-color:var(--pink);">
          見積書の合計を税込みで表示する
        </label>
      </div>
    </div>

    <div style="margin-top:8px;">
      <button type="submit" class="btn btn-primary">💾 設定を保存</button>
    </div>
  </form>
</div>

<?php
$pageScript = <<<'JS'
const HINTS = {
  over_cost:  { label: '上乗せ率（%）', hint: '原価に対して何%上乗せするか。100%で原価の2倍。' },
  multiplier: { label: '掛け率（×倍）', hint: '原価に掛ける倍率。2.0 = 原価の2倍。' },
  margin:     { label: '利益率（%）',   hint: '販売価格に対する利益の割合。50%で原価の2倍。' },
};

function updateMarkupHint() {
  const type  = document.getElementById('markupTypeSelect').value;
  const rate  = parseFloat(document.getElementById('markupRateInput').value) || 0;
  const h     = HINTS[type];
  document.getElementById('markupRateLabel').textContent = h.label;
  document.getElementById('markupHint').textContent = h.hint;
  updatePreview(rate, type);
}

function updatePreview(rate, type) {
  const cost = 1000;
  let price = 0;
  if (type === 'multiplier') price = cost * rate;
  else if (type === 'margin') price = rate >= 100 ? 0 : cost / (1 - rate / 100);
  else price = cost * (1 + rate / 100);
  document.getElementById('markupPreviewPrice').textContent = '¥' + Math.round(price).toLocaleString();
}

document.getElementById('markupRateInput')?.addEventListener('input', () => {
  const type = document.getElementById('markupTypeSelect').value;
  updatePreview(parseFloat(document.getElementById('markupRateInput').value) || 0, type);
});

updateMarkupHint();
JS;
include __DIR__ . '/includes/footer.php';
?>
