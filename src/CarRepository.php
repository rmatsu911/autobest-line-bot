<?php
/**
 * 在庫（cars / car_images）のデータアクセス。
 *
 * SQL をここに集約する。画面ファイルに SQL を書かないのは、
 * プリペアドステートメントの徹底をこの1ファイルの確認で担保できるようにするため。
 */

declare(strict_types=1);

namespace App;

final class CarRepository
{
    /** 入力を受け付ける列。ここに無いキーは無視される（意図しない列の書き換えを防ぐ） */
    private const FILLABLE = [
        'stock_number', 'category', 'location',
        'maker', 'model_name', 'grade', 'model_year', 'mileage_km', 'engine_hours',
        'total_price', 'body_price', 'price_negotiable',
        'inspection_until', 'body_color',
        'fuel', 'transmission', 'body_type', 'note', 'status', 'sort_order',
    ];

    // -------------------------------------------------------------------------
    // 参照
    // -------------------------------------------------------------------------

    /**
     * 管理画面の一覧。
     *
     * @return array{rows:array,total:int}
     */
    public static function search(?string $status, string $keyword, int $page, int $perPage = 20): array
    {
        $where  = [];
        $params = [];

        if ($status !== null && $status !== '') {
            $where[]  = 'status = ?';
            $params[] = $status;
        }
        if ($keyword !== '') {
            // LIKE のワイルドカードはユーザー入力側でエスケープする。
            // これをしないと「%」だけ入力されて全件一致になる。
            $escaped  = addcslashes($keyword, '%_\\');
            $where[]  = '(maker LIKE ? OR model_name LIKE ? OR grade LIKE ?)';
            $params[] = '%' . $escaped . '%';
            $params[] = '%' . $escaped . '%';
            $params[] = '%' . $escaped . '%';
        }

        $whereSql = $where === [] ? '' : ' WHERE ' . implode(' AND ', $where);

        $total = (int) Db::value('SELECT COUNT(*) FROM cars' . $whereSql, $params);

        $rows = Db::paged(
            'SELECT c.*,
                    (SELECT image_url FROM car_images WHERE car_id = c.id ORDER BY position, id LIMIT 1) AS thumb_url,
                    (SELECT COUNT(*)  FROM car_images WHERE car_id = c.id) AS image_count
             FROM cars c' . $whereSql . '
             ORDER BY c.sort_order, c.id DESC',
            $params,
            $perPage,
            ($page - 1) * $perPage
        );

        return ['rows' => $rows, 'total' => $total];
    }

    public static function find(int $id): ?array
    {
        return Db::one('SELECT * FROM cars WHERE id = ?', [$id]);
    }

    /** @return array<int,array> position 昇順 */
    public static function images(int $carId): array
    {
        return Db::all(
            'SELECT id, image_url, position FROM car_images WHERE car_id = ? ORDER BY position, id',
            [$carId]
        );
    }

    public static function countByStatus(): array
    {
        $rows   = Db::all('SELECT status, COUNT(*) AS c FROM cars GROUP BY status');
        $counts = ['draft' => 0, 'published' => 0, 'sold' => 0];
        foreach ($rows as $row) {
            $counts[(string) $row['status']] = (int) $row['c'];
        }
        return $counts;
    }

    // -------------------------------------------------------------------------
    // 更新
    // -------------------------------------------------------------------------

    public static function create(array $input): int
    {
        $data = self::filter($input);

        // 新規は末尾に置く。COALESCE は1件も無いときの NULL 対策。
        $data['sort_order'] = (int) Db::value('SELECT COALESCE(MAX(sort_order), 0) + 10 FROM cars');

        $columns = array_keys($data);
        // プレースホルダは列名から機械的に生成する。列名は FILLABLE 由来なので
        // 利用者入力が SQL 断片になることはない。値は必ずバインドする。
        $sql = 'INSERT INTO cars (' . implode(', ', $columns) . ')
                VALUES (' . implode(', ', array_map(static fn(string $c): string => ':' . $c, $columns)) . ')';

        Db::exec($sql, self::bindable($data));
        return Db::lastInsertId();
    }

    public static function update(int $id, array $input): void
    {
        $data = self::filter($input);
        if ($data === []) {
            return;
        }
        $sets = implode(', ', array_map(static fn(string $c): string => $c . ' = :' . $c, array_keys($data)));

        $params       = self::bindable($data);
        $params[':id'] = $id;

        Db::exec('UPDATE cars SET ' . $sets . ' WHERE id = :id', $params);
    }

    public static function updateStatus(int $id, string $status): void
    {
        if (!in_array($status, CarValidator::STATUSES, true)) {
            throw new \InvalidArgumentException('不正なステータスです');
        }

        // 初めて公開したときだけ published_at を入れる。
        // 公開→下書き→公開と往復するたびに更新すると、そのたび「新着」に戻り、
        // 同じ車両が繰り返し新着通知の対象になってしまう。
        Db::exec(
            'UPDATE cars SET status = ?, published_at = CASE
                WHEN ? = \'published\' AND published_at IS NULL THEN ' . Db::nowSql() . '
                ELSE published_at END
             WHERE id = ?',
            [$status, $status, $id]
        );
    }

