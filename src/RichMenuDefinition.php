<?php
/**
 * 3タブ式リッチメニューの定義。
 *
 * 確定したUI設計（タブA 車を探す／タブB 車を売る／タブC サポート）を
 * LINEのリッチメニューJSONに落としたもの。
 *
 * 座標の考え方：
 *   リッチメニュー画像は 2500 x 1686。UI設計では幅390pxに対して
 *   タブ帯54px＋ボタン area 210px = 264px なので、2500/390 = 6.41倍して
 *   タブ帯 346px、ボタン area 1340px（2段 x 670px）に割り当てる。
 *
 * タブの切替は richmenuswitch。エイリアスIDを参照するので、
 * メニューを作り直してもボタン定義を書き換えずに済む。
 */

declare(strict_types=1);

namespace App;

final class RichMenuDefinition
{
    public const WIDTH  = 2500;
    public const HEIGHT = 1686;

    /** タブ帯の高さ */
    private const TAB_H = 346;

    /** エイリアスID。LINE側で固定の名前として使う */
    public const ALIAS_FIND    = 'autobest-find';
    public const ALIAS_SELL    = 'autobest-sell';
    public const ALIAS_SUPPORT = 'autobest-support';

    /** @return array<string,array> エイリアスID => リッチメニュー定義 */
    public static function all(): array
    {
        return [
            self::ALIAS_FIND    => self::find(),
            self::ALIAS_SELL    => self::sell(),
            self::ALIAS_SUPPORT => self::support(),
        ];
    }

    private static function find(): array
    {
        return [
            'size'        => ['width' => self::WIDTH, 'height' => self::HEIGHT],
            'selected'    => true,   // 既定で開いた状態にする
            'name'        => 'AUTOBEST 車を探す',
            'chatBarText' => '車を探す',
            'areas'       => array_merge(
                self::tabAreas(self::ALIAS_FIND),
                self::gridAreas([
                    ['postback', 'action=cars&page=1',            '販売在庫を探す'],
                    ['postback', 'action=search_menu',            '条件から検索'],
                    ['postback', 'action=cars&page=1&sort=new',   '新着入庫'],
                    ['postback', 'action=favorites',              'お気に入り'],
                    ['postback', 'action=reserve',                '来店・商談予約'],
                    ['postback', 'action=stores',                 '福岡・神奈川の店舗'],
                ])
            ),
        ];
    }

    private static function sell(): array
    {
        return [
            'size'        => ['width' => self::WIDTH, 'height' => self::HEIGHT],
            'selected'    => false,
            'name'        => 'AUTOBEST 車を売る',
            'chatBarText' => '車を売る',
            'areas'       => array_merge(
                self::tabAreas(self::ALIAS_SELL),
                self::gridAreas([
                    ['postback', 'action=assessment',        '無料買取査定'],
                    ['postback', 'action=purchases&page=1',  '高額査定実績'],
                    ['postback', 'action=why_us',            '高額査定の理由'],
                    ['postback', 'action=flow',              '買取までの流れ'],
                    ['postback', 'action=documents',         '必要書類'],
                    ['postback', 'action=consult',           '査定について相談'],
                ])
            ),
        ];
    }

    private static function support(): array
    {
        $siteUrl = Config::get('SITE_URL', 'https://autobest.jp');

        return [
            'size'        => ['width' => self::WIDTH, 'height' => self::HEIGHT],
            'selected'    => false,
            'name'        => 'AUTOBEST サポート',
            'chatBarText' => 'サポート',
            'areas'       => array_merge(
                self::tabAreas(self::ALIAS_SUPPORT),
                self::gridAreas([
                    ['postback', 'action=faq',              'よくある質問'],
                    ['postback', 'action=contact',          '問い合わせ'],
                    ['postback', 'action=my_reservations',  '予約確認'],
                    ['postback', 'action=notify_settings',  '通知設定'],
                    ['postback', 'action=stores',           '会社・店舗案内'],
                    ['uri',      $siteUrl,                  'Webサイトを見る'],
                ])
            ),
        ];
    }

    /**
     * タブ3つ。押されたタブへ切り替える。
     * 現在開いているタブは自分自身へ切り替える（見た目が変わらず、実害もない）。
     */
    private static function tabAreas(string $currentAlias): array
    {
        $tabs = [
            [self::ALIAS_FIND,    0,    833],
            [self::ALIAS_SELL,    833,  833],
            [self::ALIAS_SUPPORT, 1666, 834],
        ];

        $areas = [];
        foreach ($tabs as [$alias, $x, $w]) {
            $areas[] = [
                'bounds' => ['x' => $x, 'y' => 0, 'width' => $w, 'height' => self::TAB_H],
                'action' => [
                    'type'            => 'richmenuswitch',
                    'richMenuAliasId' => $alias,
                    // タブ切替でも postback イベントが飛ぶ。
                    // WebhookHandler 側で tab= を見て黙って無視する。
                    'data'            => 'tab=' . $alias . ($alias === $currentAlias ? '&same=1' : ''),
                ],
            ];
        }
        return $areas;
    }

    /**
     * 3列2段の6ボタン。
     *
     * @param array<int,array{0:string,1:string,2:string}> $buttons [種別, data/uri, ラベル]
     */
    private static function gridAreas(array $buttons): array
    {
        $cols   = [[0, 833], [833, 833], [1666, 834]];
        $rowH   = (int) ((self::HEIGHT - self::TAB_H) / 2); // 670
        $areas  = [];

        foreach (array_slice($buttons, 0, 6) as $i => [$type, $value, $label]) {
            $col = $i % 3;
            $row = intdiv($i, 3);

            $action = $type === 'uri'
                ? ['type' => 'uri', 'label' => mb_substr($label, 0, 20), 'uri' => $value]
                : [
                    'type'        => 'postback',
                    'label'       => mb_substr($label, 0, 20),
                    'data'        => $value,
                    // 押した内容がトークに残ると、後から会話を追いやすい。
                    'displayText' => $label,
                ];

            $areas[] = [
                'bounds' => [
                    'x'      => $cols[$col][0],
                    'y'      => self::TAB_H + $row * $rowH,
                    'width'  => $cols[$col][1],
                    'height' => $rowH,
                ],
                'action' => $action,
            ];
        }
        return $areas;
    }
}
