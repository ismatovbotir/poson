<?php

namespace App\Support\Telegram\MiniApp;

/**
 * A Telegram Mini App initData whose signature and age were checked
 * (InitData::verify()). Only the Telegram user id, the client language and
 * the whitelisted start_param are kept; names always come from the database
 * (MA-9). Never logged, flashed or stored in the session (MA-11).
 */
final readonly class VerifiedInitData
{
    public function __construct(
        public int $telegramId,
        public int $authDate,
        public ?string $languageCode = null,
        public ?string $startParam = null,
    ) {}
}
