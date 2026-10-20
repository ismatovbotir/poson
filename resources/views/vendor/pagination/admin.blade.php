{{--
    Admin paginator (Livewire): use the App\Livewire\Admin\Concerns\WithAdminPagination trait,
    or $items->links('vendor.pagination.admin').
--}}
@if ($paginator->hasPages())
    <nav class="mt-4 flex items-center justify-between gap-3" aria-label="{{ __('admin.pagination.label') }}">
        <p class="text-sm text-slate-500">
            {{ __('admin.pagination.showing', [
                'from' => $paginator->firstItem(),
                'to' => $paginator->lastItem(),
                'total' => number_format($paginator->total(), 0, ',', ' '),
            ]) }}
        </p>
        <div class="flex items-center gap-1">
            @if ($paginator->onFirstPage())
                <span class="grid size-10 place-items-center rounded-lg border border-slate-200 bg-white text-slate-300" aria-disabled="true"><span class="sr-only">{{ __('admin.pagination.previous') }}</span><x-admin.icon name="chevron-left" size="size-4" /></span>
            @else
                <button type="button" wire:click="previousPage('{{ $paginator->getPageName() }}')" wire:loading.attr="disabled" class="grid size-10 place-items-center rounded-lg border border-slate-200 bg-white text-slate-700 hover:bg-slate-50" aria-label="{{ __('admin.pagination.previous') }}"><x-admin.icon name="chevron-left" size="size-4" /></button>
            @endif

            @foreach ($elements as $element)
                @if (is_string($element))
                    <span class="hidden px-1 text-slate-400 sm:inline" aria-hidden="true">{{ $element }}</span>
                @endif

                @if (is_array($element))
                    @foreach ($element as $page => $url)
                        @if ($page == $paginator->currentPage())
                            <span aria-current="page" class="hidden size-10 place-items-center rounded-lg bg-slate-900 text-sm font-semibold text-white sm:grid">{{ $page }}</span>
                        @else
                            <button type="button" wire:key="paginator-{{ $paginator->getPageName() }}-page-{{ $page }}" wire:click="gotoPage({{ $page }}, '{{ $paginator->getPageName() }}')" class="hidden size-10 place-items-center rounded-lg text-sm font-semibold text-slate-600 hover:bg-white sm:grid" aria-label="{{ __('admin.pagination.page', ['page' => $page]) }}">{{ $page }}</button>
                        @endif
                    @endforeach
                @endif
            @endforeach

            <span class="px-2 text-sm font-medium text-slate-600 sm:hidden">{{ $paginator->currentPage() }} / {{ $paginator->lastPage() }}</span>

            @if ($paginator->hasMorePages())
                <button type="button" wire:click="nextPage('{{ $paginator->getPageName() }}')" wire:loading.attr="disabled" class="grid size-10 place-items-center rounded-lg border border-slate-200 bg-white text-slate-700 hover:bg-slate-50" aria-label="{{ __('admin.pagination.next') }}"><x-admin.icon name="chevron-right" size="size-4" /></button>
            @else
                <span class="grid size-10 place-items-center rounded-lg border border-slate-200 bg-white text-slate-300" aria-disabled="true"><span class="sr-only">{{ __('admin.pagination.next') }}</span><x-admin.icon name="chevron-right" size="size-4" /></span>
            @endif
        </div>
    </nav>
@endif
