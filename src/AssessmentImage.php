<?php
/**
 * 査定申込に添付された写真。
 *
 * 在庫写真（car_images）と決定的に違うのは「これはお客様の車の写真であって
 * 商品写真ではない」という点。URLを知られたら誰でも見られる場所には置かない。
 *
 *   実体 : APP_ROOT/storage/assessments/{問い合わせID}/{乱数32桁}.jpg
 *          （ドキュメントルートの外。Webサーバーからは直接開けない）
 *   DB   : ファイル名だけ。パスもURLも持たない
 *   配信 : public/admin/inquiry_image.php（ログイン必須）だけ
 */

declare(strict_types=1);

namespace App;

final class AssessmentImage
{
    /** 1件の申込に添付できる枚数。査定に必要なのは外装4面＋内装＋メーター程度。 */
    public const MAX_PER_INQUIRY = 8;

    /** 保存されたファイル名の形。これ以外は自分が作ったものではないので触らない。 */
    private const NAME_PATTERN = '/\A[0-9a-f]{32}\.(jpg|png|webp)\z/';

    /**
     * $_FILES の1要素を保存して inquiry_images に記録する。
     *
     * @throws \RuntimeException 検証に失敗した場合（メッセージはそのまま画面に出してよい）
     */
    public static function store(array $file, int $inquiryId, int $position): void
    {
        // 置き場所を守る保険を毎回張り直す。
        // Xserverのサブディレクトリ設置ではリポジトリごと public_html に置くため、
        // storage/ もURLで叩ける状態になりうる。.htaccess が消えていても写真は漏らさない。
        self::protectRoot();

        $name = ImageUploader::storePrivate($file, self::dir($inquiryId));
        Db::exec(
            'INSERT INTO inquiry_images (inquiry_id, file_name, position) VALUES (?, ?, ?)',
            [$inquiryId, $name, $position]
        );
    }

    /** @return array<int, array<string, mixed>> */
    public static function forInquiry(int $inquiryId): array
    {
        return Db::all(
            'SELECT * FROM inquiry_images WHERE inquiry_id = ? ORDER BY position, id',
            [$inquiryId]
        );
    }

    public static function find(int $imageId): ?array
    {
        return Db::one('SELECT * FROM inquiry_images WHERE id = ?', [$imageId]);
    }

    /**
     * 実ファイルの絶対パスを返す。読めなければ null。
     *
     * DBに入っているファイル名をそのまま連結せず、必ず形を確かめてから使う。
     * DBに ../../config/.env のような値が入り込んだ場合でも、
     * ここを通れば外へ出られない。
     */
    public static function path(array $image): ?string
    {
        $name = (string) ($image['file_name'] ?? '');
        if (!preg_match(self::NAME_PATTERN, $name)) {
            Logger::warning('査定写真のファイル名が想定外です', ['file_name' => $name]);
            return null;
        }
        $path = self::dir((int) $image['inquiry_id']) . '/' . $name;
        return is_file($path) ? $path : null;
    }

    public static function mimeOf(array $image): string
    {
        return match (pathinfo((string) ($image['file_name'] ?? ''), PATHINFO_EXTENSION)) {
            'png'  => 'image/png',
            'webp' => 'image/webp',
            default => 'image/jpeg',
        };
    }

    /** 1枚削除（DBと実体の両方） */
    public static function delete(int $imageId): void
    {
        $image = self::find($imageId);
        if ($image === null) {
            return;
        }
        $path = self::path($image);
        if ($path !== null) {
            @unlink($path);
        }
        Db::exec('DELETE FROM inquiry_images WHERE id = ?', [$imageId]);
    }

    /**
     * 申込1件分の写真をまとめて消す。
     * inquiry_images 側は外部キーの CASCADE で消えるが、実体は自動では消えないため、
     * 問い合わせを削除する前にこれを呼ぶ。
     */
    public static function deleteAll(int $inquiryId): void
    {
        foreach (self::forInquiry($inquiryId) as $image) {
            $path = self::path($image);
            if ($path !== null) {
                @unlink($path);
            }
        }
        $dir = self::dir($inquiryId);
        if (is_dir($dir)) {
            @rmdir($dir);
        }
    }

    private static function dir(int $inquiryId): string
    {
        return APP_ROOT . '/storage/assessments/' . $inquiryId;
    }

    /** 写真置き場を Web から一切配信させない */
    private static function protectRoot(): void
    {
        $root = APP_ROOT . '/storage/assessments';
        if (!is_dir($root) && !@mkdir($root, 0700, true) && !is_dir($root)) {
            return;
        }
        $htaccess = $root . '/.htaccess';
        if (!is_file($htaccess)) {
            @file_put_contents($htaccess, "Require all denied\nOptions -Indexes\n");
        }
    }
}
