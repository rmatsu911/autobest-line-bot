# リッチメニュー画像の作り方

```bash
npm install                        # 初回のみ（playwright を入れる）
npx playwright install chromium    # 初回のみ（描画用のブラウザ）
node tools/richmenu/build.mjs
```

`bin/richmenu/{find,sell,support}.png` が出来る。そのまま

```bash
php bin/setup_richmenu.php          # 何が登録されるか表示するだけ
php bin/setup_richmenu.php --apply   # 実際に登録する
```

## 当たり判定とずれていないか確かめる

```bash
node tools/richmenu/build.mjs --overlay
```

`*-overlay.png` に赤い破線で当たり判定が重なって出る。
**白いカードが必ず枠の内側に収まっていること**を目で確かめる。
はみ出していると、隣のボタンが反応するメニューになる。
この確認用の画像はコミットしない（`.gitignore` 済み）。

## 中身を変えるとき

| 変えたいもの | 触る場所 |
|---|---|
| ボタンの文言・動作・座標 | `src/RichMenuDefinition.php`（**ここが唯一の正**） |
| アイコン | `tools/richmenu/icons.mjs` |
| 英字の副題・アイコンの割り当て | `tools/richmenu/build.mjs` の `BUTTONS` |
| 配色 | `tools/richmenu/build.mjs` の `THEME` |

**日本語のラベルは `build.mjs` に書かない。** `RichMenuDefinition` から読んでいる。
絵の文言と、押したときトークに出る文言（`displayText`）がずれると、
「押したボタンと違うものが出た」ように見えるため。

## 環境変数

| 変数 | 用途 |
|---|---|
| `CHROMIUM_PATH` | 既にあるChromiumを使う（`npx playwright install` を避けたいとき） |

## Xserverでは動かさない

これは開発機で画像を作るための道具。
出来たPNGだけをサーバーに置けばよく、Node.jsもplaywrightもサーバーには要らない。
