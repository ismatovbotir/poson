<?php

namespace App\Actions\Telegram;

use App\Enums\AuditAction;
use App\Enums\SettingsArea;
use App\Models\Setting;
use App\Models\SettingsAudit;
use App\Models\User;
use App\Support\Telegram\Bot\BotChecklist;
use App\Support\Telegram\Bot\BotSwitch;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

/**
 * Switch the customer bot on or off (plan 14 v2 D12, TG-37).
 *
 * - enable(): telegram.bot permission (Admin); the whole BotChecklist is
 *   re-evaluated here on every call, whatever the screen showed, and any
 *   failing item refuses with TelegramBotException(not_ready).
 * - disable(): no checklist, so it always works in an emergency; a user
 *   must have telegram.bot, null = the `telegram:bot disable` console command.
 *
 * The row is created (off) first if missing, then, in one transaction
 * (row locked): unchanged state → false; otherwise the
 * telegram.bot_enabled row (group telegram) is written and audited
 * (enabled / disabled, actor, IP). BotSwitch reads it fresh per update.
 */
class SetBotEnabled
{
    public function __construct(protected BotChecklist $checklist) {}

    /**
     * @return bool whether the state changed
     *
     * @throws AuthorizationException
     * @throws TelegramBotException not_ready
     */
    public function enable(User $user, ?string $ip): bool
    {
        $this->authorize($user);

        $failing = BotChecklist::failing($this->checklist->evaluate());

        if ($failing !== []) {
            throw new TelegramBotException('not_ready', $failing);
        }

        return $this->write(true, $user, $ip);
    }

    /**
     * @return bool whether the state changed
     *
     * @throws AuthorizationException
     */
    public function disable(?User $user, ?string $ip): bool
    {
        if ($user !== null) {
            $this->authorize($user);
        }

        return $this->write(false, $user, $ip);
    }

    /**
     * @throws AuthorizationException
     */
    protected function authorize(User $user): void
    {
        if (! $user->can('telegram.bot')) {
            throw new AuthorizationException;
        }
    }

    protected function write(bool $enabled, ?User $user, ?string $ip): bool
    {
        // First use: make sure the row exists (off) before locking it, so two
        // concurrent first writes can't both insert (createOrFirst absorbs the race).
        if (! Setting::query()->where('key', BotSwitch::KEY)->exists()) {
            Setting::query()->createOrFirst(['key' => BotSwitch::KEY], ['group' => SettingsArea::TelegramBot->group(), 'value' => false]);
        }

        return DB::transaction(function () use ($enabled, $user, $ip): bool {
            $row = Setting::query()->where('key', BotSwitch::KEY)->lockForUpdate()->firstOrFail();

            if (($row->value === true) === $enabled) {
                return false;
            }

            $row->forceFill(['group' => SettingsArea::TelegramBot->group(), 'value' => $enabled])->save();

            SettingsAudit::record(SettingsArea::TelegramBot, BotSwitch::KEY, $enabled ? AuditAction::Enabled : AuditAction::Disabled, $user, $ip);

            return true;
        });
    }
}
