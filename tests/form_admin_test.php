<?php
declare(strict_types=1);

// HTTPを通した結合テスト。tests/run.php がビルトインサーバを立ててから呼ぶ。
// 単体で走らせる場合は先に `php -S 127.0.0.1:8099 -t public` を起動しておくこと。
define('APP', dirname(__DIR__));
define('BASE', getenv('TEST_BASE_URL') ?: 'http://127.0.0.1:8099');
// .env の LINE_CHANNEL_SECRET と同じ値。PublicForm の発行時刻の署名に使う。
define('SECRET', getenv('TEST_CHANNEL_SECRET') ?: 'abc123');
$SP = sys_get_temp_dir() . '/autobest-tests';
@mkdir($SP, 0700, true);

$pass = 0; $fail = 0;
function check(string $name, bool $cond, string $extra = ''): void {
    global $pass, $fail;
    if ($cond) { $pass++; echo "  ok   {$name}\n"; }
    else { $fail++; echo "  FAIL {$name}   {$extra}\n"; }
}

/** @return array{status:int, headers:string, body:string} */
function http(string $path, array $opts = []): array {
    $ch = curl_init(str_starts_with($path, 'http') ? $path : BASE . $path);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER         => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_COOKIEJAR      => $opts['jar'] ?? null,
        CURLOPT_COOKIEFILE     => $opts['jar'] ?? null,
        CURLOPT_TIMEOUT        => 20,
    ]);
    if (isset($opts['post'])) {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $opts['post']);
    }
    $raw  = (string) curl_exec($ch);
    $size = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ['status' => $code, 'headers' => substr($raw, 0, $size), 'body' => substr($raw, $size)];
}

function hidden(string $html, string $name): string {
    return preg_match('/name="' . preg_quote($name, '/') . '" value="([^"]*)"/', $html, $m) ? $m[1] : '';
}

/** フォームの hidden をそのまま使い、経過時間だけ過去にずらす（3秒待たずに正規の送信を作る） */
function agedTokens(string $html, int $secondsAgo = 10): array {
    $issued = (string) (time() - $secondsAgo);
    return [
        '_token'      => hidden($html, '_token'),
        '_issued'     => $issued,
        '_issued_sig' => hash_hmac('sha256', 'form:' . $issued, SECRET),
    ];
}

function db(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        // MySQLでも同じテストを流せるよう、接続先を環境変数で差し替えられるようにする
        $dsn  = getenv('P4_DSN') ?: ('sqlite:' . APP . '/storage/autobest.sqlite');
        $user = getenv('P4_USER') ?: null;
        $pdo  = new PDO($dsn, $user, getenv('P4_PASS') ?: null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    }
    return $pdo;
}
function one(string $sql, array $p = []): array { $st = db()->prepare($sql); $st->execute($p); return $st->fetch(PDO::FETCH_ASSOC) ?: []; }
function val(string $sql, array $p = []) { $st = db()->prepare($sql); $st->execute($p); return $st->fetchColumn(); }

// テスト用の小さなJPEGを作る
function makeJpeg(string $path, int $w = 2400, int $h = 1600): void {
    $im = imagecreatetruecolor($w, $h);
    imagefilledrectangle($im, 0, 0, $w, $h, imagecolorallocate($im, 30, 120, 200));
    imagejpeg($im, $path, 80);
    imagedestroy($im);
}

$jar = $SP . '/p4_cookies.txt';
@unlink($jar);
$adminJar = $SP . '/p4_admin.txt';
@unlink($adminJar);

