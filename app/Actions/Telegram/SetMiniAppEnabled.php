<?php

namespace App\Actions\Telegram;

use App\Enums\AuditAction;
use App\Enums\SettingsArea;
use App\Models\Setting;
use App\Models\SettingsAudit;
use App\Models\User;
use App\Support\Admin\PasswordConfirmation;
use App\Support\Telegram\MiniApp\MiniAppChecklist;
use App\Support\Telegram\MiniApp\MiniAppMenuSync;
use App\Support\Telegram\MiniApp\MiniAppSwitch;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

/**
 * Switch the Telegram Mini App on or off (plan 20 M6, MA-34, MA-35, MA-37),
 * modelled on SetBotEnabled.
 *
 * - enable(): telegram.bot permission (Admin) and a recent password
 *   confirmation, both checked here; the MiniAppChecklist is re-evaluated on
 *   the server on every call (whatever the screen showed) and any failing
 *   item refuses with TelegramBotException(mini_app_not_ready).
 * - disable(): no checklist and no Telegram call, so it works while the API
 *   is down; with a user, telegram.bot and a recent password; null = the
 *   `telegram:mini-app disable` console command.
 *
 * The row is created (off) first if missing (createOrFirst), then, in one
 * transaction with the row locked: unchanged → false; otherwise the
 * telegram.mini_app_enabled row is written, audited (enabled / disabled,
 * actor, IP; never a value) and MiniAppMenuSync::start() bumps the
 * generation and queues the menu fan-out after commit.
 */
class SetMiniAppEnabled
{
    public function __construct(protected MiniAppChecklist $checklist) {}

    /**
     * @return bool whether the state changed
     *
     * @throws AuthorizationException
     * @throws TelegramBotException password_required, mini_app_not_ready
     */
    public function enable(User $user, ?string $ip): bool
    {
        $this->authorize($user);

        $failing = MiniAppChecklist::failing($this->checklist->evaluate());

        if ($failing !== []) {
            throw new TelegramBotException('mini_app_not_ready', $failing);
        }

        return $this->write(true, $user, $ip);
    }

    /**
     * @return bool whether the state changed
     *
     * @throws AuthorizationException
     * @throws TelegramBotException password_required
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
     * @throws TelegramBotException
     */
    protected function authorize(User $user): void
    {
        if (! $user->can('telegram.bot')) {
            throw new AuthorizationException;
        }

        if (! PasswordConfirmation::isRecent()) {
            throw new TelegramBotException('password_required');
        }
    }

    protected function write(bool $enabled, ?User $user, ?string $ip): bool
    {
        if (! Setting::query()->where('key', MiniAppSwitch::KEY)->exists()) {
            Setting::query()->createOrFirst(['key' => MiniAppSwitch::KEY], ['group' => SettingsArea::TelegramBot->group(), 'value' => false]);
        }

        return DB::transaction(function () use ($enabled, $user, $ip): bool {
            $row = Setting::query()->where('key', MiniAppSwitch::KEY)->lockForUpdate()->firstOrFail();

            if (($row->value === true) === $enabled) {
                return false;
            }

            $row->forceFill(['group' => SettingsArea::TelegramBot->group(), 'value' => $enabled])->save();

            SettingsAudit::record(SettingsArea::TelegramBot, MiniAppSwitch::KEY, $enabled ? AuditAction::Enabled : AuditAction::Disabled, $user, $ip);

            MiniAppMenuSync::start();

            return true;
        });
    }
}
