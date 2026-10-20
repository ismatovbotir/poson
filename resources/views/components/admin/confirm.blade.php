{{--
    Global confirm dialog (rendered once in layouts::admin; bottom sheet on phones).

    Open it from Alpine/Blade:
        $dispatch('confirm', {
            title: '…', body: '…', label: '…',
            component: $wire.$id, method: 'delete', params: [id],
        })
    On confirm it calls the Livewire method; without "component" it only closes.
    Esc, the backdrop and Cancel close it; focus is trapped while open.
--}}
<div
    x-data="{
        open: false,
        title: '', body: '', label: '',
        component: null, method: null, params: [],
        show(detail) {
            const d = (Array.isArray(detail) ? detail[0] : detail) ?? {};
            this.title = d.title ?? @js(__('admin.confirm.title'));
            this.body = d.body ?? @js(__('admin.confirm.body'));
            this.label = d.label ?? @js(__('admin.confirm.confirm'));
            this.component = d.component ?? null;
            this.method = d.method ?? null;
            this.params = Array.isArray(d.params) ? d.params : [];
            this.open = true;
        },
        accept() {
            if (this.component && this.method) {
                Livewire.find(this.component)?.call(this.method, ...(this.params ?? []));
            }
            this.open = false;
        },
    }"
    x-on:confirm.window="show($event.detail)"
    x-on:keydown.escape.window="open = false"
    x-cloak
    x-show="open"
    class="fixed inset-0 z-[65] flex items-end justify-center sm:items-center"
    role="alertdialog"
    aria-modal="true"
    aria-labelledby="admin-confirm-title"
    aria-describedby="admin-confirm-body"
>
    <div x-show="open" x-transition.opacity class="absolute inset-0 bg-slate-900/50" x-on:click="open = false"></div>
    <div x-show="open" x-transition x-trap.noscroll="open" class="relative w-full rounded-t-2xl bg-white p-5 pb-[calc(1.25rem+env(safe-area-inset-bottom))] shadow-2xl sm:max-w-md sm:rounded-2xl sm:pb-5">
        <div class="flex gap-4">
            <span class="grid size-11 shrink-0 place-items-center rounded-full bg-red-50 text-danger"><x-admin.icon name="alert" size="size-6" /></span>
            <div>
                <h2 id="admin-confirm-title" class="text-base font-semibold" x-text="title"></h2>
                <p id="admin-confirm-body" class="mt-1 text-sm text-slate-500" x-text="body"></p>
            </div>
        </div>
        <div class="mt-6 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
            <button type="button" x-on:click="open = false" class="h-11 rounded-lg border border-slate-200 px-4 text-sm font-semibold text-slate-700 hover:bg-slate-50 sm:h-10">{{ __('admin.confirm.cancel') }}</button>
            <button type="button" x-on:click="accept()" class="h-11 rounded-lg bg-danger px-4 text-sm font-semibold text-white hover:bg-red-700 sm:h-10" x-text="label"></button>
        </div>
    </div>
</div>
