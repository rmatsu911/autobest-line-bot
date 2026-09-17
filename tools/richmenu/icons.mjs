// リッチメニューのアイコン。32x32 の viewBox で描き、表示側で拡大する。
// 線画だけにせず色面で描くのは、小さく表示されたときに何のボタンか
// 一目で分かるようにするため（確定したUI設計の方針）。
export const NAVY = '#1F3358';
export const INK  = '#22262D';

const shadow = '<ellipse cx="16" cy="27" rx="11" ry="1.5" fill="#1F3358" opacity="0.12"/>';

export const icons = {
  // --- タブ ---
  tabFind: (c) => `
    <circle cx="14" cy="13.5" r="8.5" fill="${c}" opacity="0.28"/>
    <circle cx="14" cy="13.5" r="8.5" fill="none" stroke="${c}" stroke-width="2.8"/>
    <path d="M20.4 20l5.4 5.4" stroke="${c}" stroke-width="3.6" stroke-linecap="round"/>`,
  tabSell: (c) => `
    <path d="M16 4v24" stroke="${c}" stroke-width="2.6" stroke-linecap="round"/>
    <path d="M22 10c0-2-2-3.4-6-3.4S10 8 10 10.6s2.6 3.4 6 4.4 6 2 6 4.6-2 4-6 4-6-1.4-6-3.4"
          stroke="${c}" stroke-width="2.6" stroke-linecap="round" fill="none"/>`,
  tabSupport: (c) => `
    <circle cx="16" cy="16" r="11" fill="${c}" opacity="0.22"/>
    <circle cx="16" cy="16" r="11" fill="none" stroke="${c}" stroke-width="2.6"/>
    <path d="M12.7 13.3a3.4 3.4 0 1 1 4.5 3.2c-.8.3-1.2 1-1.2 1.8v.4"
          stroke="${c}" stroke-width="2.4" stroke-linecap="round" fill="none"/>
    <circle cx="16" cy="22.2" r="1.5" fill="${c}"/>`,

  // --- 車を探す ---
  car: () => `${shadow}
    <path d="M3.5 20.5v-3.6c0-.7.2-1.3.6-1.9l3.2-4.3c.7-.9 1.7-1.4 2.8-1.4h11.8c1.1 0 2.1.5 2.8 1.4l3.2 4.3c.4.6.6 1.2.6 1.9v3.6c0 1-.8 1.8-1.8 1.8H5.3c-1 0-1.8-.8-1.8-1.8z" fill="#1E7FD6"/>
    <path d="M9.6 10.9c.4-.5 1-.8 1.7-.8h9.4c.7 0 1.3.3 1.7.8l2.4 3.2H7.2z" fill="#8CC4F7"/>
    <path d="M10.6 11.7h4.5v2.4H8.8z" fill="#EAF4FE"/>
    <path d="M16.9 11.7h4.5l1.8 2.4h-6.3z" fill="#EAF4FE"/>
    <rect x="4.6" y="16.4" width="3.4" height="2.2" rx="1.1" fill="#FFD27A"/>
    <rect x="24" y="16.4" width="3.4" height="2.2" rx="1.1" fill="#F0525A"/>
    <circle cx="9.8" cy="22.3" r="3" fill="${NAVY}"/><circle cx="9.8" cy="22.3" r="1.3" fill="#C9D2DE"/>
    <circle cx="22.2" cy="22.3" r="3" fill="${NAVY}"/><circle cx="22.2" cy="22.3" r="1.3" fill="#C9D2DE"/>`,
  filter: () => `
    <rect x="4" y="7" width="24" height="3.2" rx="1.6" fill="#8CC4F7"/>
    <rect x="4" y="14.4" width="24" height="3.2" rx="1.6" fill="#8CC4F7"/>
    <rect x="4" y="21.8" width="24" height="3.2" rx="1.6" fill="#8CC4F7"/>
    <circle cx="10" cy="8.6" r="3.4" fill="#1E7FD6" stroke="${NAVY}" stroke-width="1.8"/>
    <circle cx="21" cy="16" r="3.4" fill="#1E7FD6" stroke="${NAVY}" stroke-width="1.8"/>
    <circle cx="13" cy="23.4" r="3.4" fill="#1E7FD6" stroke="${NAVY}" stroke-width="1.8"/>`,
  sparkleCar: () => `${shadow}
    <path d="M5 21v-3.2c0-.6.2-1.2.5-1.7l2.9-3.9c.6-.8 1.5-1.3 2.5-1.3h10.2c1 0 1.9.5 2.5 1.3l2.9 3.9c.3.5.5 1.1.5 1.7V21c0 .9-.7 1.6-1.6 1.6H6.6c-.9 0-1.6-.7-1.6-1.6z" fill="#1E7FD6"/>
    <path d="M10.6 12.3c.3-.4.9-.7 1.5-.7h7.8c.6 0 1.2.3 1.5.7l2 2.7H8.6z" fill="#8CC4F7"/>
    <circle cx="10.6" cy="22.6" r="2.6" fill="${NAVY}"/><circle cx="21.4" cy="22.6" r="2.6" fill="${NAVY}"/>
    <path d="M24.5 4l1.1 2.6 2.6 1.1-2.6 1.1-1.1 2.6-1.1-2.6L20.8 7.7l2.6-1.1z" fill="#FFB020"/>
    <path d="M7.5 3.5l.7 1.7 1.7.7-1.7.7-.7 1.7-.7-1.7L5.1 5.9l1.7-.7z" fill="#FFD27A"/>`,
  heart: () => `
    <path d="M16 27S4.5 20.2 4.5 12.6C4.5 8.6 7.6 6 11 6c2.2 0 4 1.1 5 2.7C17 7.1 18.8 6 21 6c3.4 0 6.5 2.6 6.5 6.6C27.5 20.2 16 27 16 27z"
          fill="#F0525A" stroke="${NAVY}" stroke-width="1.8" stroke-linejoin="round"/>
    <path d="M10.6 10.2c-1.2.3-2.1 1.3-2.3 2.6" stroke="#FFFFFF" stroke-width="1.8" stroke-linecap="round" opacity="0.8"/>`,
  calendar: () => `
    <rect x="4" y="7" width="24" height="21" rx="3" fill="#FFFFFF" stroke="${NAVY}" stroke-width="2"/>
    <path d="M4 13.5h24" stroke="${NAVY}" stroke-width="2"/>
    <rect x="4" y="7" width="24" height="6.5" rx="3" fill="#1E7FD6"/>
    <path d="M10 4v5M22 4v5" stroke="${NAVY}" stroke-width="2.6" stroke-linecap="round"/>
    <rect x="8.5" y="17" width="4.5" height="4" rx="1" fill="#FFB020"/>
    <rect x="15" y="17" width="4.5" height="4" rx="1" fill="#C9D2DE"/>
    <rect x="8.5" y="22.5" width="4.5" height="3.2" rx="1" fill="#C9D2DE"/>`,
  shop: () => `${shadow}
    <path d="M5 12h22v13.5c0 .8-.7 1.5-1.5 1.5h-19c-.8 0-1.5-.7-1.5-1.5z" fill="#FFFFFF" stroke="${NAVY}" stroke-width="2"/>
    <path d="M4 12l2.2-5.3c.3-.6.9-1 1.5-1h16.6c.6 0 1.2.4 1.5 1L28 12z" fill="#1E7FD6" stroke="${NAVY}" stroke-width="1.8" stroke-linejoin="round"/>
    <path d="M11.5 6v6M20.5 6v6" stroke="${NAVY}" stroke-width="1.4" opacity="0.5"/>
    <rect x="12.5" y="18" width="7" height="9" fill="#8CC4F7" stroke="${NAVY}" stroke-width="1.6"/>`,

  // --- 車を売る ---
  yenCar: () => `${shadow}
    <path d="M4 20.5v-3.3c0-.6.2-1.2.5-1.7l2.9-4c.6-.8 1.5-1.3 2.5-1.3h10.4c1 0 1.9.5 2.5 1.3l1.4 1.9" fill="none" stroke="${NAVY}" stroke-width="1.8" stroke-linecap="round"/>
    <path d="M4 20.5v-3.3c0-.6.2-1.2.5-1.7l2.9-4c.6-.8 1.5-1.3 2.5-1.3h10.4c1 0 1.9.5 2.5 1.3l2.9 4c.3.5.5 1.1.5 1.7v3.3c0 .9-.7 1.6-1.6 1.6H5.6c-.9 0-1.6-.7-1.6-1.6z" fill="#EE6A12"/>
    <path d="M9.6 11.6c.3-.4.9-.7 1.5-.7h7.8c.6 0 1.2.3 1.5.7l2 2.8H7.6z" fill="#FFB86B"/>
    <circle cx="9.6" cy="22.2" r="2.7" fill="${NAVY}"/><circle cx="20.4" cy="22.2" r="2.7" fill="${NAVY}"/>
    <circle cx="25" cy="8" r="6" fill="#FFB020" stroke="${NAVY}" stroke-width="1.8"/>
    <path d="M22.6 5.4L25 8.4l2.4-3M23 9.3h4M23 11h4M25 8.4V12" stroke="${NAVY}" stroke-width="1.5" stroke-linecap="round"/>`,
  chartUp: () => `
    <rect x="4" y="6" width="24" height="21" rx="3" fill="#FFFFFF" stroke="${NAVY}" stroke-width="2"/>
    <rect x="8" y="17" width="4" height="6" rx="1" fill="#FFB86B"/>
    <rect x="14" y="13" width="4" height="10" rx="1" fill="#EE6A12"/>
    <rect x="20" y="9" width="4" height="14" rx="1" fill="#C85A0E"/>
    <path d="M8 13l5-4 4 3 6-6" fill="none" stroke="#F0525A" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
    <path d="M19 6h4.5v4.5" fill="none" stroke="#F0525A" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>`,
  medal: () => `
    <path d="M10 4h12l-3 9h-6z" fill="#FFB86B" stroke="${NAVY}" stroke-width="1.8" stroke-linejoin="round"/>
    <circle cx="16" cy="20" r="8" fill="#FFB020" stroke="${NAVY}" stroke-width="2"/>
    <path d="M16 15.4l1.5 3 3.3.5-2.4 2.3.6 3.3-3-1.6-3 1.6.6-3.3-2.4-2.3 3.3-.5z" fill="#FFFFFF"/>`,
  steps: () => `
    <rect x="4" y="20" width="7" height="7" rx="1.6" fill="#FFB86B" stroke="${NAVY}" stroke-width="1.8"/>
    <rect x="12.5" y="14" width="7" height="13" rx="1.6" fill="#EE6A12" stroke="${NAVY}" stroke-width="1.8"/>
    <rect x="21" y="8" width="7" height="19" rx="1.6" fill="#C85A0E" stroke="${NAVY}" stroke-width="1.8"/>
    <path d="M7.5 17V8.5M7.5 4.5l3 4h-6z" fill="#F0525A" stroke="${NAVY}" stroke-width="1.5" stroke-linejoin="round"/>`,
  document: () => `
    <path d="M7 4h11l7 7v17c0 .6-.4 1-1 1H7c-.6 0-1-.4-1-1V5c0-.6.4-1 1-1z" fill="#FFFFFF" stroke="${NAVY}" stroke-width="2" stroke-linejoin="round"/>
    <path d="M18 4v7h7" fill="#FFD27A" stroke="${NAVY}" stroke-width="2" stroke-linejoin="round"/>
    <path d="M10 16h12M10 20h12M10 24h7" stroke="#EE6A12" stroke-width="2" stroke-linecap="round"/>`,
  chat: () => `
    <path d="M4 9c0-1.7 1.3-3 3-3h13c1.7 0 3 1.3 3 3v7c0 1.7-1.3 3-3 3h-8l-5 4v-4H7c-1.7 0-3-1.3-3-3z" fill="#EE6A12" stroke="${NAVY}" stroke-width="1.8" stroke-linejoin="round"/>
    <path d="M25 12h1c1.7 0 3 1.3 3 3v6c0 1.7-1.3 3-3 3h-1v3l-4-3h-3" fill="#FFD27A" stroke="${NAVY}" stroke-width="1.8" stroke-linejoin="round"/>
    <circle cx="10" cy="12.5" r="1.4" fill="#FFFFFF"/><circle cx="14.5" cy="12.5" r="1.4" fill="#FFFFFF"/><circle cx="19" cy="12.5" r="1.4" fill="#FFFFFF"/>`,

  // --- サポート ---
  faq: () => `
    <path d="M4 8c0-1.7 1.3-3 3-3h18c1.7 0 3 1.3 3 3v12c0 1.7-1.3 3-3 3H14l-6 5v-5H7c-1.7 0-3-1.3-3-3z" fill="#2FC4D6" stroke="${NAVY}" stroke-width="1.8" stroke-linejoin="round"/>
    <path d="M12.6 11.4a3.6 3.6 0 1 1 4.8 3.4c-.9.3-1.3 1.1-1.3 2v.5" fill="none" stroke="#FFFFFF" stroke-width="2.4" stroke-linecap="round"/>
    <circle cx="16" cy="20.4" r="1.6" fill="#FFFFFF"/>`,
  mail: () => `
    <rect x="3" y="7" width="26" height="18" rx="3" fill="#FFFFFF" stroke="${NAVY}" stroke-width="2"/>
    <path d="M3.5 9l12.5 9 12.5-9" fill="none" stroke="#0FA5BA" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"/>
    <path d="M3 24l9-7M29 24l-9-7" fill="none" stroke="#7BDCEA" stroke-width="2" stroke-linecap="round"/>`,
  calendarCheck: () => `
    <rect x="4" y="7" width="24" height="21" rx="3" fill="#FFFFFF" stroke="${NAVY}" stroke-width="2"/>
    <rect x="4" y="7" width="24" height="6.5" rx="3" fill="#0FA5BA"/>
    <path d="M10 4v5M22 4v5" stroke="${NAVY}" stroke-width="2.6" stroke-linecap="round"/>
    <path d="M10.5 21l3.6 3.6 7.4-7.4" fill="none" stroke="#2FC4D6" stroke-width="3.2" stroke-linecap="round" stroke-linejoin="round"/>`,
  bell: () => `
    <path d="M16 4a7.5 7.5 0 0 1 7.5 7.5v5l2.5 4.5H6l2.5-4.5v-5A7.5 7.5 0 0 1 16 4z" fill="#FFB020" stroke="${NAVY}" stroke-width="2" stroke-linejoin="round"/>
    <path d="M12.8 23.5a3.3 3.3 0 0 0 6.4 0" fill="none" stroke="${NAVY}" stroke-width="2.2" stroke-linecap="round"/>
    <circle cx="24" cy="7" r="3.4" fill="#F0525A" stroke="#FFFFFF" stroke-width="1.6"/>`,
  building: () => `${shadow}
    <rect x="5" y="8" width="11" height="19" rx="1.5" fill="#FFFFFF" stroke="${NAVY}" stroke-width="1.8"/>
    <rect x="16" y="13" width="11" height="14" rx="1.5" fill="#2FC4D6" stroke="${NAVY}" stroke-width="1.8"/>
    <rect x="8" y="11.5" width="2.6" height="2.6" fill="#7BDCEA"/><rect x="12" y="11.5" width="2.6" height="2.6" fill="#7BDCEA"/>
    <rect x="8" y="16" width="2.6" height="2.6" fill="#7BDCEA"/><rect x="12" y="16" width="2.6" height="2.6" fill="#7BDCEA"/>
    <rect x="19" y="16.5" width="2.4" height="2.4" fill="#FFFFFF"/><rect x="22.6" y="16.5" width="2.4" height="2.4" fill="#FFFFFF"/>
    <rect x="9.5" y="21" width="4" height="6" fill="#0FA5BA"/>`,
  globe: () => `
    <circle cx="16" cy="16" r="12" fill="#2FC4D6" stroke="${NAVY}" stroke-width="2"/>
    <ellipse cx="16" cy="16" rx="5" ry="12" fill="none" stroke="#FFFFFF" stroke-width="1.8"/>
    <path d="M4.4 12.5h23.2M4.4 19.5h23.2M16 4v24" fill="none" stroke="#FFFFFF" stroke-width="1.8"/>`,
};
