{{--
    Toasts (rendered once in the admin layouts).
    Livewire: $this->dispatch('toast', title: __('admin.toast.saved'), body: '…', type: 'success'|'error');
    Alpine:   $dispatch('toast', { title, body, type })
    Also shows a flashed session('toast') = ['title' => …, 'body' => …, 'type' => …] after redirects.
--}}
<div
    x-data="{
        items: [],
        add(detail) {
            const toast = Array.isArray(detail) ? detail[0] : detail;
            const id = Date.now() + Math.random();
            this.items.push({ id, type: 'success', body: '', ...toast });
            setTimeout(() => this.remove(id), 4500);
        },
        remove(id) { this.items = this.items.filter(i => i.id !== id) },
    }"
    x-init="@if (session('toast')) add(@js(session('toast'))) @endif"
    x-on:toast.window="add($event.detail)"
    aria-live="polite"
    class="pointer-events-none fixed inset-x-0 top-3 z-[70] flex flex-col items-center gap-2 px-3 sm:inset-x-auto sm:right-4 sm:top-4 sm:items-end"
>
    <template x-for="t in items" :key="t.id">
        <div x-transition x-bind:role="t.type === 'error' ? 'alert' : null" class="pointer-events-auto flex w-full max-w-sm items-start gap-3 rounded-xl border border-slate-200 bg-white p-3.5 shadow-xl shadow-slate-900/10">
            <span x-bind:class="t.type === 'error' ? 'bg-red-50 text-danger' : 'bg-brand-50 text-brand-600'" class="grid size-8 shrink-0 place-items-center rounded-lg">
                <svg class="ico size-5" aria-hidden="true"><use x-bind:href="t.type === 'error' ? '#i-alert' : '#i-check'" /></svg>
            </span>
            <div class="min-w-0 flex-1 pt-0.5">
                <p class="text-sm font-semibold" x-text="t.title"></p>
                <p x-show="t.body" class="text-sm text-slate-500" x-text="t.body"></p>
            </div>
            <button type="button" x-on:click="remove(t.id)" class="text-slate-400 hover:text-slate-600" aria-label="{{ __('admin.a11y.close') }}"><x-admin.icon name="x" size="size-4" /></button>
        </div>
    </template>
</div>
