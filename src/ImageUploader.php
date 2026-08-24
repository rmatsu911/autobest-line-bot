<?php
/**
 * 車両画像のアップロード。
 *
 * 保存先は img.autobest.jp 配下（.env の IMG_UPLOAD_DIR）。DBにはURLだけを持つ。
 *
 * アップロードは管理画面で最も危険な機能なので、以下を必ず通す。
 *   - 拡張子ではなく中身で判定する（finfo + getimagesize）
 *   - ファイル名は完全にこちらで作り直す（元の名前は一切使わない）
 *   - 保存先ディレクトリで PHP が実行されないようにする
 */

declare(strict_types=1);

namespace App;

final class ImageUploader
{
    /** 受け付ける MIME と、対応する保存拡張子 */
    private const ALLOWED = [
        'image/jpeg' => 'jpg',
        'image/png'  => 'png',
        'image/webp' => 'webp',
    ];

    /** 長辺の上限。スマホの写真をそのまま置くとLINEでの読み込みが遅い。 */
    private const MAX_EDGE = 1600;

    /** 1台あたりの上限枚数（想定は4枚程度） */
    public const MAX_PER_CAR = 10;

    /**
     * $_FILES の1要素を保存し、公開URLを返す。
     *
     * @param array $file $_FILES['images'] を1件ずつに分解したもの
     * @throws \RuntimeException 検証に失敗した場合（メッセージはそのまま画面に出してよい内容）
     */
    public static function store(array $file, int $carId): string
    {
        // 1〜3) 検証。中身を見て「本当に画像か」を確かめる。
        [$mime, $width, $height] = self::validateUpload($file);
        $tmpPath = (string) $file['tmp_name'];

        // 4) 保存先。車両IDごとに分けておくと、車両削除時にまとめて消せる。
        $dir = self::carDir($carId);
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            Logger::error('画像保存ディレクトリを作成できません', ['dir' => $dir]);
            throw new \RuntimeException('画像を保存できませんでした。');
        }
        self::protectDirectory(dirname($dir));

        // 5) ファイル名は元の名前を一切使わず、乱数で作る。
        //    元の名前を残すと、日本語・記号・二重拡張子・パス区切りの扱いを
        //    すべて自前で守る必要が出てくる。作り直せばその問題自体が消える。
        $ext      = self::ALLOWED[$mime];
        $basename = bin2hex(random_bytes(16));
        $saved    = self::saveNormalized($tmpPath, $dir, $basename, $ext, $mime, $width, $height);

        // 6) 実行権限を与えない。読み取り専用で十分。
        @chmod($saved, 0644);

