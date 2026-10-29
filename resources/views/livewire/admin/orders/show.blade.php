@php
    /** @var \App\Models\Order $order */
    $tz = \App\Models\Lead::TIMEZONE;
    $money = fn (int $tiyin) => \App\Support\Money::format($tiyin);
    $when = fn ($date, $format = 'D MMM YYYY, HH:mm') => $date ? \App\Support\DateFormat::iso($date->copy()->setTimezone($tz), $format) : __('admin.common.empty_value');
    $flow = \App\Enums\OrderStatus::flow();
    $currentIndex = array_search($order->status, $flow, true);
    $separate = $order->delivery_pricing === \App\Enums\DeliveryPricing::Separate;
    $pendingCharge = $order->pendingDeliveryCharge();
@endphp

{{-- Order page (plan 11b), ported from .claude/html-templates/admin/order.html without tier prices or discounts. --}}
<div x-data="{ reasonOpen: false, reasonTitle: '', reasonMethod: '', reasonArg: null, payOpen: false }"
     x-on:open-payment.window="payOpen = true" x-on:close-payment.window="payOpen = false">
    <div class="flex flex-col gap-3 lg:flex-row lg:items-start lg:justify-between">
        <div class="min-w-0">
            <nav class="text-sm text-slate-500" aria-label="{{ __('admin.a11y.breadcrumb') }}"><a href="{{ route('admin.orders.index', $order->is_test ? ['test' => 1] : []) }}" wire:navigate class="inline-flex items-center gap-1 hover:text-slate-700"><x-admin.icon name="chevron-left" size="size-4" />{{ __('orders.title') }}</a></nav>
            <div class="mt-1 flex flex-wrap items-center gap-2">
                <h1 class="text-xl font-bold tracking-tight sm:text-2xl">{{ __('orders.show_title', ['number' => $order->number]) }}</h1>
                @include('livewire.admin.orders.partials.badges', ['order' => $order])
            </div>
            <p class="mt-1 text-sm text-slate-500">{{ $when($order->created_at, 'D MMMM YYYY, HH:mm') }} · {{ $order->source->label() }}@if ($order->createdBy) · {{ $order->createdBy->name }}@endif</p>
        </div>
        <div class="flex flex-wrap gap-2">
            @if ($hasInvoice)
                <x-admin.button variant="secondary" icon="file-text" :href="route('admin.orders.invoice', $order)" data-action="invoice">{{ __('orders.actions.invoice') }}</x-admin.button>
                @if ($order->status !== \App\Enums\OrderStatus::New)
                    <x-admin.button variant="secondary" icon="download" :href="route('admin.orders.invoice', [$order, 'signed' => 1])" data-action="invoice-signed">{{ __('orders.actions.invoice_signed') }}</x-admin.button>
                @endif
            @endif
            @if ($canEdit && ! $order->status->isClosed())
                <x-admin.button variant="secondary" icon="edit" :href="route('admin.orders.edit', $order)" wire:navigate data-action="edit">{{ __('admin.common.edit') }}</x-admin.button>
            @endif
            @if ($canEdit && ($next !== [] || $canCancel))
                <div class="relative" x-data="{ open: false }" x-on:click.outside="open = false">
                    <button type="button" x-on:click="open = !open" class="inline-flex h-10 items-center gap-2 rounded-lg bg-brand-600 px-4 text-sm font-semibold text-white shadow-sm hover:bg-brand-700" data-status-menu>{{ __('orders.actions.status') }}<x-admin.icon name="chevron-down" size="size-4" /></button>
                    <div x-cloak x-show="open" x-transition.origin.top.right class="absolute right-0 z-20 mt-2 w-60 rounded-xl border border-slate-200 bg-white p-1.5 text-sm shadow-xl shadow-slate-900/10">
                        @foreach ($next as $status)
                            <button type="button" x-on:click="open = false" wire:click="changeStatus('{{ $status->value }}')" class="flex w-full items-center gap-2.5 rounded-lg px-3 py-2 hover:bg-slate-50" data-status-to="{{ $status->value }}"><x-admin.icon :name="$status === \App\Enums\OrderStatus::Shipped ? 'truck' : 'check'" size="size-4" class="text-slate-400" />{{ $status->label() }}</button>
                        @endforeach
                        @if ($canCancel)
                            <div class="my-1 h-px bg-slate-100"></div>
                            <button type="button" x-on:click="open = false; reasonTitle = @js(__('orders.cancel_title')); reasonMethod = 'changeStatus'; reasonArg = 'cancelled'; reasonOpen = true" class="flex w-full items-center gap-2.5 rounded-lg px-3 py-2 text-danger hover:bg-red-50" data-status-to="cancelled"><x-admin.icon name="x" size="size-4" />{{ __('orders.actions.cancel') }}</button>
                        @endif
                    </div>
                </div>
            @endif
        </div>
    </div>

    {{-- Stepper --}}
    @if ($order->status === \App\Enums\OrderStatus::Cancelled)
        <p class="mt-5 rounded-2xl border border-red-200 bg-red-50 p-4 text-sm font-medium text-danger" data-cancelled>{{ __('orders.cancelled_banner', ['date' => $when($order->cancelled_at)]) }}@if ($order->cancel_reason) — {{ $order->cancel_reason }}@endif</p>
    @else
        <ol class="scrollbar-none mt-5 flex items-center gap-2 overflow-x-auto rounded-2xl border border-slate-200 bg-white p-3 text-sm sm:gap-3 sm:p-4" aria-label="{{ __('orders.stepper') }}" data-stepper>
            @foreach ($flow as $index => $step)
                @if ($index > 0)<li @class(['h-px w-6 shrink-0 sm:flex-1', 'bg-brand-600' => $index <= $currentIndex, 'bg-slate-200' => $index > $currentIndex]) aria-hidden="true"></li>@endif
                <li class="flex shrink-0 items-center gap-2" @if ($index === $currentIndex) aria-current="step" @endif>
                    @if ($index < $currentIndex || ($index === $currentIndex && $step === \App\Enums\OrderStatus::Completed))
                        <span class="grid size-7 place-items-center rounded-full bg-brand-600 text-white"><x-admin.icon name="check" size="size-4" /></span><span class="font-semibold">{{ $step->label() }}</span>
                    @elseif ($index === $currentIndex)
                        <span class="grid size-7 place-items-center rounded-full border-2 border-brand-600 text-xs font-bold text-brand-700">{{ $index + 1 }}</span><span class="font-semibold text-brand-700">{{ $step->label() }}</span>
                    @else
                        <span class="grid size-7 place-items-center rounded-full border-2 border-slate-200 text-xs font-bold text-slate-400">{{ $index + 1 }}</span><span class="text-slate-400">{{ $step->label() }}</span>
                    @endif
                </li>
            @endforeach
        </ol>
    @endif

    <div class="mt-4 grid grid-cols-1 gap-4 lg:grid-cols-3 lg:gap-6">
        <div class="min-w-0 space-y-4 lg:col-span-2 lg:space-y-6">
            {{-- Lines --}}
            <section class="rounded-2xl border border-slate-200 bg-white">
                <div class="flex items-center justify-between border-b border-slate-100 px-4 py-3.5 sm:px-6">
                    <h2 class="text-base font-semibold">{{ __('orders.items.title') }} <span class="font-normal text-slate-400">({{ $order->items->count() }})</span></h2>
                    @if ($canEdit && $order->isLinesEditable())
                        <a href="{{ route('admin.orders.edit', $order) }}" wire:navigate class="text-sm font-semibold text-brand-600 hover:text-brand-700">{{ __('admin.common.edit') }}</a>
                    @endif
                </div>
                <ul class="divide-y divide-slate-100" data-order-items>
                    @foreach ($order->items as $item)
                        <li wire:key="line-{{ $item->id }}" class="flex gap-3 px-4 py-3.5 sm:items-center sm:px-6">
                            <div class="min-w-0 flex-1 sm:flex sm:items-center sm:gap-4">
                                <div class="min-w-0 flex-1">
                                    @if ($canViewProducts && $item->product && ! $item->product->trashed())
                                        <a href="{{ route('admin.products.edit', $item->product) }}" wire:navigate class="text-sm font-semibold hover:text-brand-700">{{ $item->name }}</a>
                                    @else
                                        <p class="text-sm font-semibold">{{ $item->name }}</p>
                                    @endif
                                    @if ($item->isRollLine())<p class="text-xs text-slate-600" data-roll-line>{{ $item->rollDescription(withRolls: false) }}</p>@endif
                                    <p class="text-xs text-slate-500">
                                        @if ($item->sku)<span class="font-mono">{{ $item->sku }}</span>@endif
                                        @if (! $item->hasIkpu()) · <span class="font-semibold text-amber-700" data-no-ikpu>{{ __('orders.items.no_ikpu') }}</span>@endif
                                        @if ($item->was_on_order) · {{ __('orders.items.on_order') }}@endif
                                        @if ($item->price_edited)
                                            · <span @class(['rounded px-1 font-semibold', 'bg-amber-50 text-amber-700' => $item->isBelowReference() || $item->product_id === null, 'bg-slate-100 text-slate-600' => ! $item->isBelowReference() && $item->product_id !== null]) data-price-edited>{{ $item->reference_price !== null ? __('orders.items.price_edited', ['reference' => $money($item->reference_price)]) : __('orders.items.custom_line') }}</span>
                                        @endif
                                    </p>
                                </div>
                                <p class="mt-1 text-sm tabular-nums text-slate-500 sm:mt-0 sm:w-44 sm:text-right">{{ \App\Support\NumberFormat::format($item->qty) }} {{ $item->unit?->label() }} × {{ $money($item->price) }}</p>
                                <p class="text-sm font-semibold tabular-nums sm:w-36 sm:text-right">{{ $money($item->total) }}</p>
                            </div>
                        </li>
                    @endforeach
                </ul>
                <dl class="space-y-2 border-t border-slate-100 bg-slate-50/60 px-4 py-4 text-sm sm:px-6" data-totals>
                    <div class="flex justify-between"><dt class="text-slate-500">{{ __('orders.totals.goods') }}</dt><dd class="tabular-nums">{{ $money($order->subtotal) }}</dd></div>
                    <div class="flex justify-between"><dt class="text-slate-500">{{ __('orders.totals.delivery', ['type' => $order->delivery_type->label()]) }}</dt><dd class="tabular-nums" data-delivery-total>{{ $separate ? __('orders.public.delivery_separate_short') : $money($order->delivery_amount) }}</dd></div>
                    <div class="flex justify-between"><dt class="text-slate-500">{{ __('orders.invoice.vat', ['percent' => \App\Support\Vat::PERCENT]) }}</dt><dd class="tabular-nums">{{ $money($order->vat_amount) }}</dd></div>
                    <div class="flex justify-between border-t border-slate-200 pt-3 text-base font-bold"><dt>{{ __('orders.totals.total') }}</dt><dd class="tabular-nums" data-total>{{ $money($order->total) }}</dd></div>
                </dl>
            </section>

            {{-- Payments (not for the service engineer, condition 30) --}}
            @if ($canPayments)
                @include('livewire.admin.orders.partials.payments')
            @endif

            {{-- History --}}
            <section class="rounded-2xl border border-slate-200 bg-white p-4 sm:p-6">
                <h2 class="text-base font-semibold">{{ __('orders.history') }}</h2>
                <ol class="mt-5 space-y-4 border-l border-slate-200 pl-5" data-timeline>
                    @foreach ($this->activities as $activity)
                        <li wire:key="activity-{{ $activity->id }}" class="relative">
                            <span @class(['absolute -left-[1.6rem] top-1 size-2.5 rounded-full ring-4 ring-white', $activity->type->dot()]) aria-hidden="true"></span>
                            <p class="text-sm">@if ($activity->user)<b class="font-semibold">{{ $activity->user->name }}</b> · @endif @include('livewire.admin.orders.partials.activity', ['activity' => $activity, 'canPayments' => $canPayments])</p>
                            @if ($activity->body)<p class="mt-0.5 whitespace-pre-line break-words text-sm text-slate-600">{{ $activity->body }}</p>@endif
                            <p class="mt-0.5 text-xs text-slate-400">{{ $when($activity->created_at) }}</p>
                        </li>
                    @endforeach
                </ol>
            </section>
        </div>

        <aside class="min-w-0 space-y-4 lg:space-y-6">
            <x-admin.card :title="__('orders.sections.buyer')">
                <div class="flex items-center gap-3">
                    <span class="grid size-10 place-items-center rounded-lg bg-slate-900 text-white"><x-admin.icon :name="$order->buyer_type->isBusiness() ? 'building' : 'users'" size="size-5" /></span>
                    <div class="min-w-0">
                        <p class="truncate text-sm font-semibold">{{ $order->company_name ?: $order->name }}</p>
                        <p class="text-xs text-slate-500">{{ $order->buyer_type->label() }}</p>
                    </div>
                </div>
                <dl class="mt-4 space-y-2.5 text-sm">
                    @if ($order->tin)<div class="flex justify-between gap-3"><dt class="text-slate-500">{{ __('orders.fields.tin') }}</dt><dd class="font-mono" data-tin>{{ $order->tin }}</dd></div>@endif
                    <div class="flex justify-between gap-3"><dt class="text-slate-500">{{ __('orders.fields.name') }}</dt><dd class="text-right">{{ $order->name }}</dd></div>
                    <div class="flex justify-between gap-3"><dt class="text-slate-500">{{ __('orders.fields.phone') }}</dt><dd><a href="tel:{{ $order->phone }}" class="font-medium text-brand-700">{{ \App\Support\Phone::display($order->phone) }}</a></dd></div>
                    @if ($order->customer)
                        <div class="flex justify-between gap-3"><dt class="text-slate-500">{{ __('orders.fields.customer') }}</dt><dd class="text-right">
                            @if ($canViewCustomer)<a href="{{ route('admin.customers.show', $order->customer) }}" wire:navigate class="font-semibold text-brand-700 hover:underline">{{ $order->customer->name }}</a>@else{{ $order->customer->name }}@endif
                        </dd></div>
                    @endif
                    <div class="flex justify-between gap-3"><dt class="text-slate-500">{{ __('orders.fields.price_level') }}</dt><dd>{{ $order->price_level->label() }}</dd></div>
                </dl>
                @if ($order->comment)
                    <p class="mt-4 rounded-lg bg-slate-50 p-3 text-sm"><span class="block text-xs font-semibold text-slate-500">{{ __('orders.fields.comment') }}</span><span class="whitespace-pre-line break-words">{{ $order->comment }}</span></p>
                @endif
                @if ($order->staff_note)
                    <p class="mt-3 rounded-lg bg-amber-50 p-3 text-sm"><span class="block text-xs font-semibold text-amber-700">{{ __('orders.fields.staff_note') }}</span><span class="whitespace-pre-line break-words">{{ $order->staff_note }}</span></p>
                @endif
            </x-admin.card>

            <x-admin.card :title="__('orders.sections.delivery')">
                <div class="flex gap-3 text-sm">
                    <x-admin.icon name="truck" size="size-5" class="mt-0.5 text-slate-400" />
                    <div>
                        <p class="font-semibold">{{ $order->delivery_type->label() }}@if ($order->region) · {{ $order->region->label() }}@endif</p>
                        @if ($order->address)<p class="mt-1 text-slate-600" data-address>{{ $order->address }}</p>@endif
                        <p class="mt-1 text-xs text-slate-500">{{ $separate ? __('orders.public.delivery_separate') : __('orders.delivery_fixed', ['amount' => $money($order->delivery_amount)]) }}</p>
                    </div>
                </div>
                @if ($order->delivery_type === \App\Enums\DeliveryType::CourierKokand)
                    <div class="mt-3 flex items-center justify-between gap-2 rounded-lg bg-slate-50 p-3 text-sm" data-kokand>
                        @if ($order->kokand_address_confirmed)
                            <span class="font-medium text-emerald-700">{{ __('orders.kokand_confirmed') }}</span>
                        @else
                            <span class="text-amber-700">{{ __('orders.kokand_unconfirmed') }}</span>
                            @if ($canEdit && $order->isDeliveryEditable())
                                <x-admin.button size="sm" variant="secondary" wire:click="confirmKokandAddress" data-action="confirm-kokand">{{ __('orders.actions.confirm_kokand') }}</x-admin.button>
                            @endif
                        @endif
                    </div>
                @endif
            </x-admin.card>

            @if ($canPayments && ! $order->is_test)
                <x-admin.card :title="__('orders.sections.link')">
                    @if ($publicUrl)
                        <p class="break-all font-mono text-xs text-slate-600" data-public-url>{{ $publicUrl }}</p>
                        <p class="mt-2 text-xs text-slate-500">{{ __('orders.link_valid_until', ['date' => $when($order->public_until, 'D MMMM YYYY')]) }}</p>
                        <div class="mt-3 flex flex-wrap gap-2">
                            <x-admin.button size="sm" variant="secondary" icon="link" x-data x-on:click="$wire.copyLink().then(url => url && navigator.clipboard?.writeText(url))" data-action="copy-link">{{ __('orders.actions.copy_link') }}</x-admin.button>
                        </div>
                    @else
                        <p class="text-sm text-slate-500" data-link-expired>{{ __('orders.link_expired') }}</p>
                    @endif
                    @if ($canEdit)
                        <x-admin.button size="sm" variant="ghost" icon="restore" class="mt-2" wire:click="renewLink" data-action="renew-link">{{ __('orders.actions.renew_link') }}</x-admin.button>
                    @endif
                </x-admin.card>
            @endif

            <x-admin.card :title="__('orders.sections.manager')">
                <p class="text-sm">{{ $order->manager?->name ?? __('admin.common.empty_value') }}</p>
                <p class="mt-3 flex items-center gap-2 text-xs text-slate-500"><x-admin.icon name="telegram" size="size-4" />{{ __('orders.notifications_hint') }}</p>
            </x-admin.card>

            @if ($canDelete)
                <section class="rounded-2xl border border-slate-200 bg-white p-4 sm:p-5">
                    <button
                        type="button"
                        x-on:click="$dispatch('confirm', { title: @js(__('orders.delete_title')), body: @js(__('orders.delete_body', ['number' => $order->number])), label: @js(__('admin.common.delete')), component: $wire.$id, method: 'delete' })"
                        class="h-10 w-full rounded-lg border border-red-200 text-sm font-semibold text-danger hover:bg-red-50"
                        data-action="delete"
                    >{{ __('orders.actions.delete_test') }}</button>
                </section>
            @endif
        </aside>
    </div>

    {{-- Reason dialog: cancel order, cancel delivery charge, void, refund --}}
    <div x-cloak x-show="reasonOpen" class="fixed inset-0 z-[60] flex items-end justify-center sm:items-center" role="dialog" aria-modal="true" aria-labelledby="reason-title" x-on:keydown.escape.window="reasonOpen = false">
        <div class="absolute inset-0 bg-slate-900/50" x-on:click="reasonOpen = false"></div>
        <div x-trap.noscroll="reasonOpen" class="relative w-full rounded-t-2xl bg-white p-5 shadow-2xl sm:max-w-md sm:rounded-2xl">
            <h2 id="reason-title" class="text-base font-semibold" x-text="reasonTitle"></h2>
            <x-admin.textarea class="mt-4" wire:model="reason" rows="3" maxlength="500" :label="__('orders.fields.reason')" />
            <div class="mt-5 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
                <button type="button" x-on:click="reasonOpen = false" class="h-10 rounded-lg border border-slate-200 px-4 text-sm font-semibold text-slate-700 hover:bg-slate-50">{{ __('admin.confirm.cancel') }}</button>
                <button type="button" x-on:click="$wire.call(reasonMethod, reasonArg).then(() => reasonOpen = false)" class="h-10 rounded-lg bg-danger px-4 text-sm font-semibold text-white hover:bg-red-700" data-reason-submit>{{ __('admin.confirm.confirm') }}</button>
            </div>
        </div>
    </div>
</div>
