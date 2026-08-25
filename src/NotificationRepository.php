<?php
/**
 * 新着のお知らせ（お客様が押したときに見せる方式）。
 *
 * ★なぜ「自動で送る」のをやめたか
 *   LINE公式アカウントの無料プラン（コミュニケーションプラン）は
 *   月200通まで。しかも数に入るのは push / multicast / broadcast だけで、
 *   お客様の操作に対する reply は何通返しても無料。
 *   友だち100人に月2回通知すると400通で、その月はもう何も送れなくなる。
 *
 *   そこで「条件に合う新着が入ったら送る」のではなく、
 *   「条件を覚えておいて、押されたときにその人向けの新着を返す」形にした。
 *   通数を一切消費せず、条件の登録も在庫の追加も今までどおり行える。
 *
 * ★同じ車両を毎回見せないための仕組み
 *   見せた車両を notification_log に記録し、次からは除外する。
 *   この表はもともと「通知済み」を記録するために作ったもので、
 *   押されたときに見せる方式でもそのまま意味が通る。
 */

declare(strict_types=1);

namespace App;

final class NotificationRepository
{
    /** 何日前までを「新着」とみなすか。月1回しか開かない人でも取りこぼさない幅にする。 */
    private const WINDOW_DAYS = 60;

    /** 1回に見せる件数。カルーセルの上限に合わせる。 */
    public const PER_PAGE = 10;

    /**
     * その人の条件（1人1件で運用する）。
     * 表は複数行を許す形だが、トーク上で複数条件を出し入れさせると
     * 「いまどれが効いているか」が分からなくなるため、1件に絞っている。
     */
    public static function conditionFor(int $userRowId): ?array
    {
        return Db::one(
            'SELECT * FROM notification_conditions WHERE line_user_id = ? ORDER BY id LIMIT 1',
            [$userRowId]
        );
    }

    /** 条件が1つでも設定されているか（未設定なら全体の新着を見せる） */
    public static function hasCondition(int $userRowId): bool
    {
        $row = self::conditionFor($userRowId);
        if ($row === null || (int) $row['enabled'] !== 1) {
            return false;
        }
        foreach (['category', 'location', 'maker', 'keyword', 'price_max'] as $key) {
            if (($row[$key] ?? null) !== null && (string) $row[$key] !== '') {
                return true;
            }
        }
        return false;
    }

    /**
     * 条件を1項目だけ更新する。値が '' なら、その項目を外す。
     *
     * @param array<string, string> $changes category / location / maker / keyword / price_max
     */
    public static function updateCondition(int $userRowId, array $changes): void
    {
        $row = self::conditionFor($userRowId);
        if ($row === null) {
            Db::exec('INSERT INTO notification_conditions (line_user_id) VALUES (?)', [$userRowId]);
            $row = self::conditionFor($userRowId);
            if ($row === null) {
                return;
            }
        }

        $sets   = ['enabled = 1'];
        $params = [];

        foreach ($changes as $key => $value) {
            if (!in_array($key, ['category', 'location', 'maker', 'keyword', 'price_max'], true)) {
                continue;
            }
            // 空文字は「その条件を外す」の意味。NULL で入れる（CHECK制約もNULLは許している）。
            $sets[]   = $key . ' = ?';
            $params[] = $value === '' ? null : ($key === 'price_max' ? (int) $value : $value);
        }

        if (count($sets) === 1) {
            return;
        }

        $params[] = (int) $row['id'];
        Db::exec('UPDATE notification_conditions SET ' . implode(', ', $sets) . ' WHERE id = ?', $params);
    }

    /** 条件をすべて消す（行ごと消して「未設定」に戻す） */
    public static function clearCondition(int $userRowId): void
    {
        Db::exec('DELETE FROM notification_conditions WHERE line_user_id = ?', [$userRowId]);
    }

