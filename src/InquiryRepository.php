<?php
declare(strict_types=1);

namespace App;

final class InquiryRepository
{
    public static function search(?string $status, ?string $kind, int $page, int $perPage = 20): array
    {
        $where = [];
        $params = [];

        if ($status !== null && $status !== '') {
            $where[] = 'i.status = ?';
            $params[] = $status;
        }
        if ($kind !== null && $kind !== '') {
            $where[] = 'i.kind = ?';
            $params[] = $kind;
        }

        $whereSql = $where === [] ? '' : ' WHERE ' . implode(' AND ', $where);
        $total = (int) Db::value('SELECT COUNT(*) FROM inquiries i' . $whereSql, $params);
        $rows = Db::paged(
            'SELECT i.*, u.line_user_id, u.display_name, c.maker AS car_maker, c.model_name AS car_model_name
             FROM inquiries i
             JOIN line_users u ON u.id = i.line_user_id
             LEFT JOIN cars c ON c.id = i.car_id' . $whereSql . '
             ORDER BY i.created_at DESC, i.id DESC',
            $params,
            $perPage,
            ($page - 1) * $perPage
        );

        return ['rows' => $rows, 'total' => $total];
    }

    public static function find(int $id): ?array
    {
        return Db::one('SELECT * FROM inquiries WHERE id = ?', [$id]);
    }

    public static function updateStatus(int $id, string $status): void
    {
        if (!in_array($status, ['new', 'in_progress', 'done'], true)) {
            throw new \InvalidArgumentException('不正なステータスです');
        }
        Db::exec('UPDATE inquiries SET status = ? WHERE id = ?', [$status, $id]);
    }

    public static function delete(int $id): void
    {
        Db::exec('DELETE FROM inquiries WHERE id = ?', [$id]);
    }

    public static function countByStatus(): array
    {
        $rows = Db::all('SELECT status, COUNT(*) AS c FROM inquiries GROUP BY status');
        $counts = ['new' => 0, 'in_progress' => 0, 'done' => 0];
        foreach ($rows as $row) {
            $counts[(string) $row['status']] = (int) $row['c'];
        }
        return $counts;
    }
}
