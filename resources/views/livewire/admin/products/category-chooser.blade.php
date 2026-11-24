<div x-data="{ open: false }" x-on:category-chooser-opened.window="open = true">
    <x-catalog.category-icon-sprite />
    <div x-cloak x-show="open" class="fixed inset-0 z-[55]" role="dialog" aria-modal="true" aria-labelledby="category-chooser-title" x-on:keydown.escape.window="open = false">
        <div class="absolute inset-0 bg-slate-900/50" x-on:click="open = false"></div>
        <div x-show="open" x-trap.noscroll="open" class="absolute inset-x-0 bottom-0 flex max-h-[88vh] flex-col rounded-t-2xl bg-white shadow-2xl sm:inset-x-auto sm:bottom-auto sm:left-1/2 sm:top-1/2 sm:max-h-[85vh] sm:w-[36rem] sm:-translate-x-1/2 sm:-translate-y-1/2 sm:rounded-2xl">
            <div class="flex items-start justify-between gap-3 border-b border-slate-100 px-5 py-4">
                <div>
                    <h2 id="category-chooser-title" class="text-base font-semibold">{{ __('admin.products.chooser.title') }}</h2>
                    <p class="mt-0.5 text-xs text-slate-500">{{ __('admin.products.chooser.subtitle') }}</p>
                </div>
                <button type="button" x-on:click="open = false" class="grid size-9 shrink-0 place-items-center rounded-lg text-slate-500 hover:bg-slate-100" aria-label="{{ __('admin.a11y.close') }}"><x-admin.icon name="x" /></button>
            </div>
            <div class="min-h-0 flex-1 overflow-y-auto px-5 py-4">
                <ul class="grid grid-cols-2 gap-3 sm:grid-cols-3">
                    @foreach ($this->roots() as $root)
                        <li wire:key="chooser-root-{{ $root['id'] }}">
                            <button type="button" wire:click="choose({{ $root['id'] }})" wire:loading.attr="disabled" wire:target="choose({{ $root['id'] }})" class="flex min-h-24 w-full flex-col items-center justify-center gap-2 rounded-xl border border-slate-200 p-3 text-center text-sm font-medium text-slate-800 transition hover:border-brand-500 hover:bg-brand-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-500 disabled:opacity-60">
                                <span wire:loading.remove wire:target="choose({{ $root['id'] }})"><x-admin.icon :name="$root['icon']" size="size-7" class="text-brand-600" /></span>
                                <span wire:loading wire:target="choose({{ $root['id'] }})" class="size-7 animate-spin rounded-full border-2 border-slate-200 border-t-brand-600" aria-hidden="true"></span>
                                <span>{{ $root['name'] }}</span>
                                @unless ($root['active'])
                                    <span class="rounded-full bg-slate-100 px-2 py-0.5 text-xs font-normal text-slate-500">{{ __('admin.categories.inactive') }}</span>
                                @endunless
                            </button>
                        </li>
                    @endforeach
                </ul>
            </div>
            @error('category')
                <p class="px-5 pb-2 text-sm text-red-600" role="alert">{{ $message }}</p>
            @enderror
            <div class="flex gap-2 border-t border-slate-100 p-4 pb-[calc(1rem+env(safe-area-inset-bottom))]">
                <button type="button" x-on:click="open = false" class="h-11 flex-1 rounded-lg border border-slate-200 text-sm font-semibold text-slate-700 hover:bg-slate-50">{{ __('admin.products.chooser.cancel') }}</button>
            </div>
        </div>
    </div>
</div>
