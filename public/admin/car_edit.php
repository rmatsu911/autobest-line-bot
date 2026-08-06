<?php
/**
 * 販売在庫の新規登録・編集。
 *
 * 1画面で「基本情報」と「写真」の両方を扱う。写真は登録済みの車両にしか
 * 紐付けられないので、新規登録時は保存後に写真欄が現れる作りにしている。
 */

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config/config.php';
require_once __DIR__ . '/_layout.php';

use App\Auth;
use App\CarRepository;
use App\CarValidator;
use App\ImageUploader;
use App\Logger;

Auth::requireLogin();

$carId  = (int) ($_GET['id'] ?? 0);
$car    = $carId > 0 ? CarRepository::find($carId) : null;
$isNew  = $car === null;

if ($carId > 0 && $car === null) {
    Auth::flash('指定された車両が見つかりませんでした。');
    header('Location: cars.php');
    exit;
}

$errors = [];
$posted = [];
$notice = null;

// -----------------------------------------------------------------------------
// 保存（POST）
// -----------------------------------------------------------------------------
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    Auth::requireValidCsrf();
    $action = (string) ($_POST['action'] ?? 'save');

    // --- 基本情報の保存 ---
    if ($action === 'save') {
        $posted = $_POST;
        $errors = CarValidator::validate($_POST);

        if ($errors === []) {
            if ($isNew) {
                $carId = CarRepository::create($_POST);
                Logger::info('在庫を登録しました', ['car_id' => $carId, 'admin_id' => Auth::id()]);
                Auth::flash('登録しました。続けて写真を追加してください。');
            } else {
                CarRepository::update($carId, $_POST);
                Logger::info('在庫を更新しました', ['car_id' => $carId, 'admin_id' => Auth::id()]);
                Auth::flash('保存しました。');
            }
            // PRG。再読み込みで二重登録されないようリダイレクトする。
            header('Location: car_edit.php?id=' . $carId);
            exit;
        }
    }

    // --- 写真の追加 ---
    if ($action === 'upload' && $carId > 0) {
        $stored = 0;
        $already = count(CarRepository::images($carId));

        // $_FILES['images'] は「キーごとに配列」という扱いにくい形で届くので
        // 1ファイルずつの配列に組み直す。
        $files = $_FILES['images'] ?? null;
        if (is_array($files) && is_array($files['name'])) {
            foreach (array_keys($files['name']) as $index) {
                if ((int) $files['error'][$index] === UPLOAD_ERR_NO_FILE) {
                    continue;
                }
                if ($already + $stored >= ImageUploader::MAX_PER_CAR) {
                    $errors['images'] = '写真は1台あたり' . ImageUploader::MAX_PER_CAR . '枚までです。';
                    break;
                }
                try {
                    $url = ImageUploader::store([
                        'name'     => $files['name'][$index],
                        'type'     => $files['type'][$index],
                        'tmp_name' => $files['tmp_name'][$index],
                        'error'    => $files['error'][$index],
                        'size'     => $files['size'][$index],
                    ], $carId);
                    CarRepository::addImage($carId, $url);
                    $stored++;
                } catch (\RuntimeException $e) {
                    // 1枚失敗しても残りは処理する。どの枚数で失敗したかを伝える。
                    $errors['images_' . $index] = ($index + 1) . '枚目: ' . $e->getMessage();
                }
            }
        }

        if ($stored > 0) {
            Auth::flash($stored . '枚の写真を追加しました。');
            Logger::info('車両画像を追加しました', ['car_id' => $carId, 'count' => $stored, 'admin_id' => Auth::id()]);
        }
        if ($errors === []) {
            header('Location: car_edit.php?id=' . $carId);
            exit;
        }
        $car = CarRepository::find($carId);
    }

    // --- 写真の並べ替え ---
    if ($action === 'reorder' && $carId > 0) {
        $positions = [];
        foreach ((array) ($_POST['position'] ?? []) as $imageId => $value) {
            $positions[(int) $imageId] = (int) $value;
        }
        CarRepository::reorderImages($carId, $positions);
        Auth::flash('写真の並び順を更新しました。先頭の写真がLINEでの代表画像になります。');
        header('Location: car_edit.php?id=' . $carId);
        exit;
    }

    // --- 写真の削除 ---
    if ($action === 'delete_image' && $carId > 0) {
        $imageId = (int) ($_POST['image_id'] ?? 0);
        // car_id を条件に含めて取得する。画像IDだけで消せると
        // 他の車両の写真を消すリクエストを作れてしまう。
        $image = CarRepository::findImage($imageId, $carId);
        if ($image !== null) {
            ImageUploader::deleteByUrl((string) $image['image_url'], $carId);
            CarRepository::deleteImage($imageId, $carId);
            // 並びに穴が空くので 0 から詰め直す。
            CarRepository::reorderImages($carId, []);
            Auth::flash('写真を削除しました。');
            Logger::info('車両画像を削除しました', ['car_id' => $carId, 'image_id' => $imageId, 'admin_id' => Auth::id()]);
        }
        header('Location: car_edit.php?id=' . $carId);
        exit;
    }
}

