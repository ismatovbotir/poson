{{--
    "Print receipt" button + small UZ/RU/EN menu (plan 35). Opens the PDF in a new tab; the default
    language is the repair's locale. Needs $repair.
--}}
@can('printReceipt', $repair)
    <div class="relative" x-data="{ open: false }" x-on:click.outside="open = false" x-on:keydown.escape.window="open = false" data-print-receipt>
        <div class="inline-flex">
            <x-admin.button variant="secondary" :href="route('admin.repairs.receipt', ['repair' => $repair, 'lang' => $repair->locale])" target="_blank" rel="noopener" icon="printer" class="rounded-r-none" data-print-link>{{ __('repairs.receipt.print') }}</x-admin.button>
            <button type="button" x-on:click="open = ! open" class="inline-flex h-11 items-center rounded-r-lg border border-l-0 border-slate-200 bg-white px-2 text-slate-600 hover:bg-slate-50 lg:h-10" aria-haspopup="true" :aria-expanded="open" aria-label="{{ __('repairs.receipt.print_language') }}">
                <x-admin.icon name="chevron-down" size="size-4" />
            </button>
        </div>
        <ul x-show="open" x-cloak class="absolute right-0 z-20 mt-1 w-40 rounded-lg border border-slate-200 bg-white py-1 text-sm shadow-lg">
            @foreach (config('app.supported_locales') as $lang)
                <li>
                    <a href="{{ route('admin.repairs.receipt', ['repair' => $repair, 'lang' => $lang]) }}" target="_blank" rel="noopener" class="block px-3 py-2 text-slate-700 hover:bg-slate-50" data-print-lang="{{ $lang }}">{{ __('repairs.locales.'.$lang) }}</a>
                </li>
            @endforeach
        </ul>
    </div>
@endcan
