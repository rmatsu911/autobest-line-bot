<?php
/**
 * 来店・商談予約の管理。
 *
 * 既定は「これから来る予約を早い順」。予約は振り返る台帳ではなく、
 * 次に誰が来るかを見るための画面なので、在庫や問い合わせと並び順を変えている。
 *
 * 確定日時を入れると status も自動で「確定」になる（2回目以降は「変更」）。
 * 日時と状態を別々に操作させると、確定日時が入っているのに仮予約のまま、
 * という食い違いが必ず起きるため。
 */

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config/config.php';
require_once __DIR__ . '/_layout.php';

use App\AdminUserRepository;
use App\AuditLog;
use App\Auth;
use App\ContactRules;
use App\Logger;
use App\ReservationRepository;

Auth::requireLogin();

function reservation_status_label(string $status): string
{
    return match ($status) {
        'tentative' => '仮予約',
        'confirmed' => '確定',
        'changed'   => '日時変更',
        'cancelled' => 'キャンセル',
        default     => $status,
    };
}

function reservation_status_class(string $status): string
{
    return match ($status) {
        'confirmed', 'changed' => 'published',
        'cancelled'            => 'sold',
        default                => 'draft',
    };
}

function reservation_purpose_label(?string $purpose): string
{
    return $purpose === 'consult' ? 'オンライン相談' : '来店';
}

/** 「2026-09-01 10:00:00」を「9/1(火) 10:00」にする。一覧で日時を追いやすくするため。 */
function reservation_when(?string $value): string
{
    if ($value === null || $value === '') {
        return '-';
    }
    $at = date_create_immutable($value);
    if ($at === false) {
        return (string) $value;
    }
    $week = ['日', '月', '火', '水', '木', '金', '土'][(int) $at->format('w')];
    return $at->format('n/j') . '(' . $week . ') ' . $at->format('H:i');
}

function current_query(): array
{
    return [
        'scope'    => $_GET['scope'] ?? 'upcoming',
        'status'   => $_GET['status'] ?? '',
        'location' => $_GET['location'] ?? '',
        'assigned' => $_GET['assigned'] ?? '',
        'page'     => $_GET['page'] ?? 1,
    ];
}

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    Auth::requireValidCsrf();

    $action      = (string) ($_POST['action'] ?? '');
    $id          = (int) ($_POST['id'] ?? 0);
    $reservation = $id > 0 ? ReservationRepository::find($id) : null;

    if ($reservation === null) {
        Auth::flash('対象の予約が見つかりませんでした。');
    } elseif ($action === 'confirm') {
        // 候補ボタンから来る場合は preferred_N の値をそのまま使い、
        // 手入力の場合は datetime-local を検証してから使う。
        $raw = (string) ($_POST['confirmed_at'] ?? '');
        if (str_contains($raw, 'T')) {
            [$parsed, $error] = ContactRules::parsePreferred($raw, '確定日時', true);
        } else {
            // DBから来た「Y-m-d H:i:s」形式。過去日でも店の判断で確定できるようにする。
            $at     = date_create_immutable($raw);
            $parsed = $at === false ? null : $at->format('Y-m-d H:i:00');
            $error  = $at === false ? '確定日時の形式をご確認ください。' : null;
        }

        if ($parsed === null) {
            Auth::flash($error ?? '確定日時を確認できませんでした。');
        } else {
            ReservationRepository::confirm($id, $parsed);
            ReservationRepository::appendNote($id, '来店日時を ' . $parsed . ' で確定しました。');
            AuditLog::record('reservation.confirm', 'reservation', $id, '日時を ' . $parsed . ' で確定');
            Auth::flash('予約日時を確定しました。お客様への連絡をお忘れなく。');
            Logger::info('予約を確定しました', ['reservation_id' => $id, 'admin_id' => Auth::id()]);
        }
    } elseif ($action === 'status') {
        $status = (string) ($_POST['status'] ?? 'tentative');
        // 選択肢に無い値が送られてくることがある（古い画面を開いたまま、細工したPOST）。
        // 例外をそのまま外へ出すと500になり、担当者には「壊れた」としか見えないため受け止める。
        try {
            ReservationRepository::updateStatus($id, $status);
            AuditLog::record('reservation.status', 'reservation', $id, '状態を ' . $status . ' に変更');
            Auth::flash('予約の状態を変更しました。');
        } catch (\InvalidArgumentException $e) {
            Auth::flash('その状態には変更できません。画面を読み込み直してください。');
        }
    } elseif ($action === 'assign') {
        $raw     = (string) ($_POST['assigned_admin_id'] ?? '');
        $adminId = $raw === '' ? null : (int) $raw;
        ReservationRepository::assign($id, $adminId);
        AuditLog::record('reservation.assign', 'reservation', $id, $adminId === null ? '担当者を外しました' : '担当者を変更しました');
        Auth::flash('担当者を変更しました。');
    } elseif ($action === 'note') {
        $body = trim((string) ($_POST['body'] ?? ''));
        if ($body !== '') {
            ReservationRepository::appendNote($id, mb_substr($body, 0, 1000));
            AuditLog::record('reservation.note', 'reservation', $id, '対応メモを追加');
            Auth::flash('メモを追加しました。');
        }
    } elseif ($action === 'delete') {
        ReservationRepository::delete($id);
        AuditLog::record('reservation.delete', 'reservation', $id, '予約を削除しました');
        Auth::flash('予約を削除しました。');
    }

    header('Location: reservations.php?' . http_build_query(current_query()));
    exit;
}

