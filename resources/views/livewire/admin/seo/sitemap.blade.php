@php($t = 'sitemap')

<div data-sitemap-screen>
    <x-admin.page-header :title="__($t.'.title')" :subtitle="__($t.'.subtitle')">
        <x-slot:breadcrumbs>{{ __('menu.groups.seo') }} <span class="text-slate-300">/</span> {{ __($t.'.title') }}</x-slot:breadcrumbs>
        <x-slot:actions>
            <x-admin.button :href="route('sitemap')" variant="secondary" icon="external" target="_blank" rel="noopener">{{ __($t.'.open_xml') }}</x-admin.button>
            @if ($canRefresh)
                <x-admin.button wire:click="refresh" loading="refresh" icon="restore" data-sitemap-refresh>{{ __($t.'.refresh') }}</x-admin.button>
            @endif
        </x-slot:actions>
    </x-admin.page-header>

    <div class="mt-5 space-y-4 lg:space-y-6">
        <x-admin.card :title="__($t.'.status_title')" :description="__($t.'.status_hint')">
            @if ($this->payload === null)
                <p class="text-sm text-slate-600" data-sitemap-state="empty">{{ __($t.'.not_generated') }}</p>
            @else
                <div data-sitemap-state="ready">
                    <p class="text-sm text-slate-600">
                        {{ __($t.'.generated_at') }}: <span class="font-semibold text-slate-900" data-sitemap-generated>{{ $this->generatedAt }}</span>
                        <span class="text-slate-300">·</span>
                        {{ __($t.'.expires_at') }}: <span class="font-semibold text-slate-900" data-sitemap-expires>{{ $this->expiresAt }}</span>
                        <span class="text-xs text-slate-400">({{ \App\Livewire\Admin\Seo\Sitemap::TIMEZONE }})</span>
                    </p>
                    <dl class="mt-4 grid grid-cols-2 gap-3 sm:grid-cols-4 lg:grid-cols-8">
                        <div class="rounded-xl border border-slate-200 p-3">
                            <dt class="text-xs font-semibold uppercase tracking-wide text-slate-500">{{ __($t.'.counts.total') }}</dt>
                            <dd class="mt-1 text-lg font-semibold" data-sitemap-count="total">{{ array_sum($this->payload['counts']) }}</dd>
                        </div>
                        @foreach (\App\Support\Site\Sitemap::TYPES as $type)
                            <div class="rounded-xl border border-slate-200 p-3" wire:key="count-{{ $type }}">
                                <dt class="text-xs font-semibold uppercase tracking-wide text-slate-500">{{ __($t.'.counts.'.$type) }}</dt>
                                <dd class="mt-1 text-lg font-semibold" data-sitemap-count="{{ $type }}">{{ $this->payload['counts'][$type] ?? 0 }}</dd>
                            </div>
                        @endforeach
                    </dl>
                </div>
            @endif
        </x-admin.card>

        <x-admin.card :title="__($t.'.preview_title')">
            <x-slot:actions>
                <div class="inline-flex rounded-lg border border-slate-200 p-0.5 text-sm font-semibold" role="tablist">
                    <button type="button" role="tab" wire:click="showTab('urls')" aria-selected="{{ $tab === 'urls' ? 'true' : 'false' }}" @class(['rounded-md px-3 py-1.5', 'bg-brand-50 text-brand-700' => $tab === 'urls', 'text-slate-600' => $tab !== 'urls'])>{{ __($t.'.tab_urls') }}</button>
                    <button type="button" role="tab" wire:click="showTab('xml')" aria-selected="{{ $tab === 'xml' ? 'true' : 'false' }}" @class(['rounded-md px-3 py-1.5', 'bg-brand-50 text-brand-700' => $tab === 'xml', 'text-slate-600' => $tab !== 'xml'])>{{ __($t.'.tab_xml') }}</button>
                </div>
            </x-slot:actions>

            @if ($this->payload === null)
                <x-admin.empty-state icon="globe" :title="__($t.'.not_generated')" :body="__($t.'.not_generated_body')" />
            @elseif ($tab === 'xml')
                @if ($this->rawXml['truncated'])
                    <p class="mb-2 text-xs text-slate-500" data-sitemap-truncated>{{ __($t.'.raw_truncated', ['lines' => \App\Livewire\Admin\Seo\Sitemap::RAW_LINES]) }}</p>
                @endif
                <pre class="max-h-[32rem] overflow-auto rounded-xl bg-slate-900 p-4 text-xs leading-relaxed text-slate-100" data-sitemap-raw>{{ $this->rawXml['text'] }}</pre>
            @else
                <label class="relative block">
                    <span class="sr-only">{{ __('admin.common.search') }}</span>
                    <x-admin.icon name="search" class="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-slate-400" />
                    <input wire:model.live.debounce.300ms="search" type="search" maxlength="200" placeholder="{{ __($t.'.search_placeholder') }}" class="h-11 w-full rounded-lg border border-slate-200 bg-white pl-10 pr-3 text-base outline-none placeholder:text-slate-400 focus:border-brand-500 focus:ring-4 focus:ring-brand-500/15 sm:text-sm lg:h-10">
                </label>

                <div class="relative mt-4">
                    <div wire:loading.delay class="absolute inset-0 z-10 rounded-xl bg-white/50" aria-hidden="true"></div>

                    @if ($this->urls === null || $this->urls->isEmpty())
                        <x-admin.empty-state icon="search" :title="__('admin.catalog.no_results')" :body="__('admin.catalog.no_results_body')" />
                    @else
                        <div class="overflow-x-auto rounded-xl border border-slate-200">
                            <table class="w-full text-left text-sm">
                                <caption class="sr-only">{{ __($t.'.preview_title') }}</caption>
                                <thead class="border-b border-slate-200 bg-slate-50/70 text-xs font-semibold uppercase tracking-wide text-slate-500">
                                    <tr>
                                        <th scope="col" class="px-4 py-3">{{ __($t.'.col_loc') }}</th>
                                        <th scope="col" class="px-4 py-3">{{ __($t.'.col_lastmod') }}</th>
                                        <th scope="col" class="px-4 py-3">{{ __($t.'.col_locale') }}</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-slate-100">
                                    @foreach ($this->urls as $row)
                                        <tr wire:key="url-{{ $this->urls->currentPage() }}-{{ $loop->index }}" data-sitemap-row>
                                            <td class="max-w-xl break-all px-4 py-2.5 font-mono text-xs">{{ $row['loc'] }}</td>
                                            <td class="whitespace-nowrap px-4 py-2.5 text-slate-600">{{ $row['lastmod'] ?? __('admin.common.empty_value') }}</td>
                                            <td class="whitespace-nowrap px-4 py-2.5 uppercase text-slate-600">{{ $row['locale'] }}</td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                        <div class="mt-4">{{ $this->urls->links() }}</div>
                    @endif
                </div>
            @endif
        </x-admin.card>
    </div>
</div>
