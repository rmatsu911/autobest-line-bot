<?php
/**
 * 操作履歴（監査ログ）。
 *
 * 要件書6章の「誰がいつ返信・変更・配信したか」を後から確認するための画面。
 * 複数人で在庫と問い合わせを触ると「この車、誰が公開した？」が必ず起きる。
 *
 * この画面からは記録を消せない。消せる証跡は証跡ではないため、
 * 削除の口をそもそも作っていない（古い記録の整理はSQLで行う）。
 */

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config/config.php';
require_once __DIR__ . '/_layout.php';

use App\AdminUserRepository;
use App\AuditLog;
use App\Auth;

Auth::requireLogin();

/** 対象種別の日本語 */
function audit_target_label(?string $type): string
{
    return match ($type) {
        'car'         => '在庫',
        'inquiry'     => '問い合わせ',
        'reservation' => '予約',
        'purchase'    => '買取実績',
        'admin'       => '管理者',
        default       => (string) $type,
    };
}

/** 対象へのリンク。消えている場合もあるので、あくまで手がかりとして出す。 */
function audit_target_link(?string $type, ?int $id): string
{
    if ($type === null || $id === null || $id <= 0) {
        return '';
    }
    $href = match ($type) {
        'car'         => 'car_edit.php?id=' . $id,
        'inquiry'     => 'inquiry.php?id=' . $id,
        'reservation' => 'reservations.php?scope=all',
        default       => '',
    };
    return $href === '' ? '#' . $id : '<a href="' . h($href) . '">#' . $id . '</a>';
}

$targetType = (string) ($_GET['target_type'] ?? '');
if (!in_array($targetType, ['', 'car', 'inquiry', 'reservation', 'purchase', 'admin'], true)) {
    $targetType = '';
}
$adminId  = (int) ($_GET['admin_id'] ?? 0);
$page     = max(1, (int) ($_GET['page'] ?? 1));
$perPage  = 50;
$result   = AuditLog::search($targetType === '' ? null : $targetType, $adminId > 0 ? $adminId : null, $page, $perPage);
$admins   = AdminUserRepository::active();
$lastPage = max(1, (int) ceil($result['total'] / $perPage));

admin_header('操作履歴', 'audit');
admin_message(Auth::flash());
?>

<h1>操作履歴 <span class="muted" style="font-weight:400"><?= (int) $result['total'] ?>件</span></h1>

<form method="get" class="toolbar">
  <div class="field" style="margin:0">
    <label for="target_type">対象</label>
    <select id="target_type" name="target_type">
      <option value="">すべて</option>
      <?php foreach (['car', 'inquiry', 'reservation', 'purchase', 'admin'] as $value): ?>
        <option value="<?= h($value) ?>"<?= $targetType === $value ? ' selected' : '' ?>><?= h(audit_target_label($value)) ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <div class="field" style="margin:0">
    <label for="admin_id">操作者</label>
    <select id="admin_id" name="admin_id">
      <option value="">すべて</option>
      <?php foreach ($admins as $admin): ?>
        <option value="<?= (int) $admin['id'] ?>"<?= $adminId === (int) $admin['id'] ? ' selected' : '' ?>>
          <?= h(AdminUserRepository::label($admin)) ?>
        </option>
      <?php endforeach; ?>
    </select>
  </div>
  <button type="submit" class="btn ghost">絞り込む</button>
</form>

<?php if ($result['rows'] === []): ?>
  <div class="card"><p>記録はまだありません。</p></div>
<?php else: ?>
<table>
  <thead>
    <tr><th>日時</th><th>操作者</th><th>操作</th><th>対象</th><th>内容</th><th>接続元</th></tr>
  </thead>
  <tbody>
  <?php foreach ($result['rows'] as $row): ?>
    <tr>
      <td style="white-space:nowrap"><?= h((string) $row['created_at']) ?></td>
      <td>
        <?php if (!empty($row['admin_name'])): ?>
          <?= h((string) $row['admin_name']) ?>
        <?php else: ?>
          <?php // 管理者IDが無い記録は、お客様がフォームから送信したもの。 ?>
          <span class="muted">お客様の送信</span>
        <?php endif; ?>
      </td>
      <td><code style="font-size:.85rem"><?= h((string) $row['action']) ?></code></td>
      <td style="white-space:nowrap">
        <?= h(audit_target_label($row['target_type'] === null ? null : (string) $row['target_type'])) ?>
        <?= audit_target_link(
              $row['target_type'] === null ? null : (string) $row['target_type'],
              $row['target_id'] === null ? null : (int) $row['target_id']
            ) ?>
      </td>
      <td><?= h((string) ($row['summary'] ?? '')) ?></td>
      <td class="muted" style="white-space:nowrap"><?= h((string) ($row['ip'] ?? '-')) ?></td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
<?php if ($lastPage > 1): ?>
  <div class="pager">
    <?php for ($p = max(1, $page - 5); $p <= min($lastPage, $page + 5); $p++): ?>
      <?= $p === $page
        ? '<span class="current">' . $p . '</span>'
        : '<a href="?' . h(http_build_query(['target_type' => $targetType, 'admin_id' => $adminId ?: '', 'page' => $p])) . '">' . $p . '</a>' ?>
    <?php endfor; ?>
  </div>
<?php endif; ?>
<?php endif; ?>

<?php admin_footer(); ?>