$car    ??= [];
$images = $carId > 0 ? CarRepository::images($carId) : [];

admin_header($isNew ? '在庫の新規登録' : '在庫の編集', 'cars');
admin_message(Auth::flash(), $errors);
?>

<h1><?= $isNew ? '在庫の新規登録' : '在庫の編集' ?>
  <?php if (!$isNew): ?>
    <span class="pill <?= h((string) $car['status']) ?>"><?= h(car_status_label((string) $car['status'])) ?></span>
  <?php endif; ?>
</h1>

<form method="post" class="card">
  <?= Auth::csrfField() ?>
  <input type="hidden" name="action" value="save">

  <div class="grid">
    <div class="field">
      <label for="maker">メーカー <span style="color:#c0392b">*</span></label>
      <input type="text" id="maker" name="maker" required value="<?= h(old($posted, $car, 'maker')) ?>" placeholder="トヨタ">
      <?php if (isset($errors['maker'])): ?><div class="err"><?= h($errors['maker']) ?></div><?php endif; ?>
    </div>

    <div class="field">
      <label for="model_name">車種名 <span style="color:#c0392b">*</span></label>
      <input type="text" id="model_name" name="model_name" required value="<?= h(old($posted, $car, 'model_name')) ?>" placeholder="アクア">
      <?php if (isset($errors['model_name'])): ?><div class="err"><?= h($errors['model_name']) ?></div><?php endif; ?>
    </div>

    <div class="field">
      <label for="grade">グレード</label>
      <input type="text" id="grade" name="grade" value="<?= h(old($posted, $car, 'grade')) ?>" placeholder="S スタイルブラック">
      <?php if (isset($errors['grade'])): ?><div class="err"><?= h($errors['grade']) ?></div><?php endif; ?>
    </div>

    <div class="field">
      <label for="model_year">年式（西暦）</label>
      <input type="number" id="model_year" name="model_year" value="<?= h(old($posted, $car, 'model_year')) ?>" placeholder="2019" min="1950" max="<?= (int) date('Y') + 1 ?>">
      <?php if (isset($errors['model_year'])): ?><div class="err"><?= h($errors['model_year']) ?></div><?php endif; ?>
    </div>

    <div class="field">
      <label for="mileage_km">走行距離（km）</label>
      <input type="number" id="mileage_km" name="mileage_km" value="<?= h(old($posted, $car, 'mileage_km')) ?>" placeholder="48000" min="0">
      <div class="hint">カンマなしの半角数字で入力してください。</div>
      <?php if (isset($errors['mileage_km'])): ?><div class="err"><?= h($errors['mileage_km']) ?></div><?php endif; ?>
    </div>

    <div class="field">
      <label for="total_price">支払総額（円）</label>
      <input type="number" id="total_price" name="total_price" value="<?= h(old($posted, $car, 'total_price')) ?>" placeholder="1280000" min="0">
      <div class="hint">LINEのカードにはこの金額が出ます。</div>
      <?php if (isset($errors['total_price'])): ?><div class="err"><?= h($errors['total_price']) ?></div><?php endif; ?>
    </div>

    <div class="field">
      <label for="body_price">車両本体価格（円）</label>
      <input type="number" id="body_price" name="body_price" value="<?= h(old($posted, $car, 'body_price')) ?>" placeholder="1180000" min="0">
      <?php if (isset($errors['body_price'])): ?><div class="err"><?= h($errors['body_price']) ?></div><?php endif; ?>
    </div>

    <div class="field">
      <label for="inspection_until">車検満了日</label>
      <input type="date" id="inspection_until" name="inspection_until" value="<?= h(old($posted, $car, 'inspection_until')) ?>">
      <div class="hint">車検が無い場合は空欄にしてください。</div>
      <?php if (isset($errors['inspection_until'])): ?><div class="err"><?= h($errors['inspection_until']) ?></div><?php endif; ?>
    </div>

    <div class="field">
      <label for="body_color">ボディカラー</label>
      <input type="text" id="body_color" name="body_color" value="<?= h(old($posted, $car, 'body_color')) ?>" placeholder="パールホワイト">
      <?php if (isset($errors['body_color'])): ?><div class="err"><?= h($errors['body_color']) ?></div><?php endif; ?>
    </div>

    <?php foreach ([
        'fuel'         => ['燃料',     CarValidator::FUELS],
        'transmission' => ['ミッション', CarValidator::TRANSMISSIONS],
        'body_type'    => ['ボディタイプ', CarValidator::BODY_TYPES],
    ] as $name => [$label, $options]): ?>
      <div class="field">
        <label for="<?= h($name) ?>"><?= h($label) ?></label>
        <select id="<?= h($name) ?>" name="<?= h($name) ?>">
          <option value="">未設定</option>
          <?php foreach ($options as $option): ?>
            <option value="<?= h($option) ?>"<?= old($posted, $car, $name) === $option ? ' selected' : '' ?>><?= h($option) ?></option>
          <?php endforeach; ?>
        </select>
        <?php if (isset($errors[$name])): ?><div class="err"><?= h($errors[$name]) ?></div><?php endif; ?>
      </div>
    <?php endforeach; ?>

    <div class="field">
      <label for="status">公開状態</label>
      <select id="status" name="status">
        <?php foreach (CarValidator::STATUSES as $value): ?>
          <option value="<?= h($value) ?>"<?= old($posted, $car, 'status', 'draft') === $value ? ' selected' : '' ?>><?= h(car_status_label($value)) ?></option>
        <?php endforeach; ?>
      </select>
      <div class="hint">「公開中」にするとLINEの在庫カルーセルに表示されます。</div>
      <?php if (isset($errors['status'])): ?><div class="err"><?= h($errors['status']) ?></div><?php endif; ?>
    </div>

    <div class="field">
      <label for="sort_order">表示順</label>
      <input type="number" id="sort_order" name="sort_order" value="<?= h(old($posted, $car, 'sort_order', '0')) ?>">
      <div class="hint">小さいほど先に表示されます。</div>
    </div>
  </div>

  <div class="field">
    <label for="note">備考</label>
    <textarea id="note" name="note" rows="4" placeholder="ワンオーナー、禁煙車、記録簿あり など"><?= h(old($posted, $car, 'note')) ?></textarea>
    <?php if (isset($errors['note'])): ?><div class="err"><?= h($errors['note']) ?></div><?php endif; ?>
  </div>

  <button type="submit" class="btn"><?= $isNew ? '登録する' : '保存する' ?></button>
  <a class="btn ghost" href="cars.php">一覧に戻る</a>
