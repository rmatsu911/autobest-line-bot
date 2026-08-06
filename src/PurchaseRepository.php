<?php
declare(strict_types=1);

namespace App;

final class PurchaseRepository
{
    public static function search(?string $published, string $keyword, int $page, int $perPage = 20): array
    {
        $where = [];
        $params = [];

        if ($published !== null && $published !== '') {
            $where[] = 'published = ?';
            $params[] = (int) $published;
        }
        if ($keyword !== '') {
            $escaped = addcslashes($keyword, '%_\\');
            $where[] = "(maker LIKE ? ESCAPE '\\' OR model_name LIKE ? ESCAPE '\\' OR area LIKE ? ESCAPE '\\')";
            $params[] = '%' . $escaped . '%';
            $params[] = '%' . $escaped . '%';
            $params[] = '%' . $escaped . '%';
        }

        $whereSql = $where === [] ? '' : ' WHERE ' . implode(' AND ', $where);
        $total = (int) Db::value('SELECT COUNT(*) FROM purchase_records' . $whereSql, $params);
        $rows = Db::paged(
            'SELECT * FROM purchase_records' . $whereSql . ' ORDER BY published DESC, purchased_on DESC, id DESC',
            $params,
            $perPage,
            ($page - 1) * $perPage
        );

        return ['rows' => $rows, 'total' => $total];
    }

    public static function find(int $id): ?array
    {
        return Db::one('SELECT * FROM purchase_records WHERE id = ?', [$id]);
    }

    public static function create(array $input): int
    {
        Db::exec(
            'INSERT INTO purchase_records
             (maker, model_name, model_year, mileage_km, purchase_price, area, purchased_on, note, published)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
            self::params($input)
        );
        return Db::lastInsertId();
    }

    public static function update(int $id, array $input): void
    {
        $params = self::params($input);
        $params[] = $id;
        Db::exec(
            'UPDATE purchase_records
             SET maker = ?, model_name = ?, model_year = ?, mileage_km = ?, purchase_price = ?,
                 area = ?, purchased_on = ?, note = ?, published = ?
             WHERE id = ?',
            $params
        );
    }

    public static function setPublished(int $id, bool $published): void
    {
        Db::exec('UPDATE purchase_records SET published = ? WHERE id = ?', [$published ? 1 : 0, $id]);
    }

    public static function delete(int $id): void
    {
        Db::exec('DELETE FROM purchase_records WHERE id = ?', [$id]);
    }

    public static function countByPublished(): array
    {
        $rows = Db::all('SELECT published, COUNT(*) AS c FROM purchase_records GROUP BY published');
        $counts = [0 => 0, 1 => 0];
        foreach ($rows as $row) {
            $counts[(int) $row['published']] = (int) $row['c'];
        }
        return $counts;
    }

    private static function params(array $input): array
    {
        return [
            trim((string) ($input['maker'] ?? '')),
            trim((string) ($input['model_name'] ?? '')),
            self::nullableInt($input['model_year'] ?? null),
            self::nullableInt($input['mileage_km'] ?? null),
            self::nullableInt($input['purchase_price'] ?? null),
            self::nullableString($input['area'] ?? null),
            self::nullableString($input['purchased_on'] ?? null),
            self::nullableString($input['note'] ?? null),
            !empty($input['published']) ? 1 : 0,
        ];
    }

    private static function nullableString(mixed $value): ?string
    {
        $value = trim((string) $value);
        return $value === '' ? null : $value;
    }

    private static function nullableInt(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }
        return (int) $value;
    }
}
