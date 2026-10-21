{{--
    SVG sprite: UI icons (i-*) and product placeholders (p-*), from the site template.
    Rendered once at the top of <body>; use <x-site.icon name="search" /> for icons.
    Template icons left out: i-heart (favorites), i-user (login), i-percent (brand rule).
--}}
<svg width="0" height="0" class="absolute" aria-hidden="true" focusable="false">
  <symbol id="i-search" viewBox="0 0 24 24"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/></symbol>
  <symbol id="i-cart" viewBox="0 0 24 24"><path d="M3 4h2l2.4 11.2a2 2 0 0 0 2 1.6h7.4a2 2 0 0 0 2-1.5L20.5 8H6"/><circle cx="9.5" cy="20" r="1.2"/><circle cx="17" cy="20" r="1.2"/></symbol>
  <symbol id="i-menu" viewBox="0 0 24 24"><path d="M4 7h16M4 12h16M4 17h16"/></symbol>
  <symbol id="i-grid" viewBox="0 0 24 24"><rect x="4" y="4" width="7" height="7" rx="1.5"/><rect x="13" y="4" width="7" height="7" rx="1.5"/><rect x="4" y="13" width="7" height="7" rx="1.5"/><rect x="13" y="13" width="7" height="7" rx="1.5"/></symbol>
  <symbol id="i-check" viewBox="0 0 24 24"><path d="m5 12.500 4.500 4.500L19 7.500"/></symbol>
  <symbol id="i-truck" viewBox="0 0 24 24"><path d="M2 6h11v10H2zM13 10h4l3 3v3h-7"/><circle cx="6.500" cy="17.500" r="1.800"/><circle cx="16.500" cy="17.500" r="1.800"/></symbol>
  <symbol id="i-shield" viewBox="0 0 24 24"><path d="M12 3 4.500 6v5.500c0 4.500 3 8 7.500 9.500 4.500-1.500 7.500-5 7.500-9.500V6z"/><path d="m9 12 2.200 2.200L15.500 10"/></symbol>
  <symbol id="i-wrench" viewBox="0 0 24 24"><path d="M14.500 6.500a4 4 0 0 0 5 5L21 13l-8 8-4-4 8-8z" transform="scale(.9) translate(1 -1)"/><path d="m9 17-4 4"/></symbol>
  <symbol id="i-phone" viewBox="0 0 24 24"><path d="M5 4h4l2 5-2.500 1.500a11 11 0 0 0 5 5L15 13l5 2v4a2 2 0 0 1-2 2A15 15 0 0 1 3 6a2 2 0 0 1 2-2z"/></symbol>
  <symbol id="i-doc" viewBox="0 0 24 24"><path d="M6 3h9l4 4v14H6z"/><path d="M14 3v5h5M9 13h7M9 17h7"/></symbol>
  <symbol id="i-scan" viewBox="0 0 24 24"><path d="M4 8V5a1 1 0 0 1 1-1h3M16 4h3a1 1 0 0 1 1 1v3M20 16v3a1 1 0 0 1-1 1h-3M8 20H5a1 1 0 0 1-1-1v-3M4 12h16"/></symbol>
  <symbol id="i-print" viewBox="0 0 24 24"><path d="M7 9V4h10v5M7 17H4v-7h16v7h-3"/><rect x="7" y="14" width="10" height="6"/></symbol>
  <symbol id="i-term" viewBox="0 0 24 24"><rect x="7" y="2.500" width="10" height="19" rx="2"/><path d="M10 6h4v5h-4zM10 15h1M13 15h1M10 18h1M13 18h1"/></symbol>
  <symbol id="i-label" viewBox="0 0 24 24"><path d="M3 12V4h8l10 10-8 8z"/><circle cx="7.500" cy="8.500" r="1.200"/></symbol>
  <symbol id="i-rfid" viewBox="0 0 24 24"><path d="M5 12a7 7 0 0 1 7-7M5 12a7 7 0 0 0 7 7M9 12a3 3 0 0 1 3-3M9 12a3 3 0 0 0 3 3"/><circle cx="14.500" cy="12" r="1"/><path d="M17 8a6 6 0 0 1 0 8M20 5.500a10 10 0 0 1 0 13"/></symbol>
  <symbol id="i-pos" viewBox="0 0 24 24"><rect x="3" y="3" width="18" height="12" rx="2"/><path d="M8 21h8M12 15v6"/></symbol>
  <symbol id="i-arrow" viewBox="0 0 24 24"><path d="M5 12h14M13 6l6 6-6 6"/></symbol>
  <symbol id="i-close" viewBox="0 0 24 24"><path d="M6 6l12 12M18 6 6 18"/></symbol>

  <symbol id="i-send" viewBox="0 0 24 24"><path d="M21 3 3 10.5l7 2.5 2.5 7z"/><path d="m10 13 4-4"/></symbol>
  <symbol id="i-mail" viewBox="0 0 24 24"><rect x="3" y="5" width="18" height="14" rx="2"/><path d="m3 7 9 6 9-6"/></symbol>
  <symbol id="i-pin" viewBox="0 0 24 24"><path d="M12 21s-7-6.2-7-11.5a7 7 0 0 1 14 0C19 14.8 12 21 12 21z"/><circle cx="12" cy="9.5" r="2.5"/></symbol>
  <symbol id="i-clock" viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/></symbol>

  {{-- Social networks (plan 04): simplified line glyphs matching the UI icons. --}}
  <symbol id="i-telegram" viewBox="0 0 24 24"><path d="M21 4 3 11l6 2.2L18 7l-7 7.5V20l3.2-3.6L18 19z"/></symbol>
  <symbol id="i-instagram" viewBox="0 0 24 24"><rect x="3.5" y="3.5" width="17" height="17" rx="5"/><circle cx="12" cy="12" r="4"/><circle cx="17.2" cy="6.8" r=".6"/></symbol>
  <symbol id="i-facebook" viewBox="0 0 24 24"><path d="M14.5 21v-8h2.7l.4-3.2h-3.1V7.9c0-.9.3-1.6 1.6-1.6h1.6V3.5a21 21 0 0 0-2.4-.1c-2.4 0-4 1.4-4 4.1v2.3H8.6V13h2.7v8"/></symbol>
  <symbol id="i-youtube" viewBox="0 0 24 24"><rect x="2.5" y="5.5" width="19" height="13" rx="4"/><path d="m10 9 5 3-5 3z"/></symbol>
  <symbol id="i-linkedin" viewBox="0 0 24 24"><rect x="3" y="3" width="18" height="18" rx="3"/><path d="M8 10.5V17M8 7.5v.01M12 17v-6.5M12 13.5c0-1.7 1-3 2.5-3s2.5 1 2.5 3V17"/></symbol>
  <symbol id="i-tiktok" viewBox="0 0 24 24"><path d="M14 3v11.5a3.5 3.5 0 1 1-3.5-3.5"/><path d="M14 3c.4 2.6 2.2 4.4 5 4.6"/></symbol>
  <symbol id="i-whatsapp" viewBox="0 0 24 24"><path d="M4 20l1.2-3.8A8.5 8.5 0 1 1 8 19z"/><path d="M9 8.5c0 3.5 3 6.5 6.5 6.5l1-1.5-2-1-1 .8a5 5 0 0 1-2.3-2.3l.8-1-1-2z"/></symbol>

  <symbol id="p-scanner" viewBox="0 0 120 120">
    <rect x="16" y="18" width="78" height="34" rx="10" fill="#1f9d55"/>
    <rect x="78" y="24" width="14" height="22" rx="4" fill="#0f172a"/>
    <path d="M44 48h30l-4 54a8 8 0 0 1-8 7h-6a8 8 0 0 1-8-7z" fill="#0f172a"/>
    <rect x="50" y="56" width="18" height="6" rx="3" fill="#1f9d55"/>
    <path d="M96 35h20" stroke="#ef4444" stroke-width="3" stroke-linecap="round"/>
  </symbol>
  <symbol id="p-printer" viewBox="0 0 120 120">
    <rect x="22" y="22" width="76" height="30" rx="8" fill="#1f9d55"/>
    <rect x="12" y="46" width="96" height="46" rx="10" fill="#0f172a"/>
    <rect x="22" y="56" width="28" height="6" rx="3" fill="#475569"/>
    <circle cx="92" cy="59" r="4" fill="#12a150"/>
    <rect x="34" y="84" width="52" height="26" rx="3" fill="#fff" stroke="#cbd5e1" stroke-width="2"/>
    <path d="M42 94v10M47 94v10M51 94v10M58 94v10M63 94v10M70 94v10M76 94v10" stroke="#0f172a" stroke-width="2"/>
  </symbol>
  <symbol id="p-terminal" viewBox="0 0 120 120">
    <rect x="34" y="6" width="52" height="108" rx="12" fill="#0f172a"/>
    <rect x="40" y="18" width="40" height="42" rx="5" fill="#e8f8ee"/>
    <path d="M46 30h28M46 38h20M46 46h24" stroke="#1f9d55" stroke-width="3" stroke-linecap="round"/>
    <rect x="44" y="9" width="32" height="5" rx="2.500" fill="#ef4444"/>
    <g fill="#475569"><circle cx="47" cy="72" r="4"/><circle cx="60" cy="72" r="4"/><circle cx="73" cy="72" r="4"/><circle cx="47" cy="84" r="4"/><circle cx="60" cy="84" r="4"/><circle cx="73" cy="84" r="4"/><circle cx="47" cy="96" r="4"/><circle cx="60" cy="96" r="4"/><circle cx="73" cy="96" r="4" fill="#1f9d55"/></g>
  </symbol>
  <symbol id="p-labels" viewBox="0 0 120 120">
    <ellipse cx="60" cy="90" rx="42" ry="12" fill="#cbd5e1"/>
    <rect x="18" y="38" width="84" height="52" fill="#fff" stroke="#cbd5e1" stroke-width="2"/>
    <ellipse cx="60" cy="38" rx="42" ry="12" fill="#fff" stroke="#cbd5e1" stroke-width="2"/>
    <ellipse cx="60" cy="38" rx="12" ry="3.500" fill="#e2e8f0"/>
    <path d="M30 56h60M30 66h40" stroke="#1f9d55" stroke-width="3" stroke-linecap="round"/>
    <path d="M30 78v6M34 78v6M38 78v6M44 78v6M48 78v6M56 78v6" stroke="#0f172a" stroke-width="2"/>
  </symbol>
  <symbol id="p-ribbon" viewBox="0 0 120 120">
    <rect x="14" y="40" width="46" height="50" rx="6" fill="#0f172a"/>
    <rect x="60" y="40" width="46" height="50" rx="6" fill="#1f9d55"/>
    <circle cx="37" cy="65" r="14" fill="#fff"/><circle cx="37" cy="65" r="6" fill="#0f172a"/>
    <circle cx="83" cy="65" r="14" fill="#fff"/><circle cx="83" cy="65" r="6" fill="#1f9d55"/>
    <path d="M14 92h92" stroke="#cbd5e1" stroke-width="3"/>
  </symbol>
  <symbol id="p-barcode" viewBox="0 0 120 120">
    <rect x="12" y="24" width="96" height="72" rx="8" fill="#fff" stroke="#cbd5e1" stroke-width="2"/>
    <path d="M24 38v36M29 38v36M33 38v36M40 38v36M44 38v36M52 38v36M57 38v36M64 38v36M69 38v36M77 38v36M82 38v36M90 38v36" stroke="#0f172a" stroke-width="3"/>
    <rect x="24" y="80" width="40" height="6" rx="3" fill="#1f9d55"/>
  </symbol>
</svg>
