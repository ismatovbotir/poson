{{-- Decorative 3D-style handheld 2D imager. Gradient/filter ids are prefixed hs- to avoid sprite collisions. --}}
<svg viewBox="0 0 300 240" role="img" {{ $attributes->merge(['class' => 'hs-root']) }}>
    <title>{{ __('site.home.hero.scanner_alt') }}</title>
    <style>
        @media (prefers-reduced-motion: no-preference) {
            .hs-float { animation: hs-float 6s ease-in-out infinite; }
            .hs-sweep { animation: hs-sweep 2.5s ease-in-out infinite alternate; }
            .hs-beam { animation: hs-pulse 2.5s ease-in-out infinite; }
        }
        @keyframes hs-float { 0%, 100% { transform: translateY(0); } 50% { transform: translateY(-6px); } }
        @keyframes hs-sweep { from { transform: translateY(0); } to { transform: translateY(26px); } }
        @keyframes hs-pulse { 0%, 100% { opacity: .85; } 50% { opacity: 1; } }
    </style>
    <defs aria-hidden="true">
        <linearGradient id="hs-head" x1="0" y1="0" x2="1" y2="1">
            <stop offset="0" stop-color="#ffffff" />
            <stop offset=".45" stop-color="#e2e8f0" />
            <stop offset="1" stop-color="#94a3b8" />
        </linearGradient>
        <linearGradient id="hs-top" x1="0" y1="0" x2="0" y2="1">
            <stop offset="0" stop-color="#1e293b" />
            <stop offset="1" stop-color="#0f172a" />
        </linearGradient>
        <linearGradient id="hs-grip" x1="0" y1="0" x2="1" y2="0">
            <stop offset="0" stop-color="#334155" />
            <stop offset=".35" stop-color="#1e293b" />
            <stop offset="1" stop-color="#020617" />
        </linearGradient>
        <linearGradient id="hs-glass" x1="0" y1="0" x2="1" y2="1">
            <stop offset="0" stop-color="#1e293b" />
            <stop offset="1" stop-color="#020617" />
        </linearGradient>
        <linearGradient id="hs-trigger" x1="0" y1="0" x2="1" y2="0">
            <stop offset="0" stop-color="#475569" />
            <stop offset="1" stop-color="#0f172a" />
        </linearGradient>
        <linearGradient id="hs-beam" x1="0" y1="0" x2="1" y2="0">
            <stop offset="0" stop-color="#ef4444" stop-opacity=".55" />
            <stop offset="1" stop-color="#ef4444" stop-opacity=".08" />
        </linearGradient>
        <radialGradient id="hs-led" cx=".5" cy=".5" r=".5">
            <stop offset="0" stop-color="#86efac" />
            <stop offset=".4" stop-color="#22c55e" />
            <stop offset="1" stop-color="#22c55e" stop-opacity="0" />
        </radialGradient>
        <radialGradient id="hs-shadow" cx=".5" cy=".5" r=".5">
            <stop offset="0" stop-color="#0f172a" stop-opacity=".35" />
            <stop offset="1" stop-color="#0f172a" stop-opacity="0" />
        </radialGradient>
        <linearGradient id="hs-shine" x1="0" y1="0" x2="1" y2="0">
            <stop offset="0" stop-color="#fff" stop-opacity="0" />
            <stop offset=".3" stop-color="#fff" stop-opacity=".9" />
            <stop offset="1" stop-color="#fff" stop-opacity="0" />
        </linearGradient>
        <filter id="hs-blur" x="-.2" y="-.5" width="1.4" height="2">
            <feGaussianBlur stdDeviation="3" />
        </filter>
        <clipPath id="hs-label-clip"><rect x="196" y="150" width="84" height="62" rx="5" /></clipPath>
    </defs>

    <g aria-hidden="true">
        {{-- contact shadows --}}
        <ellipse cx="105" cy="226" rx="70" ry="9" fill="url(#hs-shadow)" filter="url(#hs-blur)" />
        <ellipse cx="238" cy="219" rx="48" ry="6" fill="url(#hs-shadow)" filter="url(#hs-blur)" />

        {{-- scan beam --}}
        <g class="hs-beam">
            <path d="M138 66 L200 138 L282 138 L282 196 L200 196 Z" fill="url(#hs-beam)" />
            <path d="M138 66 L200 150 M138 66 L280 192" stroke="#ef4444" stroke-opacity=".5" stroke-width=".8" fill="none" />
        </g>

        {{-- barcode label / box --}}
        <rect x="192" y="146" width="92" height="70" rx="7" fill="#cbd5e1" />
        <rect x="196" y="150" width="84" height="62" rx="5" fill="#fff" stroke="#e2e8f0" />
        <g clip-path="url(#hs-label-clip)">
            <g fill="#0f172a">
                <rect x="206" y="160" width="3" height="34" /><rect x="212" y="160" width="1.5" height="34" />
                <rect x="216" y="160" width="4" height="34" /><rect x="223" y="160" width="2" height="34" />
                <rect x="228" y="160" width="1.5" height="34" /><rect x="232" y="160" width="4" height="34" />
                <rect x="239" y="160" width="2" height="34" /><rect x="244" y="160" width="3" height="34" />
                <rect x="250" y="160" width="1.5" height="34" /><rect x="254" y="160" width="4" height="34" />
                <rect x="261" y="160" width="2" height="34" /><rect x="266" y="160" width="3" height="34" />
            </g>
            <rect x="206" y="199" width="64" height="3" rx="1.5" fill="#cbd5e1" />
            <g class="hs-sweep">
                <rect x="198" y="158" width="80" height="2.2" rx="1.1" fill="#ef4444" />
                <rect x="198" y="155" width="80" height="8" fill="#ef4444" opacity=".15" />
            </g>
        </g>

        {{-- scanner --}}
        <g class="hs-float" transform="rotate(-6 100 140)">
            {{-- grip --}}
            <path d="M82 104 L132 104 L118 214 Q116 224 104 224 L82 224 Q70 224 71 212 Z" fill="url(#hs-grip)" />
            <path d="M82 104 L132 104 L130 118 L80 118 Z" fill="#000" opacity=".25" />
            <g stroke="#475569" stroke-opacity=".55" stroke-width="1.2" stroke-linecap="round">
                <path d="M78 142 L117 142" /><path d="M77 154 L115 154" /><path d="M76 166 L113 166" />
                <path d="M75 178 L111 178" /><path d="M74 190 L109 190" /><path d="M73 202 L107 202" />
            </g>
            <path d="M82 106 L72 210" stroke="#64748b" stroke-opacity=".6" stroke-width="1.5" fill="none" />
            {{-- trigger --}}
            <path d="M134 118 Q146 118 146 132 L142 150 Q140 156 134 150 L128 130 Z" fill="url(#hs-trigger)" stroke="#020617" stroke-opacity=".4" />
            {{-- head shell --}}
            <path d="M40 54 Q40 38 58 38 L168 38 Q184 38 184 54 L184 92 Q184 108 168 108 L58 108 Q40 108 40 92 Z" fill="url(#hs-head)" />
            <path d="M40 92 Q40 108 58 108 L168 108 Q184 108 184 92 L184 98 Q184 112 168 112 L58 112 Q40 112 40 98 Z" fill="#475569" opacity=".5" />
            {{-- top cap --}}
            <path d="M58 38 L168 38 Q184 38 184 54 L184 62 L40 62 L40 54 Q40 38 58 38 Z" fill="url(#hs-top)" opacity=".92" />
            {{-- front face + scan window --}}
            <path d="M30 56 L44 46 L44 98 L30 92 Q26 90 26 84 L26 64 Q26 58 30 56 Z" fill="#cbd5e1" />
            <rect x="29" y="58" width="15" height="34" rx="4" fill="url(#hs-glass)" stroke="#020617" />
            <path d="M32 61 L41 61 L32 78 Z" fill="#fff" opacity=".35" />
            <circle cx="36" cy="82" r="2.5" fill="#ef4444" opacity=".7" />
            {{-- LED + glow --}}
            <circle cx="150" cy="50" r="9" fill="url(#hs-led)" />
            <circle cx="150" cy="50" r="2.6" fill="#bbf7d0" />
            {{-- seam + specular --}}
            <path d="M48 76 L176 76" stroke="#64748b" stroke-opacity=".5" />
            <path d="M60 44 L160 44" stroke="url(#hs-shine)" stroke-width="3" stroke-linecap="round" />
            <ellipse cx="90" cy="88" rx="34" ry="5" fill="#fff" opacity=".45" />
            <path d="M40 54 Q40 38 58 38 L168 38 Q184 38 184 54" fill="none" stroke="#fff" stroke-opacity=".7" />
        </g>
    </g>
</svg>
