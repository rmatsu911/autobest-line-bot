<?php
/**
 * 無料査定の申込フォーム（UI設計7枚目）。
 *
 * LIFFではなく素のWebページにしている。理由は2つ。
 *   - LINEログインチャネルがまだ無くても今日から受け付けられる
 *   - LINE以外（店頭のQRコード、Webサイト、電話案内）からも同じURLで使える
 * LINEと紐づける口だけ LiffBridge に用意してあり、
 * .env に LINE_LOGIN_CHANNEL_ID を入れた時点で自動的に効き始める。
 *
 * 送信後は必ずリダイレクトする（POST→リダイレクト→GET）。
 * 完了画面をPOSTのまま出すと、再読み込みで二重に申し込まれてしまう。
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/config/config.php';
require_once __DIR__ . '/_form_layout.php';

use App\AssessmentImage;
use App\AssessmentValidator;
use App\AuditLog;
use App\CarValidator;
use App\Config;
use App\ContactRules;
use App\InquiryRepository;
use App\LiffBridge;
use App\Logger;
use App\PublicForm;

// セッションはHTMLを1文字でも出す前に開始する。
// PublicForm::fields() の中で開始すると、その時点ではもうヘッダを送り終えているため
// Set-Cookie が飛ばず、送信時にトークンが照合できなくなる（毎回「確認できませんでした」になる）。
PublicForm::startSession();

const ACTION = 'inquiry.create.web';

$errors = [];
$top    = null;
$values = [];

// --- 完了画面 ---------------------------------------------------------------
if (($_GET['done'] ?? '') === '1') {
    form_header('お申し込みを受け付けました', 'AUTOBEST 無料査定');
    ?>
    <div class="card done">
      <div class="mark">✓</div>
      <h2>お申し込みありがとうございます</h2>
      <p>担当者より、ご希望の時間帯に折り返しご連絡いたします。<br>
         お急ぎの場合はお電話でもご相談を承ります。</p>
    </div>
    <?php $tel = Config::get('SHOP_TEL', ''); if ($tel !== ''): ?>
      <a class="submit orange" href="tel:<?= h(preg_replace('/[^0-9+]/', '', $tel)) ?>" style="text-decoration:none">
        電話で相談する（<?= h($tel) ?>）
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

// --- 送信 -------------------------------------------------------------------
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $top = PublicForm::verify($_POST);

    if ($top === null && PublicForm::tooManyAttempts(ACTION)) {
        $top = '短時間に多くの送信がありました。お手数ですが、しばらく経ってからお試しください。';
    }

    $result = AssessmentValidator::validate($_POST);
    $errors = $result['errors'];
    $values = $result['values'];

    if ($top === null && $errors === []) {
        $record = AssessmentValidator::toRecord($values);

        // LINEログインチャネルが設定されていれば、誰の申込かを紐づける。
        // 未設定なら null が返り、連絡先だけの申込として保存される。
        $lineUserRowId = LiffBridge::resolveUserRowId($_POST['liff_access_token'] ?? null);
        if ($lineUserRowId !== null) {
            $record['line_user_id'] = $lineUserRowId;
            $record['source']       = 'liff';
        }

        try {
            $inquiryId = InquiryRepository::create($record);

            // 写真は「入っていれば保存」。1枚失敗しても申込自体は成立させる。
            $photoErrors = storeUploadedPhotos($inquiryId);

            AuditLog::record(
                ACTION,
                'inquiry',
                $inquiryId,
                '査定申込を受け付けました（' . $record['contact_name'] . ' / ' . ($values['maker'] ?? '') . ' ' . ($values['model_name'] ?? '') . '）'
            );
            Logger::info('査定申込を受け付けました', ['inquiry_id' => $inquiryId, 'photos' => $photoErrors === [] ? 'ok' : 'partial']);

            PublicForm::rotateToken();
            header('Location: assessment.php?done=1');
            exit;
        } catch (Throwable $e) {
            Logger::error('査定申込の保存に失敗しました', ['message' => $e->getMessage()]);
            $top = '送信に失敗しました。お手数ですが、時間をおいてもう一度お試しください。';
        }
    } elseif ($top === null) {
        $top = '入力内容をご確認ください。';
    }
}

/**
 * アップロードされた写真を保存する。
 *
 * $_FILES の multiple は各項目が配列になるため、1件ずつに組み直してから渡す。
 * 途中で失敗しても例外を投げずに続けるのは、
 * 「写真1枚のせいで査定申込そのものが消える」のを避けるため。
 *
 * @return array<int,string> 保存できなかった枚数分のメッセージ
 */
