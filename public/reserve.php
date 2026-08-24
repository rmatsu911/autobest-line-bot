<?php
/**
 * 来店・商談予約フォーム（UI設計8枚目）。
 *
 * 車両詳細ページの「来店・商談予約」から ?car_id=... 付きで開かれる想定。
 * car_id が無くても（相談だけ、車はこれから探す）受け付ける。
 *
 * 送信された時点では必ず仮予約。確定は管理画面で担当者が行う。
 * 自動確定にしないのは、車両の在庫状況と担当者の在席を人が見る必要があるため。
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/config/config.php';
require_once __DIR__ . '/_form_layout.php';

use App\AuditLog;
use App\CarRepository;
use App\Config;
use App\LiffBridge;
use App\Logger;
use App\PublicForm;
use App\ReservationRepository;
use App\ReservationValidator;

// セッションはHTMLを1文字でも出す前に開始する。
// PublicForm::fields() の中で開始すると、その時点ではもうヘッダを送り終えているため
// Set-Cookie が飛ばず、送信時にトークンが照合できなくなる（毎回「確認できませんでした」になる）。
PublicForm::startSession();

const ACTION = 'reservation.create.web';

$errors = [];
$top    = null;
$values = [];

// --- 完了画面 ---------------------------------------------------------------
if (($_GET['done'] ?? '') === '1') {
    form_header('ご予約を受け付けました', 'AUTOBEST 来店・商談予約');
    ?>
    <div class="card done">
      <div class="mark">✓</div>
      <h2>仮予約を受け付けました</h2>
      <p>この時点ではまだ確定ではありません。<br>
         担当者がご希望の日時を確認のうえ、折り返しご連絡いたします。</p>
    </div>
    <?php $tel = Config::get('SHOP_TEL', ''); if ($tel !== ''): ?>
      <a class="submit" href="tel:<?= h(preg_replace('/[^0-9+]/', '', $tel)) ?>" style="text-decoration:none">
        電話で確認する（<?= h($tel) ?>）
      </a>
    <?php endif; ?>
    <?php
    // 「トップへ戻る」は置かない。public/index.php は管理画面への入口であって
    // お客様の行き先ではないため。LINEから開かれた場合だけトークへ戻す。
    $basicId = Config::get('LINE_BASIC_ID', '');
    ?>
    <?php if ($basicId !== ''): ?>
      <a class="backlink" href="https://line.me/R/ti/p/<?= h(rawurlencode($basicId)) ?>">LINEのトークに戻る</a>
    <?php endif; ?>
    <?php
    form_footer();
    exit;
}

// 対象車両。公開中のものだけを引く（下書きや売却済みを予約されても案内できない）。
$carId = (int) ($_POST['car_id'] ?? $_GET['car_id'] ?? 0);
$car   = $carId > 0 ? CarRepository::findPublished($carId) : null;
if ($car === null) {
    $carId = 0;
}

// --- 送信 -------------------------------------------------------------------
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $top = PublicForm::verify($_POST);

    if ($top === null && PublicForm::tooManyAttempts(ACTION)) {
        $top = '短時間に多くの送信がありました。お手数ですが、しばらく経ってからお試しください。';
    }

    $result = ReservationValidator::validate($_POST + ['car_id' => (string) $carId]);
    $errors = $result['errors'];
    $values = $result['values'];

    if ($top === null && $errors === []) {
        $record = ReservationValidator::toRecord($values);

        $lineUserRowId = LiffBridge::resolveUserRowId($_POST['liff_access_token'] ?? null);
        if ($lineUserRowId !== null) {
            $record['line_user_id'] = $lineUserRowId;
            $record['source']       = 'liff';
        }

        try {
            $reservationId = ReservationRepository::create($record);

            AuditLog::record(
                ACTION,
                'reservation',
                $reservationId,
                '来店予約を受け付けました（' . $record['contact_name'] . ' / '
                    . car_location_label($record['location']) . ' / ' . $record['preferred_1'] . '）'
            );
            Logger::info('来店予約を受け付けました', ['reservation_id' => $reservationId]);

            PublicForm::rotateToken();
            header('Location: reserve.php?done=1');
            exit;
        } catch (Throwable $e) {
            Logger::error('来店予約の保存に失敗しました', ['message' => $e->getMessage()]);
            $top = '送信に失敗しました。お手数ですが、時間をおいてもう一度お試しください。';
        }
    } elseif ($top === null) {
        $top = '入力内容をご確認ください。';
    }
}

// 車両が指定されていれば、その拠点を既定で選んでおく。
if (($values['location'] ?? '') === '' && $car !== null) {
    $values['location'] = (string) $car['location'];
}

form_header('来店・商談のご予約', '第3希望までお選びいただけます。');
form_errors($errors, $top);
?>

<?php if ($car !== null): ?>
  <div class="card" style="display:flex;gap:12px;align-items:center">
    <div style="flex:1;min-width:0">
      <div style="font-size:11.5px;color:#5A5F68">ご覧の車両</div>
      <div style="font-size:14.5px;font-weight:800;line-height:1.45">
        <?= h(trim((string) $car['maker'] . ' ' . (string) $car['model_name'])) ?>
      </div>
      <div style="font-size:13px;color:#C85A0E;font-weight:800"><?= h(car_price_short($car)) ?></div>
    </div>
    <a href="car.php?id=<?= (int) $car['id'] ?>" style="font-size:12.5px;color:#1268C4;white-space:nowrap">車両を見る</a>
  </div>
<?php endif; ?>

<form method="post" novalidate>
  <?= PublicForm::fields() ?>
  <input type="hidden" name="liff_access_token" value="">
  <input type="hidden" name="car_id" value="<?= (int) $carId ?>">

  <div class="card">
    <h2>ご来店の目的</h2>
    <div class="field">
      <div class="choice">
        <?php foreach (['visit' => '来店して実車を見る', 'consult' => 'オンライン・電話で相談'] as $value => $label): ?>
          <label>
            <input type="radio" name="purpose" value="<?= h($value) ?>"
                   <?= ($values['purpose'] ?? 'visit') === $value ? ' checked' : '' ?>>
            <span><?= h($label) ?></span>
          </label>
        <?php endforeach; ?>
      </div>
    </div>

    <div class="field<?= isset($errors['location']) ? ' bad' : '' ?>">
      <label>ご希望の拠点<span class="req">必須</span></label>
      <div class="choice">
        <?php foreach (ReservationRepository::LOCATIONS as $location): ?>
          <label>
            <input type="radio" name="location" value="<?= h($location) ?>"
                   <?= ($values['location'] ?? '') === $location ? ' checked' : '' ?>>
            <span><?= h(car_location_label($location)) ?></span>
          </label>
        <?php endforeach; ?>
      </div>
      <?php if (isset($errors['location'])): ?><p class="err"><?= h($errors['location']) ?></p><?php endif; ?>
    </div>
  </div>

  <div class="card">
    <h2>ご希望の日時</h2>
    <?php foreach ([1 => '第1希望', 2 => '第2希望', 3 => '第3希望'] as $n => $label): ?>
      <div class="field<?= isset($errors["preferred_{$n}"]) ? ' bad' : '' ?>">
        <label for="preferred_<?= $n ?>"><?= h($label) ?><?= $n === 1 ? '<span class="req">必須</span>' : '<span class="opt">任意</span>' ?></label>
        <?php // min/max を入れておくと、そもそも選べない日時が候補に出ない ?>
        <input type="datetime-local" id="preferred_<?= $n ?>" name="preferred_<?= $n ?>"
               min="<?= h(App\ContactRules::preferredMin()) ?>"
               max="<?= h(App\ContactRules::preferredMax()) ?>"
               value="<?= h(str_replace(' ', 'T', substr((string) ($_POST["preferred_{$n}"] ?? ''), 0, 16))) ?>">
        <?php if (isset($errors["preferred_{$n}"])): ?><p class="err"><?= h($errors["preferred_{$n}"]) ?></p><?php endif; ?>
      </div>
    <?php endforeach; ?>
    <p class="hint">
      受付時間は9:00〜19:00です<?php $hours = Config::get('SHOP_HOURS', ''); ?><?= $hours !== '' ? '（営業時間 ' . h($hours) . '）' : '' ?>。
      定休日と重なった場合は、折り返しのご連絡で調整いたします。
    </p>
  </div>

  <div class="card">
    <h2>お客様の連絡先</h2>

    <div class="field<?= isset($errors['contact_name']) ? ' bad' : '' ?>">
      <label for="contact_name">お名前<span class="req">必須</span></label>
      <input type="text" id="contact_name" name="contact_name" autocomplete="name"
             value="<?= form_old($values, 'contact_name') ?>" maxlength="64">
      <?php if (isset($errors['contact_name'])): ?><p class="err"><?= h($errors['contact_name']) ?></p><?php endif; ?>
    </div>

    <div class="field<?= isset($errors['contact_tel']) ? ' bad' : '' ?>">
      <label for="contact_tel">電話番号<span class="req">必須</span></label>
      <input type="tel" id="contact_tel" name="contact_tel" autocomplete="tel" inputmode="tel"
             value="<?= form_old($values, 'contact_tel') ?>" maxlength="24" placeholder="09012345678">
      <p class="hint">日時の確定をご連絡します。</p>
      <?php if (isset($errors['contact_tel'])): ?><p class="err"><?= h($errors['contact_tel']) ?></p><?php endif; ?>
    </div>

    <div class="field<?= isset($errors['contact_email']) ? ' bad' : '' ?>">
      <label for="contact_email">メールアドレス<span class="opt">任意</span></label>
      <input type="email" id="contact_email" name="contact_email" autocomplete="email"
             value="<?= form_old($values, 'contact_email') ?>" maxlength="191">
      <?php if (isset($errors['contact_email'])): ?><p class="err"><?= h($errors['contact_email']) ?></p><?php endif; ?>
    </div>
  </div>

  <div class="card">
    <h2>ご要望</h2>
    <div class="field<?= isset($errors['note']) ? ' bad' : '' ?>">
      <label for="note" style="position:absolute;left:-9999px">ご要望</label>
      <textarea id="note" name="note" maxlength="2000"
                placeholder="下取り車の相談、ローンの相談、同乗される人数など"><?= form_old($values, 'note') ?></textarea>
      <?php if (isset($errors['note'])): ?><p class="err"><?= h($errors['note']) ?></p><?php endif; ?>
    </div>
  </div>

  <button type="submit" class="submit">この内容で予約する（仮）</button>

  <p class="note-small">
    送信後、担当者が日時を確認してご連絡します。この時点では確定ではありません。
  </p>
</form>

<script>
document.querySelector('form').addEventListener('submit', function (e) {
  var button = e.target.querySelector('button[type=submit]');
  if (button.disabled) { e.preventDefault(); return; }
  button.disabled = true;
  button.textContent = '送信しています…';
  setTimeout(function () { button.disabled = false; button.textContent = 'この内容で予約する（仮）'; }, 8000);
});
</script>
<?php form_footer(); ?>
