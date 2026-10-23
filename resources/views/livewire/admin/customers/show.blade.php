@php
    $tz = \App\Models\Lead::TIMEZONE;
    $when = fn ($date, $format = 'D MMM YYYY, HH:mm') => $date ? \App\Support\DateFormat::iso($date->copy()->setTimezone($tz), $format) : __('admin.common.empty_value');
    $tabs = collect(\App\Livewire\Admin\Customers\Show::TABS)->mapWithKeys(fn ($tab) => [$tab => __('crm.customers.tabs.'.$tab)]);
@endphp

<div>
    <x-admin.page-header :title="$customer->name" :back-href="route('admin.customers.index')" :back-label="__('crm.customers.title')">
        @if ($canEdit)
            <x-slot:actions>
                <x-admin.button variant="secondary" :href="route('admin.customers.edit', $customer)" wire:navigate icon="edit">{{ __('admin.common.edit') }}</x-admin.button>
            </x-slot:actions>
        @endif
    </x-admin.page-header>

    <div class="mt-3 flex flex-wrap items-center gap-1.5">
        <x-admin.badge color="outline">{{ $customer->type->label() }}</x-admin.badge>
        <x-admin.badge :color="$customer->price_level->color()" data-price-level="{{ $customer->price_level->value }}">{{ __('crm.customers.fields.price_level') }}: {{ $customer->price_level->label() }}</x-admin.badge>
        @if (! $canEdit)
            <x-admin.badge color="muted" data-read-only><x-admin.icon name="eye" size="size-3.5" />{{ __('crm.leads.read_only') }}</x-admin.badge>
        @endif
    </div>

    <div class="mt-5 grid grid-cols-1 gap-4 lg:grid-cols-3 lg:gap-6">
        <div class="min-w-0 lg:col-span-2">
            <div class="scrollbar-none -mx-4 overflow-x-auto border-b border-slate-200 px-4 sm:mx-0 sm:px-0" role="tablist" aria-label="{{ __('crm.customers.tabs_label') }}">
                <div class="flex gap-6">
                    @foreach ($tabs as $key => $label)
                        <button type="button" role="tab" wire:click="$set('tab', '{{ $key }}')" aria-selected="{{ $tab === $key ? 'true' : 'false' }}" data-tab="{{ $key }}" @class([
                            '-mb-px flex items-center gap-2 whitespace-nowrap border-b-2 pb-3 text-sm font-semibold',
                            'border-brand-600 text-slate-900' => $tab === $key,
                            'border-transparent text-slate-500 hover:text-slate-700' => $tab !== $key,
                        ])>
                            {{ $label }}
                            @if ($key === 'orders')
                                <span class="rounded-full bg-slate-100 px-1.5 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-slate-500">{{ __('crm.customers.later') }}</span>
                            @endif
                        </button>
                    @endforeach
                </div>
            </div>

            <div class="mt-4">
                @if ($tab === 'leads')
                    <x-admin.card :padding="false" data-customer-leads>
                        @if ($this->leads->isEmpty())
                            <p class="px-4 py-6 text-center text-sm text-slate-500 sm:px-6">{{ __('crm.customers.no_leads') }}</p>
                        @else
                            <ul class="divide-y divide-slate-100">
                                @foreach ($this->leads as $lead)
                                    <li wire:key="lead-{{ $lead->id }}">
                                        <a href="{{ route('admin.leads.show', $lead) }}" wire:navigate class="flex items-start gap-3 px-4 py-3 hover:bg-slate-50 sm:px-6">
                                            <span class="min-w-0 flex-1">
                                                <span class="block truncate text-sm font-semibold"><span class="font-mono">{{ $lead->reference() }}</span> · {{ $lead->type->label() }}</span>
                                                <span class="block truncate text-sm text-slate-500">@include('livewire.admin.leads.partials.need', ['lead' => $lead])</span>
                                                <span class="mt-1 block text-xs text-slate-400">{{ $when($lead->created_at, 'D MMM YYYY') }} · {{ $lead->manager?->name ?? __('crm.leads.unassigned') }}</span>
                                            </span>
                                            <x-admin.badge :color="$lead->status->color()" dot>{{ $lead->status->label() }}</x-admin.badge>
                                        </a>
                                    </li>
                                @endforeach
                            </ul>
                        @endif
                        @if ($this->hiddenLeads > 0)
                            <p class="border-t border-slate-100 px-4 py-3 text-sm text-slate-500 sm:px-6" data-hidden-leads>{{ trans_choice('crm.customers.hidden_leads', $this->hiddenLeads, ['count' => $this->hiddenLeads]) }}</p>
                        @endif
                    </x-admin.card>
                @elseif ($tab === 'history')
                    <x-admin.card>
                        @if ($this->activities->isEmpty())
                            <p class="text-sm text-slate-500">{{ __('crm.customers.no_history') }}</p>
                        @else
                            <ol class="space-y-4 border-l border-slate-200 pl-5" data-timeline>
                                @foreach ($this->activities as $activity)
                                    <li wire:key="activity-{{ $activity->id }}" class="relative" data-activity="{{ $activity->type->value }}">
                                        <span @class(['absolute -left-[1.6rem] top-1 size-2.5 rounded-full ring-4 ring-white', $activity->type->dot()]) aria-hidden="true"></span>
                                        <p class="text-sm">
                                            @if ($activity->user)<b class="font-semibold">{{ $activity->user->name }}</b> · @endif
                                            @php($meta = $activity->meta ?? [])
                                            @if ($activity->type === \App\Enums\ActivityType::Conversion && isset($meta['lead_id']))
                                                {{ __('crm.customers.timeline.converted_from', ['reference' => \App\Models\Lead::REFERENCE_PREFIX.$meta['lead_id']]) }}
                                                @if (! empty($meta['contact_name']))<span class="text-slate-500">· {{ __('crm.customers.timeline.contact_added', ['name' => $meta['contact_name']]) }}</span>@endif
                                            @elseif ($activity->type === \App\Enums\ActivityType::Assignment)
                                                {{ __('crm.customers.timeline.manager', ['from' => $meta['from_name'] ?? __('crm.customers.unassigned'), 'to' => $meta['to_name'] ?? __('crm.customers.unassigned')]) }}
                                            @elseif ($activity->type === \App\Enums\ActivityType::PriceLevelChange)
                                                {{ __('crm.customers.timeline.price_level', ['from' => \App\Enums\PriceLevel::tryFrom((string) ($meta['from'] ?? ''))?->label() ?? '—', 'to' => \App\Enums\PriceLevel::tryFrom((string) ($meta['to'] ?? ''))?->label() ?? '—']) }}
                                            @elseif ($activity->type === \App\Enums\ActivityType::Quote)
                                                @include('livewire.admin.quotes.partials.activity', ['activity' => $activity])
                                            @elseif ($activity->type === \App\Enums\ActivityType::Created)
                                                {{ __('crm.customers.timeline.created') }}
                                            @else
                                                {{ $activity->type->label() }}
                                            @endif
                                        </p>
                                        @if (filled($activity->body))
                                            <p class="mt-1 whitespace-pre-line break-words rounded-lg bg-slate-50 px-3 py-2 text-sm text-slate-700">{{ $activity->body }}</p>
                                        @endif
                                        <p class="mt-0.5 text-xs text-slate-400">{{ $when($activity->created_at) }}</p>
                                    </li>
                                @endforeach
                            </ol>
                        @endif
                    </x-admin.card>
                @elseif ($tab === 'quotes')
                    <x-admin.card :padding="false" data-customer-quotes>
                        @if ($canCreateQuote)
                            <x-slot:actions>
                                <x-admin.button size="sm" icon="plus" :href="route('admin.quotes.create', ['customer' => $customer->id])" wire:navigate>{{ __('crm.quotes.create') }}</x-admin.button>
                            </x-slot:actions>
                        @endif
                        @if ($this->quotes->isEmpty())
                            <p class="px-4 py-6 text-center text-sm text-slate-500 sm:px-6">{{ __('crm.quotes.none_for_customer') }}</p>
                        @else
                            <ul class="divide-y divide-slate-100">
                                @foreach ($this->quotes as $quote)
                                    <li wire:key="quote-{{ $quote->id }}">
                                        <a href="{{ route('admin.quotes.show', $quote) }}" wire:navigate class="flex items-center gap-3 px-4 py-3 hover:bg-slate-50 sm:px-6">
                                            <span class="min-w-0 flex-1">
                                                <span class="block font-mono text-sm font-semibold">{{ $quote->number }}</span>
                                                <span class="block text-xs text-slate-500">{{ $when($quote->created_at, 'D MMM YYYY') }} · {{ __('crm.quotes.fields.valid_until') }}: {{ \App\Support\DateFormat::iso($quote->valid_until, 'D MMM YYYY') }}</span>
                                            </span>
                                            <span class="text-sm font-semibold tabular-nums">{{ \App\Support\Money::format($quote->total) }}</span>
                                            @include('livewire.admin.quotes.partials.status', ['quote' => $quote])
                                        </a>
                                    </li>
                                @endforeach
                            </ul>
                        @endif
                    </x-admin.card>
                @else
                    <x-admin.empty-state icon="bag" :title="__('crm.customers.later_title')" :body="__('crm.customers.later_'.$tab)" data-later="{{ $tab }}" />
                @endif
            </div>
        </div>

        <aside class="min-w-0 space-y-4 lg:space-y-6">
            <x-admin.card :title="__('crm.customers.sections.requisites')">
                <dl class="space-y-2 text-sm" data-requisites>
                    @foreach (array_filter([
                        __('crm.customers.fields.inn') => $customer->inn,
                        __('crm.customers.fields.phone') => $customer->phone ? \App\Support\Phone::display($customer->phone) : null,
                        __('crm.customers.fields.email') => $customer->email,
                        __('crm.customers.fields.address') => $customer->address,
                        __('crm.customers.fields.telegram_id') => $customer->telegram_id,
                        __('crm.customers.fields.created_at') => $when($customer->created_at, 'D MMM YYYY'),
                    ], fn ($value) => filled($value)) as $label => $value)
                        <div class="flex gap-3"><dt class="w-28 shrink-0 text-slate-500">{{ $label }}</dt><dd class="min-w-0 break-words">{{ $value }}</dd></div>
                    @endforeach
                </dl>
                @if (filled($customer->notes))
                    <p class="mt-3 whitespace-pre-line break-words rounded-lg bg-slate-50 px-3 py-2 text-sm text-slate-700">{{ $customer->notes }}</p>
                @endif
            </x-admin.card>

            <x-admin.card :title="__('crm.customers.fields.manager')">
                @include('livewire.admin.customers.partials.manager', ['customer' => $customer])
            </x-admin.card>

            <x-admin.card :title="__('crm.customers.sections.contacts')">
                @if ($customer->contacts->isEmpty())
                    <p class="text-sm text-slate-500">{{ __('crm.customers.contacts.empty') }}</p>
                @else
                    <ul class="space-y-4" data-contact-list>
                        @foreach ($customer->contacts as $contact)
                            <li wire:key="contact-{{ $contact->id }}">
                                <p class="flex items-center gap-2 text-sm font-semibold">{{ $contact->name }}@if ($contact->is_primary)<x-admin.badge color="brand">{{ __('crm.customers.contacts.primary') }}</x-admin.badge>@endif</p>
                                @if ($contact->position)<p class="text-xs text-slate-500">{{ $contact->position }}</p>@endif
                                <div class="mt-1.5 flex flex-wrap gap-2 text-sm">
                                    @if ($contact->phone)<a href="tel:{{ $contact->phone }}" class="inline-flex items-center gap-1.5 text-brand-700 hover:underline"><x-admin.icon name="phone" size="size-4" />{{ \App\Support\Phone::display($contact->phone) }}</a>@endif
                                    @if ($contact->email)<a href="mailto:{{ $contact->email }}" class="inline-flex items-center gap-1.5 text-sky-700 hover:underline"><x-admin.icon name="mail" size="size-4" />{{ $contact->email }}</a>@endif
                                    @if ($contact->telegram)<span class="inline-flex items-center gap-1.5 text-slate-600"><x-admin.icon name="telegram" size="size-4" />{{ $contact->telegram }}</span>@endif
                                </div>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </x-admin.card>

            @if ($canDelete)
                <section class="rounded-2xl border border-red-200 bg-white p-4 sm:p-5">
                    <h2 class="text-sm font-semibold text-danger">{{ __('crm.leads.danger_zone') }}</h2>
                    <p class="mt-1 text-xs text-slate-500">{{ __('crm.customers.delete_help') }}</p>
                    <button
                        type="button"
                        x-on:click="$dispatch('confirm', {
                            title: @js(__('crm.customers.delete_title')),
                            body: @js(__('crm.customers.delete_body', ['name' => $customer->name])),
                            label: @js(__('admin.common.delete')),
                            component: $wire.$id, method: 'delete',
                        })"
                        class="mt-3 h-10 w-full rounded-lg border border-red-200 text-sm font-semibold text-danger hover:bg-red-50"
                    >{{ __('admin.common.delete') }}</button>
                </section>
            @endif
        </aside>
    </div>
</div>
