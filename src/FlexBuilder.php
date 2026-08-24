<?php
/**
 * 在庫カルーセル（Flex Message）の組み立て。
 *
 * 配色・構成は確定したUI設計に合わせている。
 *   タブA「車を探す」  #4FA3F0 / ボタン #1268C4
 *   価格             #C85A0E
 *   新着バッジ        #FFB020 に濃紺文字
 *
 * Flexは「送ってみないと分からない」壊れ方をするため、
 * 長い車種名・価格応談・写真なし・稼働時間表示のいずれでも
 * 崩れないことを前提に組む（テストで確認済み）。
 */

declare(strict_types=1);

namespace App;

final class FlexBuilder
{
    /** carousel に入れられるバブルの上限（LINEの仕様） */
    public const MAX_BUBBLES = 12;

    /** 1ページに載せる車両数。残り1枠を「もっと見る」に使う */
    public const PER_PAGE = 10;

    // UI設計の配色
    private const NAVY     = '#16283F';
    private const BLUE     = '#1268C4';
    private const BLUE_LT  = '#4FA3F0';
    private const PRICE    = '#C85A0E';
    private const AMBER    = '#FFB020';
    private const MUTED    = '#5A5F68';
    private const LINE_CLR = '#E4E1DA';

    /**
     * 在庫カルーセルを組み立てる。
     *
     * @param array<int,array> $cars  この1ページ分の車両（PER_PAGE件まで）
     * @param int   $page             現在のページ（1始まり）
     * @param bool  $hasNext          次ページがあるか
     * @param string $params          ページ送りに引き継ぐ絞り込み（例 "category=truck"）
     */
    public static function carousel(array $cars, int $page, bool $hasNext, string $params = ''): array
    {
        $bubbles = [];
        foreach (array_slice($cars, 0, self::PER_PAGE) as $car) {
            $bubbles[] = self::carBubble($car);
        }

        if ($hasNext && count($bubbles) < self::MAX_BUBBLES) {
            $bubbles[] = self::moreBubble($page + 1, $params);
        }

        return [
            'type'     => 'flex',
            // 通知一覧やトーク一覧に出る代替テキスト。Flexが表示できない端末でも用件が分かるようにする。
            'altText'  => '販売中のお車（' . count($cars) . '台）',
            'contents' => ['type' => 'carousel', 'contents' => $bubbles],
        ];
    }

