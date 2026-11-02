{{-- Copyable code block for the API docs panel. Pure client side: copies the element's textContent, sends nothing to the server. --}}
<div x-data="{ copied: false, failed: false }" class="mt-2 min-w-0 overflow-hidden rounded-lg border border-slate-200 bg-slate-50" data-api-docs-block="{{ $key }}">
    @if ($copyable ?? true)
        <div class="flex justify-end border-b border-slate-200 bg-white px-2 py-1.5">
            <button type="button" class="inline-flex h-8 items-center gap-1.5 rounded-lg px-2.5 text-xs font-semibold text-slate-700 ring-1 ring-slate-200 hover:bg-slate-50" aria-label="{{ __('admin.api_tokens.docs.copy') }}: {{ $label }}"
                x-on:click="
                    const el = $refs.code;
                    const text = el.textContent;
                    const select = () => { const r = document.createRange(); r.selectNodeContents(el); const s = window.getSelection(); s.removeAllRanges(); s.addRange(r); };
                    if (navigator.clipboard?.writeText) {
                        navigator.clipboard.writeText(text).then(() => { copied = true; failed = false; setTimeout(() => copied = false, 1500); }, () => { select(); failed = true; });
                    } else { select(); failed = true; }
                ">
                <x-admin.icon name="copy" size="size-4" />
                <span x-show="! copied">{{ __('admin.api_tokens.docs.copy') }}</span>
                <span x-cloak x-show="copied">{{ __('admin.api_tokens.docs.copied') }}</span>
            </button>
        </div>
    @endif
    <pre class="max-w-full overflow-x-auto p-3 font-mono text-xs leading-relaxed text-slate-800"><code x-ref="code">{{ $code }}</code></pre>
    <p x-cloak x-show="failed" class="border-t border-slate-200 px-3 py-1.5 text-xs text-amber-800">{{ __('admin.api_tokens.docs.copy_failed') }}</p>
</div>
