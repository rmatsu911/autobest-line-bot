<?php
/**
 * 管理操作の証跡。
 *
 * 要件書6章の「誰がいつ返信・変更・配信したか」を満たすためのもの。
 * storage/logs/ のテキストログと別にDBへ持つ理由は3つ。
 *   - 管理画面から検索できる（ログはSSHが要る）
 *   - ログのローテーションで消えない
 *   - 対象（車両ID・問い合わせID）で引ける
 *
 * 記録に失敗しても本来の操作は続行する。
 * 「証跡が残らなかったから在庫の公開もできない」では業務が止まるため。
 */

declare(strict_types=1);

namespace App;

final class AuditLog
{
    /**
     * @param string $action     'inquiry.assign' のように「対象.操作」で揃える
     * @param string $targetType 'car' / 'inquiry' / 'reservation' など
     * @param string $summary    人が読んで分かる一行（画面にそのまま出す）
     */
    public static function record(
        string $action,
        ?string $targetType = null,
        ?int $targetId = null,
        string $summary = '',
    ): void {
        try {
            Db::exec(
                'INSERT INTO audit_logs (admin_id, admin_name, action, target_type, target_id, summary, ip)
                 VALUES (?, ?, ?, ?, ?, ?, ?)',
                [
                    Auth::id(),
                    // 表示名を焼き込む。管理者を消しても「誰が」が残るようにするため。
                    Auth::check() ? Auth::name() : null,
                    $action,
                    $targetType,
                    $targetId,
                    mb_substr($summary, 0, 255),
                    self::clientIp(),
                ]
            );
        } catch (\Throwable $e) {
            Logger::error('監査ログを記録できませんでした', ['action' => $action, 'message' => $e->getMessage()]);
        }
    }

    /**
     * 一覧。絞り込みは対象種別と操作者のみ（それ以上は運用で使われない）。
     *
     * @return array{rows: array, total: int}
     */
    public static function search(?string $targetType, ?int $adminId, int $page, int $perPage = 50): array
    {
        $where  = [];
        $params = [];

        if ($targetType !== null && $targetType !== '') {
            $where[]  = 'target_type = ?';
            $params[] = $targetType;
        }
        if ($adminId !== null && $adminId > 0) {
            $where[]  = 'admin_id = ?';
            $params[] = $adminId;
        }

        $whereSql = $where === [] ? '' : ' WHERE ' . implode(' AND ', $where);
        $total    = (int) Db::value('SELECT COUNT(*) FROM audit_logs' . $whereSql, $params);
        $rows     = Db::paged(
            'SELECT * FROM audit_logs' . $whereSql . ' ORDER BY created_at DESC, id DESC',
            $params,
            $perPage,
            ($page - 1) * $perPage
        );

        return ['rows' => $rows, 'total' => $total];
    }

    /** ある対象の履歴だけを取る（問い合わせ詳細画面の右側に出す） */
    public static function forTarget(string $targetType, int $targetId, int $limit = 20): array
    {
        return Db::paged(
            'SELECT * FROM audit_logs WHERE target_type = ? AND target_id = ? ORDER BY id DESC',
            [$targetType, $targetId],
            $limit,
            0
        );
    }

    /**
     * リバースプロキシ配下でも実IPを拾う。
     * ただし X-Forwarded-For は簡単に詐称できるので、
     * これは「参考情報」であって認証や制限の根拠には使わない。
     */
    private static function clientIp(): ?string
    {
        $remote = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
        $xff    = (string) ($_SERVER['HTTP_X_FORWARDED_FOR'] ?? '');
        if ($xff !== '') {
            $first = trim(explode(',', $xff)[0]);
            if (filter_var($first, FILTER_VALIDATE_IP) !== false) {
                return mb_substr($first, 0, 45);
            }
        }
        return $remote === '' ? null : mb_substr($remote, 0, 45);
    }
}
