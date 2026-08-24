<?php
/**
 * 問い合わせの詳細と対応。
 *
 * 1件を開いたまま、電話しながら操作することを想定した画面。
 *   左 … 申込内容と写真（お客様が送ってきたもの）
 *   右 … 担当者・状態・対応履歴（こちらが動かすもの）
 *
 * 査定写真はここでしか見られない。実体は公開領域の外にあり、
 * inquiry_image.php（ログイン必須）を通してのみ配信される。
 */

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config/config.php';
require_once __DIR__ . '/_layout.php';

use App\AdminUserRepository;
use App\AssessmentImage;
use App\AuditLog;
use App\Auth;
use App\InquiryRepository;
use App\Logger;

Auth::requireLogin();

function inquiry_kind_label(string $kind): string
{
    return match ($kind) {
        'assessment' => '査定申込',
        'car'        => '在庫問い合わせ',
        'visit'      => '来店相談',
        default      => $kind,
    };
}

function inquiry_status_label(string $status): string
{
    return match ($status) {
        'new'         => '未対応',
        'in_progress' => '対応中',
        'done'        => '完了',
        default       => $status,
    };
}

function note_kind_label(string $kind): string
{
    return match ($kind) {
        'call'   => '電話',
        'reply'  => '返信',
        'status' => '状態変更',
        default  => 'メモ',
    };
}

$id      = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);
$inquiry = $id > 0 ? InquiryRepository::find($id) : null;

if ($inquiry === null) {
    http_response_code(404);
    admin_header('問い合わせ', 'inquiries');
    echo '<div class="card"><p>指定された問い合わせは見つかりませんでした。</p>'
       . '<p><a class="btn ghost" href="inquiries.php">一覧へ戻る</a></p></div>';
    admin_footer();
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    Auth::requireValidCsrf();
    $action = (string) ($_POST['action'] ?? '');

    if ($action === 'assign') {
        $raw     = (string) ($_POST['assigned_admin_id'] ?? '');
        $adminId = $raw === '' ? null : (int) $raw;
        InquiryRepository::assign($id, $adminId);
        AuditLog::record('inquiry.assign', 'inquiry', $id, $adminId === null ? '担当者を外しました' : '担当者を変更しました');
        Auth::flash('担当者を変更しました。');

    } elseif ($action === 'status') {
        $status = (string) ($_POST['status'] ?? 'new');
        try {
            InquiryRepository::updateStatus($id, $status);
            InquiryRepository::addNote($id, '状態を「' . inquiry_status_label($status) . '」に変更しました。', 'status');
            AuditLog::record('inquiry.status', 'inquiry', $id, '状態を ' . $status . ' に変更');
            Auth::flash('状態を変更しました。');
        } catch (\InvalidArgumentException $e) {
            Auth::flash('その状態には変更できません。画面を読み込み直してください。');
        }

    } elseif ($action === 'note') {
        $body = trim((string) ($_POST['body'] ?? ''));
        $kind = (string) ($_POST['kind'] ?? 'note');
        if ($body === '') {
            Auth::flash('対応内容を入力してください。');
        } else {
            InquiryRepository::addNote($id, mb_substr($body, 0, 4000), $kind);
            AuditLog::record('inquiry.note', 'inquiry', $id, '対応履歴を追加（' . note_kind_label($kind) . '）');
            // 未対応のまま履歴だけ増えるのを防ぐ。最初の記録で自動的に「対応中」へ進める。
            if ((string) $inquiry['status'] === 'new') {
                InquiryRepository::updateStatus($id, 'in_progress');
            }
            Auth::flash('対応履歴を追加しました。');
        }

    } elseif ($action === 'delete_note') {
        InquiryRepository::deleteNote((int) ($_POST['note_id'] ?? 0), $id);
        AuditLog::record('inquiry.note.delete', 'inquiry', $id, '対応履歴を削除しました');
        Auth::flash('対応履歴を削除しました。');

    } elseif ($action === 'delete_image') {
        AssessmentImage::delete((int) ($_POST['image_id'] ?? 0));
        AuditLog::record('inquiry.image.delete', 'inquiry', $id, '査定写真を削除しました');
        Auth::flash('写真を削除しました。');

    } elseif ($action === 'delete') {
        InquiryRepository::delete($id);
        AuditLog::record('inquiry.delete', 'inquiry', $id, '問い合わせを削除しました');
        Logger::info('問い合わせを削除しました', ['inquiry_id' => $id, 'admin_id' => Auth::id()]);
        Auth::flash('問い合わせを削除しました。');
        header('Location: inquiries.php');
        exit;
    }

    header('Location: inquiry.php?id=' . $id);
    exit;
}

