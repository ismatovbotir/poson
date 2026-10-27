<?php

namespace App\Support\Telegram;

use App\Enums\PriceSyncStatus;
use App\Models\PriceSyncLog;

/**
 * Finance-chat alert for a price API batch with problems (plan 09), as
 * HTML for parse_mode=HTML in the given locale (lang/{uz,ru,en}/telegram.php,
 * Uzbek by default): token name, the counts (or the rejection code), the
 * first MAX_CODES unknown codes and an "Open the log" button (only when
 * APP_URL is https, like LeadMessage). Every value is escaped with e();
 * never the token itself or the IP.
 */
final readonly class PriceSyncMessage
{
    public const MAX_CODES = 10;

    /**
     * @param  list<array{text: string, url: string}>  $buttons
     */
    public function __construct(
        public string $html,
        public array $buttons,
    ) {}

    public static function for(PriceSyncLog $log, string $locale): self
    {
        $previous = app()->getLocale();
        app()->setLocale($locale);

        try {
            return new self(self::html($log), self::buttons($log));
        } finally {
            app()->setLocale($previous);
        }
    }

    private static function html(PriceSyncLog $log): string
    {
        $lines = [
            '<b>'.e(__('telegram.price_sync.title', ['id' => $log->id])).'</b>',
            '<b>'.e(__('telegram.price_sync.token')).':</b> '.e($log->token_name ?? __('telegram.price_sync.no_token')),
        ];

        if ($log->status === PriceSyncStatus::Rejected) {
            $lines[] = e(__('telegram.price_sync.rejected', ['error' => (string) $log->error]));

            return implode("\n", $lines);
        }

        $lines[] = e(__('telegram.price_sync.counts', [
            'received' => $log->received,
            'updated' => $log->updated,
            'unchanged' => $log->unchanged,
            'unknown' => $log->unknown,
            'invalid' => $log->invalid,
        ]));

        $codes = array_values((array) $log->unknown_codes);

        if ($codes !== []) {
            $shown = array_slice($codes, 0, self::MAX_CODES);
            $more = count($codes) - count($shown);

            $lines[] = '<b>'.e(__('telegram.price_sync.unknown_codes')).':</b> '
                .implode(', ', array_map(fn (string $code): string => '<code>'.e($code).'</code>', $shown))
                .($more > 0 ? ' '.e(__('telegram.price_sync.more_codes', ['count' => $more])) : '');
        }

        return implode("\n", $lines);
    }

    /**
     * @return list<array{text: string, url: string}>
     */
    private static function buttons(PriceSyncLog $log): array
    {
        $url = rtrim((string) config('app.url'), '/').route('admin.price-sync.show', $log, absolute: false);

        if (strtolower((string) parse_url($url, PHP_URL_SCHEME)) !== 'https' || ! filter_var($url, FILTER_VALIDATE_URL)) {
            return [];
        }

        return [['text' => __('telegram.price_sync.open_log'), 'url' => $url]];
    }
}
