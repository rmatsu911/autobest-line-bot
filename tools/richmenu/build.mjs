/**
 * リッチメニュー画像（2500 x 1686）を生成する。
 *
 *   node tools/richmenu/build.mjs            … 3枚を bin/richmenu/ に出力
 *   node tools/richmenu/build.mjs --overlay  … 当たり判定を重ねた確認用画像も出す
 *
 * ★この作り方にしている理由
 *   リッチメニューは「画像」と「当たり判定（areas）」が別物で、
 *   LINE側は両者が一致しているかを検査しない。
 *   ずれていても登録は通り、押しても反応しないメニューが出来上がる。
 *   そこで座標を src/RichMenuDefinition.php から読み出し、
 *   同じ数値で絵を描くことで、ずれようがない状態にしている。
 *
 *   セルは CSS grid の gap ではなく position:absolute で置く。
 *   gap や border は小数の丸めで1pxずつずれることがあり、
 *   6枚のセルが少しずつ当たり判定からはみ出すため。
 */

import { execFileSync } from 'node:child_process';
import { chromium } from 'playwright';
import { icons } from './icons.mjs';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..');
const OUT = path.join(ROOT, 'bin/richmenu');
const CACHE = path.join(ROOT, 'storage/tmp/fonts');
const overlay = process.argv.includes('--overlay');

// LINEの制限。これを外すと登録時に弾かれる。
const LIMITS = { width: 2500, height: 1686, maxBytes: 1024 * 1024 };

// -----------------------------------------------------------------------------
// 1) 当たり判定を PHP 側の定義から読む（絵と判定の唯一の正）
// -----------------------------------------------------------------------------
function loadAreas() {
  const php = `
    define("APP_ROOT", ${JSON.stringify(ROOT)});
    require APP_ROOT . "/config/config.php";
    echo json_encode(App\\RichMenuDefinition::all(), JSON_UNESCAPED_UNICODE);
  `;
  const json = execFileSync('php', ['-r', php], { encoding: 'utf8', cwd: ROOT });
  return JSON.parse(json);
}

// -----------------------------------------------------------------------------
// 2) 日本語フォント
//    ダウンロードして base64 で埋め込む。<link> で読ませると、
//    描画の瞬間にまだ落ちてきておらず豆腐（□）のまま撮れることがある。
// -----------------------------------------------------------------------------
async function fontFaces() {
  fs.mkdirSync(CACHE, { recursive: true });
  const weights = [500, 700, 900];
  const faces = [];

  for (const w of weights) {
    const file = path.join(CACHE, `noto-sans-jp-${w}.ttf`);
    if (!fs.existsSync(file)) {
      try {
        const css = await fetch(
          `https://fonts.googleapis.com/css2?family=Noto+Sans+JP:wght@${w}&display=swap`,
          { headers: { 'User-Agent': 'Mozilla/5.0' } }
        ).then((r) => r.text());
        const url = css.match(/url\((https:[^)]+)\)/)?.[1];
        if (!url) throw new Error('フォントのURLを取れませんでした');
        const buf = Buffer.from(await fetch(url).then((r) => r.arrayBuffer()));
        fs.writeFileSync(file, buf);
      } catch (e) {
        console.warn(`  警告: Noto Sans JP ${w} を取得できませんでした（${e.message}）`);
        console.warn('        システムのゴシック体で描画します。見た目が多少変わります。');
        return '';
      }
    }
    const b64 = fs.readFileSync(file).toString('base64');
    faces.push(
      `@font-face{font-family:'NotoJP';font-weight:${w};font-style:normal;` +
      `src:url(data:font/ttf;base64,${b64}) format('truetype');}`
    );
  }
  return faces.join('\n');
}

// -----------------------------------------------------------------------------
// 3) 画面ごとの中身
// -----------------------------------------------------------------------------
const THEME = {
  'autobest-find':    { panel: '#4FA3F0', sub: '#1268C4', label: '車を探す',  en: 'FIND A CAR',      tabIcon: 'tabFind' },
  'autobest-sell':    { panel: '#FF8A2B', sub: '#C85A0E', label: '車を売る',  en: 'SELL',            tabIcon: 'tabSell' },
  'autobest-support': { panel: '#2FC4D6', sub: '#0B7F91', label: 'サポート',  en: 'SUPPORT',         tabIcon: 'tabSupport' },
};
const TAB_ORDER = ['autobest-find', 'autobest-sell', 'autobest-support'];

