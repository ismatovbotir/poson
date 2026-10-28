{{-- Related products (inside the product form: no <form>, Enter never submits the parent). --}}
<div class="p-4 sm:p-5" x-on:keydown.enter="if ($event.target.tagName === 'INPUT') $event.preventDefault()" data-product-relations>
    <h2 class="text-sm font-semibold">{{ __('admin.products.relations.title') }}</h2>
    <p class="mt-1 text-xs text-slate-500">{{ __('admin.products.relations.help') }}</p>

    @php($groups = $this->relations->groupBy(fn ($relation) => $relation->type->value))
    @forelse ($groups as $typeValue => $relations)
        <p class="mt-4 text-[11px] font-semibold uppercase tracking-wider text-slate-500">{{ \App\Enums\RelationType::from($typeValue)->label() }}</p>
        <ul class="mt-2 space-y-2">
            @foreach ($relations as $relation)
                @php($related = $relation->related)
                @continue($related === null)
                <li wire:key="relation-{{ $relation->id }}" class="flex items-center gap-2.5 rounded-lg border border-slate-200 p-2" data-relation="{{ $related->sku }}">
                    @include('livewire.admin.products.partials.thumb', ['product' => $related, 'size' => 'size-9'])
                    <span class="min-w-0 flex-1">
                        @if ($related->trashed())
                            <span class="block truncate text-sm text-slate-400 line-through">{{ $related->name }}</span>
                        @else
                            <a href="{{ route('admin.products.edit', $related) }}" wire:navigate class="block truncate text-sm hover:text-brand-700">{{ $related->name }}</a>
                        @endif
                        <span class="block truncate text-[11px] text-slate-400">
                            <span class="font-mono">{{ $related->sku }}</span>
                            @if ($related->trashed()) · {{ __('admin.products.status.trashed') }}@elseif (! $related->is_active) · {{ __('admin.products.status.draft') }}@endif
                        </span>
                    </span>
                    @if ($canEdit)
                        <button type="button" wire:click="remove({{ $relation->id }})" class="grid size-8 place-items-center rounded-lg text-slate-400 hover:bg-red-50 hover:text-danger" aria-label="{{ __('admin.products.relations.remove') }}: {{ $related->name }}"><x-admin.icon name="x" size="size-4" /></button>
                    @endif
                </li>
            @endforeach
        </ul>
    @empty
        <p class="mt-3 text-sm text-slate-500">{{ __('admin.products.relations.none') }}</p>
    @endforelse

    @if ($canEdit)
        <div class="mt-4 space-y-2 border-t border-slate-100 pt-4">
            <x-admin.select wire:model.live="type" :label="__('admin.products.relations.type')" :options="collect(\App\Enums\RelationType::cases())->mapWithKeys(fn ($type) => [$type->value => $type->label()])->all()" :hint="__('admin.products.relations.type_hint')" />
            <div class="relative">
                <x-admin.icon name="search" size="size-4" class="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-slate-400" />
                <input wire:model.live.debounce.300ms="search" type="search" placeholder="{{ __('admin.products.relations.search') }}" aria-label="{{ __('admin.products.relations.search') }}" class="h-10 w-full rounded-lg border border-slate-200 bg-white pl-9 pr-3 text-base outline-none focus:border-brand-500 focus:ring-4 focus:ring-brand-500/15 sm:text-sm">
            </div>
            @if (mb_strlen(trim($search)) >= 2)
                <ul class="max-h-64 overflow-y-auto rounded-lg border border-slate-200" data-relation-results>
                    @forelse ($this->results as $result)
                        <li wire:key="relation-result-{{ $result->id }}">
                            <button type="button" wire:click="add({{ $result->id }})" class="flex w-full items-center gap-2 px-3 py-2 text-left hover:bg-slate-50">
                                <span class="min-w-0 flex-1">
                                    <span class="block truncate text-sm">{{ $result->name }}</span>
                                    <span class="block truncate text-[11px] text-slate-400"><span class="font-mono">{{ $result->sku }}</span>@unless ($result->is_active) · {{ __('admin.products.status.draft') }}@endunless</span>
                                </span>
                                <x-admin.icon name="plus" size="size-4" class="text-slate-400" />
                            </button>
                        </li>
                    @empty
                        <li class="px-3 py-3 text-center text-sm text-slate-400">{{ __('admin.catalog.no_results') }}</li>
                    @endforelse
                </ul>
            @endif
        </div>
    @endif
</div>