$scope = (string) ($_GET['scope'] ?? 'upcoming');
if (!in_array($scope, ['upcoming', 'past', 'all'], true)) {
    $scope = 'upcoming';
}
$filters = [
    'scope'    => $scope,
    'status'   => (string) ($_GET['status'] ?? ''),
    'location' => (string) ($_GET['location'] ?? ''),
    'assigned' => (string) ($_GET['assigned'] ?? ''),
];
$page     = max(1, (int) ($_GET['page'] ?? 1));
$perPage  = 20;
$result   = ReservationRepository::search($filters, $page, $perPage);
$counts   = ReservationRepository::countByStatus();
$soon     = ReservationRepository::pendingSoon();
$admins   = AdminUserRepository::active();
$lastPage = max(1, (int) ceil($result['total'] / $perPage));

admin_header('予約', 'reservations');
admin_message(Auth::flash());
?>

<h1>来店・商談予約
  <span class="muted" style="font-weight:400">
    仮予約 <?= (int) $counts['tentative'] ?> / 確定 <?= (int) ($counts['confirmed'] + $counts['changed']) ?>
    / キャンセル <?= (int) $counts['cancelled'] ?>
  </span>
</h1>

<?php if ($soon > 0): ?>
  <div class="msg err">
    3日以内に第1希望が来る仮予約が <?= (int) $soon ?> 件あります。日時の確定とご連絡をお願いします。
  </div>
<?php endif; ?>

<form method="get" class="toolbar">
  <div class="field" style="margin:0">
    <label for="scope">表示</label>
    <select id="scope" name="scope">
      <?php foreach (['upcoming' => 'これから', 'past' => '過去', 'all' => 'すべて'] as $value => $label): ?>
        <option value="<?= h($value) ?>"<?= $scope === $value ? ' selected' : '' ?>><?= h($label) ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <div class="field" style="margin:0">
    <label for="status">状態</label>
    <select id="status" name="status">
      <option value="">すべて</option>
      <?php foreach (ReservationRepository::STATUSES as $value): ?>
        <option value="<?= h($value) ?>"<?= $filters['status'] === $value ? ' selected' : '' ?>><?= h(reservation_status_label($value)) ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <div class="field" style="margin:0">
    <label for="location">拠点</label>
    <select id="location" name="location">
      <option value="">すべて</option>
      <?php foreach (ReservationRepository::LOCATIONS as $value): ?>
        <option value="<?= h($value) ?>"<?= $filters['location'] === $value ? ' selected' : '' ?>><?= h(car_location_label($value)) ?></option>
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
  <div class="card"><p>該当する予約はありません。</p></div>
<?php else: ?>

