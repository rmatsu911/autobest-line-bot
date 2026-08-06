<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config/config.php';
require_once __DIR__ . '/_layout.php';

use App\Auth;
use App\Logger;
use App\PurchaseRepository;

Auth::requireLogin();

function purchase_validate(array $input): array
{
    $errors = [];
    if (trim((string) ($input['maker'] ?? '')) === '') {
        $errors[] = 'メーカーを入力してください。';
    }
    if (trim((string) ($input['model_name'] ?? '')) === '') {
        $errors[] = '車種名を入力してください。';
    }
    foreach (['model_year' => '年式', 'mileage_km' => '走行距離', 'purchase_price' => '買取金額'] as $key => $label) {
        $value = (string) ($input[$key] ?? '');
        if ($value !== '' && !preg_match('/\A[0-9]+\z/', $value)) {
            $errors[] = $label . 'はカンマなしの半角数字で入力してください。';
        }
    }
    $date = trim((string) ($input['purchased_on'] ?? ''));
    if ($date !== '') {
        $dt = DateTime::createFromFormat('!Y-m-d', $date);
        if (!$dt || $dt->format('Y-m-d') !== $date) {
            $errors[] = '買取日は正しい日付で入力してください。';
        }
    }
    return $errors;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    Auth::requireValidCsrf();
    $action = (string) ($_POST['action'] ?? '');
    $id = (int) ($_POST['id'] ?? 0);

    if ($action === 'save') {
        $errors = purchase_validate($_POST);
        if ($errors === []) {
            if ($id > 0 && PurchaseRepository::find($id) !== null) {
                PurchaseRepository::update($id, $_POST);
                Auth::flash('買取実績を保存しました。');
                Logger::info('買取実績を更新しました', ['purchase_id' => $id, 'admin_id' => Auth::id()]);
            } else {
                $id = PurchaseRepository::create($_POST);
                Auth::flash('買取実績を登録しました。');
                Logger::info('買取実績を登録しました', ['purchase_id' => $id, 'admin_id' => Auth::id()]);
            }
            header('Location: purchases.php');
            exit;
        }
    } elseif ($action === 'published' && $id > 0) {
        PurchaseRepository::setPublished($id, (string) ($_POST['published'] ?? '0') === '1');
        Auth::flash('公開状態を変更しました。');
        header('Location: purchases.php');
        exit;
    } elseif ($action === 'delete' && $id > 0) {
        PurchaseRepository::delete($id);
        Auth::flash('買取実績を削除しました。');
        Logger::info('買取実績を削除しました', ['purchase_id' => $id, 'admin_id' => Auth::id()]);
        header('Location: purchases.php');
        exit;
    }
} else {
    $errors = [];
}