    /**
     * その人にまだ見せていない新着。
     *
     * @return array{rows: array, hasNext: bool}
     */
    public static function newArrivals(int $userRowId, int $perPage = self::PER_PAGE): array
    {
        $row = self::conditionFor($userRowId);

        $where  = ["c.status = 'published'", 'c.published_at IS NOT NULL'];
        $params = [];

        // 期間で区切る。これが無いと、初めて押した人に在庫全部が「新着」として出る。
        $where[] = 'c.published_at >= ' . (Db::isSqlite()
            ? "datetime('now', '+9 hours', '-" . self::WINDOW_DAYS . " days')"
            : 'DATE_SUB(NOW(), INTERVAL ' . self::WINDOW_DAYS . ' DAY)');

        // 見せた車両は次から出さない。NOT EXISTS にしているのは、
        // NOT IN (SELECT ...) だと車両が1台も無いときの挙動がドライバ間で揺れるため。
        $where[]  = 'NOT EXISTS (SELECT 1 FROM notification_log n WHERE n.line_user_id = ? AND n.car_id = c.id)';
        $params[] = $userRowId;

        if ($row !== null && (int) $row['enabled'] === 1) {
            if (!empty($row['category'])) {
                $where[]  = 'c.category = ?';
                $params[] = (string) $row['category'];
            }
            if (!empty($row['location'])) {
                $where[]  = 'c.location = ?';
                $params[] = (string) $row['location'];
            }
            if (!empty($row['maker'])) {
                // ESCAPE を明示する。SQLite は既定でエスケープ文字を持たないため、
                // 付けないと打ち消したはずの「%」がワイルドカードのまま残る。
                $where[]  = "c.maker LIKE ? ESCAPE '!'";
                $params[] = '%' . self::escapeLike((string) $row['maker']) . '%';
            }
            if (!empty($row['keyword'])) {
                $where[]  = "(c.maker LIKE ? ESCAPE '!' OR c.model_name LIKE ? ESCAPE '!')";
                $like     = '%' . self::escapeLike((string) $row['keyword']) . '%';
                $params[] = $like;
                $params[] = $like;
            }
            if (!empty($row['price_max'])) {
                // 応談の車両は上限で切らない（金額が決まっていないため）
                $where[]  = '(c.price_negotiable = 1 OR c.total_price <= ?)';
                $params[] = (int) $row['price_max'];
            }
        }

        $rows = Db::paged(
            'SELECT c.*,
                    (SELECT image_url FROM car_images WHERE car_id = c.id ORDER BY position, id LIMIT 1) AS thumb_url
             FROM cars c WHERE ' . implode(' AND ', $where) . '
             ORDER BY c.published_at DESC, c.id DESC',
            $params,
            $perPage + 1,
            0
        );

        return ['rows' => array_slice($rows, 0, $perPage), 'hasNext' => count($rows) > $perPage];
    }

    /**
     * 見せた車両を記録する。
     * 実際に画面に出した分だけを渡すこと（次ページ判定用に多めに取った1件を含めない）。
     *
     * @param array<int,int> $carIds
     */
    public static function markSeen(int $userRowId, array $carIds): void
    {
        if ($carIds === []) {
            return;
        }
        $sql = Db::isSqlite()
            ? 'INSERT OR IGNORE INTO notification_log (line_user_id, car_id) VALUES (?, ?)'
            : 'INSERT IGNORE INTO notification_log (line_user_id, car_id) VALUES (?, ?)';

        foreach ($carIds as $carId) {
            Db::exec($sql, [$userRowId, (int) $carId]);
        }
    }

    /** 条件を日本語1行にする。設定画面でいま何が効いているかを見せる。 */
    public static function conditionText(?array $row): string
    {
        if ($row === null) {
            return '未設定（すべての新着をお見せします）';
        }

        $parts = [];
        if (!empty($row['category'])) {
            $parts[] = car_category_label((string) $row['category']);
        }
        if (!empty($row['location'])) {
            $parts[] = car_location_label((string) $row['location']);
        }
        if (!empty($row['maker'])) {
            $parts[] = (string) $row['maker'];
        }
        if (!empty($row['keyword'])) {
            $parts[] = '「' . (string) $row['keyword'] . '」を含む';
        }
        if (!empty($row['price_max'])) {
            $parts[] = (int) round((int) $row['price_max'] / 10000) . '万円以下';
        }

        return $parts === [] ? '未設定（すべての新着をお見せします）' : implode(' / ', $parts);
    }

    /**
     * LIKE のワイルドカードを打ち消す。
     * 「%」を入力されたときに全件一致になるのを防ぐ。
     *
     * エスケープ文字にバックスラッシュを使わないのは、
     * SQL文字列リテラルの中でのバックスラッシュの扱いがドライバで違うため。
     *   MySQL  : '\\' は1文字のバックスラッシュ
     *   SQLite : '\\' は2文字のバックスラッシュ（ESCAPE には渡せない）
     * 「!」なら両方で同じ意味になり、SQL側に ESCAPE '!' と書くだけで済む。
     */
    private static function escapeLike(string $value): string
    {
        return str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $value);
    }
}
