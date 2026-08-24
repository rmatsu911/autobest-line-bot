<?php
/**
 * 査定写真の配信（管理画面ログイン必須）。
 *
 * 写真の実体はドキュメントルートの外（storage/assessments/）にあり、
 * Webサーバーからは直接開けない。唯一の入口がこのファイル。
 *
 * ここで守っていること：
 *   - ログインしていなければ 404（403だと「そこに何かある」と教えてしまう）
 *   - 表示するファイルはDBのIDから引く。パスやファイル名は受け取らない
 *   - 引いたファイル名も形を確かめてから使う（AssessmentImage::path）
 *   - Content-Type は拡張子から決め打ちし、no-store でキャッシュに残さない
 */

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config/config.php';

use App\AssessmentImage;
use App\Auth;

Auth::startSession();

// requireLogin() はログイン画面へ飛ばすが、画像の <img> で飛ばしても意味がない。
// 未ログインなら黙って 404 を返す。
if (!Auth::check()) {
    http_response_code(404);
    exit;
}

$image = AssessmentImage::find((int) ($_GET['id'] ?? 0));
$path  = $image === null ? null : AssessmentImage::path($image);

if ($path === null) {
    http_response_code(404);
    exit;
}

header('Content-Type: ' . AssessmentImage::mimeOf($image));
header('Content-Length: ' . (string) filesize($path));
// 個人情報なので共用PCのキャッシュにも検索エンジンにも残さない。
header('Cache-Control: no-store, no-cache, must-revalidate, private');
header('Pragma: no-cache');
header('X-Robots-Tag: noindex, nofollow');
header('X-Content-Type-Options: nosniff');
// 画像として開かせる。万一 SVG などが紛れ込んでもブラウザで実行させない。
header('Content-Disposition: inline; filename="photo-' . (int) $image['id'] . '"');
header('Content-Security-Policy: default-src \'none\'; sandbox');

readfile($path);
