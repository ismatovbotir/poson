<?php

namespace App\Support\Telegram;

use App\Models\Lead;
use App\Models\LeadItem;
use App\Support\Phone;
use Illuminate\Support\Str;

/**
 * The Telegram alert for a new lead (plan 05c, decisions 2026-10-22), as
 * HTML for parse_mode=HTML in the given locale (lang/{uz,ru,en}/telegram.php).
 *
 * Content: reference + type, source and traffic channel (enum labels only:
 * traffic_source can hold a utm_source value), products (name,
 * SKU, qty), name + phone, company, a COMMENT_EXCERPT-character comment
 * excerpt, the duplicate note and the matched customer. Every value is
 * escaped with e(). Never included: email, INN, UTM tags, traffic source,
 * page / landing URLs, IP. The "Open in admin" button only when APP_URL is
 * https; its URL is APP_URL + the relative admin path (never the request host).
 *
 * Needs "items" and "customer" loaded on the lead.
 */
final readonly class LeadMessage
{
    public const COMMENT_EXCERPT = 300;

    public const MAX_ITEMS = 10;

    /**
     * @param  list<array{text: string, url: string}>  $buttons
     */
    public function __construct(
        public string $html,
        public array $buttons,
    ) {}

    public static function for(Lead $lead, string $locale): self
    {
        $previous = app()->getLocale();
        app()->setLocale($locale);

        try {
            return new self(self::html($lead), self::buttons($lead));
        } finally {
            app()->setLocale($previous);
        }
    }

    private static function html(Lead $lead): string
    {
        $lines = [
            '<b>'.e(__('telegram.lead.title', ['reference' => $lead->reference()])).'</b> · '.e($lead->type->label()),
        ];

        $channel = array_filter([
            $lead->source->label(),
            $lead->traffic_channel?->label(),
        ]);
        $lines[] = self::line('channel', implode(' · ', $channel));

        $items = $lead->items->take(self::MAX_ITEMS)->map(fn (LeadItem $item): string => e($item->product_name)
            .($item->sku ? ' ('.e($item->sku).')' : '')
            .' ×'.$item->qty);

        if ($items->isNotEmpty()) {
            $more = $lead->items->count() - $items->count();
            $lines[] = '<b>'.e(__('telegram.lead.products')).':</b> '.$items->implode(', ')
                .($more > 0 ? ' '.e(__('telegram.lead.more_items', ['count' => $more])) : '');
        }

        $lines[] = self::line('contact', $lead->name.' · '.Phone::display($lead->phone));

        if (filled($lead->company)) {
            $lines[] = self::line('company', (string) $lead->company);
        }

        if (filled($lead->message)) {
            $lines[] = self::line('comment', Str::limit(trim((string) $lead->message), self::COMMENT_EXCERPT, '…'));
        }

        if ($lead->duplicate_of_id !== null) {
            $lines[] = e(__('telegram.lead.duplicate', ['reference' => Lead::REFERENCE_PREFIX.$lead->duplicate_of_id]));
        }

        if ($lead->customer !== null) {
            $lines[] = self::line('customer', $lead->customer->name);
        }

        return implode("\n", $lines);
    }

    /**
     * "<b>Label:</b> value" with both parts escaped.
     */
    private static function line(string $label, string $value): string
    {
        return '<b>'.e(__('telegram.lead.'.$label)).':</b> '.e($value);
    }

    /**
     * @return list<array{text: string, url: string}>
     */
    private static function buttons(Lead $lead): array
    {
        $url = rtrim((string) config('app.url'), '/').route('admin.leads.show', $lead, absolute: false);

        if (strtolower((string) parse_url($url, PHP_URL_SCHEME)) !== 'https' || ! filter_var($url, FILTER_VALIDATE_URL)) {
            return [];
        }

        return [['text' => __('telegram.lead.open_in_admin'), 'url' => $url]];
    }
}