$notes   = InquiryRepository::notes($id);
$images  = AssessmentImage::forInquiry($id);
$admins  = AdminUserRepository::active();
$audit   = AuditLog::forTarget('inquiry', $id, 20);
$payload = [];
if (!empty($inquiry['payload'])) {
    $decoded = json_decode((string) $inquiry['payload'], true);
    if (is_array($decoded)) {
        $payload = $decoded;
    }
}

admin_header('問い合わせ #' . $id, 'inquiries');
admin_message(Auth::flash());
?>

<p style="margin:0 0 .6rem"><a href="inquiries.php">&laquo; 問い合わせ一覧</a></p>

<h1>
  <?= h(inquiry_kind_label((string) $inquiry['kind'])) ?> #<?= (int) $inquiry['id'] ?>
  <?= admin_source_badge($inquiry['source'] === null ? null : (string) $inquiry['source']) ?>
  <span class="muted" style="font-weight:400"><?= h((string) $inquiry['created_at']) ?></span>
</h1>

<div style="display:grid;grid-template-columns:minmax(0,1.35fr) minmax(0,1fr);gap:1rem;align-items:start">

  <!-- 左：お客様から届いた内容 -->
  <div>
    <div class="card">
      <h2 style="font-size:1rem;margin:0 0 .8rem">お客様</h2>
      <p style="margin:0"><?= admin_contact_cell($inquiry) ?></p>
      <?php if (!empty($inquiry['contact_pref'])): ?>
        <p class="muted" style="margin:.4rem 0 0">ご希望の連絡時間帯：<?= h((string) $inquiry['contact_pref']) ?></p>
      <?php endif; ?>
    </div>

    <?php if (!empty($inquiry['car_maker']) || !empty($inquiry['car_model_name'])): ?>
      <div class="card">
        <h2 style="font-size:1rem;margin:0 0 .5rem">対象車両</h2>
        <p style="margin:0">
          <?= h(trim((string) $inquiry['car_maker'] . ' ' . (string) $inquiry['car_model_name'])) ?>
          <?php if (!empty($inquiry['car_stock_number'])): ?>
            <span class="muted">（在庫番号 <?= h((string) $inquiry['car_stock_number']) ?>）</span>
          <?php endif; ?>
          <?php if (!empty($inquiry['car_id'])): ?>
            <a href="car_edit.php?id=<?= (int) $inquiry['car_id'] ?>" style="margin-left:.5rem">在庫を編集</a>
          <?php endif; ?>
        </p>
      </div>
    <?php endif; ?>

    <?php if ($payload !== []): ?>
      <div class="card">
        <h2 style="font-size:1rem;margin:0 0 .5rem">お申し込み内容</h2>
        <table>
          <tbody>
          <?php foreach ($payload as $key => $value): ?>
            <?php if (!is_scalar($value) && $value !== null) { continue; } ?>
            <tr><th style="width:9rem"><?= h((string) $key) ?></th><td><?= h((string) $value) ?></td></tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>

    <?php if (!empty($inquiry['message'])): ?>
      <div class="card">
        <h2 style="font-size:1rem;margin:0 0 .5rem">ご要望・気になる点</h2>
        <p style="margin:0;white-space:pre-wrap"><?= h((string) $inquiry['message']) ?></p>
      </div>
    <?php endif; ?>

    <div class="card">
      <h2 style="font-size:1rem;margin:0 0 .5rem">お客様の写真（<?= count($images) ?>枚）</h2>
      <?php if ($images === []): ?>
        <p class="muted" style="margin:0">写真は添付されていません。</p>
      <?php else: ?>
        <p class="muted" style="margin:0 0 .6rem">
          この写真は公開領域には置いていません。ログイン中のみ表示されます。
        </p>
        <div class="grid" style="grid-template-columns:repeat(auto-fill,minmax(160px,1fr));gap:.7rem">
          <?php foreach ($images as $image): ?>
            <div class="imgcard">
              <?php // クリックで原寸。査定はキズの確認に大きい画像が要る。 ?>
              <a href="inquiry_image.php?id=<?= (int) $image['id'] ?>" target="_blank" rel="noopener">
                <img src="inquiry_image.php?id=<?= (int) $image['id'] ?>" alt="お客様の写真">
              </a>
              <form method="post" onsubmit="return confirm('この写真を削除します。元に戻せません。よろしいですか？');" style="margin-top:.4rem">
                <?= Auth::csrfField() ?>
                <input type="hidden" name="action" value="delete_image">
                <input type="hidden" name="id" value="<?= (int) $id ?>">
                <input type="hidden" name="image_id" value="<?= (int) $image['id'] ?>">
                <button type="submit" class="btn danger" style="padding:.25rem .6rem;font-size:.8rem">削除</button>
              </form>
            </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </div>
  </div>

  <!-- 右：こちらの対応 -->
  <div>
    <div class="card">
      <h2 style="font-size:1rem;margin:0 0 .8rem">対応の状態</h2>

      <form method="post" class="field">
        <?= Auth::csrfField() ?>
        <input type="hidden" name="action" value="assign">
        <input type="hidden" name="id" value="<?= (int) $id ?>">
        <label>担当者</label>
        <div style="display:flex;gap:.4rem">
          <?php admin_assignee_select($admins, $inquiry['assigned_admin_id'] === null ? null : (int) $inquiry['assigned_admin_id']); ?>
          <button type="submit" class="btn ghost">変更</button>
        </div>
      </form>

      <form method="post" class="field">
        <?= Auth::csrfField() ?>
        <input type="hidden" name="action" value="status">
        <input type="hidden" name="id" value="<?= (int) $id ?>">
        <label>状態</label>
        <div style="display:flex;gap:.4rem">
          <select name="status">
            <?php foreach (InquiryRepository::STATUSES as $value): ?>
              <option value="<?= h($value) ?>"<?= $inquiry['status'] === $value ? ' selected' : '' ?>><?= h(inquiry_status_label($value)) ?></option>
            <?php endforeach; ?>
          </select>
          <button type="submit" class="btn ghost">変更</button>
        </div>
      </form>
    </div>

    <div class="card">
      <h2 style="font-size:1rem;margin:0 0 .8rem">対応履歴</h2>

      <form method="post">
        <?= Auth::csrfField() ?>
        <input type="hidden" name="action" value="note">
        <input type="hidden" name="id" value="<?= (int) $id ?>">
        <div class="field">
          <label for="kind">種別</label>
          <select id="kind" name="kind">
            <option value="call">電話</option>
            <option value="reply">返信</option>
            <option value="note">メモ</option>
          </select>
        </div>
        <div class="field">
          <label for="body">対応内容</label>
          <textarea id="body" name="body" rows="3" placeholder="例）折り返し電話。査定額45万円を提示、来週来店予定。"></textarea>
        </div>
        <button type="submit" class="btn">履歴を追加</button>
      </form>

      <?php if ($notes === []): ?>
        <p class="muted" style="margin:1rem 0 0">まだ対応履歴はありません。</p>
      <?php else: ?>
        <ul style="list-style:none;padding:0;margin:1rem 0 0">
        <?php foreach ($notes as $note): ?>
          <li style="border-top:1px solid var(--line);padding:.6rem 0">
            <div class="muted" style="display:flex;gap:.5rem;align-items:center;flex-wrap:wrap">
              <span class="pill draft"><?= h(note_kind_label((string) $note['kind'])) ?></span>
              <span><?= h((string) $note['created_at']) ?></span>
              <span><?= h((string) ($note['admin_name'] ?? '担当者不明')) ?></span>
              <form method="post" style="margin-left:auto"
                    onsubmit="return confirm('この履歴を削除しますか？');">
                <?= Auth::csrfField() ?>
                <input type="hidden" name="action" value="delete_note">
                <input type="hidden" name="id" value="<?= (int) $id ?>">
                <input type="hidden" name="note_id" value="<?= (int) $note['id'] ?>">
                <button type="submit" class="btn ghost" style="padding:.15rem .45rem;font-size:.75rem">削除</button>
              </form>
            </div>
            <div style="white-space:pre-wrap;margin-top:.25rem"><?= h((string) $note['body']) ?></div>
          </li>
        <?php endforeach; ?>
        </ul>
      <?php endif; ?>
    </div>

    <?php if ($audit !== []): ?>
      <div class="card">
        <h2 style="font-size:1rem;margin:0 0 .5rem">操作の記録</h2>
        <ul class="muted" style="margin:0;padding-left:1.1rem">
          <?php foreach ($audit as $log): ?>
            <li><?= h(mb_substr((string) $log['created_at'], 0, 16)) ?>
                <?= h((string) ($log['admin_name'] ?? 'お客様の送信')) ?>
                — <?= h((string) ($log['summary'] ?? $log['action'])) ?></li>
          <?php endforeach; ?>
        </ul>
      </div>
    <?php endif; ?>

    <div class="card">
      <form method="post" onsubmit="return confirm('この問い合わせと写真をすべて削除します。元に戻せません。よろしいですか？');">
        <?= Auth::csrfField() ?>
        <input type="hidden" name="action" value="delete">
        <input type="hidden" name="id" value="<?= (int) $id ?>">
        <button type="submit" class="btn danger">この問い合わせを削除する</button>
        <p class="muted" style="margin:.4rem 0 0">対応履歴と写真も一緒に消えます。</p>
      </form>
    </div>
  </div>
</div>

<style>
  /* 狭い画面では1カラムに落とす。店舗ではタブレットで開くこともある。 */
  @media (max-width: 820px) {
    main > div[style*="grid-template-columns"] { grid-template-columns:1fr !important; }
  }
</style>

<?php admin_footer(); ?>
