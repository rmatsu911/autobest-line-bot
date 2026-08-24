<?php
/**
 * 管理ユーザーの参照。
 *
 * 作成・パスワード変更は bin/create_admin.php（CLI）でしか行わない。
 * 管理画面から管理者を増やせるようにすると、そこが乗っ取られた時点で
 * 攻撃者が正規の入口を作れてしまうため、あえて画面からは触れないようにしている。
 */

declare(strict_types=1);

namespace App;

final class AdminUserRepository
{
    /** 担当者の選択肢に使う。停止中（is_active=0）は出さない。 */
    public static function active(): array
    {
        return Db::all(
            'SELECT id, login_id, display_name FROM admin_users WHERE is_active = 1 ORDER BY id'
        );
    }

    /** 表示名。未設定ならログインIDで代用する。 */
    public static function label(array $admin): string
    {
        $name = (string) ($admin['display_name'] ?? '');
        return $name !== '' ? $name : (string) ($admin['login_id'] ?? '');
    }
}
