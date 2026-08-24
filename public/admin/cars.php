<?php
/**
 * 販売在庫の一覧。
 *
 * 一覧からできること：絞り込み、ステータス切替、削除、編集画面への遷移。
 * 状態を変える操作はすべて POST + CSRFトークンで受ける
 * （GET で更新できると、画像タグ1つで勝手に操作されてしまう）。
 */

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config/config.php';
require_once __DIR__ . '/_layout.php';

use App\AuditLog;
use App\Auth;
use App\CarRepository;
use App\ImageUploader;
use App\Logger;

Auth::requireLogin();

// -----------------------------------------------------------------------------
// 更新系（POST）
// -----------------------------------------------------------------------------
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    Auth::requireValidCsrf();

    $action = (string) ($_POST['action'] ?? '');
    $carId  = (int) ($_POST['car_id'] ?? 0);
    $car    = $carId > 0 ? CarRepository::find($carId) : null;

    if ($car === null) {
        Auth::flash('対象の車両が見つかりませんでした。');
    } elseif ($action === 'status') {
        CarRepository::updateStatus($carId, (string) ($_POST['status'] ?? 'draft'));
        AuditLog::record('car.status', 'car', $carId,
            $car['maker'] . ' ' . $car['model_name'] . ' の状態を ' . (string) ($_POST['status'] ?? 'draft') . ' に変更');
        Auth::flash('「' . $car['maker'] . ' ' . $car['model_name'] . '」の状態を変更しました。');
        Logger::info('在庫の状態を変更しました', ['car_id' => $carId, 'status' => $_POST['status'] ?? '', 'admin_id' => Auth::id()]);
    } elseif ($action === 'delete') {
        // 画像の実体はDBの外にあるので、レコードを消す前に消す。
        // 順序が逆だと、DBの行が消えた後に実体だけ残って追跡できなくなる。
        ImageUploader::deleteCarDir($carId);
        CarRepository::delete($carId);
        AuditLog::record('car.delete', 'car', $carId, $car['maker'] . ' ' . $car['model_name'] . ' を削除');
        Auth::flash('「' . $car['maker'] . ' ' . $car['model_name'] . '」を削除しました。');
        Logger::info('在庫を削除しました', ['car_id' => $carId, 'admin_id' => Auth::id()]);
    }

    // PRG パターン。POST のまま画面を出すと、再読み込みで同じ操作が繰り返される。
    header('Location: cars.php?' . http_build_query([
        'status' => $_GET['status'] ?? '',
        'q'      => $_GET['q'] ?? '',
        'page'   => $_GET['page'] ?? 1,
    ]));
    exit;
}

// -----------------------------------------------------------------------------
// 表示（GET）
// -----------------------------------------------------------------------------
$status  = (string) ($_GET['status'] ?? '');
$keyword = trim((string) ($_GET['q'] ?? ''));
$page    = max(1, (int) ($_GET['page'] ?? 1));
$perPage = 20;

if ($status !== '' && !in_array($status, ['draft', 'published', 'sold'], true)) {
    $status = '';
}

$result   = CarRepository::search($status === '' ? null : $status, $keyword, $page, $perPage);
$counts   = CarRepository::countByStatus();
$lastPage = max(1, (int) ceil($result['total'] / $perPage));

admin_header('販売在庫', 'cars');
admin_message(Auth::flash());
?>

<h1>販売在庫
  <span class="muted" style="font-weight:400">
    公開中 <?= (int) $counts['published'] ?> / 下書き <?= (int) $counts['draft'] ?> / 成約済み <?= (int) $counts['sold'] ?>
  </span>
</h1>

<form method="get" class="toolbar">
  <div class="field" style="margin:0">
    <label for="q">キーワード</label>
    <input type="text" id="q" name="q" value="<?= h($keyword) ?>" placeholder="メーカー・車種・グレード">
  </div>
  <div class="field" style="margin:0">
    <label for="status">状態</label>
    <select id="status" name="status">
      <option value="">すべて</option>
      <?php foreach (['published', 'draft', 'sold'] as $value): ?>
        <option value="<?= h($value) ?>"<?= $status === $value ? ' selected' : '' ?>><?= h(car_status_label($value)) ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <button type="submit" class="btn ghost">絞り込む</button>
  <a class="btn" href="car_edit.php">＋ 新規登録</a>
</form>

<?php if ($result['rows'] === []): ?>
  <div class="card">
    <p>該当する車両がありません。<?= $keyword !== '' || $status !== '' ? '条件を変えてお試しください。' : '「＋ 新規登録」から追加してください。' ?></p>
  </div>
<?php else: ?>
<table>
  <thead>
    <tr>
      <th>写真</th><th>車両</th><th>年式 / 走行</th><th>支払総額</th><th>車検</th><th>状態</th><th>順</th><th></th>
    </tr>
  </thead>
  <tbody>
  <?php foreach ($result['rows'] as $car): ?>
    <tr>
      <td>
        <?php if (!empty($car['thumb_url'])): ?>
          <img class="thumb" src="<?= h((string) $car['thumb_url']) ?>" alt="">
        <?php else: ?>
          <span class="thumb" style="display:inline-block"></span>
        <?php endif; ?>
        <div class="muted" style="text-align:center"><?= (int) $car['image_count'] ?>枚</div>
      </td>
      <td>
        <a href="car_edit.php?id=<?= (int) $car['id'] ?>"><strong><?= h((string) $car['maker']) ?> <?= h((string) $car['model_name']) ?></strong></a>
        <?php if (!empty($car['grade'])): ?>
          <div class="muted"><?= h((string) $car['grade']) ?></div>
        <?php endif; ?>
      </td>
      <td><?= h(model_year($car['model_year'])) ?><br><span class="muted"><?= h(mileage($car['mileage_km'])) ?></span></td>
      <td><?= h(yen($car['total_price'])) ?></td>
      <td><?= h(inspection($car['inspection_until'] === null ? null : (string) $car['inspection_until'])) ?></td>
      <td>
        <form method="post" style="display:inline">
          <?= Auth::csrfField() ?>
          <input type="hidden" name="action" value="status">
          <input type="hidden" name="car_id" value="<?= (int) $car['id'] ?>">
          <select name="status" onchange="this.form.submit()">
            <?php foreach (['draft', 'published', 'sold'] as $value): ?>
              <option value="<?= h($value) ?>"<?= $car['status'] === $value ? ' selected' : '' ?>><?= h(car_status_label($value)) ?></option>
            <?php endforeach; ?>
          </select>
          <noscript><button type="submit" class="btn ghost">変更</button></noscript>
        </form>
      </td>
      <td class="muted"><?= (int) $car['sort_order'] ?></td>
      <td style="white-space:nowrap">
        <a class="btn ghost" href="car_edit.php?id=<?= (int) $car['id'] ?>">編集</a>
        <form method="post" style="display:inline"
              onsubmit="return confirm('「<?= h((string) $car['maker'] . ' ' . (string) $car['model_name']) ?>」を削除します。写真もすべて削除され、元に戻せません。よろしいですか？');">
          <?= Auth::csrfField() ?>
          <input type="hidden" name="action" value="delete">
          <input type="hidden" name="car_id" value="<?= (int) $car['id'] ?>">
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
      <?php if ($p === $page): ?>
        <span class="current"><?= $p ?></span>
      <?php else: ?>
        <a href="?<?= h(http_build_query(['q' => $keyword, 'status' => $status, 'page' => $p])) ?>"><?= $p ?></a>
      <?php endif; ?>
    <?php endfor; ?>
  </div>
<?php endif; ?>
<?php endif; ?>

<?php admin_footer(); ?>
