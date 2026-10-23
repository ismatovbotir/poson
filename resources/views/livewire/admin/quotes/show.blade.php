@php
    $tz = \App\Models\Lead::TIMEZONE;
    $money = fn (int $tiyin) => \App\Support\Money::format($tiyin);
    $when = fn ($date, $format = 'D MMM YYYY, HH:mm') => $date ? \App\Support\DateFormat::iso($date->copy()->setTimezone($tz), $format) : __('admin.common.empty_value');
    $customer = $quote->customer;
@endphp

<div>
    <x-admin.page-header :title="__('crm.quotes.show_title', ['number' => $quote->number])" :back-href="route('admin.quotes.index')" :back-label="__('crm.quotes.title')">
        <x-slot:actions>
            <div class="flex flex-wrap gap-2">
                <x-admin.button variant="secondary" icon="download" :href="route('admin.quotes.pdf', $quote)" data-action="pdf">{{ __('crm.quotes.actions.pdf') }}</x-admin.button>
                @if ($canChangeStatus && $quote->status->isEditable())
                    <x-admin.button
                        variant="secondary"
                        icon="link"
                        x-data
                        x-on:click="$wire.copyLink().then(url => navigator.clipboard?.writeText(url))"
                        data-action="copy-link"
                    >{{ __('crm.quotes.actions.copy_link') }}</x-admin.button>
                @endif
                @if ($canEdit)
                    <x-admin.button variant="secondary" icon="edit" :href="route('admin.quotes.edit', $quote)" wire:navigate>{{ __('admin.common.edit') }}</x-admin.button>
                @endif
            </div>
        </x-slot:actions>
    </x-admin.page-header>

    <div class="mt-3 flex flex-wrap items-center gap-1.5 text-sm text-slate-500">
        @include('livewire.admin.quotes.partials.status', ['quote' => $quote])
        <span class="text-slate-300">·</span>
        <span>{{ $when($quote->created_at, 'D MMMM YYYY') }}</span>
        <span class="text-slate-300">·</span>
        <span>{{ __('crm.quotes.fields.valid_until') }}: <b class="font-semibold text-slate-700">{{ \App\Support\DateFormat::iso($quote->valid_until, 'D MMMM YYYY') }}</b></span>
        <x-admin.badge color="outline">{{ __('crm.customers.fields.price_level') }}: {{ $quote->price_level->label() }}</x-admin.badge>
        <x-admin.badge color="outline">{{ strtoupper($quote->locale) }}</x-admin.badge>
    </div>

    <div class="mt-5 grid grid-cols-1 gap-4 lg:grid-cols-3 lg:gap-6">
        <div class="min-w-0 space-y-4 lg:col-span-2 lg:space-y-6">
            {{-- Status --}}
            @if ($canChangeStatus && ($quote->status === \App\Enums\QuoteStatus::Draft || $closing !== []))
                <x-admin.card :title="__('crm.quotes.sections.status')">
                    <div class="flex flex-wrap gap-2" data-status-control>
                        @if ($quote->status === \App\Enums\QuoteStatus::Draft)
                            <x-admin.button size="sm" icon="check" wire:click="markSent" loading="markSent" data-status-to="sent">{{ __('crm.quotes.actions.mark_sent') }}</x-admin.button>
                        @endif
                        @foreach ($closing as $status)
                            <x-admin.button size="sm" :variant="$status === \App\Enums\QuoteStatus::Accepted ? 'primary' : 'secondary'" wire:click="changeStatus('{{ $status->value }}')" data-status-to="{{ $status->value }}">{{ __('crm.quotes.actions.'.$status->value) }}</x-admin.button>
                        @endforeach
                    </div>
                </x-admin.card>
            @endif

            {{-- Lines --}}
            <x-admin.card :title="__('crm.quotes.items.title')" :padding="false">
                <ul class="divide-y divide-slate-100" data-quote-items>
                    @foreach ($quote->items as $item)
                        <li wire:key="line-{{ $item->id }}" class="flex flex-wrap items-start gap-3 px-4 py-3.5 sm:px-6">
                            <div class="min-w-0 flex-1">
                                @if ($canViewProducts && $item->product && ! $item->product->trashed())
                                    <a href="{{ route('admin.products.edit', $item->product) }}" wire:navigate class="text-sm font-semibold hover:text-brand-700">{{ $item->name }}</a>
                                @else
                                    <p class="text-sm font-semibold">{{ $item->name }}</p>
                                @endif
                                @if ($item->sku)<p class="font-mono text-xs text-slate-500">{{ $item->sku }}</p>@endif
                            </div>
                            <p class="text-sm tabular-nums text-slate-600">{{ \App\Support\NumberFormat::format($item->qty) }} {{ $item->unit?->label() }} × {{ $money($item->price) }}</p>
                            <p class="w-36 text-right text-sm font-semibold tabular-nums">{{ $money($item->total) }}</p>
                        </li>
                    @endforeach
                </ul>
                <dl class="space-y-2 border-t border-slate-100 bg-slate-50/60 px-4 py-4 text-sm sm:px-6" data-totals>
                    <div class="flex justify-between text-base font-bold"><dt>{{ __('crm.quotes.total') }}</dt><dd class="tabular-nums" data-total>{{ $money($quote->total) }}</dd></div>
                    <div class="flex justify-between text-slate-500"><dt>{{ __('crm.quotes.vat_included', ['percent' => \App\Models\Quote::VAT_PERCENT]) }}</dt><dd class="tabular-nums" data-vat>{{ $money($quote->vat_amount) }}</dd></div>
                </dl>
            </x-admin.card>

            @if (filled($quote->notes))
                <x-admin.card :title="__('crm.quotes.fields.notes')">
                    <p class="whitespace-pre-line break-words text-sm">{{ $quote->notes }}</p>
                </x-admin.card>
            @endif

            {{-- History --}}
            <x-admin.card :title="__('crm.leads.sections.activity')">
                <ol class="space-y-4 border-l border-slate-200 pl-5" data-timeline>
                    @foreach ($this->activities as $activity)
                        <li wire:key="activity-{{ $activity->id }}" class="relative">
                            <span @class(['absolute -left-[1.6rem] top-1 size-2.5 rounded-full ring-4 ring-white', $activity->type->dot()]) aria-hidden="true"></span>
                            <p class="text-sm">@if ($activity->user)<b class="font-semibold">{{ $activity->user->name }}</b> · @endif@include('livewire.admin.quotes.partials.activity', ['activity' => $activity])</p>
                            <p class="mt-0.5 text-xs text-slate-400">{{ $when($activity->created_at) }}</p>
                        </li>
                    @endforeach
                </ol>
            </x-admin.card>
        </div>

        <aside class="min-w-0 space-y-4 lg:space-y-6">
            <x-admin.card :title="__('crm.quotes.fields.customer')">
                @if ($canViewCustomer)
                    <a href="{{ route('admin.customers.show', $customer) }}" wire:navigate class="text-sm font-semibold hover:text-brand-700">{{ $customer->name }}</a>
                @else
                    <p class="text-sm font-semibold">{{ $customer->name }}</p>
                @endif
                <dl class="mt-3 space-y-1.5 text-sm">
                    @if ($customer->inn)<div class="flex gap-3"><dt class="w-24 text-slate-500">{{ __('crm.customers.fields.inn') }}</dt><dd>{{ $customer->inn }}</dd></div>@endif
                    @if ($customer->primaryContact)<div class="flex gap-3"><dt class="w-24 text-slate-500">{{ __('crm.customers.columns.contact') }}</dt><dd>{{ $customer->primaryContact->name }}</dd></div>@endif
                    @if ($customer->phone)<div class="flex gap-3"><dt class="w-24 text-slate-500">{{ __('crm.customers.fields.phone') }}</dt><dd>{{ \App\Support\Phone::display($customer->phone) }}</dd></div>@endif
                </dl>
                @if ($quote->lead)
                    <p class="mt-3 text-sm text-slate-500">{{ __('crm.quotes.fields.lead') }}:
                        @if ($canViewLead)
                            <a href="{{ route('admin.leads.show', $quote->lead) }}" wire:navigate class="font-mono font-semibold text-brand-700 hover:underline">{{ $quote->lead->reference() }}</a>
                        @else
                            <span class="font-mono">{{ $quote->lead->reference() }}</span>
                        @endif
                    </p>
                @endif
            </x-admin.card>

            <x-admin.card :title="__('crm.quotes.sections.sending')">
                @if ($publicUrl)
                    <p class="break-all font-mono text-xs text-slate-600" data-public-url>{{ $publicUrl }}</p>
                    <p class="mt-2 text-xs text-slate-500">{{ __('crm.quotes.public_link_hint') }}</p>
                @else
                    <p class="text-sm text-slate-500">{{ __('crm.quotes.public_link_draft') }}</p>
                @endif
                <dl class="mt-3 space-y-1.5 text-sm">
                    <div class="flex gap-3"><dt class="w-28 text-slate-500">{{ __('crm.quotes.fields.manager') }}</dt><dd>{{ $quote->manager?->name ?? __('admin.common.empty_value') }}</dd></div>
                    <div class="flex gap-3"><dt class="w-28 text-slate-500">{{ __('crm.quotes.fields.sent_at') }}</dt><dd>{{ $when($quote->sent_at) }}</dd></div>
                    @if ($quote->accepted_at)<div class="flex gap-3"><dt class="w-28 text-slate-500">{{ __('crm.quotes.fields.accepted_at') }}</dt><dd>{{ $when($quote->accepted_at) }}</dd></div>@endif
                </dl>
            </x-admin.card>

            @if ($canDuplicate || $canDelete)
                <section class="space-y-2 rounded-2xl border border-slate-200 bg-white p-4 sm:p-5">
                    @if ($canDuplicate)
                        <x-admin.button variant="secondary" icon="copy" class="w-full" wire:click="duplicate" loading="duplicate" data-action="duplicate">{{ __('crm.quotes.actions.duplicate') }}</x-admin.button>
                    @endif
                    @if ($canDelete)
                        <button
                            type="button"
                            x-on:click="$dispatch('confirm', {
                                title: @js(__('crm.quotes.delete_title')),
                                body: @js(__('crm.quotes.delete_body', ['number' => $quote->number])),
                                label: @js(__('admin.common.delete')),
                                component: $wire.$id, method: 'delete',
                            })"
                            class="h-10 w-full rounded-lg border border-red-200 text-sm font-semibold text-danger hover:bg-red-50"
                            data-action="delete"
                        >{{ __('admin.common.delete') }}</button>
                    @endif
                </section>
            @endif
        </aside>
    </div>
</div>
