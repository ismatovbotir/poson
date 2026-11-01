{{-- Dynamic UzQR needing attention (conditions 47, 50): unmatched payments and fiscal receipts to issue. --}}
@if ($uzqrUnmatched->isNotEmpty() || $uzqrReceipts->isNotEmpty())
    <section class="mt-5 space-y-3 rounded-2xl border border-amber-200 bg-amber-50/60 p-4 text-sm" aria-labelledby="uzqr-attention-title" data-uzqr-attention>
        <h2 id="uzqr-attention-title" class="font-bold">{{ __('uzqr.admin.attention_title') }}</h2>

        @if ($uzqrUnmatched->isNotEmpty())
            <div data-uzqr-unmatched-list>
                <p class="font-semibold text-amber-900">{{ __('uzqr.admin.unmatched_count', ['count' => $uzqrUnmatched->count()]) }}</p>
                <ul class="mt-1 space-y-1">
                    @foreach ($uzqrUnmatched as $row)
                        <li wire:key="uzqr-unmatched-{{ $row->id }}">
                            <a href="{{ route('admin.orders.show', $row->order_id) }}" wire:navigate class="font-semibold text-brand-700 hover:underline">{{ $row->order?->number }}</a>
                            · {{ \App\Support\Money::format($row->amount) }} · {{ __('uzqr.unmatched_reasons.'.($row->unmatched_reason ?? 'unknown')) }}
                            @if ($row->order?->is_test) · TEST @endif
                        </li>
                    @endforeach
                </ul>
            </div>
        @endif

        @if ($uzqrReceipts->isNotEmpty())
            <div data-uzqr-receipts-list>
                <p class="font-semibold text-amber-900">{{ __('uzqr.admin.receipts_count', ['count' => $uzqrReceipts->count()]) }}</p>
                <ul class="mt-1 space-y-1">
                    @foreach ($uzqrReceipts as $row)
                        <li wire:key="uzqr-receipt-{{ $row->id }}" class="flex flex-wrap items-center gap-2">
                            <a href="{{ route('admin.orders.show', $row->order_id) }}" wire:navigate class="font-semibold text-brand-700 hover:underline">{{ $row->order?->number }}</a>
                            <span>· {{ \App\Support\Money::format($row->amount) }} · {{ $row->paid_at?->copy()->setTimezone(\App\Models\Lead::TIMEZONE)->format('d.m.Y H:i') }}</span>
                            @if ($canReconcile)
                                <x-admin.button size="sm" variant="secondary" wire:click="confirmReceipt({{ $row->id }})" data-action="confirm-receipt">{{ __('uzqr.admin.receipt_issued') }}</x-admin.button>
                            @endif
                        </li>
                    @endforeach
                </ul>
            </div>
        @endif
    </section>
@endif
