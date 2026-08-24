<?php
/**
 * 公開フォーム（査定申込・来店予約）の共通レイアウト。
 *
 * 見た目は確定したUI設計に合わせる。想定する開き方は主に3つ。
 *   1) LINEのリッチメニュー → LINE内ブラウザ
 *   2) 車両詳細ページのボタン
 *   3) 店頭でスマホに直接URLを入力
 * どれもスマホ縦画面なので、1カラム・大きめのタップ領域で組む。
 *
 * 入力欄の文字を16px以上にしているのは、iOS Safari が15px以下の入力欄に
 * フォーカスすると勝手に拡大し、そのあと縮まらないため。
 */

declare(strict_types=1);

if (!defined('APP_ROOT')) {
    http_response_code(404);
    exit;
}

function form_header(string $title, string $lead = ''): void
{
    header('Content-Type: text/html; charset=UTF-8');
    header('Cache-Control: no-store, no-cache, must-revalidate');
    // 個人情報を入力する画面なので検索にも載せない。
    header('X-Robots-Tag: noindex, nofollow');
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    ?>
<!doctype html>
<html lang="ja">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="robots" content="noindex, nofollow">
<title><?= h($title) ?> | AUTOBEST</title>
<style>
  :root {
    --navy:#1F3358; --blue:#1268C4; --blue-lt:#4FA3F0; --orange:#E2600A; --orange-lt:#FF8A2B;
    --teal:#0B8FA3; --amber:#FFB020; --red:#F0525A;
    --ivory:#FDFAF3; --line:#EAE4D6; --muted:#5A5F68; --text:#22262D;
  }
  * { box-sizing:border-box; }
  body { margin:0; background:var(--ivory); color:var(--text);
         font-family:'Hiragino Kaku Gothic ProN','Yu Gothic',Meiryo,sans-serif;
         font-size:15px; line-height:1.7; }
  header { background:var(--navy); color:#fff; padding:14px 16px calc(14px + env(safe-area-inset-top)); }
  header .t { font-size:17px; font-weight:800; }
  header .l { font-size:12.5px; color:#C6D2E6; margin-top:3px; }
  main { padding:14px 14px calc(28px + env(safe-area-inset-bottom)); display:flex; flex-direction:column; gap:12px; }
  .card { background:#fff; border-radius:12px; padding:14px; border:1px solid var(--line); }
  .card > h2 { font-size:13px; font-weight:800; margin:0 0 10px; color:var(--navy);
               display:flex; align-items:center; gap:7px; }
  .card > h2::before { content:''; width:4px; height:15px; border-radius:2px; background:var(--blue-lt); }
  .field { margin-bottom:13px; }
  .field:last-child { margin-bottom:0; }
  .field > label { display:block; font-size:13px; font-weight:700; margin-bottom:5px; }
  .req, .opt { font-size:10.5px; border-radius:3px; padding:1px 5px; margin-left:6px; vertical-align:1px; font-weight:700; }
  .req { background:#FDE7E8; color:#C0392B; }
  .opt { background:#EFECE4; color:#6C7078; }
  input[type=text], input[type=tel], input[type=email], input[type=number],
  input[type=month], input[type=datetime-local], select, textarea {
      width:100%; padding:11px 12px; border:1.5px solid #D6D0C4; border-radius:9px;
      /* 16px未満にするとiOSが勝手に拡大するので下げない */
      font-size:16px; font-family:inherit; background:#fff; color:var(--text); }
  input:focus, select:focus, textarea:focus { outline:none; border-color:var(--blue-lt);
      box-shadow:0 0 0 3px rgba(79,163,240,.22); }
  textarea { min-height:96px; resize:vertical; line-height:1.7; }
  .hint { font-size:12px; color:var(--muted); margin-top:5px; }
  .err { font-size:12.5px; color:#C0392B; margin-top:5px; font-weight:700; }
  .field.bad input, .field.bad select, .field.bad textarea { border-color:#E8908F; background:#FFFBFB; }
  .choice { display:flex; gap:8px; flex-wrap:wrap; }
  .choice label { flex:1 1 calc(50% - 4px); position:relative; }
  .choice input { position:absolute; opacity:0; pointer-events:none; }
  .choice span { display:flex; align-items:center; justify-content:center; min-height:46px; padding:8px;
      border:1.5px solid #D6D0C4; border-radius:9px; background:#fff; font-size:14px; font-weight:700;
      text-align:center; cursor:pointer; }
  .choice input:checked + span { border-color:var(--blue); background:#EAF4FE; color:var(--blue); }
  .choice input:focus-visible + span { box-shadow:0 0 0 3px rgba(79,163,240,.22); }
  .alert { border-radius:10px; padding:12px 14px; font-size:13.5px; font-weight:700; }
  .alert.err { background:#FDECEA; border:1px solid #F0B3AE; color:#A03027; }
  .alert.err ul { margin:6px 0 0; padding-left:1.1rem; font-weight:400; }
  .submit { display:flex; align-items:center; justify-content:center; width:100%; height:54px;
      border:0; border-radius:11px; background:var(--blue); color:#fff; font-size:16.5px; font-weight:900;
      font-family:inherit; cursor:pointer; box-shadow:0 3px 0 #0E4E96; }
  .submit.orange { background:var(--orange); box-shadow:0 3px 0 #A9470A; }
  .submit:disabled { opacity:.6; box-shadow:none; }
  .note-small { font-size:11.5px; color:var(--muted); text-align:center; }
  .note-small a { color:var(--blue); }
  .done { text-align:center; padding:22px 14px; }
  .done .mark { width:66px; height:66px; border-radius:50%; background:#E7F5EC; color:#1E6B3A;
      display:flex; align-items:center; justify-content:center; font-size:32px; margin:0 auto 14px; }
  .done h2 { font-size:18px; margin:0 0 8px; }
  .done p { font-size:13.5px; color:var(--muted); margin:0; }
  .backlink { display:flex; align-items:center; justify-content:center; height:48px; border-radius:10px;
      border:2px solid #C9CDD6; background:#fff; color:var(--text); font-weight:700; font-size:14px;
      text-decoration:none; }
  .photos { display:grid; grid-template-columns:repeat(2,1fr); gap:8px; }
  .photos input[type=file] { font-size:13px; padding:9px; border:1.5px dashed #D6D0C4; border-radius:9px; background:#fff; width:100%; }
</style>
</head>
<body>
<header>
  <div class="t"><?= h($title) ?></div>
  <?php if ($lead !== ''): ?><div class="l"><?= h($lead) ?></div><?php endif; ?>
</header>
<main>
<?php
}

function form_footer(): void
{
    ?>
</main>
</body>
</html>
<?php
}

/** 入力エラーのまとめ。個々の欄にも出すが、上にも出さないと気づかれない。 */
function form_errors(array $errors, ?string $topMessage = null): void
{
    if ($topMessage === null && $errors === []) {
        return;
    }
    echo '<div class="alert err">' . h($topMessage ?? '入力内容をご確認ください。');
    if ($errors !== []) {
        echo '<ul>';
        foreach ($errors as $message) {
            echo '<li>' . h($message) . '</li>';
        }
        echo '</ul>';
    }
    echo '</div>';
}

/** 送信し直したときに入力を消さないための再表示 */
function form_old(array $values, string $key): string
{
    return h((string) ($values[$key] ?? ''));
}