// =============================================================================
echo "\n== 査定フォームの表示 ==\n";
$r = http('/assessment.php', ['jar' => $jar]);
check('200で表示される', $r['status'] === 200, 'status=' . $r['status']);
check('見出しが出る', str_contains($r['body'], '無料査定のお申し込み'));
check('検索避けヘッダが付く', str_contains($r['headers'], 'X-Robots-Tag: noindex'));
check('キャッシュを残さない', str_contains($r['headers'], 'no-store'));
check('ハニーポットが仕込まれている', str_contains($r['body'], 'company_name'));
check('トークンが埋め込まれる', hidden($r['body'], '_token') !== '');
check('入力欄は16px以上（iOSの自動ズーム対策）', str_contains($r['body'], 'font-size:16px'));
$form = $r['body'];

echo "\n== 送信の防御 ==\n";
$valid = [
    'contact_name' => '山田 花子', 'contact_tel' => '090-1234-5678',
    'maker' => 'トヨタ', 'model_name' => 'ハイエース',
];

$r = http('/assessment.php', ['jar' => $jar, 'post' => $valid]);
check('トークン無しは拒否', str_contains($r['body'], '送信内容を確認できませんでした'));
check('拒否してもDBに入らない', (int) val('SELECT COUNT(*) FROM inquiries') === 0);

$r = http('/assessment.php', ['jar' => $jar, 'post' => $valid + agedTokens($form) + ['company_name' => 'bot株式会社']]);
check('ハニーポットに入力があれば拒否', str_contains($r['body'], '送信できませんでした'));

$now = (string) time();
$r = http('/assessment.php', ['jar' => $jar, 'post' => $valid + [
    '_token' => hidden($form, '_token'), '_issued' => $now,
    '_issued_sig' => hash_hmac('sha256', 'form:' . $now, SECRET),
]]);
check('表示から3秒未満の送信は拒否', str_contains($r['body'], '送信できませんでした'));

$old = (string) (time() - 7200);
$r = http('/assessment.php', ['jar' => $jar, 'post' => $valid + [
    '_token' => hidden($form, '_token'), '_issued' => $old,
    '_issued_sig' => hash_hmac('sha256', 'form:' . $old, SECRET),
]]);
check('古すぎるフォームは拒否', str_contains($r['body'], '時間が経ちすぎています'));

$r = http('/assessment.php', ['jar' => $jar, 'post' => $valid + [
    '_token' => hidden($form, '_token'), '_issued' => (string) (time() - 10),
    '_issued_sig' => 'deadbeef',
]]);
check('発行時刻の署名が違えば拒否', str_contains($r['body'], '送信内容を確認できませんでした'));
check('ここまでDBは空のまま', (int) val('SELECT COUNT(*) FROM inquiries') === 0);

echo "\n== 入力検証 ==\n";
$cases = [
    [['contact_name' => '', 'contact_tel' => '09012345678', 'maker' => 'ト', 'model_name' => 'ハ'], 'お名前を入力してください'],
    [['contact_name' => '山田', 'contact_tel' => '', 'maker' => 'ト', 'model_name' => 'ハ'], '電話番号を入力してください'],
    [['contact_name' => '山田', 'contact_tel' => 'アイウエオ', 'maker' => 'ト', 'model_name' => 'ハ'], '電話番号は半角数字'],
    [['contact_name' => '山田', 'contact_tel' => '123', 'maker' => 'ト', 'model_name' => 'ハ'], '電話番号'],
    [['contact_name' => '山田', 'contact_tel' => '09012345678', 'maker' => '', 'model_name' => 'ハ'], 'メーカーを入力してください'],
    [['contact_name' => '山田', 'contact_tel' => '09012345678', 'maker' => 'ト', 'model_name' => ''], '車種を入力してください'],
    [['contact_name' => '山田', 'contact_tel' => '09012345678', 'maker' => 'ト', 'model_name' => 'ハ', 'contact_email' => 'not-an-email'], 'メールアドレスの形式'],
    [['contact_name' => '山田', 'contact_tel' => '09012345678', 'maker' => 'ト', 'model_name' => 'ハ', 'model_year' => '1800'], '年式は1950'],
    [['contact_name' => '山田', 'contact_tel' => '09012345678', 'maker' => 'ト', 'model_name' => 'ハ', 'mileage_km' => 'abc'], '走行距離は半角数字'],
    [['contact_name' => '山田', 'contact_tel' => '09012345678', 'maker' => 'ト', 'model_name' => 'ハ', 'contact_pref' => '深夜3時'], 'ご希望の連絡時間帯'],
];
foreach ($cases as [$post, $expect]) {
    $r = http('/assessment.php', ['jar' => $jar, 'post' => $post + agedTokens($form)]);
    check("「{$expect}」を返す",
        str_contains($r['body'], $expect) && !str_contains($r['body'], '送信内容を確認できませんでした'),
        'status=' . $r['status']);
}
check('検証で弾いた分はDBに入らない', (int) val('SELECT COUNT(*) FROM inquiries') === 0);

