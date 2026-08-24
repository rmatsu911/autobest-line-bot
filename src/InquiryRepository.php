<?php
/**
 * 問い合わせ（査定申込・在庫問い合わせ・来店相談）。
 *
 * フェーズ4で「誰が担当し、どう対応したか」まで追えるようにした。
 *   assigned_admin_id … 担当者
 *   inquiry_notes     … 対応履歴（電話した・見積を送った）
 *   inquiry_images    … 査定写真（公開領域には置かない。AssessmentImage を参照）
 *
 * line_user_id が NULL の行は、LINEを経由しない素のWebフォームからの申込。
 * 連絡先は contact_* 列に入る。
 */

declare(strict_types=1);

namespace App;

final class InquiryRepository
{
    public const KINDS    = ['assessment', 'car', 'visit'];
    public const STATUSES = ['new', 'in_progress', 'done'];
    public const SOURCES  = ['line', 'web', 'liff'];

    /**
     * 新規登録。返すのは採番されたID（写真の保存先に使う）。
     *
     * @param array<string, mixed> $input
     */
    public static function create(array $input): int
    {
        $payload = $input['payload'] ?? null;
        if (is_array($payload)) {
            // JSON_UNESCAPED_UNICODE を付けないと日本語が \uXXXX になり、
            // 管理画面でそのまま出したときに読めなくなる。
            // JSON_INVALID_UTF8_SUBSTITUTE を付けるのは、壊れたバイト列が1つ混ざると
            // json_encode が false を返し、申込内容がまるごと空で保存されてしまうため。
            $encoded = json_encode(
                $payload,
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE
            );
            if ($encoded === false) {
                Logger::error('申込内容をJSONにできませんでした', ['json_error' => json_last_error_msg()]);
                // 形を変えてでも中身は残す。査定の申込内容が消える方が困る。
                $encoded = json_encode(['変換できなかった内容' => print_r($payload, true)], JSON_UNESCAPED_UNICODE);
            }
            $payload = $encoded;
        }

        Db::exec(
            'INSERT INTO inquiries
                (line_user_id, car_id, kind, source, contact_name, contact_tel, contact_email, contact_pref, payload, message)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $input['line_user_id'] ?? null,
                $input['car_id'] ?? null,
                (string) ($input['kind'] ?? 'car'),
                (string) ($input['source'] ?? 'web'),
                $input['contact_name']  ?? null,
                $input['contact_tel']   ?? null,
                $input['contact_email'] ?? null,
                $input['contact_pref']  ?? null,
                $payload,
                $input['message'] ?? null,
            ]
        );