        return rtrim(Config::get('IMG_BASE_URL', 'https://img.autobest.jp'), '/')
            . '/cars/' . $carId . '/' . basename($saved);
    }

    /**
     * 公開しない場所へ保存し、ファイル名だけを返す。
     *
     * 査定の写真はお客様の車の写真であって商品写真ではない。
     * img.autobest.jp に置くと、URLさえ分かれば誰でも見られてしまうため、
     * 公開領域の外（storage/assessments/）に置き、
     * 管理画面のログイン必須スクリプト経由でしか配信しない。
     *
     * 返すのがURLではなくファイル名なのは、DBにパスを持たせないため。
     * パスを持つと、そこにディレクトリを遡る文字列が入り込む余地が生まれる。
     */
    public static function storePrivate(array $file, string $dir): string
    {
        [$mime, $width, $height] = self::validateUpload($file);
        $tmpPath = (string) $file['tmp_name'];

        if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) {
            Logger::error('画像保存ディレクトリを作成できません', ['dir' => $dir]);
            throw new \RuntimeException('画像を保存できませんでした。');
        }

        $basename = bin2hex(random_bytes(16));
        $saved    = self::saveNormalized($tmpPath, $dir, $basename, self::ALLOWED[$mime], $mime, $width, $height);
        @chmod($saved, 0600);

        return basename($saved);
    }

    /**
     * アップロードの検証。中身を見て種別と寸法を返す。
     *
     * @return array{0:string,1:int,2:int} [MIME, 幅, 高さ]
     */
    private static function validateUpload(array $file): array
    {
        self::assertUploadOk($file);

        $tmpPath = (string) $file['tmp_name'];

        // 1) アップロード経由のファイルであることを確認する。
        //    これを省くと、パスを細工して /etc/passwd などを「アップロード扱い」にされうる。
        if (!is_uploaded_file($tmpPath)) {
            throw new \RuntimeException('不正なアップロードです。');
        }

        $maxBytes = Config::getInt('IMG_MAX_BYTES', 10 * 1024 * 1024);
        if ((int) $file['size'] > $maxBytes) {
            throw new \RuntimeException('画像が大きすぎます（上限 ' . (int) round($maxBytes / 1024 / 1024) . 'MB）。');
        }

        // 2) 中身で種別を判定する。
        //    ブラウザが送ってくる $file['type'] は自己申告なので信用しない。
        //    拡張子も同様（photo.php.jpg のような名前を付けられる）。
        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mime  = (string) $finfo->file($tmpPath);
        if (!isset(self::ALLOWED[$mime])) {
            throw new \RuntimeException('対応していない画像形式です（JPEG / PNG / WebP のみ）。');
        }

        // 3) 実際に画像として読めるかを確認する。
        //    先頭に画像のシグネチャだけ付けたPHPスクリプトはここで弾かれる。
        $size = @getimagesize($tmpPath);
        if ($size === false || (int) $size[0] < 1 || (int) $size[1] < 1) {
            throw new \RuntimeException('画像として読み込めませんでした。');
        }

        return [$mime, (int) $size[0], (int) $size[1]];
    }

    /**
     * 長辺が MAX_EDGE を超えていれば縮小して保存、そうでなければそのまま移動。
     * GD が無い環境では常にそのまま移動する（縮小は品質向上であって必須ではない）。
     */
    private static function saveNormalized(
        string $tmpPath,
        string $dir,
        string $basename,
        string $ext,
        string $mime,
        int $width,
        int $height,
    ): string {
        $longEdge = max($width, $height);

        if (!function_exists('imagecreatefromjpeg') || $longEdge <= self::MAX_EDGE) {
            $dest = $dir . '/' . $basename . '.' . $ext;
            if (!move_uploaded_file($tmpPath, $dest)) {
                Logger::error('画像の移動に失敗しました', ['dest' => $dest]);
                throw new \RuntimeException('画像を保存できませんでした。');
            }
            return $dest;
        }

        $source = match ($mime) {
            'image/jpeg' => @imagecreatefromjpeg($tmpPath),
            'image/png'  => @imagecreatefrompng($tmpPath),
            'image/webp' => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($tmpPath) : false,
            default      => false,
        };

        if ($source === false) {
            // 読めなければ縮小を諦めて原本を保存する。表示はできる。
            $dest = $dir . '/' . $basename . '.' . $ext;
            if (!move_uploaded_file($tmpPath, $dest)) {
                throw new \RuntimeException('画像を保存できませんでした。');
            }
            return $dest;
        }

        $scale     = self::MAX_EDGE / $longEdge;
        $newWidth  = max(1, (int) round($width * $scale));
        $newHeight = max(1, (int) round($height * $scale));

        $canvas = imagecreatetruecolor($newWidth, $newHeight);
        // PNG/WebP の透過を白で潰す。車両写真に透過は不要で、
        // 透過のまま JPEG にすると黒く落ちるため先に塗っておく。
        $white = imagecolorallocate($canvas, 255, 255, 255);
        imagefilledrectangle($canvas, 0, 0, $newWidth, $newHeight, $white);
        imagecopyresampled($canvas, $source, 0, 0, 0, 0, $newWidth, $newHeight, $width, $height);

        // 縮小後は写真なので JPEG に統一する。
        $dest = $dir . '/' . $basename . '.jpg';
        $ok   = imagejpeg($canvas, $dest, 85);

        imagedestroy($canvas);
        imagedestroy($source);

        if (!$ok) {
            Logger::error('画像の書き出しに失敗しました', ['dest' => $dest]);
            throw new \RuntimeException('画像を保存できませんでした。');
        }
        return $dest;
    }

    /** 車両1台分の画像をすべて削除する（車両削除時に呼ぶ） */
    public static function deleteCarDir(int $carId): void
    {
        $dir = self::carDir($carId);
        if (!is_dir($dir)) {
            return;
        }
        foreach ((array) glob($dir . '/*') as $path) {
            if (is_file($path)) {
                @unlink($path);
            }
        }
        @rmdir($dir);
    }

    /**
     * URL から実ファイルを消す。
     * URL を組み立て直すのではなく、ファイル名だけを取り出して自前のパスに繋ぐ。
     * URL 内の ../ でディレクトリを遡られないようにするため。
     */
    public static function deleteByUrl(string $url, int $carId): void
    {
        $name = basename((string) parse_url($url, PHP_URL_PATH));
        if ($name === '' || !preg_match('/\A[0-9a-f]{32}\.(jpg|png|webp)\z/', $name)) {
            // こちらが付けた命名規則に合わないものは触らない。
            return;
        }
        $path = self::carDir($carId) . '/' . $name;
        if (is_file($path)) {
            @unlink($path);
        }
    }

    private static function carDir(int $carId): string
    {
        return rtrim(Config::get('IMG_UPLOAD_DIR'), '/') . '/' . $carId;
    }

    /**
     * 画像置き場で PHP が実行されないようにする。
     * 検証をすり抜けた細工ファイルが置かれても、実行されなければ被害にならない。
     */
    private static function protectDirectory(string $root): void
    {
        $htaccess = $root . '/.htaccess';
        if (is_file($htaccess)) {
            return;
        }
        @file_put_contents($htaccess, <<<'CONF'
        # 画像置き場。ここでスクリプトが動く理由は無いので全面的に止める。
        <FilesMatch "\.(php|phar|phtml|cgi|pl|py|sh|html|htm)$">
            Require all denied
        </FilesMatch>
        Options -Indexes -ExecCGI
        AddType text/plain .php .phtml .phar
        CONF);
    }

    /** PHP のアップロードエラーコードを日本語にする */
    private static function assertUploadOk(array $file): void
    {
        $error = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($error === UPLOAD_ERR_OK) {
            return;
        }
        throw new \RuntimeException(match ($error) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => '画像のサイズが大きすぎます。',
            UPLOAD_ERR_PARTIAL                        => '画像のアップロードが中断されました。',
            UPLOAD_ERR_NO_FILE                        => 'ファイルが選択されていません。',
            UPLOAD_ERR_NO_TMP_DIR, UPLOAD_ERR_CANT_WRITE => '画像を保存できませんでした（サーバー側の問題）。',
            UPLOAD_ERR_EXTENSION                      => '画像のアップロードが拒否されました。',
            default                                   => '画像のアップロードに失敗しました。',
        });
    }
}
