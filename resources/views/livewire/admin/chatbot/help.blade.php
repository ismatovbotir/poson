@php($t = 'chatbot_help')

<div data-chatbot-help>
    <x-admin.page-header :title="__($t.'.title')" :subtitle="__($t.'.subtitle')">
        <x-slot:breadcrumbs>{{ __('menu.groups.chatbot') }} <span class="text-slate-300">/</span> {{ __($t.'.title') }}</x-slot:breadcrumbs>
    </x-admin.page-header>

    <div class="mt-5 grid gap-6 lg:grid-cols-4">
        <nav class="lg:sticky lg:top-24 lg:self-start" aria-label="{{ __($t.'.contents') }}">
            <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">{{ __($t.'.contents') }}</p>
            <ul class="mt-2 space-y-1 text-sm">
                @foreach ($sections as $section)
                    <li><a href="#help-{{ $section }}" class="block rounded-lg px-3 py-1.5 text-slate-600 hover:bg-slate-100 hover:text-slate-900">{{ __($t.'.sections.'.$section.'.title') }}</a></li>
                @endforeach
            </ul>
        </nav>

        <div class="min-w-0 space-y-4 lg:col-span-3 lg:space-y-6">
            @foreach ($sections as $section)
                <x-admin.card :title="__($t.'.sections.'.$section.'.title')" id="help-{{ $section }}" class="scroll-mt-24" data-help-section="{{ $section }}">
                    <div class="space-y-3 text-sm text-slate-700">
                        @if (__($t.'.sections.'.$section.'.intro') !== $t.'.sections.'.$section.'.intro')
                            <p>{{ __($t.'.sections.'.$section.'.intro') }}</p>
                        @endif
                        <ol class="list-decimal space-y-2 pl-5 marker:font-semibold marker:text-slate-400">
                            @foreach ((array) __($t.'.sections.'.$section.'.steps') as $step)
                                <li>{{ $step }}</li>
                            @endforeach
                        </ol>
                        @if (is_array(__($t.'.sections.'.$section.'.examples')))
                            <div class="overflow-x-auto rounded-lg border border-slate-200">
                                <table class="w-full text-left text-sm">
                                    <thead class="bg-slate-50 text-xs text-slate-500">
                                        <tr>
                                            @foreach ((array) __($t.'.example_columns') as $column)
                                                <th class="px-3 py-2 font-semibold">{{ $column }}</th>
                                            @endforeach
                                        </tr>
                                    </thead>
                                    <tbody class="divide-y divide-slate-100">
                                        @foreach ((array) __($t.'.sections.'.$section.'.examples') as $row)
                                            <tr>
                                                @foreach ($row as $cell)
                                                    <td class="px-3 py-2">{{ $cell }}</td>
                                                @endforeach
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            </div>
                        @endif
                        @if (__($t.'.sections.'.$section.'.note') !== $t.'.sections.'.$section.'.note')
                            <p class="rounded-lg bg-slate-50 px-3 py-2.5 text-slate-600">{{ __($t.'.sections.'.$section.'.note') }}</p>
                        @endif
                    </div>
                </x-admin.card>
            @endforeach
        </div>
    </div>
</div>
