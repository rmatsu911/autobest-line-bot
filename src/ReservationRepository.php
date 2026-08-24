<?php
/**
 * 来店・商談予約。
 *
 * 送信時点では必ず「仮予約（tentative）」。
 * 店側が第1〜第3希望を見て confirmed_at を入れ、確定にする。
 * 自動で確定させないのは、車両の状態確認や担当者の在席が要るため。
 *
 * line_user_id が NULL の行は素のWebフォームからの予約で、連絡先は contact_* に入る。
 */

declare(strict_types=1);

namespace App;

final class ReservationRepository
{
    public const STATUSES  = ['tentative', 'confirmed', 'changed', 'cancelled'];
    public const LOCATIONS = ['fukuoka', 'kanagawa'];
    public const PURPOSES  = ['visit', 'consult'];

    /** @param array<string, mixed> $input */
    public static function create(array $input): int
    {
        Db::exec(
            'INSERT INTO reservations
                (line_user_id, car_id, location, source, purpose, contact_name, contact_tel, contact_email,
                 preferred_1, preferred_2, preferred_3, note)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $input['line_user_id'] ?? null,
                $input['car_id'] ?? null,
                (string) ($input['location'] ?? 'fukuoka'),
                (string) ($input['source'] ?? 'web'),
                (string) ($input['purpose'] ?? 'visit'),
                $input['contact_name']  ?? null,
                $input['contact_tel']   ?? null,
                $input['contact_email'] ?? null,
                (string) $input['preferred_1'],
                $input['preferred_2'] ?? null,
                $input['preferred_3'] ?? null,
                $input['note'] ?? null,
            ]
        );