echo "\n== 入力の保持 ==\n";
$r = http('/assessment.php', ['jar' => $jar, 'post' => [
    'contact_name' => '山田 花子', 'contact_tel' => '', 'maker' => 'トヨタ', 'model_name' => 'ハイエース',
] + agedTokens($form)]);
check('エラー時も入力済みの値を出し直す', str_contains($r['body'], 'value="山田 花子"') && str_contains($r['body'], 'value="ハイエース"'));
$r2 = http('/assessment.php', ['jar' => $jar, 'post' => [
    'contact_name' => '　山田　', 'contact_tel' => '', 'maker' => 'トヨタ', 'model_name' => 'ハイエース',
] + agedTokens($form)]);
check('全角スペースを削っても文字化けしない',
    str_contains($r2['body'], 'value="山田"') && str_contains($r2['body'], 'value="トヨタ"'),
    '実際: ' . (preg_match('/name="maker" value="([^"]*)"/', $r2['body'], $mm) ? $mm[1] : '-'));

echo "\n== 正常な申込（写真つき） ==\n";
makeJpeg($SP . '/p4_front.jpg');
makeJpeg($SP . '/p4_meter.jpg', 800, 600);
file_put_contents($SP . '/p4_evil.php.jpg', "<?php system('id'); ?>");

$post = [
    'contact_name'     => '<script>alert(1)</script> 山田',
    'contact_tel'      => '０９０－１２３４－５６７８',   // 全角
    'contact_email'    => 'hanako@example.jp',
    'contact_pref'     => '午前中',
    'category'         => 'passenger',
    'maker'            => "トヨタ' OR 1=1 --",
    'model_name'       => 'ハイエース',
    'model_year'       => '２０１８',                    // 全角
    'mileage_km'       => '82,000',                      // カンマ入り
    'inspection_until' => '2027-03',
    'message'          => "後ろのバンパーにキズあり\n売却は来月を予定",
    'photos[0]'        => new CURLFile($SP . '/p4_front.jpg', 'image/jpeg', 'front.jpg'),
    'photos[1]'        => new CURLFile($SP . '/p4_meter.jpg', 'image/jpeg', 'meter.jpg'),
    'photos[2]'        => new CURLFile($SP . '/p4_evil.php.jpg', 'image/jpeg', 'evil.php.jpg'),
] + agedTokens($form);

$r = http('/assessment.php', ['jar' => $jar, 'post' => $post]);
check('完了画面へリダイレクトする', $r['status'] === 302 && str_contains($r['headers'], 'assessment.php?done=1'),
    'status=' . $r['status']);

