<?php
/**
 * 問い合わせ一覧。
 *
 * ここでは「誰が担当か」と「状態」だけを素早く変えられるようにし、
 * 対応履歴や写真の確認は詳細画面（inquiry.php）に分けている。
 * 一覧に全部載せると、査定申込の写真が並んで肝心の未対応が見えなくなるため。
 */

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config/config.php';
require_once __DIR__ . '/_layout.php';

use App\AdminUserRepository;
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

/** 絞り込み条件を保ったままリダイレクトするためのクエリ */
function current_query(): array
{
    return [
        'status'   => $_GET['status'] ?? '',
        'kind'     => $_GET['kind'] ?? '',
        'assigned' => $_GET['assigned'] ?? '',
        'page'     => $_GET['page'] ?? 1,
    ];
}

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    Auth::requireValidCsrf();

    $action  = (string) ($_POST['action'] ?? '');
    $id      = (int) ($_POST['id'] ?? 0);
    $inquiry = $id > 0 ? InquiryRepository::find($id) : null;

    if ($inquiry === null) {
        Auth::flash('対象の問い合わせが見つかりませんでした。');
    } elseif ($action === 'status') {
        $status = (string) ($_POST['status'] ?? 'new');
        // 選択肢に無い値が送られてくることがある（古い画面、細工したPOST）。
        // 500にせず、その場で気づける文言に変える。
        try {
            InquiryRepository::updateStatus($id, $status);
            // 状態変更は対応履歴にも残す。誰がいつ「完了」にしたかが後から要る。
            InquiryRepository::addNote($id, '状態を「' . inquiry_status_label($status) . '」に変更しました。', 'status');
            AuditLog::record('inquiry.status', 'inquiry', $id, '状態を ' . $status . ' に変更');
            Auth::flash('問い合わせの状態を変更しました。');
            Logger::info('問い合わせの状態を変更しました', ['inquiry_id' => $id, 'admin_id' => Auth::id()]);
        } catch (\InvalidArgumentException $e) {
            Auth::flash('その状態には変更できません。画面を読み込み直してください。');
        }
    } elseif ($action === 'assign') {
        $raw     = (string) ($_POST['assigned_admin_id'] ?? '');
        $adminId = $raw === '' ? null : (int) $raw;
        InquiryRepository::assign($id, $adminId);
        AuditLog::record('inquiry.assign', 'inquiry', $id, $adminId === null ? '担当者を外しました' : '担当者を変更しました');
        Auth::flash('担当者を変更しました。');
    } elseif ($action === 'delete') {
        InquiryRepository::delete($id);
        AuditLog::record('inquiry.delete', 'inquiry', $id, '問い合わせを削除しました');
        Auth::flash('問い合わせを削除しました。');
        Logger::info('問い合わせを削除しました', ['inquiry_id' => $id, 'admin_id' => Auth::id()]);
    }

    header('Location: inquiries.php?' . http_build_query(current_query()));
    exit;
}

$filters = [
    'status'   => (string) ($_GET['status'] ?? ''),
    'kind'     => (string) ($_GET['kind'] ?? ''),
    'assigned' => (string) ($_GET['assigned'] ?? ''),
];
$page     = max(1, (int) ($_GET['page'] ?? 1));
$perPage  = 20;
$result   = InquiryRepository::search($filters, $page, $perPage);
$counts   = InquiryRepository::countByStatus();
$admins   = AdminUserRepository::active();
$lastPage = max(1, (int) ceil($result['total'] / $perPage));

admin_header('問い合わせ', 'inquiries');
admin_message(Auth::flash());
?>

<h1>問い合わせ
  <span class="muted" style="font-weight:400">
    未対応 <?= (int) $counts['new'] ?> / 対応中 <?= (int) $counts['in_progress'] ?> / 完了 <?= (int) $counts['done'] ?>
  </span>
</h1>