        return (int) Db::lastInsertId();
    }

    /**
     * 一覧。既定は「これから来る予約を早い順」。
     * 予約管理は過去を振り返る画面ではなく、次に何が来るかを見る画面なので、
     * 在庫や問い合わせと違って新しい順にはしない。
     *
     * @param array{status?:string,location?:string,assigned?:string,scope?:string} $filters
     * @return array{rows: array, total: int}
     */
    public static function search(array $filters, int $page, int $perPage = 20): array
    {
        $where  = [];
        $params = [];

        $status = (string) ($filters['status'] ?? '');
        if (in_array($status, self::STATUSES, true)) {
            $where[]  = 'r.status = ?';
            $params[] = $status;
        }

        $location = (string) ($filters['location'] ?? '');
        if (in_array($location, self::LOCATIONS, true)) {
            $where[]  = 'r.location = ?';
            $params[] = $location;
        }

        $assigned = (string) ($filters['assigned'] ?? '');
        if ($assigned === 'none') {
            $where[] = 'r.assigned_admin_id IS NULL';
        } elseif ($assigned !== '' && ctype_digit($assigned)) {
            $where[]  = 'r.assigned_admin_id = ?';
            $params[] = (int) $assigned;
        }

        // 期間の絞り込みは「確定日時があればそれ、無ければ第1希望」で判定する。
        // 日時をずらして確定した予約が、元の希望日のまま埋もれるのを防ぐため。
        $effective = 'COALESCE(r.confirmed_at, r.preferred_1)';
        $scope     = (string) ($filters['scope'] ?? 'upcoming');
        if ($scope === 'upcoming') {
            $where[] = $effective . ' >= ' . Db::nowSql();
        } elseif ($scope === 'past') {
            $where[] = $effective . ' < ' . Db::nowSql();
        }

        $whereSql = $where === [] ? '' : ' WHERE ' . implode(' AND ', $where);
        $total    = (int) Db::value('SELECT COUNT(*) FROM reservations r' . $whereSql, $params);

        $order = $scope === 'past' ? 'DESC' : 'ASC';
        $rows  = Db::paged(
            'SELECT r.*,
                    u.line_user_id AS line_uid, u.display_name,
                    c.maker AS car_maker, c.model_name AS car_model_name, c.stock_number AS car_stock_number,
                    a.display_name AS admin_display_name, a.login_id AS admin_login_id,
                    ' . $effective . ' AS effective_at
             FROM reservations r
             LEFT JOIN line_users  u ON u.id = r.line_user_id
             LEFT JOIN cars        c ON c.id = r.car_id
             LEFT JOIN admin_users a ON a.id = r.assigned_admin_id' . $whereSql . '
             ORDER BY ' . $effective . ' ' . $order . ', r.id ' . $order,
            $params,
            $perPage,
            ($page - 1) * $perPage
        );

        return ['rows' => $rows, 'total' => $total];
    }

    public static function find(int $id): ?array
    {
        return Db::one(
            'SELECT r.*,
                    u.line_user_id AS line_uid, u.display_name,
                    c.maker AS car_maker, c.model_name AS car_model_name, c.stock_number AS car_stock_number,
                    a.display_name AS admin_display_name, a.login_id AS admin_login_id
             FROM reservations r
             LEFT JOIN line_users  u ON u.id = r.line_user_id
             LEFT JOIN cars        c ON c.id = r.car_id
             LEFT JOIN admin_users a ON a.id = r.assigned_admin_id
             WHERE r.id = ?',
            [$id]
        );
    }

    /**
     * 日時を確定する。
     * status を呼び出し側に決めさせず、ここで一緒に変えるのは
     * 「確定日時は入っているのに status は仮予約のまま」という
     * 食い違いを作らないため。
     */
    public static function confirm(int $id, string $confirmedAt): void
    {
        $current = Db::one('SELECT confirmed_at FROM reservations WHERE id = ?', [$id]);
        // 一度確定したものを別の日時に変えたときは「変更」として残す。
        $status = ($current !== null && !empty($current['confirmed_at'])) ? 'changed' : 'confirmed';
        Db::exec('UPDATE reservations SET confirmed_at = ?, status = ? WHERE id = ?', [$confirmedAt, $status, $id]);
    }

    public static function updateStatus(int $id, string $status): void
    {
        if (!in_array($status, self::STATUSES, true)) {
            throw new \InvalidArgumentException('不正なステータスです');
        }
        // キャンセルに戻したら確定日時も消す。予定表に幽霊が残らないようにする。
        if ($status === 'cancelled') {
            Db::exec('UPDATE reservations SET status = ?, confirmed_at = NULL WHERE id = ?', [$status, $id]);
            return;
        }
        Db::exec('UPDATE reservations SET status = ? WHERE id = ?', [$status, $id]);
    }

    public static function assign(int $id, ?int $adminId): void
    {
        Db::exec('UPDATE reservations SET assigned_admin_id = ? WHERE id = ?', [$adminId, $id]);
    }

    public static function appendNote(int $id, string $line): void
    {
        $current = (string) (Db::value('SELECT note FROM reservations WHERE id = ?', [$id]) ?? '');
        $stamp   = (new \DateTimeImmutable('now'))->format('Y-m-d H:i');
        $name    = Auth::check() ? Auth::name() : '担当者';
        $next    = trim($current . "\n" . "[{$stamp} {$name}] " . $line);
        Db::exec('UPDATE reservations SET note = ? WHERE id = ?', [$next, $id]);
    }

    public static function delete(int $id): void
    {
        Db::exec('DELETE FROM reservations WHERE id = ?', [$id]);
    }

    /** @return array<string,int> */
    public static function countByStatus(): array
    {
        $counts = array_fill_keys(self::STATUSES, 0);
        foreach (Db::all('SELECT status, COUNT(*) AS c FROM reservations GROUP BY status') as $row) {
            $counts[(string) $row['status']] = (int) $row['c'];
        }
        return $counts;
    }

    /** 未確定のまま第1希望が近づいている件数。管理画面の見出しに出す。 */
    public static function pendingSoon(): int
    {
        $limit = Db::isSqlite()
            ? "datetime('now', '+9 hours', '+3 days')"
            : 'DATE_ADD(NOW(), INTERVAL 3 DAY)';

        return (int) Db::value(
            "SELECT COUNT(*) FROM reservations
             WHERE status = 'tentative' AND preferred_1 <= {$limit}"
        );
    }
}