$inq = one('SELECT * FROM inquiries ORDER BY id DESC LIMIT 1');
check('問い合わせが1件保存された', $inq !== [] && (int) val('SELECT COUNT(*) FROM inquiries') === 1);
check('種別は査定申込', ($inq['kind'] ?? '') === 'assessment');
check('経路はWebフォーム', ($inq['source'] ?? '') === 'web');
check('LINEユーザーは紐づかない', $inq['line_user_id'] === null);
check('状態は未対応', ($inq['status'] ?? '') === 'new');
check('担当者は未定', $inq['assigned_admin_id'] === null);
check('全角の電話番号を半角へ寄せる', ($inq['contact_tel'] ?? '') === '090-1234-5678', '実際: ' . ($inq['contact_tel'] ?? ''));
check('SQLの断片も文字として保存する', str_contains((string) $inq['payload'], "トヨタ' OR 1=1 --"));
check('全角の年式を半角で保存', str_contains((string) $inq['payload'], '2018'));
check('カンマ付き走行距離を数値で保存', str_contains((string) $inq['payload'], '82000km'), '実際: ' . $inq['payload']);
check('日本語がJSONでそのまま読める', str_contains((string) $inq['payload'], 'ハイエース'));
check('車両は全件そのまま（在庫は増えない）', (int) val("SELECT COUNT(*) FROM cars") === 18);

$inqId  = (int) $inq['id'];
$images = db()->query("SELECT * FROM inquiry_images WHERE inquiry_id = {$inqId} ORDER BY position")->fetchAll(PDO::FETCH_ASSOC);
check('写真は画像として読めた2枚だけ保存', count($images) === 2, '件数=' . count($images));
check('PHPを仕込んだファイルは保存されない',
    !array_filter($images, fn ($i) => str_contains((string) $i['file_name'], 'php')));
foreach ($images as $i) {
    check('ファイル名は乱数32桁 + 拡張子', (bool) preg_match('/\A[0-9a-f]{32}\.(jpg|png|webp)\z/', (string) $i['file_name']),
        '実際: ' . $i['file_name']);
}

$dir = APP . '/storage/assessments/' . $inqId;
$files = array_values(array_diff((array) scandir($dir), ['.', '..']));
check('実体は公開領域の外に置かれる', count($files) === 2 && str_starts_with($dir, APP . '/storage/'));
check('実体に実行権限が無い', (fileperms($dir . '/' . $files[0]) & 0777) === 0600,
    '実際: ' . decoct(fileperms($dir . '/' . $files[0]) & 0777));
[$w, $h] = getimagesize($dir . '/' . $files[0]);
check('大きい写真は縮小される（長辺1600以下）', max($w, $h) <= 1600, "実際: {$w}x{$h}");

$log = one("SELECT * FROM audit_logs WHERE action = 'inquiry.create.web' ORDER BY id DESC LIMIT 1");
check('監査ログに残る', $log !== []);
check('お客様の送信は操作者なしで記録', $log['admin_id'] === null && $log['admin_name'] === null);
check('接続元IPを記録', ($log['ip'] ?? '') !== '');

echo "\n== 完了画面 ==\n";
$r = http('/assessment.php?done=1', ['jar' => $jar]);
check('完了画面が出る', $r['status'] === 200 && str_contains($r['body'], 'お申し込みありがとうございます'));
check('管理画面への導線は出さない', !str_contains($r['body'], 'index.php'));

echo "\n== 写真の配信 ==\n";
$imageId = (int) $images[0]['id'];
$r = http('/admin/inquiry_image.php?id=' . $imageId);
check('未ログインでは404（存在も知らせない）', $r['status'] === 404, 'status=' . $r['status']);
$r = http('/storage/assessments/' . $inqId . '/' . ($files[0] ?? 'x.jpg'));
check('storage配下は直接開けない', $r['status'] !== 200 && !str_contains($r['headers'], 'image/'),
    'status=' . $r['status']);

// 管理画面にログイン
$login = http('/admin/login.php', ['jar' => $adminJar]);
$r = http('/admin/login.php', ['jar' => $adminJar, 'post' => [
    '_token' => hidden($login['body'], '_token'), 'login_id' => 'owner', 'password' => 'Test1234abcd',
]]);
check('ログインできる', $r['status'] === 302, 'status=' . $r['status']);
check('ログインも監査ログに残る', (int) val("SELECT COUNT(*) FROM audit_logs WHERE action = 'admin.login'") === 1);

