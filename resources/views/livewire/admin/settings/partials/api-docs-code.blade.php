{{-- Code block for the API docs panel. Copyable blocks reuse x-admin.copy-block (pure client side, copies the element's textContent, sends nothing to the server); copyable => false renders the same block without a button. The code is always the static placeholder example, never a real token. --}}
@if ($copyable ?? true)
    <x-admin.copy-block class="mt-2" :value="$code" :snippet="$key" :label="$label" data-api-docs-block="{{ $key }}" />
@else
    <div class="mt-2 min-w-0 overflow-hidden rounded-lg border border-slate-200 bg-slate-50" data-api-docs-block="{{ $key }}">
        <pre class="max-w-full overflow-x-auto p-3 font-mono text-xs leading-relaxed text-slate-800"><code>{{ $code }}</code></pre>
    </div>
@endif