    /** 車両1台分のバブル */
    private static function carBubble(array $car): array
    {
        $carId = (int) $car['id'];
        $title = trim(($car['maker'] ?? '') . ' ' . ($car['model_name'] ?? ''));
        $grade = trim((string) ($car['grade'] ?? ''));
        if ($grade !== '') {
            $title .= ' ' . $grade;
        }

        $body = [
            'type'     => 'box',
            'layout'   => 'vertical',
            'spacing'  => 'sm',
            'paddingAll' => '14px',
            'contents' => [
                // 車名。長いものがあるので2行までで省略する（wrap+maxLines）。
                [
                    'type'    => 'text',
                    'text'    => $title !== '' ? $title : '（車名未設定）',
                    'weight'  => 'bold',
                    'size'    => 'md',
                    'wrap'    => true,
                    'maxLines' => 2,
                    'color'   => self::NAVY,
                ],
                [
                    'type'  => 'text',
                    'text'  => self::specLine($car),
                    'size'  => 'xs',
                    'color' => self::MUTED,
                    'wrap'  => true,
                ],
                ['type' => 'separator', 'margin' => 'md', 'color' => self::LINE_CLR],
                // 価格と拠点。価格応談でも桁が伸びないので横並びで崩れない。
                [
                    'type'   => 'box',
                    'layout' => 'horizontal',
                    'margin' => 'md',
                    'alignItems' => 'flex-end',
                    'contents' => [
                        [
                            'type'   => 'box',
                            'layout' => 'vertical',
                            'flex'   => 3,
                            'contents' => [
                                ['type' => 'text', 'text' => self::priceLabel($car), 'size' => 'xxs', 'color' => self::MUTED],
                                [
                                    'type'   => 'text',
                                    'text'   => car_price_short($car),
                                    'size'   => 'xl',
                                    'weight' => 'bold',
                                    'color'  => self::PRICE,
                                    'wrap'   => true,
                                ],
                            ],
                        ],
                        [
                            'type'   => 'text',
                            'text'   => car_location_label($car['location'] ?? null),
                            'size'   => 'xxs',
                            'color'  => self::MUTED,
                            'align'  => 'end',
                            'flex'   => 2,
                            'gravity' => 'bottom',
                        ],
                    ],
                ],
            ],
        ];

        $footer = [
            'type'     => 'box',
            'layout'   => 'vertical',
            'spacing'  => 'sm',
            'paddingAll' => '12px',
            'paddingTop' => '0px',
            'contents' => [
                [
                    'type'   => 'button',
                    'style'  => 'primary',
                    'height' => 'sm',
                    'color'  => self::BLUE,
                    'action' => [
                        'type'  => 'uri',
                        'label' => '詳細を見る',
                        'uri'   => self::carUrl($carId),
                    ],
                ],
                [
                    'type'   => 'box',
                    'layout' => 'horizontal',
                    'spacing' => 'sm',
                    'contents' => [
                        [
                            'type'   => 'button',
                            'style'  => 'link',
                            'height' => 'sm',
                            'action' => [
                                'type'        => 'postback',
                                'label'       => '問い合わせ',
                                'data'        => 'action=inquiry&car_id=' . $carId,
                                'displayText' => 'この車について問い合わせます',
                            ],
                        ],
                        [
                            'type'   => 'button',
                            'style'  => 'link',
                            'height' => 'sm',
                            'action' => [
                                'type'  => 'postback',
                                'label' => 'お気に入り',
                                'data'  => 'action=fav&car_id=' . $carId,
                                // お気に入りはトーク画面に残す必要がないので displayText を付けない
                            ],
                        ],
                    ],
                ],
            ],
        ];

        $bubble = ['type' => 'bubble', 'body' => $body, 'footer' => $footer];

        $hero = self::hero($car);
        if ($hero !== null) {
            $bubble['hero'] = $hero;
        } elseif (car_is_new($car)) {
            // 写真がまだ無い新着車両。バッジは hero に重ねているので、
            // hero が無いと新着だと分からなくなる。本文の先頭に出す。
            array_unshift($bubble['body']['contents'], [
                'type'            => 'box',
                'layout'          => 'vertical',
                'backgroundColor' => self::AMBER,
                'cornerRadius'    => '4px',
                'paddingAll'      => '4px',
                'width'           => '52px',
                'contents'        => [
                    ['type' => 'text', 'text' => '新着', 'size' => 'xxs', 'weight' => 'bold', 'color' => self::NAVY, 'align' => 'center'],
                ],
            ]);
        }

        return $bubble;
    }

    /**
     * 代表画像。無い場合は hero を付けず、body だけのバブルにする。
     * 存在しないURLを指すと LINE 側で画像枠が崩れるため、
     * 「写真準備中」の画像を用意するより出さない方が安全。
     */
    private static function hero(array $car): ?array
    {
        $url = trim((string) ($car['thumb_url'] ?? ''));
        if ($url === '' || !str_starts_with($url, 'https://')) {
            return null;
        }

        $hero = [
            'type'        => 'image',
            'url'         => $url,
            'size'        => 'full',
            'aspectRatio' => '20:13',
            'aspectMode'  => 'cover',
            'action'      => ['type' => 'uri', 'label' => '詳細', 'uri' => self::carUrl((int) $car['id'])],
        ];

        // 新着バッジは hero に重ねず、body 側の1行目に出すと折り返しで崩れるため
        // hero の上にオーバーレイとして載せる。
        if (car_is_new($car)) {
            return [
                'type'   => 'box',
                'layout' => 'vertical',
                'paddingAll' => '0px',
                'contents' => [
                    $hero,
                    [
                        'type'            => 'box',
                        'layout'          => 'vertical',
                        'position'        => 'absolute',
                        'offsetTop'       => '10px',
                        'offsetStart'     => '10px',
                        'backgroundColor' => self::AMBER,
                        'cornerRadius'    => '4px',
                        'paddingAll'      => '4px',
                        'paddingStart'    => '8px',
                        'paddingEnd'      => '8px',
                        'width'           => '52px',
                        'contents'        => [
                            ['type' => 'text', 'text' => '新着', 'size' => 'xxs', 'weight' => 'bold', 'color' => self::NAVY, 'align' => 'center'],
                        ],
                    ],
                ],
            ];
        }

        return $hero;
    }