$r = http('/admin/inquiry_image.php?id=' . $imageId, ['jar' => $adminJar]);
check('ログイン後は写真を配信する', $r['status'] === 200 && strlen($r['body']) > 1000, 'status=' . $r['status'] . ' len=' . strlen($r['body']));
check('画像として返す', str_contains($r['headers'], 'Content-Type: image/jpeg'));
check('キャッシュに残さない', str_contains($r['headers'], 'no-store'));
check('スクリプトとして実行させない', str_contains($r['headers'], "sandbox"));

$r = http('/admin/inquiry_image.php?id=999999', ['jar' => $adminJar]);
check('存在しない写真は404', $r['status'] === 404);

echo "\n== 管理画面：問い合わせ ==\n";
$r = http('/admin/inquiries.php', ['jar' => $adminJar]);
check('一覧に出る', $r['status'] === 200 && str_contains($r['body'], '査定申込'));
check('お客様名を出す', str_contains($r['body'], '山田'));
check('XSSはそのまま出さない', !str_contains($r['body'], '<script>alert(1)</script>'));
check('エスケープして出す', str_contains($r['body'], '&lt;script&gt;'));
check('写真の枚数が分かる', str_contains($r['body'], '写真2枚'));
check('Webフォーム経由と分かる', str_contains($r['body'], 'Webフォーム'));

$r = http('/admin/inquiry.php?id=' . $inqId, ['jar' => $adminJar]);
$detail = $r['body'];
check('詳細が開ける', $r['status'] === 200 && str_contains($detail, 'お申し込み内容'));
check('申込内容の項目が読める', str_contains($detail, 'ハイエース') && str_contains($detail, '車検満了'));
check('ご要望の改行を保つ', str_contains($detail, 'バンパーにキズあり'));
check('写真を表示する', substr_count($detail, 'inquiry_image.php?id=') >= 2);
check('連絡先に電話リンクを張る', str_contains($detail, 'href="tel:09012345678"'));

$csrf = hidden($detail, '_token');
$r = http('/admin/inquiry.php', ['jar' => $adminJar, 'post' => [
    '_token' => $csrf, 'action' => 'assign', 'id' => $inqId, 'assigned_admin_id' => '1',
]]);
check('担当者を設定できる', (int) val("SELECT assigned_admin_id FROM inquiries WHERE id = ?", [$inqId]) === 1);

$r = http('/admin/inquiry.php', ['jar' => $adminJar, 'post' => [
    '_token' => $csrf, 'action' => 'note', 'id' => $inqId, 'kind' => 'call', 'body' => '折り返し電話。45万円を提示。',
]]);
$note = one("SELECT * FROM inquiry_notes WHERE inquiry_id = ? ORDER BY id DESC LIMIT 1", [$inqId]);
check('対応履歴が残る', ($note['body'] ?? '') === '折り返し電話。45万円を提示。');
check('誰が書いたかを焼き込む', ($note['admin_name'] ?? '') === '店長', '実際: ' . ($note['admin_name'] ?? ''));
check('履歴を書くと自動で対応中になる', (string) val("SELECT status FROM inquiries WHERE id = ?", [$inqId]) === 'in_progress');

$r = http('/admin/inquiry.php', ['jar' => $adminJar, 'post' => [
    '_token' => $csrf, 'action' => 'status', 'id' => $inqId, 'status' => 'done',
]]);
check('完了にできる', (string) val("SELECT status FROM inquiries WHERE id = ?", [$inqId]) === 'done');
check('状態変更も履歴に残る', (int) val("SELECT COUNT(*) FROM inquiry_notes WHERE inquiry_id = ? AND kind = 'status'", [$inqId]) === 1);