    /**
     * LINEのカルーセル用。公開中の車両だけを返す。
     *
     * 下書き・承認待ち・商談中・成約済みは、URLを直接叩かれても出さない。
     * ここが「未承認在庫と公開終了在庫がLINE経由でも漏れない」ことの要。
     *
     * @param array{category?:string,location?:string,price_max?:int} $filters
     * @return array{rows:array,hasNext:bool}
     */
    public static function published(array $filters, int $page, int $perPage = 10): array
    {
        $where  = ["c.status = 'published'"];
        $params = [];

        if (!empty($filters['category']) && in_array($filters['category'], CarValidator::CATEGORIES, true)) {
            $where[]  = 'c.category = ?';
            $params[] = $filters['category'];
        }
        if (!empty($filters['location']) && in_array($filters['location'], CarValidator::LOCATIONS, true)) {
            $where[]  = 'c.location = ?';
            $params[] = $filters['location'];
        }
        if (!empty($filters['price_max'])) {
            // 応談の車両は上限で除外しない（金額が決まっていないため）
            $where[]  = '(c.price_negotiable = 1 OR c.total_price <= ?)';
            $params[] = (int) $filters['price_max'];
        }

        $whereSql = ' WHERE ' . implode(' AND ', $where);

        // 並び順は2種類だけ。利用者入力をそのままORDER BYに載せないよう、
        // 許可した文字列にしか分岐しない。
        $orderSql = ($filters['sort'] ?? '') === 'new'
            ? ' ORDER BY c.published_at DESC, c.id DESC'
            : ' ORDER BY c.sort_order, c.id DESC';

        // 次ページの有無を数えるため1件多く取る。COUNT(*) を別に投げるより1往復少ない。
        $rows = Db::paged(
            'SELECT c.*,
                    (SELECT image_url FROM car_images WHERE car_id = c.id ORDER BY position, id LIMIT 1) AS thumb_url
             FROM cars c' . $whereSql . $orderSql,
            $params,
            $perPage + 1,
            ($page - 1) * $perPage
        );

        $hasNext = count($rows) > $perPage;

        return ['rows' => array_slice($rows, 0, $perPage), 'hasNext' => $hasNext];
    }

    /** 公開中の1台。詳細ページ用。非公開ならnullを返す */
    public static function findPublished(int $id): ?array
    {
        return Db::one("SELECT * FROM cars WHERE id = ? AND status = 'published'", [$id]);
    }

    /** 車両と画像レコードを削除する（画像の実体は呼び出し側で消す） */
    public static function delete(int $id): void
    {
        // car_images は ON DELETE CASCADE なので cars を消せば連動して消える。
        Db::exec('DELETE FROM cars WHERE id = ?', [$id]);
    }

    // -------------------------------------------------------------------------
    // 画像
    // -------------------------------------------------------------------------

    public static function addImage(int $carId, string $url): void
    {
        $next = (int) Db::value('SELECT COALESCE(MAX(position), -1) + 1 FROM car_images WHERE car_id = ?', [$carId]);
        Db::exec('INSERT INTO car_images (car_id, image_url, position) VALUES (?, ?, ?)', [$carId, $url, $next]);
    }

    public static function findImage(int $imageId, int $carId): ?array
    {
        // car_id も条件に入れる。画像IDだけで消せると、
        // 別の車両の画像を削除するリクエストを作られてしまう。
        return Db::one('SELECT * FROM car_images WHERE id = ? AND car_id = ?', [$imageId, $carId]);
    }

    public static function deleteImage(int $imageId, int $carId): void
    {
        Db::exec('DELETE FROM car_images WHERE id = ? AND car_id = ?', [$imageId, $carId]);
    }

    /**
     * 並べ替え。position を 0 から振り直す。
     * 0 が代表画像になるので、先頭に来た画像がカルーセルのメイン写真になる。
     *
     * @param array<int,int> $positions [画像ID => 入力された並び順]
     */
    public static function reorderImages(int $carId, array $positions): void
    {
        $current = self::images($carId);
        if ($current === []) {
            return;
        }

        // 入力された順序で並べ替えたうえで 0,1,2... に詰め直す。
        // 利用者が「1,1,3」のように重複や飛びを入力しても破綻しない。
        $ordered = [];
        foreach ($current as $index => $image) {
            $id        = (int) $image['id'];
            $ordered[] = ['id' => $id, 'key' => $positions[$id] ?? ($index + 1000)];
        }
        usort($ordered, static fn(array $a, array $b): int => $a['key'] <=> $b['key']);

        Db::transaction(static function () use ($ordered, $carId): void {
            foreach ($ordered as $position => $row) {
                Db::exec(
                    'UPDATE car_images SET position = ? WHERE id = ? AND car_id = ?',
                    [$position, $row['id'], $carId]
                );
            }
        });
    }

    // -------------------------------------------------------------------------
    // 内部
    // -------------------------------------------------------------------------

    /** FILLABLE に載っている列だけを取り出し、空文字を NULL に正規化する */
    private static function filter(array $input): array
    {
        $data = [];
        foreach (self::FILLABLE as $column) {
            if (!array_key_exists($column, $input)) {
                continue;
            }
            $value = $input[$column];
            if (is_string($value)) {
                $value = trim($value);
            }
            // 空文字のまま INT 列に入れると 0 になり「0円」「0km」と表示されてしまう。
            // 未入力は NULL として保存し、表示側で「応談」「不明」に振り分ける。
            $data[$column] = ($value === '' || $value === null) ? null : $value;
        }
        return $data;
    }

    /** 名前付きプレースホルダ用にキーへ ':' を付ける */
    private static function bindable(array $data): array
    {
        $params = [];
        foreach ($data as $key => $value) {
            $params[':' . $key] = $value;
        }
        return $params;
    }
}