<form method="get" class="toolbar">
  <div class="field" style="margin:0">
    <label for="status">状態</label>
    <select id="status" name="status">
      <option value="">すべて</option>
      <?php foreach (InquiryRepository::STATUSES as $value): ?>
        <option value="<?= h($value) ?>"<?= $filters['status'] === $value ? ' selected' : '' ?>><?= h(inquiry_status_label($value)) ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <div class="field" style="margin:0">
    <label for="kind">種別</label>
    <select id="kind" name="kind">
      <option value="">すべて</option>
      <?php foreach (InquiryRepository::KINDS as $value): ?>
        <option value="<?= h($value) ?>"<?= $filters['kind'] === $value ? ' selected' : '' ?>><?= h(inquiry_kind_label($value)) ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <div class="field" style="margin:0">
    <label for="assigned">担当</label>
    <select id="assigned" name="assigned">
      <option value="">すべて</option>
      <option value="none"<?= $filters['assigned'] === 'none' ? ' selected' : '' ?>>担当者未定</option>
      <?php foreach ($admins as $admin): ?>
        <option value="<?= (int) $admin['id'] ?>"<?= $filters['assigned'] === (string) $admin['id'] ? ' selected' : '' ?>>
          <?= h(AdminUserRepository::label($admin)) ?>
        </option>
      <?php endforeach; ?>
    </select>
  </div>
  <button type="submit" class="btn ghost">絞り込む</button>
</form>

<?php if ($result['rows'] === []): ?>
  <div class="card"><p>該当する問い合わせはありません。</p></div>
<?php else: ?>
<table>
  <thead>
    <tr><th>日時</th><th>種別</th><th>お客様</th><th>対象車両</th><th>内容</th><th>担当</th><th>状態</th><th></th></tr>
  </thead>
  <tbody>
  <?php foreach ($result['rows'] as $row): ?>
    <tr>
      <td style="white-space:nowrap">
        <a href="inquiry.php?id=<?= (int) $row['id'] ?>"><?= h(mb_substr((string) $row['created_at'], 0, 16)) ?></a>
      </td>
      <td style="white-space:nowrap">
        <?= h(inquiry_kind_label((string) $row['kind'])) ?><br>
        <?= admin_source_badge($row['source'] === null ? null : (string) $row['source']) ?>
      </td>
      <td><?= admin_contact_cell($row) ?></td>
      <td>
        <?php if (!empty($row['car_maker']) || !empty($row['car_model_name'])): ?>
          <?= h((string) $row['car_maker']) ?> <?= h((string) $row['car_model_name']) ?>
        <?php else: ?>
          <span class="muted">-</span>
        <?php endif; ?>
      </td>
      <td>
        <?= nl2br(h(mb_substr((string) ($row['message'] ?? ''), 0, 80))) ?>
        <div class="muted">
          <?php if ((int) $row['image_count'] > 0): ?>写真<?= (int) $row['image_count'] ?>枚<?php endif; ?>
          <?php if ((int) $row['note_count'] > 0): ?> / 履歴<?= (int) $row['note_count'] ?>件<?php endif; ?>
        </div>
      </td>
      <td>
        <form method="post" style="display:inline">
          <?= Auth::csrfField() ?>
          <input type="hidden" name="action" value="assign">
          <input type="hidden" name="id" value="<?= (int) $row['id'] ?>">
          <?php admin_assignee_select($admins, $row['assigned_admin_id'] === null ? null : (int) $row['assigned_admin_id']); ?>
          <button type="submit" class="btn ghost" style="padding:.3rem .55rem">変更</button>
        </form>
      </td>
      <td>
        <form method="post" style="display:inline">
          <?= Auth::csrfField() ?>
          <input type="hidden" name="action" value="status">
          <input type="hidden" name="id" value="<?= (int) $row['id'] ?>">
          <select name="status" onchange="this.form.submit()">
            <?php foreach (InquiryRepository::STATUSES as $value): ?>
              <option value="<?= h($value) ?>"<?= $row['status'] === $value ? ' selected' : '' ?>><?= h(inquiry_status_label($value)) ?></option>
            <?php endforeach; ?>
          </select>
        </form>
      </td>
      <td><a class="btn ghost" href="inquiry.php?id=<?= (int) $row['id'] ?>">詳細</a></td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
<?php if ($lastPage > 1): ?>
  <div class="pager">
    <?php for ($p = 1; $p <= $lastPage; $p++): ?>
      <?= $p === $page
        ? '<span class="current">' . $p . '</span>'
        : '<a href="?' . h(http_build_query($filters + ['page' => $p])) . '">' . $p . '</a>' ?>
    <?php endfor; ?>
  </div>
<?php endif; ?>
<?php endif; ?>

<?php admin_footer(); ?>