$r = http('/admin/inquiry.php', ['jar' => $adminJar, 'post' => [
    '_token' => 'wrong-token', 'action' => 'status', 'id' => $inqId, 'status' => 'new',
]]);
check('CSRFトークンが違えば400', $r['status'] === 400, 'status=' . $r['status']);
check('状態は変わっていない', (string) val("SELECT status FROM inquiries WHERE id = ?", [$inqId]) === 'done');

$r = http('/admin/inquiry.php?id=' . $inqId, ['jar' => $jar]);   // 未ログインのクッキー
check('未ログインでは詳細を見せない', $r['status'] === 302 || str_contains($r['body'], 'ログイン'), 'status=' . $r['status']);

echo "\n== 来店予約フォーム ==\n";
$car = (int) val("SELECT id FROM cars WHERE status = 'published' ORDER BY id LIMIT 1");
$r = http('/reserve.php?car_id=' . $car, ['jar' => $jar]);
$rform = $r['body'];
check('200で表示される', $r['status'] === 200 && str_contains($rform, '来店・商談のご予約'));
check('対象車両を表示する', str_contains($rform, 'ハイエース'));
check('車両の拠点を既定で選ぶ', (bool) preg_match('/value="fukuoka"\s+checked/', $rform), '');

$hidden = (int) val("SELECT id FROM cars WHERE status = 'draft' LIMIT 1");
$r = http('/reserve.php?car_id=' . $hidden, ['jar' => $jar]);
check('未公開の車両は対象として出さない', !str_contains($r['body'], '非公開'), '');

$soon = (new DateTimeImmutable('+5 days'))->setTime(11, 0);
$base = [
    'contact_name' => '鈴木 一郎', 'contact_tel' => '08012345678',
    'location' => 'fukuoka', 'purpose' => 'visit', 'car_id' => (string) $car,
];

$bad = [
    [['preferred_1' => ''],                                                  '第1希望を選んでください'],
    [['preferred_1' => (new DateTimeImmutable('-1 day'))->format('Y-m-d\T11:00')], '時間以上先'],
    [['preferred_1' => (new DateTimeImmutable('+400 days'))->format('Y-m-d\T11:00')], '日以内'],
    [['preferred_1' => $soon->setTime(23, 0)->format('Y-m-d\TH:i')],         '9:00〜19:00'],
    [['preferred_1' => 'next monday'],                                       '形式をご確認'],
    [['preferred_1' => $soon->format('Y-m-d\TH:i'), 'preferred_2' => $soon->format('Y-m-d\TH:i')], '重複しています'],
    [['preferred_1' => $soon->format('Y-m-d\TH:i'), 'location' => 'osaka'],  '拠点をお選びください'],
];
foreach ($bad as [$extra, $expect]) {
    $r = http('/reserve.php', ['jar' => $jar, 'post' => array_merge($base, $extra) + agedTokens($rform)]);
    check("「{$expect}」を返す",
        str_contains($r['body'], $expect) && !str_contains($r['body'], '送信内容を確認できませんでした'),
        'status=' . $r['status']);
}
check('弾いた予約はDBに入らない', (int) val('SELECT COUNT(*) FROM reservations') === 0);

$r = http('/reserve.php', ['jar' => $jar, 'post' => array_merge($base, [
    'preferred_1' => $soon->format('Y-m-d\TH:i'),
    'preferred_2' => $soon->modify('+1 day')->format('Y-m-d\TH:i'),
    'note'        => '下取り希望の車が1台あります',
]) + agedTokens($rform)]);
check('予約を受け付ける', $r['status'] === 302 && str_contains($r['headers'], 'reserve.php?done=1'), 'status=' . $r['status']);

$res = one('SELECT * FROM reservations ORDER BY id DESC LIMIT 1');
check('仮予約として保存', ($res['status'] ?? '') === 'tentative');
check('確定日時はまだ無い', $res['confirmed_at'] === null);
check('対象車両が付く', (int) ($res['car_id'] ?? 0) === $car);
check('拠点が入る', ($res['location'] ?? '') === 'fukuoka');
check('第2希望も保存', !empty($res['preferred_2']));
check('希望日時はDBの日時形式で保存される',
    (bool) preg_match('/\A\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}\z/', (string) $res['preferred_1']),
    '実際: ' . $res['preferred_1']);