function storeUploadedPhotos(int $inquiryId): array
{
    $files = $_FILES['photos'] ?? null;
    if (!is_array($files) || !isset($files['name']) || !is_array($files['name'])) {
        return [];
    }

    $failed   = [];
    $position = 0;

    foreach (array_keys($files['name']) as $i) {
        if ($position >= AssessmentImage::MAX_PER_INQUIRY) {
            break;
        }
        if ((int) ($files['error'][$i] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            continue;
        }

        $one = [
            'name'     => $files['name'][$i]     ?? '',
            'type'     => $files['type'][$i]     ?? '',
            'tmp_name' => $files['tmp_name'][$i] ?? '',
            'error'    => $files['error'][$i]    ?? UPLOAD_ERR_NO_FILE,
            'size'     => $files['size'][$i]     ?? 0,
        ];

        try {
            AssessmentImage::store($one, $inquiryId, $position);
            $position++;
        } catch (Throwable $e) {
            $failed[] = $e->getMessage();
            Logger::warning('査定写真を保存できませんでした', ['inquiry_id' => $inquiryId, 'message' => $e->getMessage()]);
        }
    }

    return $failed;
}

form_header('無料査定のお申し込み', '入力は1分ほど。しつこい営業は行いません。');
form_errors($errors, $top);
?>

<form method="post" enctype="multipart/form-data" novalidate>
  <?= PublicForm::fields() ?>
  <?php // LIFF未導入の間は空のまま送られる。導入後はこの欄にトークンを入れる。 ?>
  <input type="hidden" name="liff_access_token" value="">

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
      <p class="hint">査定額のご連絡に使います。</p>
      <?php if (isset($errors['contact_tel'])): ?><p class="err"><?= h($errors['contact_tel']) ?></p><?php endif; ?>
    </div>

    <div class="field<?= isset($errors['contact_email']) ? ' bad' : '' ?>">
      <label for="contact_email">メールアドレス<span class="opt">任意</span></label>
      <input type="email" id="contact_email" name="contact_email" autocomplete="email"
             value="<?= form_old($values, 'contact_email') ?>" maxlength="191">
      <?php if (isset($errors['contact_email'])): ?><p class="err"><?= h($errors['contact_email']) ?></p><?php endif; ?>
    </div>

    <div class="field<?= isset($errors['contact_pref']) ? ' bad' : '' ?>">
      <label for="contact_pref">ご連絡しやすい時間帯<span class="opt">任意</span></label>
      <select id="contact_pref" name="contact_pref">
        <option value="">選択しない</option>
        <?php foreach (ContactRules::CONTACT_PREFS as $pref): ?>
          <option value="<?= h($pref) ?>"<?= ($values['contact_pref'] ?? '') === $pref ? ' selected' : '' ?>><?= h($pref) ?></option>
        <?php endforeach; ?>
      </select>
      <?php if (isset($errors['contact_pref'])): ?><p class="err"><?= h($errors['contact_pref']) ?></p><?php endif; ?>
    </div>
  </div>

  <div class="card">
    <h2>お車の情報</h2>

    <div class="field">
      <label>種別<span class="opt">任意</span></label>
      <div class="choice">
        <?php foreach (CarValidator::CATEGORIES as $category): ?>
          <label>
            <input type="radio" name="category" value="<?= h($category) ?>"
                   <?= ($values['category'] ?? '') === $category ? ' checked' : '' ?>>
            <span><?= h(car_category_label($category)) ?></span>
          </label>
        <?php endforeach; ?>
      </div>
    </div>

    <div class="field<?= isset($errors['maker']) ? ' bad' : '' ?>">
      <label for="maker">メーカー<span class="req">必須</span></label>
      <input type="text" id="maker" name="maker" value="<?= form_old($values, 'maker') ?>"
             maxlength="64" placeholder="トヨタ / 日野 / コマツ など">
      <?php if (isset($errors['maker'])): ?><p class="err"><?= h($errors['maker']) ?></p><?php endif; ?>
    </div>

    <div class="field<?= isset($errors['model_name']) ? ' bad' : '' ?>">
      <label for="model_name">車種<span class="req">必須</span></label>
      <input type="text" id="model_name" name="model_name" value="<?= form_old($values, 'model_name') ?>"
             maxlength="128" placeholder="ハイエース / デュトロ など">
      <?php if (isset($errors['model_name'])): ?><p class="err"><?= h($errors['model_name']) ?></p><?php endif; ?>
    </div>

    <div class="field<?= isset($errors['grade']) ? ' bad' : '' ?>">
      <label for="grade">グレード<span class="opt">任意</span></label>
      <input type="text" id="grade" name="grade" value="<?= form_old($values, 'grade') ?>" maxlength="128">
      <?php if (isset($errors['grade'])): ?><p class="err"><?= h($errors['grade']) ?></p><?php endif; ?>
    </div>

    <div class="field<?= isset($errors['model_year']) ? ' bad' : '' ?>">
      <label for="model_year">年式（西暦）<span class="opt">任意</span></label>
      <input type="text" id="model_year" name="model_year" inputmode="numeric"
             value="<?= form_old($values, 'model_year') ?>" maxlength="4" placeholder="2018">
      <?php if (isset($errors['model_year'])): ?><p class="err"><?= h($errors['model_year']) ?></p><?php endif; ?>
    </div>

    <div class="field<?= isset($errors['mileage_km']) ? ' bad' : '' ?>">
      <label for="mileage_km">走行距離（km）<span class="opt">任意</span></label>
      <input type="text" id="mileage_km" name="mileage_km" inputmode="numeric"
             value="<?= form_old($values, 'mileage_km') ?>" maxlength="9" placeholder="82000">
      <p class="hint">おおよそで構いません。重機の場合は稼働時間をご要望欄にご記入ください。</p>
      <?php if (isset($errors['mileage_km'])): ?><p class="err"><?= h($errors['mileage_km']) ?></p><?php endif; ?>
    </div>

    <div class="field<?= isset($errors['inspection_until']) ? ' bad' : '' ?>">
      <label for="inspection_until">車検満了<span class="opt">任意</span></label>
      <input type="month" id="inspection_until" name="inspection_until" value="<?= form_old($values, 'inspection_until') ?>">
      <?php if (isset($errors['inspection_until'])): ?><p class="err"><?= h($errors['inspection_until']) ?></p><?php endif; ?>
    </div>
  </div>

  <div class="card">
    <h2>お車の写真<span class="opt" style="margin-left:auto">任意</span></h2>
    <p class="hint" style="margin-top:0">
      正面・後ろ・内装・メーターの4枚があると、より正確な金額をご提示できます（最大<?= AssessmentImage::MAX_PER_INQUIRY ?>枚）。
    </p>
    <div class="photos">
      <?php for ($i = 0; $i < 4; $i++): ?>
        <input type="file" name="photos[]" accept="image/jpeg,image/png,image/webp">
      <?php endfor; ?>
    </div>
  </div>

  <div class="card">
    <h2>ご要望・気になる点</h2>
    <div class="field<?= isset($errors['message']) ? ' bad' : '' ?>">
      <label for="message" class="visually-hidden" style="position:absolute;left:-9999px">ご要望</label>
      <textarea id="message" name="message" maxlength="2000"
                placeholder="キズやへこみ、修復歴、売却をお考えの時期など"><?= form_old($values, 'message') ?></textarea>
      <?php if (isset($errors['message'])): ?><p class="err"><?= h($errors['message']) ?></p><?php endif; ?>
    </div>
  </div>

  <button type="submit" class="submit orange">この内容で査定を申し込む</button>

  <p class="note-small">
    ご入力いただいた情報は査定のご連絡にのみ使用します。
    <?php $privacy = Config::get('PRIVACY_POLICY_URL', ''); ?>
    <?php if ($privacy !== ''): ?><br><a href="<?= h($privacy) ?>" target="_blank" rel="noopener">個人情報の取り扱いについて</a><?php endif; ?>
  </p>
</form>

<script>
// 二重送信の抑止。通信が遅い環境で連打されると同じ申込が2件入るため。
document.querySelector('form').addEventListener('submit', function (e) {
  var button = e.target.querySelector('button[type=submit]');
  if (button.disabled) { e.preventDefault(); return; }
  button.disabled = true;
  button.textContent = '送信しています…';
  // disabled のままだと送信されないブラウザがあるので、送信直後に戻す
  setTimeout(function () { button.disabled = false; button.textContent = 'この内容で査定を申し込む'; }, 8000);
});
</script>
<?php form_footer(); ?>