<?php foreach ($result['rows'] as $row): ?>
  <div class="card">
    <div style="display:flex;gap:1rem;align-items:flex-start;flex-wrap:wrap">

      <div style="flex:1 1 240px;min-width:0">
        <div style="display:flex;gap:.5rem;align-items:center;flex-wrap:wrap;margin-bottom:.35rem">
          <span class="pill <?= h(reservation_status_class((string) $row['status'])) ?>">
            <?= h(reservation_status_label((string) $row['status'])) ?>
          </span>
          <span class="pill draft"><?= h(reservation_purpose_label($row['purpose'] === null ? null : (string) $row['purpose'])) ?></span>
          <span class="pill draft"><?= h(car_location_label($row['location'] === null ? null : (string) $row['location'])) ?></span>
          <?= admin_source_badge($row['source'] === null ? null : (string) $row['source']) ?>
        </div>

        <p style="margin:0"><?= admin_contact_cell($row) ?></p>

        <?php if (!empty($row['car_maker']) || !empty($row['car_model_name'])): ?>
          <p class="muted" style="margin:.35rem 0 0">
            対象車両：<?= h(trim((string) $row['car_maker'] . ' ' . (string) $row['car_model_name'])) ?>
            <?php if (!empty($row['car_id'])): ?>
              <a href="car_edit.php?id=<?= (int) $row['car_id'] ?>">編集</a>
            <?php endif; ?>
          </p>
        <?php endif; ?>

        <p class="muted" style="margin:.35rem 0 0">受付 <?= h(mb_substr((string) $row['created_at'], 0, 16)) ?></p>
      </div>

      <div style="flex:1 1 220px">
        <p style="margin:0 0 .3rem;font-weight:600;font-size:.88rem">ご希望日時</p>
        <ul style="list-style:none;padding:0;margin:0">
          <?php foreach ([1, 2, 3] as $n): ?>
            <?php $value = $row["preferred_{$n}"] ?? null; ?>
            <?php if (empty($value)) { continue; } ?>
            <li style="display:flex;align-items:center;gap:.4rem;padding:.15rem 0">
              <span class="muted" style="width:2.6rem">第<?= $n ?>希望</span>
              <span><?= h(reservation_when((string) $value)) ?></span>
              <?php if ((string) $row['status'] !== 'cancelled'): ?>
                <form method="post" style="display:inline">
                  <?= Auth::csrfField() ?>
                  <input type="hidden" name="action" value="confirm">
                  <input type="hidden" name="id" value="<?= (int) $row['id'] ?>">
                  <input type="hidden" name="confirmed_at" value="<?= h((string) $value) ?>">
                  <button type="submit" class="btn ghost" style="padding:.15rem .5rem;font-size:.78rem">これで確定</button>
                </form>
              <?php endif; ?>
            </li>
          <?php endforeach; ?>
        </ul>

        <?php if (!empty($row['confirmed_at'])): ?>
          <p style="margin:.5rem 0 0;font-weight:700;color:#1e6b3a">
            確定：<?= h(reservation_when((string) $row['confirmed_at'])) ?>
          </p>
        <?php endif; ?>

        <form method="post" style="margin-top:.5rem;display:flex;gap:.35rem;align-items:center">
          <?= Auth::csrfField() ?>
          <input type="hidden" name="action" value="confirm">
          <input type="hidden" name="id" value="<?= (int) $row['id'] ?>">
          <input type="datetime-local" name="confirmed_at" style="flex:1;min-width:0"
                 value="<?= h(str_replace(' ', 'T', mb_substr((string) ($row['confirmed_at'] ?? $row['preferred_1']), 0, 16))) ?>">
          <button type="submit" class="btn">日時で確定</button>
        </form>
      </div>

      <div style="flex:0 1 210px">
        <form method="post" class="field" style="margin-bottom:.5rem">
          <?= Auth::csrfField() ?>
          <input type="hidden" name="action" value="assign">
          <input type="hidden" name="id" value="<?= (int) $row['id'] ?>">
          <label>担当</label>
          <div style="display:flex;gap:.3rem">
            <?php admin_assignee_select($admins, $row['assigned_admin_id'] === null ? null : (int) $row['assigned_admin_id']); ?>
            <button type="submit" class="btn ghost">変更</button>
          </div>
        </form>

        <form method="post" class="field" style="margin-bottom:.5rem">
          <?= Auth::csrfField() ?>
          <input type="hidden" name="action" value="status">
          <input type="hidden" name="id" value="<?= (int) $row['id'] ?>">
          <label>状態</label>
          <div style="display:flex;gap:.3rem">
            <select name="status">
              <?php foreach (ReservationRepository::STATUSES as $value): ?>
                <option value="<?= h($value) ?>"<?= $row['status'] === $value ? ' selected' : '' ?>><?= h(reservation_status_label($value)) ?></option>
              <?php endforeach; ?>
            </select>
            <button type="submit" class="btn ghost">変更</button>
          </div>
        </form>

        <form method="post" onsubmit="return confirm('この予約を削除します。よろしいですか？');">
          <?= Auth::csrfField() ?>
          <input type="hidden" name="action" value="delete">
          <input type="hidden" name="id" value="<?= (int) $row['id'] ?>">
          <button type="submit" class="btn danger" style="padding:.3rem .7rem;font-size:.82rem">削除</button>
        </form>
      </div>
    </div>

    <details style="margin-top:.7rem">
      <summary class="muted" style="cursor:pointer">ご要望・対応メモ</summary>
      <?php if (!empty($row['note'])): ?>
        <p style="white-space:pre-wrap;margin:.5rem 0 0"><?= h((string) $row['note']) ?></p>
      <?php else: ?>
        <p class="muted" style="margin:.5rem 0 0">記載はありません。</p>
      <?php endif; ?>
      <form method="post" style="margin-top:.5rem;display:flex;gap:.4rem">
        <?= Auth::csrfField() ?>
        <input type="hidden" name="action" value="note">
        <input type="hidden" name="id" value="<?= (int) $row['id'] ?>">
        <input type="text" name="body" placeholder="例）電話つながらず。夕方に再度架電。" style="flex:1">
        <button type="submit" class="btn ghost">メモ追加</button>
      </form>
    </details>
  </div>
<?php endforeach; ?>

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