check('未入力の第3希望はNULL（空文字にしない）', $res['preferred_3'] === null,
    '実際: ' . var_export($res['preferred_3'], true));
check('経路はWebフォーム', ($res['source'] ?? '') === 'web');

$r = http('/reserve.php?done=1', ['jar' => $jar]);
check('完了画面で「まだ確定ではない」と伝える', str_contains($r['body'], 'まだ確定ではありません'));

echo "\n== 管理画面：予約 ==\n";
$resId = (int) $res['id'];
$r = http('/admin/reservations.php', ['jar' => $adminJar]);
$rlist = $r['body'];
check('一覧に出る', $r['status'] === 200 && str_contains($rlist, '鈴木 一郎'));
check('仮予約として表示', str_contains($rlist, '仮予約'));
check('希望日時の候補を並べる', substr_count($rlist, '第1希望') >= 1 && str_contains($rlist, '第2希望'));

$csrf2 = hidden($rlist, '_token');
$r = http('/admin/reservations.php', ['jar' => $adminJar, 'post' => [
    '_token' => $csrf2, 'action' => 'confirm', 'id' => $resId,
    'confirmed_at' => (string) $res['preferred_1'],
]]);
$res2 = one('SELECT * FROM reservations WHERE id = ?', [$resId]);
check('第1希望で確定できる', ($res2['status'] ?? '') === 'confirmed' && !empty($res2['confirmed_at']));
check('確定はメモにも残る', str_contains((string) $res2['note'], '確定しました'));

$r = http('/admin/reservations.php', ['jar' => $adminJar, 'post' => [
    '_token' => $csrf2, 'action' => 'confirm', 'id' => $resId,
    'confirmed_at' => (string) $res['preferred_2'],
]]);
$res3 = one('SELECT * FROM reservations WHERE id = ?', [$resId]);
check('日時を変えると「日時変更」になる', ($res3['status'] ?? '') === 'changed', '実際: ' . ($res3['status'] ?? ''));

$r = http('/admin/reservations.php', ['jar' => $adminJar, 'post' => [
    '_token' => $csrf2, 'action' => 'status', 'id' => $resId, 'status' => 'cancelled',
]]);
$res4 = one('SELECT * FROM reservations WHERE id = ?', [$resId]);
check('キャンセルにできる', ($res4['status'] ?? '') === 'cancelled');
check('キャンセルで確定日時が消える', $res4['confirmed_at'] === null);

$r = http('/admin/reservations.php', ['jar' => $adminJar, 'post' => [
    '_token' => $csrf2, 'action' => 'status', 'id' => $resId, 'status' => 'よくわからない値',
]]);
check('不正な状態値では落ちない', $r['status'] < 500, 'status=' . $r['status']);
check('不正な値では変わらない', (string) val('SELECT status FROM reservations WHERE id = ?', [$resId]) === 'cancelled');

$r = http('/admin/reservations.php', ['jar' => $jar]);
check('未ログインでは予約を見せない', $r['status'] === 302 || str_contains($r['body'], 'ログイン'));

echo "\n== 管理画面：操作履歴 ==\n";
$r = http('/admin/audit.php', ['jar' => $adminJar]);
check('操作履歴が開ける', $r['status'] === 200 && str_contains($r['body'], '操作履歴'));
check('担当者の操作が載る', str_contains($r['body'], 'inquiry.assign'));
check('お客様の送信も載る', str_contains($r['body'], 'reservation.create.web'));
check('操作者名が出る', str_contains($r['body'], '店長'));
check('記録を消すボタンは無い', !str_contains($r['body'], 'action" value="delete'));

