{{-- Decorative 3D-style ultra-rugged handheld 2D imager (DS3600-style silhouette, no branding). Gradient/filter ids are prefixed hs- to avoid sprite collisions. --}}
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
            <stop offset="0" stop-color="#d9f763" />
            <stop offset=".5" stop-color="#a6da2c" />
            <stop offset="1" stop-color="#77a514" />
        </linearGradient>
        <linearGradient id="hs-side" x1="0" y1="0" x2=".3" y2="1">
            <stop offset="0" stop-color="#b4e339" />
            <stop offset="1" stop-color="#5f8a0d" />
        </linearGradient>
        <linearGradient id="hs-top" x1="0" y1="0" x2="1" y2="1">
            <stop offset="0" stop-color="#454b52" />
            <stop offset="1" stop-color="#1a1d21" />
        </linearGradient>
        <linearGradient id="hs-body" x1="0" y1="0" x2="1" y2="0">
            <stop offset="0" stop-color="#3d434a" />
            <stop offset=".45" stop-color="#22262b" />
            <stop offset="1" stop-color="#121417" />
        </linearGradient>
        <linearGradient id="hs-front" x1="0" y1="0" x2="1" y2="0">
            <stop offset="0" stop-color="#2a2f35" />
            <stop offset="1" stop-color="#0b0d0f" />
        </linearGradient>
        <linearGradient id="hs-recess" x1="0" y1="0" x2="0" y2="1">
            <stop offset="0" stop-color="#0b0d10" />
            <stop offset="1" stop-color="#2b3036" />
        </linearGradient>
        <linearGradient id="hs-glass" x1="0" y1="0" x2="1" y2="1">
            <stop offset="0" stop-color="#3b5bdb" />
            <stop offset="1" stop-color="#0b1024" />
        </linearGradient>
        <linearGradient id="hs-trigger" x1="0" y1="0" x2="1" y2="0">
            <stop offset="0" stop-color="#535a62" />
            <stop offset="1" stop-color="#101214" />
        </linearGradient>
        <linearGradient id="hs-beam" x1="0" y1="0" x2="1" y2="0">
            <stop offset="0" stop-color="#ef4444" stop-opacity=".55" />
            <stop offset="1" stop-color="#ef4444" stop-opacity=".08" />
        </linearGradient>
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
        <ellipse cx="96" cy="225" rx="44" ry="8" fill="url(#hs-shadow)" filter="url(#hs-blur)" />
        <ellipse cx="238" cy="219" rx="48" ry="6" fill="url(#hs-shadow)" filter="url(#hs-blur)" />

        {{-- scan beam --}}
        <g class="hs-beam">
            <path d="M130 86 L200 140 L282 140 L282 196 L200 196 Z" fill="url(#hs-beam)" />
            <path d="M130 86 L200 150 M130 86 L280 192" stroke="#ef4444" stroke-opacity=".5" stroke-width=".8" fill="none" />
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
        <g class="hs-float">
            {{-- grip: black rubber, tapered, with high-vis collar on the trigger side --}}
            <path d="M58 98 L98 112 L96 192 L72 186 Q66 150 58 98 Z" fill="url(#hs-body)" />
            <path d="M98 112 L122 107 L119 189 L96 192 Z" fill="url(#hs-front)" />
            <path d="M98 112 L122 107 L121 148 Q109 158 97 154 Z" fill="url(#hs-head)" />
            <path d="M84 108 L98 112 L97 154 Q89 146 86 128 Z" fill="url(#hs-side)" />
            <path d="M64 112 Q68 150 74 182" stroke="#fff" stroke-opacity=".12" stroke-width="3" stroke-linecap="round" fill="none" />
            {{-- trigger in its recess --}}
            <path d="M102 124 Q111 120 119 123 L118 142 Q110 148 103 144 Z" fill="#0b0d10" />
            <path d="M104 126 Q112 123 117 126 L116 139 Q110 144 105 140 Z" fill="url(#hs-trigger)" />
            <path d="M106 128 Q111 126 114 128" stroke="#fff" stroke-opacity=".35" stroke-linecap="round" fill="none" />
            {{-- flared boot base --}}
            <path d="M70 182 L96 189 L98 218 Q98 222 93 221 L70 215 Q66 214 66 209 Z" fill="url(#hs-body)" />
            <path d="M96 189 L120 185 L124 212 Q124 215 120 216 L102 220 Q98 221 98 218 Z" fill="url(#hs-front)" />
            <path d="M70 182 L96 189 L120 185" stroke="#5b626a" stroke-width="1.2" stroke-linejoin="round" fill="none" />
            <path d="M70 215 L98 220 L120 216" stroke="#000" stroke-opacity=".45" fill="none" />
            <path d="M74 194 L75 208" stroke="#fff" stroke-opacity=".1" stroke-width="2.5" stroke-linecap="round" />

            {{-- head: left side (green bumper, black upper facet, black lower band) --}}
            <path d="M42 48 L100 66 L101 112 L50 98 Q44 96 44 90 Z" fill="url(#hs-side)" />
            <path d="M42 48 L100 66 L100 78 L70 70 L43 62 Z" fill="#1f2328" />
            <path d="M44 88 L101 104 L101 120 L52 104 Q45 102 44 96 Z" fill="#15181b" />
            <path d="M42 50 L51 53 L52 102 Q45 100 44 94 Z" fill="#000" opacity=".35" />

            {{-- head: top cap with centre rail, corner pads and screws --}}
            <path d="M46 45 L120 32 Q125 31 130 33 L172 49 Q176 51 172 52 L104 65 Q100 66 96 64 L45 50 Q40 48 46 45 Z" fill="url(#hs-top)" />
            <path d="M76 41 L92 38 L146 56 L130 60 Z" fill="#2a2f35" stroke="#5b626a" stroke-width=".6" stroke-linejoin="round" />
            <path d="M81 40 L92 38 L93 34 L82 36 Z" fill="#30353b" />
            <path d="M158 47 L171 50 L166 53 L153 51 Z M48 47 L60 45 L66 50 L54 52 Z" fill="#111418" />
            <g fill="#6b727a"><circle cx="62" cy="47" r="1.1" /><circle cx="114" cy="37" r="1.1" /><circle cx="150" cy="50" r="1.1" /><circle cx="102" cy="60" r="1.1" /></g>
            <path d="M54 46 L118 35" stroke="url(#hs-shine)" stroke-width="2" stroke-linecap="round" opacity=".55" />

            {{-- head: front with green hood frame and deep recessed scan window --}}
            <path d="M100 66 L172 52 Q176 51 176 55 L177 94 Q177 98 173 99 L104 113 Q101 114 101 110 Z" fill="url(#hs-head)" />
            <path d="M108 74 L167 62 L168 94 L109 106 Z" fill="#15181b" stroke="#15181b" stroke-width="3" stroke-linejoin="round" />
            <path d="M112 78 L163 67 L164 90 L113 101 Z" fill="#3a4047" />
            <path d="M118 81 L157 73 L157 86 L119 94 Z" fill="url(#hs-recess)" />
            <path d="M119 94 L157 86 L164 90 L113 101 Z" fill="#555c64" />
            <path d="M121 83 L133 80.7 L133 87 L121 89.3 Z" fill="url(#hs-glass)" stroke="#000" stroke-width=".6" />
            <path d="M122.5 84 L127 83 L122.5 87 Z" fill="#fff" opacity=".45" />
            <circle cx="138" cy="81.5" r="1.3" fill="#f59e0b" />
            <circle cx="142" cy="80.7" r="1" fill="#ef4444" opacity=".8" />
            <path d="M102 67 L172 53" stroke="#efffb0" stroke-opacity=".75" stroke-width="1.2" stroke-linecap="round" />
            <path d="M175 56 L176 94" stroke="#fff" stroke-opacity=".25" stroke-linecap="round" />

            {{-- head: black chin bumper --}}
            <path d="M101 110 Q101 114 104 113 L173 99 Q177 98 177 94 L178 102 Q178 105 174 106 L106 121 Q102 122 102 118 Z" fill="#111418" />
            <path d="M104 117 L174 103" stroke="#fff" stroke-opacity=".12" />
        </g>
    </g>
</svg>
