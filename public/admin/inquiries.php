<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config/config.php';
require_once __DIR__ . '/_layout.php';

use App\Auth;
use App\InquiryRepository;
use App\Logger;

Auth::requireLogin();

function inquiry_kind_label(string $kind): string
{
    return match ($kind) {
        'assessment' => '査定申込',
        'car' => '在庫問い合わせ',
        'visit' => '来店予約',
        default => $kind,
    };
}

function inquiry_status_label(string $status): string
{
    return match ($status) {
        'new' => '未対応',
        'in_progress' => '対応中',
        'done' => '完了',
        default => $status,
    };
}

function payload_summary(?string $payload): string
{
    if ($payload === null || $payload === '') {
        return '';
    }
    $json = json_decode($payload, true);
    if (!is_array($json)) {
        return mb_substr($payload, 0, 120);
    }
    $parts = [];
    foreach ($json as $key => $value) {
        if (is_scalar($value) || $value === null) {
            $parts[] = (string) $key . ': ' . (string) $value;
        }
    }
    return mb_substr(implode(' / ', $parts), 0, 160);
}

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    Auth::requireValidCsrf();
    $action = (string) ($_POST['action'] ?? '');
    $id = (int) ($_POST['id'] ?? 0);
    $inquiry = $id > 0 ? InquiryRepository::find($id) : null;

    if ($inquiry === null) {
        Auth::flash('対象の問い合わせが見つかりませんでした。');
    } elseif ($action === 'status') {
        InquiryRepository::updateStatus($id, (string) ($_POST['status'] ?? 'new'));
        Auth::flash('問い合わせの状態を変更しました。');
        Logger::info('問い合わせの状態を変更しました', ['inquiry_id' => $id, 'admin_id' => Auth::id()]);
    } elseif ($action === 'delete') {
        InquiryRepository::delete($id);
        Auth::flash('問い合わせを削除しました。');
        Logger::info('問い合わせを削除しました', ['inquiry_id' => $id, 'admin_id' => Auth::id()]);
    }

    header('Location: inquiries.php?' . http_build_query([
        'status' => $_GET['status'] ?? '',
        'kind' => $_GET['kind'] ?? '',
        'page' => $_GET['page'] ?? 1,
    ]));
    exit;
}

$status = (string) ($_GET['status'] ?? '');
if (!in_array($status, ['', 'new', 'in_progress', 'done'], true)) {
    $status = '';
}
$kind = (string) ($_GET['kind'] ?? '');
if (!in_array($kind, ['', 'assessment', 'car', 'visit'], true)) {
    $kind = '';
}
$page = max(1, (int) ($_GET['page'] ?? 1));
$perPage = 20;
$result = InquiryRepository::search($status === '' ? null : $status, $kind === '' ? null : $kind, $page, $perPage);
$counts = InquiryRepository::countByStatus();
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
      <?php foreach (['new', 'in_progress', 'done'] as $value): ?>
        <option value="<?= h($value) ?>"<?= $status === $value ? ' selected' : '' ?>><?= h(inquiry_status_label($value)) ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <div class="field" style="margin:0">
    <label for="kind">種別</label>
    <select id="kind" name="kind">
      <option value="">すべて</option>
      <?php foreach (['assessment', 'car', 'visit'] as $value): ?>
        <option value="<?= h($value) ?>"<?= $kind === $value ? ' selected' : '' ?>><?= h(inquiry_kind_label($value)) ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <button type="submit" class="btn ghost">絞り込む</button>
</form>

<?php if ($result['rows'] === []): ?>
  <div class="card"><p>問い合わせはまだありません。</p></div>
<?php else: ?>
<table>
  <thead>
    <tr><th>日時</th><th>種別</th><th>ユーザー</th><th>対象車両</th><th>内容</th><th>状態</th><th></th></tr>
  </thead>
  <tbody>
  <?php foreach ($result['rows'] as $row): ?>
    <tr>
      <td><?= h((string) $row['created_at']) ?></td>
      <td><?= h(inquiry_kind_label((string) $row['kind'])) ?></td>
      <td>
        <strong><?= h((string) ($row['display_name'] ?: 'LINEユーザー')) ?></strong>
        <div class="muted"><?= h((string) $row['line_user_id']) ?></div>
      </td>
      <td>
        <?php if (!empty($row['car_maker']) || !empty($row['car_model_name'])): ?>
          <?= h((string) $row['car_maker']) ?> <?= h((string) $row['car_model_name']) ?>
        <?php else: ?>
          <span class="muted">-</span>
        <?php endif; ?>
      </td>
      <td>
        <?= nl2br(h((string) ($row['message'] ?? ''))) ?>
        <?php $summary = payload_summary($row['payload'] === null ? null : (string) $row['payload']); ?>
        <?php if ($summary !== ''): ?><div class="muted"><?= h($summary) ?></div><?php endif; ?>
      </td>
      <td>
        <form method="post" style="display:inline">
          <?= Auth::csrfField() ?>
          <input type="hidden" name="action" value="status">
          <input type="hidden" name="id" value="<?= (int) $row['id'] ?>">
          <select name="status" onchange="this.form.submit()">
            <?php foreach (['new', 'in_progress', 'done'] as $value): ?>
              <option value="<?= h($value) ?>"<?= $row['status'] === $value ? ' selected' : '' ?>><?= h(inquiry_status_label($value)) ?></option>
            <?php endforeach; ?>
          </select>
        </form>
      </td>
      <td>
        <form method="post" onsubmit="return confirm('この問い合わせを削除します。よろしいですか？');">
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
      <?= $p === $page ? '<span class="current">' . $p . '</span>' : '<a href="?' . h(http_build_query(['status' => $status, 'kind' => $kind, 'page' => $p])) . '">' . $p . '</a>' ?>
    <?php endfor; ?>
  </div>
<?php endif; ?>
<?php endif; ?>

<?php admin_footer(); ?>