$before = (int) val('SELECT COUNT(*) FROM audit_logs');
$r = http('/admin/cars.php', ['jar' => $adminJar]);
$carsCsrf = hidden($r['body'], '_token');
http('/admin/cars.php', ['jar' => $adminJar, 'post' => [
    '_token' => $carsCsrf, 'action' => 'status', 'car_id' => $car, 'status' => 'sold',
]]);
check('在庫の状態変更も記録される',
    (int) val("SELECT COUNT(*) FROM audit_logs WHERE action = 'car.status' AND target_id = ?", [$car]) === 1);
check('記録が増えている', (int) val('SELECT COUNT(*) FROM audit_logs') > $before);

echo "\n== 連投制限 ==\n";
$blocked = false;
$sent = 0;
for ($i = 0; $i < 8; $i++) {
    // 送信が通るとトークンが作り直されるので、毎回フォームを取り直す
    $form2 = http('/reserve.php', ['jar' => $jar])['body'];
    $r = http('/reserve.php', ['jar' => $jar, 'post' => array_merge($base, [
        'preferred_1' => $soon->modify('+' . ($i + 2) . ' days')->format('Y-m-d\TH:i'),
    ]) + agedTokens($form2)]);
    if (str_contains($r['body'], '短時間に多くの送信')) { $blocked = true; break; }
    if ($r['status'] === 302) { $sent++; }
}
check('連投は途中で止まる', $blocked, "8回すべて通ってしまった（成功{$sent}件）");
check('止まった時点の件数は上限どおり', (int) val('SELECT COUNT(*) FROM reservations') <= 6,
    '実際: ' . val('SELECT COUNT(*) FROM reservations'));

echo "\n== 二重送信 ==\n";
$dblForm = http('/reserve.php', ['jar' => $jar])['body'];
$dblPost = array_merge($base, ['preferred_1' => $soon->modify('+20 days')->format('Y-m-d\TH:i')]) + agedTokens($dblForm);
$before2 = (int) val('SELECT COUNT(*) FROM reservations');
$r1 = http('/reserve.php', ['jar' => $jar, 'post' => $dblPost]);
$r2 = http('/reserve.php', ['jar' => $jar, 'post' => $dblPost]);   // 戻るボタンで再送した想定
check('同じ内容を2回送っても1件しか増えない',
    (int) val('SELECT COUNT(*) FROM reservations') - $before2 <= 1,
    '増分=' . ((int) val('SELECT COUNT(*) FROM reservations') - $before2));

echo "\n== 削除 ==\n";
clearstatcache(true, $dir);
$dirBefore = is_dir($dir);
$r = http('/admin/inquiry.php', ['jar' => $adminJar, 'post' => [
    '_token' => $csrf, 'action' => 'delete', 'id' => $inqId,
]]);
check('問い合わせを削除できる', (int) val('SELECT COUNT(*) FROM inquiries WHERE id = ?', [$inqId]) === 0);
check('対応履歴も一緒に消える', (int) val('SELECT COUNT(*) FROM inquiry_notes WHERE inquiry_id = ?', [$inqId]) === 0);
check('写真の記録も消える', (int) val('SELECT COUNT(*) FROM inquiry_images WHERE inquiry_id = ?', [$inqId]) === 0);
clearstatcache(true, $dir);
check('写真の実体も消える（個人情報を残さない）', $dirBefore && !is_dir($dir), 'dir=' . $dir . ' before=' . var_export($dirBefore, true));
check('監査ログは消えない', (int) val('SELECT COUNT(*) FROM audit_logs') > 0);
check('削除も記録される', (int) val("SELECT COUNT(*) FROM audit_logs WHERE action = 'inquiry.delete'") === 1);

echo "\n" . str_repeat('-', 46) . "\n";
echo "成功 {$pass} / 失敗 {$fail}\n\n";
exit($fail === 0 ? 0 : 1);
