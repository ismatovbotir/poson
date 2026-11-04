@php($t = 'telegram_inbox')

{{-- CRM link, verification and manual link (plan 14 v2 §22, plan 21 A4). Only ids reach the actions. --}}
<x-admin.card :title="__($t.'.crm.title')">
    <div class="space-y-3 text-sm" data-crm-panel>
        @if ($conversation->customer)
            <dl class="space-y-3">
                <div>
                    <dt class="text-slate-500">{{ __($t.'.crm.customer') }}</dt>
                    <dd class="mt-0.5 break-words font-medium">
                        @can('view', $conversation->customer)
                            <a href="{{ route('admin.customers.show', $conversation->customer->id) }}" wire:navigate class="text-brand-700 hover:underline" data-customer-link>{{ $conversation->customer->name }}</a>
                        @else
                            {{ $conversation->customer->name }}
                        @endcan
                    </dd>
                </div>
                @if ($conversation->contact)
                    <div>
                        <dt class="text-slate-500">{{ __($t.'.crm.contact') }}</dt>
                        <dd class="mt-0.5 break-words font-medium">{{ $conversation->contact->name }}</dd>
                    </div>
                @endif
            </dl>
            @if ($conversation->isLinkVerified())
                <p class="flex flex-wrap items-center gap-2" data-link-verified>
                    <x-admin.badge color="brand">{{ __($t.'.crm.verified') }}</x-admin.badge>
                    @if ($conversation->linkVerifiedBy)
                        <span class="text-xs text-slate-500">{{ __($t.'.crm.verified_by', ['name' => $conversation->linkVerifiedBy->name]) }}</span>
                    @endif
                </p>
            @else
                <p data-link-unverified><x-admin.badge color="amber">{{ __($t.'.crm.unverified') }}</x-admin.badge></p>
                @if ($conversation->isRegistered())
                    @can('verifyLink', $conversation)
                        <x-admin.button variant="secondary" class="w-full" wire:click="verifyLink" wire:confirm="{{ __($t.'.crm.verify_confirm') }}" loading="verifyLink" data-verify-link>{{ __($t.'.crm.verify') }}</x-admin.button>
                    @endcan
                @endif
            @endif
        @else
            <p class="text-slate-500" data-not-linked>{{ __($t.'.crm.not_linked') }}</p>
        @endif

        @if ($canLink)
            <div class="space-y-2 border-t border-slate-100 pt-3" data-link-picker>
                <p class="font-semibold">{{ $conversation->customer_id === null ? __($t.'.crm.link_title') : __($t.'.crm.relink_title') }}</p>
                <x-admin.input wire:model.live.debounce.300ms="customerSearch" type="search" maxlength="100" :label="__($t.'.crm.search')" :placeholder="__($t.'.crm.search_placeholder')" />
                @if (mb_strlen(trim($customerSearch)) >= 2)
                    @forelse ($customerResults as $result)
                        <button type="button" wire:key="link-customer-{{ $result->id }}" wire:click="chooseCustomer({{ $result->id }})" @class(['block w-full rounded-lg border px-3 py-2 text-left hover:bg-slate-50', 'border-brand-500' => $chosenCustomer?->id === $result->id, 'border-slate-200' => $chosenCustomer?->id !== $result->id]) data-customer-result="{{ $result->id }}">
                            <span class="block truncate font-medium">{{ $result->name }}</span>
                            @if ($result->phone)<span class="block text-xs text-slate-500">{{ \App\Support\Phone::display($result->phone) }}</span>@endif
                        </button>
                    @empty
                        <p class="text-xs text-slate-500" data-no-customers>{{ __($t.'.crm.no_results') }}</p>
                    @endforelse
                @endif
                @if ($chosenCustomer)
                    <p class="break-words font-medium" data-chosen-customer>{{ $chosenCustomer->name }}</p>
                    @if ($chosenContacts->isNotEmpty())
                        <x-admin.select wire:model="linkContactId" :label="__($t.'.crm.contact')" :placeholder="__($t.'.crm.contact_any')">
                            @foreach ($chosenContacts as $option)
                                <option value="{{ $option->id }}" wire:key="link-contact-{{ $option->id }}">{{ $option->name }}</option>
                            @endforeach
                        </x-admin.select>
                    @endif
                    @if ($conversation->customer_id === null)
                        <x-admin.button class="w-full" wire:click="link" loading="link" data-do-link>{{ __($t.'.crm.link') }}</x-admin.button>
                    @else
                        <x-admin.button variant="danger" class="w-full" wire:click="link" wire:confirm="{{ __($t.'.crm.relink_confirm') }}" loading="link" data-do-link>{{ __($t.'.crm.relink') }}</x-admin.button>
                    @endif
                @endif
            </div>
        @endif
    </div>
</x-admin.card>
