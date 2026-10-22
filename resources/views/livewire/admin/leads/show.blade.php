@php
    $tz = \App\Models\Lead::TIMEZONE;
    $when = fn ($date, $format = 'D MMM YYYY, HH:mm') => $date ? \App\Support\DateFormat::iso($date->copy()->setTimezone($tz), $format) : __('admin.common.empty_value');
    $lostReasons = collect(\App\Enums\LeadLostReason::cases())->mapWithKeys(fn ($case) => [$case->value => $case->label()])->all();
@endphp

<div x-data="{ lost: false }" x-on:lead-lost-saved.window="lost = false">
    <x-admin.page-header :title="$lead->company ?: $lead->name" :back-href="route('admin.leads.index')" :back-label="__('crm.leads.title')">
        <x-slot:actions>
            @if ($canEdit)
                <x-admin.button variant="secondary" :href="route('admin.leads.edit', $lead)" wire:navigate icon="edit">{{ __('admin.common.edit') }}</x-admin.button>
            @endif
        </x-slot:actions>
    </x-admin.page-header>

    <div class="mt-3 flex flex-wrap items-center gap-1.5 text-sm text-slate-500">
        <span class="font-mono font-semibold text-slate-700" data-reference>{{ $lead->reference() }}</span>
        <span class="text-slate-300">·</span>
        @include('livewire.admin.leads.partials.badges', ['lead' => $lead])
        <x-admin.badge color="outline">{{ $lead->source->label() }}</x-admin.badge>
        @if ($lead->customer)
            <x-admin.badge color="brand" data-customer><x-admin.icon name="building" size="size-3.5" />{{ __('crm.leads.customer_badge', ['name' => $lead->customer->name]) }}</x-admin.badge>
        @endif
        @if (! $canEdit)
            <x-admin.badge color="muted" data-read-only><x-admin.icon name="eye" size="size-3.5" />{{ __('crm.leads.read_only') }}</x-admin.badge>
        @endif
    </div>

    @if ($lead->duplicate_of_id)
        <p class="mt-3 flex flex-wrap items-center gap-1.5 rounded-lg bg-amber-50 px-3 py-2 text-sm text-amber-800" data-duplicate-of>
            <x-admin.icon name="alert" size="size-4" />
            {{ __('crm.leads.duplicate_of', ['days' => \App\Models\Lead::DUPLICATE_WINDOW_DAYS]) }}
            @if ($duplicateOfVisible)
                <a href="{{ route('admin.leads.show', $lead->duplicate_of_id) }}" wire:navigate class="font-mono font-semibold underline">{{ \App\Models\Lead::REFERENCE_PREFIX.$lead->duplicate_of_id }}</a>
            @else
                <span class="font-mono font-semibold">{{ \App\Models\Lead::REFERENCE_PREFIX.$lead->duplicate_of_id }}</span>
            @endif
        </p>
    @endif

    <div class="mt-5 grid grid-cols-1 gap-4 lg:grid-cols-3 lg:gap-6">
        <div class="min-w-0 space-y-4 lg:col-span-2 lg:space-y-6">
            {{-- Status --}}
            <x-admin.card :title="__('crm.leads.sections.status')">
                <div class="flex flex-wrap items-center gap-2" data-status-control>
                    <x-admin.badge :color="$lead->status->color()" dot class="text-sm">{{ $lead->status->label() }}</x-admin.badge>
                    @if ($canEdit)
                        <span class="text-slate-300" aria-hidden="true">→</span>
                        @foreach ($statuses as $status)
                            @if ($status === \App\Enums\LeadStatus::Lost)
                                <x-admin.button size="sm" variant="secondary" x-on:click="lost = true" data-status-to="lost">{{ __('crm.leads.actions.mark_lost') }}</x-admin.button>
                            @elseif ($lead->status->isClosed())
                                <x-admin.button size="sm" variant="secondary" icon="restore" wire:click="changeStatus('{{ $status->value }}')" data-status-to="{{ $status->value }}">{{ __('crm.leads.actions.reopen') }}</x-admin.button>
                            @else
                                <x-admin.button size="sm" :variant="$status === \App\Enums\LeadStatus::Converted ? 'primary' : 'secondary'" wire:click="changeStatus('{{ $status->value }}')" data-status-to="{{ $status->value }}">{{ $status->label() }}</x-admin.button>
                            @endif
                        @endforeach
                    @endif
                </div>
                @if ($lead->status === \App\Enums\LeadStatus::Lost && $lead->lost_reason)
                    <p class="mt-3 text-sm"><span class="text-slate-500">{{ __('crm.leads.fields.lost_reason') }}:</span> <b class="font-semibold">{{ $lead->lost_reason->label() }}</b></p>
                    @if (filled($lead->lost_note))<p class="mt-1 whitespace-pre-line text-sm text-slate-600">{{ $lead->lost_note }}</p>@endif
                @endif
                <p class="mt-3 text-xs text-slate-500">{{ __('crm.leads.status_changed_at') }}: {{ $when($lead->status_changed_at) }}</p>
            </x-admin.card>

            {{-- Request --}}
            <x-admin.card :title="__('crm.leads.sections.request')">
                @if (filled($lead->message))
                    <p class="whitespace-pre-line break-words text-sm">{{ $lead->message }}</p>
                @endif
                @if ($lead->estimated_amount)
                    <p @class(['text-sm', 'mt-3' => filled($lead->message)])><span class="text-slate-500">{{ __('crm.leads.fields.estimated_amount') }}:</span> <b class="font-semibold tabular-nums">{{ \App\Support\Money::format($lead->estimated_amount) }}</b></p>
                @endif

                @if ($lead->items->isNotEmpty())
                    <ul @class(['divide-y divide-slate-100 rounded-xl border border-slate-200', 'mt-4' => filled($lead->message) || $lead->estimated_amount]) data-items>
                        @foreach ($lead->items as $item)
                            <li wire:key="item-{{ $item->id }}" class="flex items-start gap-3 p-3">
                                <div class="min-w-0 flex-1">
                                    @if ($canViewProducts && $item->product && ! $item->product->trashed())
                                        <a href="{{ route('admin.products.edit', $item->product) }}" wire:navigate class="block truncate text-sm font-semibold hover:text-brand-700">{{ $item->product_name }}</a>
                                    @else
                                        <span class="block truncate text-sm font-semibold">{{ $item->product_name }}</span>
                                    @endif
                                    @if ($item->sku)<span class="block font-mono text-xs text-slate-500">{{ $item->sku }}</span>@endif
                                    @if (filled($item->note))<span class="mt-1 block text-xs text-slate-600">{{ $item->note }}</span>@endif
                                </div>
                                <span class="shrink-0 text-sm font-semibold tabular-nums">×{{ $item->qty }}</span>
                            </li>
                        @endforeach
                    </ul>
                @endif

                @if (blank($lead->message) && $lead->items->isEmpty() && ! $lead->estimated_amount)
                    <p class="text-sm text-slate-400">{{ __('crm.leads.no_request') }}</p>
                @endif
            </x-admin.card>

            {{-- Activity --}}
            <x-admin.card :title="__('crm.leads.sections.activity')">
                @if ($canEdit)
                    <form wire:submit="addActivity" class="mb-6" data-activity-form>
                        <div class="flex rounded-lg border border-slate-200 bg-white p-1 sm:inline-flex" role="radiogroup" aria-label="{{ __('crm.leads.activity.type') }}">
                            @foreach (\App\Enums\ActivityType::manual() as $manualType)
                                <label class="flex-1 cursor-pointer">
                                    <input type="radio" wire:model.live="activityType" value="{{ $manualType->value }}" class="peer sr-only">
                                    <span class="flex h-8 items-center justify-center gap-1.5 rounded-md px-3 text-sm font-semibold text-slate-600 peer-checked:bg-slate-900 peer-checked:text-white peer-focus-visible:ring-2 peer-focus-visible:ring-brand-500"><x-admin.icon :name="$manualType->icon()" size="size-4" />{{ __('crm.leads.activity.add_'.$manualType->value) }}</span>
                                </label>
                            @endforeach
                        </div>
                        <x-admin.textarea wire:model="activityBody" rows="2" class="mt-3" :placeholder="$activityType === 'call' ? __('crm.leads.activity.call_placeholder') : __('crm.leads.activity.note_placeholder')" maxlength="2000" :aria-label="__('crm.leads.activity.body')" />
                        <div class="mt-2 flex justify-end">
                            <x-admin.button type="submit" size="sm" loading="addActivity">{{ __('crm.leads.activity.save') }}</x-admin.button>
                        </div>
                    </form>
                @endif

                <ol class="space-y-4 border-l border-slate-200 pl-5" data-timeline>
                    @foreach ($this->activities as $activity)
                        <li wire:key="activity-{{ $activity->id }}" class="relative" data-activity="{{ $activity->type->value }}">
                            <span @class(['absolute -left-[1.6rem] top-1 size-2.5 rounded-full ring-4 ring-white', $activity->type->dot()]) aria-hidden="true"></span>
                            @include('livewire.admin.leads.partials.activity', ['activity' => $activity])
                            <p class="mt-0.5 text-xs text-slate-400">{{ $activity->type->label() }} · {{ $when($activity->created_at) }}</p>
                        </li>
                    @endforeach
                </ol>
            </x-admin.card>
        </div>

        <aside class="min-w-0 space-y-4 lg:sticky lg:top-24 lg:self-start lg:space-y-6">
            {{-- Contact --}}
            <x-admin.card :title="__('crm.leads.sections.contact')">
                <p class="text-sm font-semibold">{{ $lead->name }}</p>
                @if ($lead->company)<p class="text-sm text-slate-500">{{ $lead->company }}@if ($lead->inn) · {{ __('crm.leads.fields.inn') }} {{ $lead->inn }}@endif</p>@endif
                <div class="mt-3 flex flex-wrap gap-2">
                    <a href="tel:{{ $lead->phone }}" class="inline-flex h-9 items-center gap-2 rounded-lg bg-brand-50 px-3 text-sm font-semibold text-brand-700 hover:bg-brand-100"><x-admin.icon name="phone" size="size-4" />{{ \App\Support\Phone::display($lead->phone) }}</a>
                    @if ($lead->email)
                        <a href="mailto:{{ $lead->email }}" class="inline-flex h-9 items-center gap-2 rounded-lg bg-sky-50 px-3 text-sm font-semibold text-sky-700 hover:bg-sky-100"><x-admin.icon name="mail" size="size-4" />{{ $lead->email }}</a>
                    @endif
                </div>
            </x-admin.card>

            {{-- Manager --}}
            <x-admin.card :title="__('crm.leads.fields.manager')">
                @include('livewire.admin.leads.partials.manager', ['lead' => $lead])
                @if ($canTake || $canRelease)
                    <div class="mt-3 flex gap-2">
                        @if ($canTake && $lead->manager_id === null)
                            <x-admin.button size="sm" wire:click="take" loading="take" data-action="take">{{ __('crm.leads.actions.take') }}</x-admin.button>
                        @endif
                        @if ($canRelease)
                            <x-admin.button size="sm" variant="secondary" wire:click="release" loading="release" data-action="release">{{ __('crm.leads.actions.release') }}</x-admin.button>
                        @endif
                    </div>
                @endif
                @if ($canAssign && $this->candidates !== [])
                    <div class="mt-4 flex items-end gap-2" data-assign>
                        <x-admin.select wire:model="assignTo" class="min-w-0 flex-1" :label="__('crm.leads.actions.assign_to')" :placeholder="__('crm.leads.unassigned')" :options="$this->candidates" />
                        <x-admin.button variant="secondary" wire:click="assign" loading="assign">{{ __('crm.leads.actions.assign') }}</x-admin.button>
                    </div>
                @endif
            </x-admin.card>

            {{-- Attribution --}}
            <x-admin.card :title="__('crm.leads.sections.attribution')">
                <dl class="space-y-2 text-sm" data-attribution>
                    @foreach (array_filter([
                        __('crm.leads.fields.source') => $lead->source->label(),
                        __('crm.leads.fields.locale') => strtoupper($lead->locale),
                        __('crm.leads.fields.traffic_channel') => $lead->traffic_channel?->label(),
                        __('crm.leads.fields.traffic_source') => $lead->traffic_source,
                        __('crm.leads.fields.landing_path') => $lead->landing_path,
                        __('crm.leads.fields.page_url') => $lead->page_url,
                        __('crm.leads.fields.created_by') => $lead->createdBy?->name,
                        __('crm.leads.fields.created_at') => $when($lead->created_at),
                        __('crm.leads.fields.closed_at') => $lead->closed_at ? $when($lead->closed_at) : null,
                    ], fn ($value) => filled($value)) as $label => $value)
                        <div class="flex gap-3"><dt class="w-32 shrink-0 text-slate-500">{{ $label }}</dt><dd class="min-w-0 break-words">{{ $value }}</dd></div>
                    @endforeach
                    @foreach ($lead->utm ?? [] as $key => $value)
                        <div class="flex gap-3"><dt class="w-32 shrink-0 font-mono text-xs text-slate-500">{{ $key }}</dt><dd class="min-w-0 break-words">{{ $value }}</dd></div>
                    @endforeach
                </dl>
            </x-admin.card>

            @if ($laterDuplicates->isNotEmpty())
                <x-admin.card :title="__('crm.leads.sections.duplicates')">
                    <ul class="space-y-1 text-sm">
                        @foreach ($laterDuplicates as $duplicate)
                            <li wire:key="later-{{ $duplicate->id }}"><a href="{{ route('admin.leads.show', $duplicate) }}" wire:navigate class="font-mono font-semibold text-brand-700 hover:underline">{{ $duplicate->reference() }}</a> · {{ $duplicate->status->label() }} · {{ $when($duplicate->created_at, 'D MMM') }}</li>
                        @endforeach
                    </ul>
                </x-admin.card>
            @endif

            @if ($canDelete)
                <section class="rounded-2xl border border-red-200 bg-white p-4 sm:p-5">
                    <h2 class="text-sm font-semibold text-danger">{{ __('crm.leads.danger_zone') }}</h2>
                    <p class="mt-1 text-xs text-slate-500">{{ __('crm.leads.delete_help') }}</p>
                    <button
                        type="button"
                        x-on:click="$dispatch('confirm', {
                            title: @js(__('crm.leads.delete_title')),
                            body: @js(__('crm.leads.delete_body', ['reference' => $lead->reference()])),
                            label: @js(__('admin.common.delete')),
                            component: $wire.$id, method: 'delete',
                        })"
                        class="mt-3 h-10 w-full rounded-lg border border-red-200 text-sm font-semibold text-danger hover:bg-red-50"
                    >{{ __('admin.common.delete') }}</button>
                </section>
            @endif
        </aside>
    </div>

    {{-- Lost: reason (and a note for "other") --}}
    @if ($canEdit)
        <div x-cloak x-show="lost" class="fixed inset-0 z-[60] flex items-end justify-center sm:items-center" role="dialog" aria-modal="true" aria-labelledby="lead-lost-title" x-on:keydown.escape.window="lost = false">
            <div x-show="lost" x-transition.opacity class="absolute inset-0 bg-slate-900/50" x-on:click="lost = false"></div>
            <form wire:submit="markLost" x-show="lost" x-transition x-trap.noscroll="lost" class="relative w-full rounded-t-2xl bg-white p-5 pb-[calc(1.25rem+env(safe-area-inset-bottom))] shadow-2xl sm:max-w-md sm:rounded-2xl sm:pb-5" data-lost-modal>
                <h2 id="lead-lost-title" class="text-base font-semibold">{{ __('crm.leads.lost_title') }}</h2>
                <p class="mt-1 text-sm text-slate-500">{{ __('crm.leads.lost_body') }}</p>
                <div class="mt-4 space-y-4">
                    <x-admin.select wire:model.live="lostReason" :label="__('crm.leads.fields.lost_reason')" required :placeholder="__('admin.common.choose')" :options="$lostReasons" />
                    <x-admin.textarea wire:model="lostNote" :label="__('crm.leads.fields.lost_note')" :required="$lostReason === 'other'" rows="3" maxlength="500" />
                </div>
                <div class="mt-6 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
                    <button type="button" x-on:click="lost = false" class="h-11 rounded-lg border border-slate-200 px-4 text-sm font-semibold text-slate-700 hover:bg-slate-50 sm:h-10">{{ __('admin.common.cancel') }}</button>
                    <x-admin.button type="submit" variant="danger" loading="markLost">{{ __('crm.leads.actions.mark_lost') }}</x-admin.button>
                </div>
            </form>
        </div>
    @endif
</div>
