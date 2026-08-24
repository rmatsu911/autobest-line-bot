<?php
/**
 * 3タブ式リッチメニューの登録（CLI専用）。
 *
 *   php bin/setup_richmenu.php              … 定義を表示するだけ（LINEには触らない）
 *   php bin/setup_richmenu.php --apply      … 作成・画像アップロード・エイリアス設定・既定化
 *   php bin/setup_richmenu.php --list       … 現在登録されているものを一覧
 *   php bin/setup_richmenu.php --clean      … このスクリプトが作ったものを削除
 *
 * 画像は bin/richmenu/{find,sell,support}.png（2500x1686）に置く。
 * 画像が無いと LINE 側でメニューを有効化できないため、--apply は中断する。
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once dirname(__DIR__) . '/config/config.php';

use App\LineClient;
use App\Logger;
use App\RichMenuDefinition;

$args  = array_slice($argv, 1);
$apply = in_array('--apply', $args, true);
$list  = in_array('--list', $args, true);
$clean = in_array('--clean', $args, true);

$line     = new LineClient();
$imageDir = __DIR__ . '/richmenu';

// エイリアスID => 画像ファイル名
$imageFor = [
    RichMenuDefinition::ALIAS_FIND    => 'find.png',
    RichMenuDefinition::ALIAS_SELL    => 'sell.png',
    RichMenuDefinition::ALIAS_SUPPORT => 'support.png',
];

// -----------------------------------------------------------------------------
if ($list) {
    echo PHP_EOL . '=== 登録済みリッチメニュー ===' . PHP_EOL;
    $res = $line->listRichMenus();
    if (!$res->ok()) {
        fwrite(STDERR, '取得できませんでした（HTTP ' . $res->status . '）' . PHP_EOL);
        exit(1);
    }
    foreach ($res->json['richmenus'] ?? [] as $m) {
        printf("  %s  %s%s", $m['richMenuId'] ?? '?', $m['name'] ?? '?', PHP_EOL);
    }

    echo PHP_EOL . '=== エイリアス ===' . PHP_EOL;
    $aliases = $line->listRichMenuAliases();
    foreach ($aliases->json['aliases'] ?? [] as $a) {
        printf("  %-20s → %s%s", $a['richMenuAliasId'] ?? '?', $a['richMenuId'] ?? '?', PHP_EOL);
    }
    echo PHP_EOL;
    exit(0);
}

// -----------------------------------------------------------------------------
if ($clean) {
    echo PHP_EOL . '=== 削除 ===' . PHP_EOL;
    foreach (array_keys(RichMenuDefinition::all()) as $alias) {
        $res = $line->deleteRichMenuAlias($alias);
        echo '  エイリアス ' . $alias . ': ' . ($res->ok() ? '削除' : 'なし/失敗') . PHP_EOL;
    }
    $res = $line->listRichMenus();
    foreach ($res->json['richmenus'] ?? [] as $m) {
        if (str_starts_with((string) ($m['name'] ?? ''), 'AUTOBEST ')) {
            $line->deleteRichMenu((string) $m['richMenuId']);
            echo '  メニュー ' . $m['name'] . ' を削除' . PHP_EOL;
        }
    }
    echo PHP_EOL;
    exit(0);
}

// -----------------------------------------------------------------------------
$definitions = RichMenuDefinition::all();

echo PHP_EOL . '=== 定義（3タブ × 6ボタン）===' . PHP_EOL;
foreach ($definitions as $alias => $def) {
    printf("%s%s（%s）%s", PHP_EOL, $def['name'], $alias, PHP_EOL);
    printf("  画像: %s%s", $imageDir . '/' . $imageFor[$alias], PHP_EOL);
    foreach ($def['areas'] as $i => $area) {
        $a = $area['action'];
        $what = match ($a['type']) {
            'richmenuswitch' => 'タブ切替 → ' . $a['richMenuAliasId'],
            'uri'            => 'URL → ' . $a['uri'],
            default          => $a['data'],
        };
        printf("    %s(%4d,%4d %4dx%4d)  %-22s %s%s",
            $i < 3 ? 'タブ ' : 'ボタン',
            $area['bounds']['x'], $area['bounds']['y'],
            $area['bounds']['width'], $area['bounds']['height'],
            $a['label'] ?? '（切替）', $what, PHP_EOL);
    }
}

// 領域が重なっていないか（押せないボタンが生まれないか）を先に自分で確認する
echo PHP_EOL . '=== 領域の重なり確認 ===' . PHP_EOL;
$problems = 0;
foreach ($definitions as $alias => $def) {
    $areas = $def['areas'];
    foreach ($areas as $i => $a) {
        $b = $a['bounds'];
        if ($b['x'] + $b['width'] > RichMenuDefinition::WIDTH || $b['y'] + $b['height'] > RichMenuDefinition::HEIGHT) {
            echo "  [NG] {$alias} #{$i} が画像の外にはみ出しています" . PHP_EOL;
            $problems++;
        }
        foreach (array_slice($areas, $i + 1) as $j => $c) {
            $d = $c['bounds'];
            $overlapX = $b['x'] < $d['x'] + $d['width']  && $d['x'] < $b['x'] + $b['width'];
            $overlapY = $b['y'] < $d['y'] + $d['height'] && $d['y'] < $b['y'] + $b['height'];
            if ($overlapX && $overlapY) {
                echo "  [NG] {$alias} の領域 #{$i} と #" . ($i + $j + 1) . ' が重なっています' . PHP_EOL;
                $problems++;
            }
        }
    }
}
echo $problems === 0 ? '  [OK] 重なり・はみ出しなし（全領域が正しく反応します）' . PHP_EOL : '';

if (!$apply) {
    echo PHP_EOL . '表示のみで終了しました。登録するには --apply を付けてください。' . PHP_EOL . PHP_EOL;
    exit($problems === 0 ? 0 : 1);
}

if ($problems > 0) {
    fwrite(STDERR, PHP_EOL . '領域に問題があるため中断しました。' . PHP_EOL);
    exit(1);
}

// --- 画像の確認 --------------------------------------------------------------
echo PHP_EOL . '=== 画像 ===' . PHP_EOL;
$missing = [];
foreach ($imageFor as $alias => $file) {
    $path = $imageDir . '/' . $file;
    if (!is_readable($path)) {
        echo "  [NG] {$file} がありません" . PHP_EOL;
        $missing[] = $file;
        continue;
    }
    $size = @getimagesize($path);
    if ($size === false || $size[0] !== RichMenuDefinition::WIDTH || $size[1] !== RichMenuDefinition::HEIGHT) {
        printf("  [NG] %s のサイズが %s です（%dx%d である必要があります）%s",
            $file, $size === false ? '不明' : "{$size[0]}x{$size[1]}",
            RichMenuDefinition::WIDTH, RichMenuDefinition::HEIGHT, PHP_EOL);
        $missing[] = $file;
        continue;
    }
    printf("  [OK] %s (%dx%d, %sKB)%s", $file, $size[0], $size[1], number_format(filesize($path) / 1024), PHP_EOL);
}

if ($missing !== []) {
    fwrite(STDERR, PHP_EOL . 'リッチメニュー画像が揃っていないため中断しました。' . PHP_EOL);
    fwrite(STDERR, "bin/richmenu/ に 2500x1686 のPNGを3枚置いてください。" . PHP_EOL . PHP_EOL);
    exit(1);
}

// --- 作成 --------------------------------------------------------------------
echo PHP_EOL . '=== 登録 ===' . PHP_EOL;

$created = [];
foreach ($definitions as $alias => $def) {
    $res = $line->createRichMenu($def);
    if (!$res->ok()) {
        fwrite(STDERR, "  {$alias} の作成に失敗（HTTP {$res->status}）: " . mb_substr($res->body, 0, 300) . PHP_EOL);
        exit(1);
    }
    $menuId = (string) ($res->json['richMenuId'] ?? '');
    echo "  作成: {$alias} → {$menuId}" . PHP_EOL;

    $up = $line->uploadRichMenuImage($menuId, $imageDir . '/' . $imageFor[$alias]);
    if (!$up->ok()) {
        fwrite(STDERR, "  {$alias} の画像アップロードに失敗（HTTP {$up->status}）" . PHP_EOL);
        exit(1);
    }
    echo "  画像: {$alias} をアップロードしました" . PHP_EOL;

    $created[$alias] = $menuId;
}

// --- エイリアス ---------------------------------------------------------------
// 既にある場合は向き先を差し替える。作り直すたびに手で消さなくて済むように。
echo PHP_EOL . '=== エイリアス ===' . PHP_EOL;
foreach ($created as $alias => $menuId) {
    $res = $line->createRichMenuAlias($alias, $menuId);
    if (!$res->ok()) {
        $res = $line->updateRichMenuAlias($alias, $menuId);
    }
    echo '  ' . $alias . ': ' . ($res->ok() ? '設定しました' : '失敗（HTTP ' . $res->status . '）') . PHP_EOL;
    if (!$res->ok()) {
        exit(1);
    }
}

// --- 既定メニュー -------------------------------------------------------------
$res = $line->setDefaultRichMenu($created[RichMenuDefinition::ALIAS_FIND]);
echo PHP_EOL . '既定メニュー（車を探す）: ' . ($res->ok() ? '設定しました' : '失敗') . PHP_EOL;

Logger::info('リッチメニューを登録しました', ['menus' => $created]);

echo PHP_EOL . '=== 確認 ===' . PHP_EOL;
echo '  1. LINEでトークを開き、メニューが表示されるか' . PHP_EOL;
echo '  2. タブを押して3つが切り替わるか' . PHP_EOL;
echo '  3. 「販売在庫を探す」で在庫カルーセルが返るか' . PHP_EOL;
echo '  4. 古いメニューが残っていないか（php bin/setup_richmenu.php --list）' . PHP_EOL . PHP_EOL;
exit(0);
