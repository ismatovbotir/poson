@props(['value', 'snippet', 'plain' => false])

{{-- Monospace block with a Copy button. Pure client side (Alpine), no server round trip. An x-show / x-cloak passed as an attribute relies on the parent's x-data (the guide's `os`). Copy follows the api-docs-code pattern: "Copied" only after the clipboard accepted the text, otherwise the text is selected with a hint. --}}
<div x-data="{ copied: false, failed: false }" {{ $attributes->class(['rounded-lg border border-slate-200 bg-slate-50 p-2']) }}>
    <div class="flex flex-col gap-2 sm:flex-row sm:items-start">
        <pre x-ref="code" class="min-w-0 flex-1 overflow-x-auto whitespace-pre-wrap break-all px-1 font-mono text-xs text-slate-800" @if ($plain) data-snippet="{{ $snippet }}" data-plain-token @else data-guide-snippet="{{ $snippet }}" @endif>{{ $value }}</pre>
        <button type="button" class="inline-flex h-10 shrink-0 items-center justify-center gap-1.5 rounded-lg bg-white px-3 text-sm font-semibold text-slate-700 ring-1 ring-slate-200 hover:bg-slate-50 sm:h-9"
            x-on:click="
                const el = $refs.code;
                const select = () => { const r = document.createRange(); r.selectNodeContents(el); const s = window.getSelection(); s.removeAllRanges(); s.addRange(r); };
                if (navigator.clipboard?.writeText) {
                    navigator.clipboard.writeText(el.textContent).then(() => { copied = true; failed = false; setTimeout(() => copied = false, 1500); }, () => { select(); failed = true; });
                } else { select(); failed = true; }
            ">
            <x-admin.icon name="copy" size="size-4" />
            <span aria-live="polite">
                <span x-show="! copied">{{ __('mcp.reveal.copy') }}</span>
                <span x-cloak x-show="copied">{{ __('mcp.reveal.copied') }}</span>
            </span>
        </button>
    </div>
    <p x-cloak x-show="failed" class="mt-1 px-1 text-xs text-amber-800">{{ __('mcp.reveal.copy_failed') }}</p>
</div>