    /** 「2021年 ／ 4.8万km ／ AT」のような1行。重機は稼働時間になる */
    private static function specLine(array $car): string
    {
        $parts = array_filter([
            model_year($car['model_year'] ?? null) !== '不明' ? model_year($car['model_year'] ?? null) : null,
            car_usage_text($car),
            trim((string) ($car['transmission'] ?? '')) !== '' ? (string) $car['transmission'] : null,
        ]);
        return $parts === [] ? '詳細はページをご覧ください' : implode(' ／ ', $parts);
    }

    private static function priceLabel(array $car): string
    {
        return (int) ($car['price_negotiable'] ?? 0) === 1 ? '価格' : '支払総額';
    }

    /** 「もっと見る」バブル。次ページを同じ postback で引く */
    private static function moreBubble(int $nextPage, string $params): array
    {
        $data = 'action=cars&page=' . $nextPage . ($params !== '' ? '&' . $params : '');

        return [
            'type'   => 'bubble',
            'body'   => [
                'type'   => 'box',
                'layout' => 'vertical',
                'justifyContent' => 'center',
                'alignItems'     => 'center',
                'spacing'        => 'md',
                'paddingAll'     => '20px',
                'contents' => [
                    ['type' => 'text', 'text' => 'ほかにも', 'size' => 'sm', 'color' => self::MUTED, 'align' => 'center'],
                    ['type' => 'text', 'text' => 'お車があります', 'size' => 'md', 'weight' => 'bold', 'color' => self::NAVY, 'align' => 'center', 'wrap' => true],
                ],
            ],
            'footer' => [
                'type'   => 'box',
                'layout' => 'vertical',
                'paddingAll' => '12px',
                'contents' => [
                    [
                        'type'   => 'button',
                        'style'  => 'primary',
                        'height' => 'sm',
                        'color'  => self::BLUE_LT,
                        'action' => [
                            'type'        => 'postback',
                            'label'       => 'もっと見る',
                            'data'        => $data,
                            'displayText' => 'もっと見る',
                        ],
                    ],
                ],
            ],
        ];
    }

    /**
     * 該当0件のときの返信。
     * 「ありません」で終わらせず、条件を変える導線をクイックリプライで出す。
     */
    public static function emptyResult(string $conditionText = ''): array
    {
        $text = $conditionText === ''
            ? "現在該当する車両がありません。\n条件を変えてお探しください。"
            : "「{$conditionText}」に該当する車両がありません。\n条件を変えてお探しください。";

        return LineClient::text($text, LineClient::quickReply([
            ['label' => 'すべての在庫',   'data' => 'action=cars&page=1'],
            ['label' => '乗用車・軽',     'data' => 'action=cars&page=1&category=passenger'],
            ['label' => 'トラック・バス', 'data' => 'action=cars&page=1&category=truck'],
            ['label' => '重機・作業車',   'data' => 'action=cars&page=1&category=machinery'],
            ['label' => '福岡本社',       'data' => 'action=cars&page=1&location=fukuoka'],
            ['label' => '神奈川支店',     'data' => 'action=cars&page=1&location=kanagawa'],
        ]));
    }

    /** 車両詳細ページのURL。LINE内ブラウザで開く */
    public static function carUrl(int $carId): string
    {
        return rtrim(Config::get('BOT_BASE_URL', 'https://bot.autobest.jp'), '/') . '/car.php?id=' . $carId;
    }
}