        return (int) Db::lastInsertId();
    }

    /**
     * 一覧。
     *
     * @param array{status?:string,kind?:string,assigned?:string} $filters
     * @return array{rows: array, total: int}
     */
    public static function search(array $filters, int $page, int $perPage = 20): array
    {
        $where  = [];
        $params = [];

        $status = (string) ($filters['status'] ?? '');
        if (in_array($status, self::STATUSES, true)) {
            $where[]  = 'i.status = ?';
            $params[] = $status;
        }

        $kind = (string) ($filters['kind'] ?? '');
        if (in_array($kind, self::KINDS, true)) {
            $where[]  = 'i.kind = ?';
            $params[] = $kind;
        }

        // 'none' は「担当者未定」。数値は担当者ID。
        $assigned = (string) ($filters['assigned'] ?? '');
        if ($assigned === 'none') {
            $where[] = 'i.assigned_admin_id IS NULL';
        } elseif ($assigned !== '' && ctype_digit($assigned)) {
            $where[]  = 'i.assigned_admin_id = ?';
            $params[] = (int) $assigned;
        }

        $whereSql = $where === [] ? '' : ' WHERE ' . implode(' AND ', $where);
        $total    = (int) Db::value('SELECT COUNT(*) FROM inquiries i' . $whereSql, $params);

        $rows = Db::paged(
            'SELECT i.*,
                    u.line_user_id AS line_uid, u.display_name,
                    c.maker AS car_maker, c.model_name AS car_model_name,
                    a.display_name AS admin_display_name, a.login_id AS admin_login_id,
                    (SELECT COUNT(*) FROM inquiry_images x WHERE x.inquiry_id = i.id) AS image_count,
                    (SELECT COUNT(*) FROM inquiry_notes  n WHERE n.inquiry_id = i.id) AS note_count
             FROM inquiries i
             LEFT JOIN line_users  u ON u.id = i.line_user_id
             LEFT JOIN cars        c ON c.id = i.car_id
             LEFT JOIN admin_users a ON a.id = i.assigned_admin_id' . $whereSql . '
             ORDER BY i.created_at DESC, i.id DESC',
            $params,
            $perPage,
            ($page - 1) * $perPage
        );

        return ['rows' => $rows, 'total' => $total];
    }

    /** 詳細。一覧と同じ体裁で取れるよう、JOIN の結果も一緒に返す。 */
    public static function find(int $id): ?array
    {
        return Db::one(
            'SELECT i.*,
                    u.line_user_id AS line_uid, u.display_name,
                    c.maker AS car_maker, c.model_name AS car_model_name, c.stock_number AS car_stock_number,
                    a.display_name AS admin_display_name, a.login_id AS admin_login_id
             FROM inquiries i
             LEFT JOIN line_users  u ON u.id = i.line_user_id
             LEFT JOIN cars        c ON c.id = i.car_id
             LEFT JOIN admin_users a ON a.id = i.assigned_admin_id
             WHERE i.id = ?',
            [$id]
        );
    }

    public static function updateStatus(int $id, string $status): void
    {
        if (!in_array($status, self::STATUSES, true)) {
            throw new \InvalidArgumentException('不正なステータスです');
        }
        Db::exec('UPDATE inquiries SET status = ? WHERE id = ?', [$status, $id]);
    }

    /** 担当者を設定する。null で「担当者未定」に戻す。 */
    public static function assign(int $id, ?int $adminId): void
    {
        Db::exec('UPDATE inquiries SET assigned_admin_id = ? WHERE id = ?', [$adminId, $id]);
    }

    /**
     * 削除。
     * inquiry_notes / inquiry_images はFKのCASCADEで消えるが、
     * 写真の実体はDBの外にあるので先に消す（消し忘れると個人情報が残り続ける）。
     */
    public static function delete(int $id): void
    {
        AssessmentImage::deleteAll($id);
        Db::exec('DELETE FROM inquiries WHERE id = ?', [$id]);
    }

    // -------------------------------------------------------------------------
    // 対応履歴
    // -------------------------------------------------------------------------

    /** @return array<int, array<string, mixed>> */
    public static function notes(int $inquiryId): array
    {
        return Db::all(
            'SELECT * FROM inquiry_notes WHERE inquiry_id = ? ORDER BY created_at, id',
            [$inquiryId]
        );
    }

    /**
     * 対応履歴を1件足す。
     * admin_name を一緒に焼き込むのは、後で管理者を削除しても
     * 「誰が対応したか」が残るようにするため。
     */
    public static function addNote(int $inquiryId, string $body, string $kind = 'note'): void
    {
        if (!in_array($kind, ['note', 'call', 'reply', 'status'], true)) {
            $kind = 'note';
        }
        Db::exec(
            'INSERT INTO inquiry_notes (inquiry_id, admin_id, admin_name, kind, body) VALUES (?, ?, ?, ?, ?)',
            [$inquiryId, Auth::id(), Auth::check() ? Auth::name() : null, $kind, $body]
        );
    }

    public static function deleteNote(int $noteId, int $inquiryId): void
    {
        Db::exec('DELETE FROM inquiry_notes WHERE id = ? AND inquiry_id = ?', [$noteId, $inquiryId]);
    }

    // -------------------------------------------------------------------------

    /** @return array{new:int,in_progress:int,done:int} */
    public static function countByStatus(): array
    {
        $counts = ['new' => 0, 'in_progress' => 0, 'done' => 0];
        foreach (Db::all('SELECT status, COUNT(*) AS c FROM inquiries GROUP BY status') as $row) {
            $counts[(string) $row['status']] = (int) $row['c'];
        }
        return $counts;
    }
}
