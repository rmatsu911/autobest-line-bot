<?php
/**
 * 管理画面の共通レイアウト。
 *
 * ファイル名を _ で始めているのは、直接開かれても意味が無いことを示すため
 * （.htaccess でも拒否している）。
 */

declare(strict_types=1);

// 直接開かれても何もしない。.htaccess でも拒否しているが、
// 設置ミスで .htaccess が効いていない場合の保険として二重にする。
if (!defined('APP_ROOT')) {
    http_response_code(404);
    exit;
}

use App\Auth;

/** @param string $active 現在のメニュー（cars / purchases / inquiries / reservations / audit） */
function admin_header(string $title, string $active = ''): void
{
    // 管理画面は検索エンジンにもキャッシュにも載せない。
    header('X-Robots-Tag: noindex, nofollow');
    header('Cache-Control: no-store, no-cache, must-revalidate');
    header('Content-Type: text/html; charset=UTF-8');

    $menu = [
        'cars'         => ['販売在庫', 'cars.php'],
        'purchases'    => ['買取実績', 'purchases.php'],
        'inquiries'    => ['問い合わせ', 'inquiries.php'],
        'reservations' => ['予約', 'reservations.php'],
        'audit'        => ['操作履歴', 'audit.php'],
    ];
    ?>
<!doctype html>
<html lang="ja">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title><?= h($title) ?> | autobest 管理画面</title>
<style>
  :root { --line:#d9dee5; --bg:#f5f7fa; --accent:#1b6ec2; --danger:#c0392b; --muted:#667085; }
  * { box-sizing:border-box; }
  body { margin:0; font-family:-apple-system,"Hiragino Kaku Gothic ProN","Yu Gothic",Meiryo,sans-serif;
         background:var(--bg); color:#1a1a1a; font-size:15px; line-height:1.6; }
  header.top { background:#22303f; color:#fff; padding:.6rem 1rem; display:flex; align-items:center; gap:1rem; flex-wrap:wrap; }
  header.top .brand { font-weight:700; }
  header.top nav { display:flex; gap:.25rem; flex:1; flex-wrap:wrap; }
  header.top nav a { color:#cfd8e3; text-decoration:none; padding:.35rem .8rem; border-radius:4px; }
  header.top nav a:hover { background:rgba(255,255,255,.1); }
  header.top nav a.active { background:var(--accent); color:#fff; }
  header.top .who { font-size:.85rem; color:#aab7c6; }
  header.top .who a { color:#aab7c6; }
  main { max-width:1100px; margin:1.2rem auto; padding:0 1rem; }
  h1 { font-size:1.35rem; margin:0 0 1rem; }
  .card { background:#fff; border:1px solid var(--line); border-radius:8px; padding:1rem 1.2rem; margin-bottom:1rem; }
  table { width:100%; border-collapse:collapse; background:#fff; }
  th, td { border-bottom:1px solid var(--line); padding:.6rem .5rem; text-align:left; vertical-align:middle; }
  th { background:#eef2f6; font-size:.85rem; color:#44546a; white-space:nowrap; }
  a.btn, button.btn { display:inline-block; border:1px solid var(--accent); background:var(--accent); color:#fff;
        padding:.45rem .9rem; border-radius:5px; text-decoration:none; cursor:pointer; font-size:.9rem; font-family:inherit; }
  a.btn.ghost, button.btn.ghost { background:#fff; color:var(--accent); }
  button.btn.danger { background:var(--danger); border-color:var(--danger); }
  .msg { padding:.7rem 1rem; border-radius:6px; margin-bottom:1rem; }
  .msg.ok { background:#e7f5ec; border:1px solid #a8d5b9; color:#1e6b3a; }
  .msg.err { background:#fdecea; border:1px solid #f0b3ae; color:#a03027; }
  .field { margin-bottom:.9rem; }
  .field label { display:block; font-weight:600; font-size:.88rem; margin-bottom:.25rem; }
  .field input[type=text], .field input[type=number], .field input[type=date],
  .field input[type=password], .field select, .field textarea {
        width:100%; padding:.5rem; border:1px solid var(--line); border-radius:5px; font-size:1rem; font-family:inherit; }
  .field .err { color:var(--danger); font-size:.83rem; margin-top:.2rem; }
  .field .hint { color:var(--muted); font-size:.82rem; margin-top:.2rem; }
  .grid { display:grid; grid-template-columns:repeat(auto-fit,minmax(220px,1fr)); gap:0 1rem; }
  .thumb { width:88px; height:66px; object-fit:cover; border-radius:4px; border:1px solid var(--line); background:#eee; }
  .pill { display:inline-block; padding:.1rem .55rem; border-radius:999px; font-size:.78rem; border:1px solid; }
  .pill.published { background:#e7f5ec; border-color:#a8d5b9; color:#1e6b3a; }
  .pill.draft { background:#f3f4f6; border-color:#d0d5dd; color:#475467; }
  .pill.sold { background:#fdecea; border-color:#f0b3ae; color:#a03027; }
  .muted { color:var(--muted); font-size:.85rem; }
  .imgcard { border:1px solid var(--line); border-radius:6px; padding:.5rem; background:#fafbfc; text-align:center; }
  .imgcard img { width:100%; height:120px; object-fit:cover; border-radius:4px; }
  .imgcard input[type=number] { width:5rem; }
  .toolbar { display:flex; gap:.5rem; align-items:flex-end; flex-wrap:wrap; margin-bottom:1rem; }
  .pager { display:flex; gap:.35rem; margin-top:1rem; flex-wrap:wrap; }
  .pager a, .pager span { padding:.35rem .7rem; border:1px solid var(--line); border-radius:4px;
        background:#fff; text-decoration:none; color:var(--accent); }
  .pager span.current { background:var(--accent); color:#fff; border-color:var(--accent); }
</style>
</head>
<body>
<header class="top">
  <span class="brand">autobest 管理画面</span>
  <nav>
<?php foreach ($menu as $key => [$label, $href]): ?>
    <a href="<?= h($href) ?>"<?= $active === $key ? ' class="active"' : '' ?>><?= h($label) ?></a>
<?php endforeach; ?>
  </nav>
  <?php // ログアウトCSRF対策としてトークンを付ける（logout.php 側で検証する） ?>
  <span class="who"><?= h(Auth::name()) ?> / <a href="logout.php?_token=<?= h(Auth::csrfToken()) ?>">ログアウト</a></span>
</header>
<main>
<?php
}

function admin_footer(): void
{
    ?>
</main>
</body>
</html>
<?php
}

/**
 * 担当者の選択欄。問い合わせと予約で同じ形にするため部品にしている。
 *
 * @param array $admins AdminUserRepository::active() の結果
 */
function admin_assignee_select(array $admins, ?int $current, string $name = 'assigned_admin_id'): void
{
    echo '<select name="' . h($name) . '">';
    echo '<option value="">担当者未定</option>';
    foreach ($admins as $admin) {
        $selected = $current !== null && (int) $admin['id'] === $current ? ' selected' : '';
        echo '<option value="' . (int) $admin['id'] . '"' . $selected . '>'
           . h(App\AdminUserRepository::label($admin)) . '</option>';
    }
    echo '</select>';
}

/** 連絡先の表示。LINE経由と素のフォーム経由で持っている情報が違うので一箇所にまとめる。 */
function admin_contact_cell(array $row): string
{
    $lines = [];

    $name = (string) ($row['contact_name'] ?? '');
    if ($name === '') {
        $name = (string) ($row['display_name'] ?? '');
    }
    $lines[] = '<strong>' . h($name !== '' ? $name : 'お名前未取得') . '</strong>';

    if (!empty($row['contact_tel'])) {
        // 管理画面をスマホで開いてそのまま折り返せるようにリンクにする。
        $tel = preg_replace('/[^0-9+]/', '', (string) $row['contact_tel']);
        $lines[] = '<a href="tel:' . h((string) $tel) . '">' . h((string) $row['contact_tel']) . '</a>';
    }
    if (!empty($row['contact_email'])) {
        $lines[] = '<span class="muted">' . h((string) $row['contact_email']) . '</span>';
    }
    if (!empty($row['line_uid'])) {
        $lines[] = '<span class="muted">LINE: ' . h((string) $row['line_uid']) . '</span>';
    }

    return implode('<br>', $lines);
}

/** 申込経路のバッジ */
function admin_source_badge(?string $source): string
{
    return match ($source) {
        'liff' => '<span class="pill" style="background:#EAF4FE;border-color:#BBD9F7;color:#1268C4">LINE連携</span>',
        'web'  => '<span class="pill" style="background:#FFF4E5;border-color:#FFD9A8;color:#C85A0E">Webフォーム</span>',
        default => '<span class="pill" style="background:#E7F5EC;border-color:#A8D5B9;color:#1E6B3A">LINEトーク</span>',
    };
}

/** 画面上部のメッセージ */
function admin_message(?string $ok, array $errors = []): void
{
    if ($ok !== null && $ok !== '') {
        echo '<div class="msg ok">' . h($ok) . '</div>';
    }
    if ($errors !== []) {
        echo '<div class="msg err">入力内容を確認してください。<ul style="margin:.4rem 0 0 1rem;padding:0">';
        foreach ($errors as $message) {
            echo '<li>' . h($message) . '</li>';
        }
        echo '</ul></div>';
    }
}