// 6ボタンの英字とアイコン。並びは RichMenuDefinition の gridAreas と同じ順。
// ★日本語のラベルはここに書かない。
//   絵に書いた文言と、押したときトークに出る文言（displayText）がずれると
//   「押したボタンと違うものが出た」ように見えるため、定義側から読む。
const BUTTONS = {
  'autobest-find': [
    ['STOCK LIST',   'car'],
    ['SEARCH',       'filter'],
    ['NEW ARRIVALS', 'sparkleCar'],
    ['FAVORITES',    'heart'],
    ['BOOKING',      'calendar'],
    ['STORES',       'shop'],
  ],
  'autobest-sell': [
    ['VALUATION',    'yenCar'],
    ['RESULTS',      'chartUp'],
    ['WHY US',       'medal'],
    ['HOW TO',       'steps'],
    ['DOCUMENTS',    'document'],
    ['CONSULT',      'chat'],
  ],
  'autobest-support': [
    ['FAQ',          'faq'],
    ['CONTACT',      'mail'],
    ['MY BOOKING',   'calendarCheck'],
    ['ALERTS',       'bell'],
    ['INFORMATION',  'building'],
    ['WEBSITE',      'globe'],
  ],
};

const svg = (body, size) =>
  `<svg width="${size}" height="${size}" viewBox="0 0 32 32" fill="none">${body}</svg>`;

function buildHtml(alias, menu, faces) {
  const theme = THEME[alias];
  const areas = menu.areas;
  const tabs = areas.slice(0, 3);
  const cells = areas.slice(3, 9);

  const tabHtml = tabs.map((a, i) => {
    const key = TAB_ORDER[i];
    const t = THEME[key];
    const on = key === alias;
    const { x, y, width, height } = a.bounds;
    // 選択中のタブだけ上端まで伸ばし、残りは少し下げて「奥にある」ことを示す
    // 3つとも上端で揃える。
    // 非選択タブを下げて「奥にある」ことを示す案は、実際にLINEで表示すると
    // 幅400px程度まで縮むため、段差が「意図しない余白」にしか見えなかった。
    // 選択中かどうかは、地の色と上端の白い印だけで区別する。
    const top = y;
    const h = height;
    // 隙間は見た目だけ。当たり判定(bounds)は隙間なく敷き詰めてあるので、
    // 境目を押しても必ずどれかのタブが反応する。
    const gap = 5;
    return `
      <div style="position:absolute;left:${x + gap}px;top:${top}px;
                  width:${width - gap * 2}px;height:${h}px;
                  background:${on ? t.panel : '#DFDCD4'};
                  border-radius:0;
                  display:flex;align-items:center;justify-content:center;gap:26px;">
        ${'' /* 選択中を示す白い印は置かない。
                上端で切れて「白い余白」に見えるうえ、地の色（テーマ色か #DFDCD4 か）
                だけで十分に見分けがつく。 */}
        ${svg(icons[t.tabIcon](on ? '#16283F' : '#5A6069'), on ? 92 : 84)}
        <div>
          <div style="color:${on ? '#FFFFFF' : '#5A6069'};font-size:56px;font-weight:900;line-height:1.15;">${t.label}</div>
          <div style="color:${on ? '#FFD27A' : '#6E727A'};font-size:30px;font-weight:700;letter-spacing:.1em;">${t.en}</div>
        </div>
      </div>`;
  }).join('');

  const pad = 34;   // セル内側の余白。当たり判定はセル全体なので、見た目だけ縮める
  const cellHtml = cells.map((a, i) => {
    const [en, icon] = BUTTONS[alias][i];
    const ja = a.action.label;                       // 定義側の文言をそのまま使う
    // 長い文言は字を詰める。はみ出して改行されると、行が増えてセルから溢れる。
    const fs = ja.length >= 9 ? 50 : ja.length >= 7 ? 56 : 62;
    const { x, y, width, height } = a.bounds;
    return `
      <div style="position:absolute;left:${x + pad}px;top:${y + pad}px;
                  width:${width - pad * 2}px;height:${height - pad * 2}px;
                  background:#FFFFFF;border:9px solid #22262D;border-radius:52px;
                  box-shadow:0 13px 0 rgba(21,27,43,.34);
                  display:flex;flex-direction:column;align-items:center;justify-content:center;gap:18px;">
        ${svg(icons[icon](), 190)}
        <div style="text-align:center;">
          <div style="font-size:${fs}px;font-weight:900;color:#22262D;line-height:1.15;white-space:nowrap;">${ja}</div>
          <div style="font-size:30px;font-weight:700;color:${theme.sub};letter-spacing:.08em;margin-top:4px;">${en}</div>
        </div>
      </div>`;
  }).join('');

  const overlayHtml = overlay ? areas.map((a, i) => `
      <div style="position:absolute;left:${a.bounds.x}px;top:${a.bounds.y}px;
                  width:${a.bounds.width}px;height:${a.bounds.height}px;
                  outline:6px dashed #FF0000;outline-offset:-6px;
                  font:700 40px monospace;color:#FF0000;padding:8px;">${i}</div>`).join('') : '';

  return `<!doctype html><html><head><meta charset="utf-8"><style>
    ${faces}
    *{box-sizing:border-box;margin:0;padding:0;}
    body{width:${LIMITS.width}px;height:${LIMITS.height}px;overflow:hidden;
         font-family:'NotoJP','IPAGothic','Noto Sans JP',sans-serif;
         -webkit-font-smoothing:antialiased;}
  </style></head><body>
    <div style="position:relative;width:${LIMITS.width}px;height:${LIMITS.height}px;background:${theme.panel};">
      ${tabHtml}${cellHtml}${overlayHtml}
    </div>
  </body></html>`;
}