</form>

<?php if ($isNew): ?>
  <div class="card">
    <p class="muted">写真は登録が終わってから追加できます。</p>
  </div>
<?php else: ?>

<div class="card">
  <h2 style="font-size:1.05rem;margin-top:0">写真（<?= count($images) ?> / <?= ImageUploader::MAX_PER_CAR ?>枚）</h2>
  <p class="muted">
    並び順が <strong>いちばん小さい写真がLINEでの代表画像</strong>になります。目安は1台につき4枚程度です。
  </p>

  <?php if ($images !== []): ?>
    <form method="post">
      <?= Auth::csrfField() ?>
      <input type="hidden" name="action" value="reorder">
      <div class="grid" style="gap:1rem">
        <?php foreach ($images as $index => $image): ?>
          <div class="imgcard">
            <img src="<?= h((string) $image['image_url']) ?>" alt="">
            <div style="margin:.5rem 0">
              <label class="muted" for="pos<?= (int) $image['id'] ?>">並び順</label>
              <input type="number" id="pos<?= (int) $image['id'] ?>" name="position[<?= (int) $image['id'] ?>]"
                     value="<?= (int) $image['position'] ?>" min="0">
              <?= $index === 0 ? '<div class="muted">代表画像</div>' : '' ?>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
      <button type="submit" class="btn ghost" style="margin-top:.8rem">並び順を保存</button>
    </form>

    <div class="grid" style="gap:1rem;margin-top:1rem">
      <?php foreach ($images as $image): ?>
        <form method="post" onsubmit="return confirm('この写真を削除します。よろしいですか？');">
          <?= Auth::csrfField() ?>
          <input type="hidden" name="action" value="delete_image">
          <input type="hidden" name="image_id" value="<?= (int) $image['id'] ?>">
          <button type="submit" class="btn danger" style="width:100%">
            並び順 <?= (int) $image['position'] ?> の写真を削除
          </button>
        </form>
      <?php endforeach; ?>
    </div>
  <?php else: ?>
    <p>まだ写真がありません。</p>
  <?php endif; ?>

  <?php if (count($images) < ImageUploader::MAX_PER_CAR): ?>
    <form method="post" enctype="multipart/form-data" style="margin-top:1.2rem;border-top:1px solid #d9dee5;padding-top:1rem">
      <?= Auth::csrfField() ?>
      <input type="hidden" name="action" value="upload">
      <div class="field">
        <label for="images">写真を追加（複数選択できます）</label>
        <input type="file" id="images" name="images[]" accept="image/jpeg,image/png,image/webp" multiple required>
        <div class="hint">
          JPEG / PNG / WebP。長辺1600pxを超える写真は自動で縮小して保存します。
        </div>
      </div>
      <button type="submit" class="btn">アップロード</button>
    </form>
  <?php endif; ?>
</div>

<?php endif; ?>

<?php admin_footer(); ?>