$editId = (int) ($_GET['edit'] ?? 0);
$editing = $editId > 0 ? PurchaseRepository::find($editId) : null;
$showForm = isset($_GET['new']) || $editing !== null || (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && ($_POST['action'] ?? '') === 'save');
$posted = (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') ? $_POST : [];

$published = (string) ($_GET['published'] ?? '');
if (!in_array($published, ['', '0', '1'], true)) {
    $published = '';
}
$keyword = trim((string) ($_GET['q'] ?? ''));
$page = max(1, (int) ($_GET['page'] ?? 1));
$perPage = 20;
$result = PurchaseRepository::search($published === '' ? null : $published, $keyword, $page, $perPage);
$counts = PurchaseRepository::countByPublished();
$lastPage = max(1, (int) ceil($result['total'] / $perPage));

admin_header('買取実績', 'purchases');
admin_message(Auth::flash(), $errors);
?>

<h1>買取実績
  <span class="muted" style="font-weight:400">公開 <?= (int) $counts[1] ?> / 非公開 <?= (int) $counts[0] ?></span>
</h1>

<form method="get" class="toolbar">
  <div class="field" style="margin:0">
    <label for="q">キーワード</label>
    <input type="text" id="q" name="q" value="<?= h($keyword) ?>" placeholder="メーカー・車種・地域">
  </div>
  <div class="field" style="margin:0">
    <label for="published">公開状態</label>
    <select id="published" name="published">
      <option value="">すべて</option>
      <option value="1"<?= $published === '1' ? ' selected' : '' ?>>公開</option>
      <option value="0"<?= $published === '0' ? ' selected' : '' ?>>非公開</option>
    </select>
  </div>
  <button type="submit" class="btn ghost">絞り込む</button>
  <a class="btn" href="purchases.php?new=1">＋ 新規登録</a>
</form>

<?php if ($showForm): ?>
  <?php $record = $editing ?? []; ?>
  <form method="post" class="card">
    <?= Auth::csrfField() ?>
    <input type="hidden" name="action" value="save">
    <input type="hidden" name="id" value="<?= (int) ($record['id'] ?? ($_POST['id'] ?? 0)) ?>">
    <div class="grid">
      <div class="field">
        <label for="maker">メーカー <span style="color:#c0392b">*</span></label>
        <input type="text" id="maker" name="maker" required value="<?= h(old($posted, $record, 'maker')) ?>">
      </div>
      <div class="field">
        <label for="model_name">車種名 <span style="color:#c0392b">*</span></label>
        <input type="text" id="model_name" name="model_name" required value="<?= h(old($posted, $record, 'model_name')) ?>">
      </div>
      <div class="field">
        <label for="model_year">年式</label>
        <input type="number" id="model_year" name="model_year" value="<?= h(old($posted, $record, 'model_year')) ?>" min="1950" max="<?= (int) date('Y') + 1 ?>">
      </div>
      <div class="field">
        <label for="mileage_km">走行距離（km）</label>
        <input type="number" id="mileage_km" name="mileage_km" value="<?= h(old($posted, $record, 'mileage_km')) ?>" min="0">
      </div>
      <div class="field">
        <label for="purchase_price">買取金額（円）</label>
        <input type="number" id="purchase_price" name="purchase_price" value="<?= h(old($posted, $record, 'purchase_price')) ?>" min="0">
      </div>
      <div class="field">
        <label for="area">地域</label>
        <input type="text" id="area" name="area" value="<?= h(old($posted, $record, 'area')) ?>" placeholder="福岡市">
      </div>
      <div class="field">
        <label for="purchased_on">買取日</label>
        <input type="date" id="purchased_on" name="purchased_on" value="<?= h(old($posted, $record, 'purchased_on')) ?>">
      </div>
      <div class="field">
        <label><input type="checkbox" name="published" value="1"<?= old($posted, $record, 'published', '0') === '1' ? ' checked' : '' ?>> LINEで公開</label>
      </div>
    </div>
    <div class="field">
      <label for="note">備考</label>
      <textarea id="note" name="note" rows="4"><?= h(old($posted, $record, 'note')) ?></textarea>
    </div>
    <button type="submit" class="btn">保存する</button>
    <a class="btn ghost" href="purchases.php">キャンセル</a>
  </form>
<?php endif; ?>

<?php if ($result['rows'] === []): ?>
  <div class="card"><p>買取実績はまだありません。</p></div>
<?php else: ?>
<table>
  <thead>
    <tr><th>車両</th><th>年式 / 走行</th><th>買取金額</th><th>地域</th><th>買取日</th><th>公開</th><th></th></tr>
  </thead>
  <tbody>
  <?php foreach ($result['rows'] as $row): ?>
    <tr>
      <td><strong><?= h((string) $row['maker']) ?> <?= h((string) $row['model_name']) ?></strong></td>
      <td><?= h(model_year($row['model_year'])) ?><br><span class="muted"><?= h(mileage($row['mileage_km'])) ?></span></td>
      <td><?= h(yen($row['purchase_price'])) ?></td>
      <td><?= h((string) ($row['area'] ?? '')) ?></td>
      <td><?= h((string) ($row['purchased_on'] ?? '')) ?></td>
      <td>
        <form method="post" style="display:inline">
          <?= Auth::csrfField() ?>
          <input type="hidden" name="action" value="published">
          <input type="hidden" name="id" value="<?= (int) $row['id'] ?>">
          <select name="published" onchange="this.form.submit()">
            <option value="1"<?= (int) $row['published'] === 1 ? ' selected' : '' ?>>公開</option>
            <option value="0"<?= (int) $row['published'] === 0 ? ' selected' : '' ?>>非公開</option>
          </select>
        </form>
      </td>
      <td style="white-space:nowrap">
        <a class="btn ghost" href="purchases.php?edit=<?= (int) $row['id'] ?>">編集</a>
        <form method="post" style="display:inline" onsubmit="return confirm('この買取実績を削除します。よろしいですか？');">
          <?= Auth::csrfField() ?>
          <input type="hidden" name="action" value="delete">
          <input type="hidden" name="id" value="<?= (int) $row['id'] ?>">
          <button type="submit" class="btn danger">削除</button>
        </form>
      </td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
<?php if ($lastPage > 1): ?>
  <div class="pager">
    <?php for ($p = 1; $p <= $lastPage; $p++): ?>
      <?= $p === $page ? '<span class="current">' . $p . '</span>' : '<a href="?' . h(http_build_query(['q' => $keyword, 'published' => $published, 'page' => $p])) . '">' . $p . '</a>' ?>
    <?php endfor; ?>
  </div>
<?php endif; ?>
<?php endif; ?>

<?php admin_footer(); ?>
