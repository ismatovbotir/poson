<?php

namespace App\Actions\Orders\Concerns;

use App\Data\Orders\ManualPaymentData;
use App\Enums\PaymentMethod;
use App\Enums\ReceiptChannel;
use App\Models\Lead;
use App\Models\OrderPayment;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * The checks of a manual payment (security condition 20), shared by
 * MarkOrderPaid and MarkDeliveryChargePaid; run under the order lock:
 * a manual method, the exact outstanding amount, a paid-on date that isn't
 * in the future, a reference (≤ 100) for uzqr_static / bank_transfer that
 * no active payment of the method already uses, the channel for on_receipt.
 */
trait RecordsManualPayment
{
    /**
     * @return array{method: PaymentMethod, paid_on: string, reference: string|null, reference_key: string|null, receipt_channel: ReceiptChannel|null, note: string|null}
     *
     * @throws ValidationException
     */
    protected function checkManualPayment(ManualPaymentData $data, int $outstanding): array
    {
        $method = PaymentMethod::tryFrom($data->method);

        if ($method === null || $method->isReserved() || ! $method->isManual()) {
            throw ValidationException::withMessages(['method' => __('orders.errors.method_not_manual')]);
        }

        if ($data->amountReceived === null || $data->amountReceived !== $outstanding) {
            throw ValidationException::withMessages(['amount' => __('orders.errors.amount_mismatch')]);
        }

        $paidOn = $this->paidOn($data->paidOn);
        $reference = $data->reference !== null ? trim(preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $data->reference) ?? '') : '';

        if (mb_strlen($reference) > OrderPayment::MAX_REFERENCE) {
            throw ValidationException::withMessages(['reference' => __('validation.max.string', ['attribute' => __('orders.payments.reference'), 'max' => OrderPayment::MAX_REFERENCE])]);
        }

        if ($method->requiresReference() && $reference === '') {
            throw ValidationException::withMessages(['reference' => __('orders.errors.reference_required')]);
        }

        $key = $reference !== '' ? OrderPayment::referenceKey($method, $reference) : null;

        if ($key !== null && OrderPayment::query()->where('reference_key', $key)->exists()) {
            throw ValidationException::withMessages(['reference' => __('orders.errors.reference_used')]);
        }

        $channel = null;

        if ($method === PaymentMethod::OnReceipt) {
            $channel = ReceiptChannel::tryFrom((string) $data->receiptChannel);

            if ($channel === null) {
                throw ValidationException::withMessages(['receipt_channel' => __('orders.errors.channel_required')]);
            }
        }

        $note = $data->note !== null ? trim($data->note) : '';

        return [
            'method' => $method,
            'paid_on' => $paidOn,
            'reference' => $reference !== '' ? $reference : null,
            'reference_key' => $key,
            'receipt_channel' => $channel,
            'note' => $note !== '' ? mb_substr($note, 0, 1000) : null,
        ];
    }

    /**
     * @throws ValidationException
     */
    private function paidOn(string $value): string
    {
        $date = null;

        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            try {
                $date = CarbonImmutable::createFromFormat('!Y-m-d', $value, Lead::TIMEZONE);
            } catch (Throwable) {
                $date = null;
            }
        }

        if (! $date instanceof CarbonImmutable || $date->format('Y-m-d') !== $value) {
            throw ValidationException::withMessages(['paid_on' => __('validation.date', ['attribute' => __('orders.payments.paid_on')])]);
        }

        if ($date->greaterThan(now(Lead::TIMEZONE)->startOfDay())) {
            throw ValidationException::withMessages(['paid_on' => __('orders.errors.paid_on_future')]);
        }

        return $value;
    }
}
