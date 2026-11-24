{{--
    Settings → Company → Tracking: Yandex Metrika / Direct setup help (plan 40 §5, T6).
    Read-only; shown only once a Metrika counter ID is saved. Goal IDs come from App\Enums\AnalyticsGoal.
--}}
@php
    $h = 'analytics.tracking_help';
    $utmTemplate = 'utm_source=yandex&utm_medium=cpc&utm_campaign={campaign_id}&utm_content={ad_id}&utm_term={keyword}';
@endphp

<x-admin.card :title="__($h.'.title')" :description="__($h.'.subtitle')" data-tracking-help>
    <div class="space-y-6 text-sm">
        <section>
            <h3 class="font-semibold text-slate-900">{{ __($h.'.goals_title') }}</h3>
            <p class="mt-1 text-slate-600">{{ __($h.'.goals_hint') }}</p>
            <div class="mt-3 overflow-x-auto">
                <table class="w-full text-left text-sm" data-goal-table>
                    <thead class="border-b border-slate-200 text-xs font-semibold uppercase tracking-wide text-slate-500">
                        <tr>
                            <th class="py-2 pr-3">{{ __($h.'.columns.id') }}</th>
                            <th class="px-3 py-2">{{ __($h.'.columns.trigger') }}</th>
                            <th class="px-3 py-2">{{ __($h.'.columns.direct') }}</th>
                            <th class="py-2 pl-3">{{ __($h.'.columns.type') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        @foreach (\App\Enums\AnalyticsGoal::cases() as $goal)
                            <tr wire:key="goal-help-{{ $goal->value }}" data-goal="{{ $goal->value }}">
                                <td class="py-2 pr-3 align-top"><x-admin.copy-block :value="$goal->value" :snippet="'goal-'.$goal->value" plain class="!p-1" /></td>
                                <td class="px-3 py-2 align-top text-slate-700">{{ $goal->trigger() }}</td>
                                <td class="px-3 py-2 align-top">
                                    <x-admin.badge :color="match ($goal->directRole()) { 'key' => 'brand', 'never' => 'red', default => 'slate' }">{{ __($h.'.direct.'.$goal->directRole()) }}</x-admin.badge>
                                </td>
                                <td class="py-2 pl-3 align-top text-slate-600">{{ $goal->isOffline() ? __($h.'.type_offline') : __($h.'.type_js') }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <p class="mt-2 text-slate-600">{{ __($h.'.lead_prefix') }}</p>
        </section>

        <section>
            <h3 class="font-semibold text-slate-900">{{ __($h.'.utm_title') }}</h3>
            <p class="mt-1 text-slate-600">{{ __($h.'.utm_hint') }}</p>
            <x-admin.copy-block class="mt-2" :value="$utmTemplate" snippet="utm-template" plain wrap :label="__($h.'.utm_title')" />
        </section>

        <section>
            <h3 class="font-semibold text-slate-900">{{ __($h.'.offline_title') }}</h3>
            <ol class="mt-2 list-decimal space-y-1 pl-5 text-slate-600">
                <li>{{ __($h.'.offline_steps.switch') }}</li>
                <li>{{ __($h.'.offline_steps.metrika') }}</li>
                <li>{{ __($h.'.offline_steps.download', ['days' => \App\Actions\Analytics\ExportOfflineConversions::maxDaysBack()]) }}</li>
                <li>{{ __($h.'.offline_steps.upload') }}</li>
            </ol>
            @can('analytics.view')
                <a href="{{ route('admin.analytics.campaigns') }}" wire:navigate class="mt-2 inline-block font-semibold text-brand-700 underline" data-campaigns-link>{{ __($h.'.campaigns_link') }}</a>
            @endcan
        </section>

        <section>
            <h3 class="font-semibold text-slate-900">{{ __($h.'.notes_title') }}</h3>
            <ul class="mt-2 list-disc space-y-1 pl-5 text-slate-600">
                <li>{{ __($h.'.notes.staff') }}</li>
                <li>{{ __($h.'.notes.production') }}</li>
                <li>{{ __($h.'.notes.debug') }}</li>
                <li>{{ __($h.'.notes.brand') }}</li>
            </ul>
        </section>
    </div>
</x-admin.card>