// -----------------------------------------------------------------------------
// 4) 生成
// -----------------------------------------------------------------------------
const menus = loadAreas();
const faces = await fontFaces();
fs.mkdirSync(OUT, { recursive: true });

// 環境によっては Playwright が自前で落としたブラウザを持たない。
// CHROMIUM_PATH が指定されていればそれを使う（用意済みのChromiumを流用できる）。
const browser = await chromium.launch(
  process.env.CHROMIUM_PATH ? { executablePath: process.env.CHROMIUM_PATH } : {}
);
const page = await browser.newPage({
  viewport: { width: LIMITS.width, height: LIMITS.height },
  deviceScaleFactor: 1,          // 2にすると5000x3372になり、LINEに弾かれる
});

let ng = 0;
for (const [alias, menu] of Object.entries(menus)) {
  const name = alias.replace('autobest-', '') + (overlay ? '-overlay' : '') + '.png';
  const file = path.join(OUT, name);

  await page.setContent(buildHtml(alias, menu, faces), { waitUntil: 'load' });
  await page.evaluate(() => document.fonts.ready);   // 豆腐のまま撮らない
  await page.screenshot({ path: file, type: 'png' });

  // --- 検査 ---
  const bytes = fs.statSync(file).size;
  const buf = fs.readFileSync(file);
  const w = buf.readUInt32BE(16);   // PNG IHDR
  const h = buf.readUInt32BE(20);
  const okSize = w === LIMITS.width && h === LIMITS.height;
  const okBytes = bytes <= LIMITS.maxBytes;

  console.log(
    `  ${okSize && okBytes ? '[OK]' : '[NG]'} ${name}  ${w}x${h}  ${(bytes / 1024).toFixed(0)}KB`
    + (okSize ? '' : `  ★寸法が ${LIMITS.width}x${LIMITS.height} ではありません`)
    + (okBytes ? '' : `  ★1MBを超えています`)
  );
  if (!okSize || !okBytes) ng++;
}

await browser.close();

if (ng > 0) {
  console.error('\n生成に問題があります。上の [NG] を確認してください。');
  process.exit(1);
}
console.log('\n完了しました。当たり判定との一致を見るには --overlay を付けて再実行してください。');
