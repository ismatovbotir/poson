<?php

namespace App\Actions\Chat;

use App\Enums\AuditAction;
use App\Enums\ChatChannel;
use App\Enums\Role;
use App\Enums\SettingsArea;
use App\Models\Setting;
use App\Models\SettingsAudit;
use App\Models\User;
use App\Rules\BrandSafeText;
use App\Support\Chat\ChatbotSwitch;
use App\Support\Chat\ChatSettings;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Save the chat bot settings (plan 28 §5, CB-5, CB-13). The only writer of
 * the "chatbot.*" settings rows.
 *
 *  - Needs chatbot.settings (Admin and Content manager).
 *  - The channel switches ("web_enabled", "telegram_enabled") are Admin only:
 *    a request that changes one without the Admin role is refused outright
 *    (AuthorizationException), whatever the screen showed. Turning one ON is
 *    also refused (ValidationException) unless the privacy gate passes
 *    (ChatSettings::privacyReady). Turning one off needs no gate.
 *  - Texts are plain text (no "<", no control characters, 500 characters) and
 *    brand-safe; working hours are HH:MM; choices come from fixed lists.
 *  - Every changed key is audited to SettingsArea::Chatbot (who, when, which
 *    key, never the value).
 *
 * Input keys are optional: only the ones present are written.
 */
class SaveChatbotSettings
{
    /**
     * @param  array<string, mixed>  $data
     * @return int Number of changed settings
     *
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function handle(array $data, User $actor, ?string $ip = null): int
    {
        if (! $actor->can('chatbot.settings')) {
            throw new AuthorizationException;
        }

        $values = $this->values($data);
        $switches = $this->switches($data, $actor);

        $changed = DB::transaction(function () use ($values, $switches, $actor, $ip): int {
            $changed = 0;

            foreach ($switches as $key => $enabled) {
                $changed += $this->write($key, $enabled, $actor, $ip, $enabled ? AuditAction::Enabled : AuditAction::Disabled);
            }

            foreach ($values as $key => $value) {
                $changed += $this->write($key, $value, $actor, $ip, AuditAction::Updated);
            }

            return $changed;
        });

        ChatbotSwitch::forget();

        return $changed;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, bool> key => wanted state, only the switches that change
     */
    private function switches(array $data, User $actor): array
    {
        $changes = [];

        foreach (ChatChannel::cases() as $channel) {
            $field = $channel->value.'_enabled';

            if (! array_key_exists($field, $data)) {
                continue;
            }

            $want = filter_var($data[$field], FILTER_VALIDATE_BOOLEAN);
            $key = ChatbotSwitch::keyFor($channel);
            $current = Setting::query()->where('key', $key)->where('group', SettingsArea::Chatbot->group())->first()?->value === true;

            if ($want === $current) {
                continue;
            }

            if (! $actor->hasRole(Role::Admin)) {
                throw new AuthorizationException;
            }

            if ($want && $channel === ChatChannel::Web && ! config('chatbot.web_ready')) {
                throw ValidationException::withMessages([$field => __('chatbot_admin.settings.web_not_ready')]);
            }

            if ($want && ! ChatSettings::privacyReady(fresh: true)) {
                throw ValidationException::withMessages([$field => __('chatbot_admin.settings.privacy_gate')]);
            }

            $changes[$key] = $want;
        }

        return $changes;
    }

    /**
     * The validated non-switch values, keyed by settings key.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     *
     * @throws ValidationException
     */
    private function values(array $data): array
    {
        $values = [];

        if (array_key_exists('texts', $data)) {
            $texts = is_array($data['texts']) ? $data['texts'] : [];

            foreach (ChatSettings::TEXT_KEYS as $name) {
                $row = is_array($texts[$name] ?? null) ? $texts[$name] : [];
                $clean = [];

                foreach (config('app.supported_locales') as $locale) {
                    $clean[$locale] = $this->text($row[$locale] ?? null, "texts.{$name}.{$locale}");
                }

                $values[ChatSettings::key("text.{$name}")] = $clean;
            }
        }

        if (array_key_exists('use_company_hours', $data)) {
            $values[ChatSettings::key('use_company_hours')] = filter_var($data['use_company_hours'], FILTER_VALIDATE_BOOLEAN);
        }

        if (array_key_exists('hours', $data)) {
            $values[ChatSettings::key('hours')] = $this->hours(is_array($data['hours']) ? $data['hours'] : []);
        }

        if (array_key_exists('handoff_target', $data)) {
            $values[ChatSettings::key('handoff_target')] = $this->choice($data['handoff_target'], ChatSettings::TARGETS, 'handoff_target');
        }

        if (array_key_exists('widget_position', $data)) {
            $values[ChatSettings::key('widget_position')] = $this->choice($data['widget_position'], ChatSettings::POSITIONS, 'widget_position');
        }

        if (array_key_exists('widget_pages', $data)) {
            $values[ChatSettings::key('widget_pages')] = $this->choice($data['widget_pages'], ChatSettings::PAGES, 'widget_pages');
        }

        if (array_key_exists('widget_mobile', $data)) {
            $values[ChatSettings::key('widget_mobile')] = filter_var($data['widget_mobile'], FILTER_VALIDATE_BOOLEAN);
        }

        if (array_key_exists('handoff_ttl_hours', $data)) {
            $values[ChatSettings::key('handoff_ttl_hours')] = $this->integer($data['handoff_ttl_hours'], 1, 72, 'handoff_ttl_hours');
        }

        if (array_key_exists('products_shown', $data)) {
            $values[ChatSettings::key('products_shown')] = $this->integer($data['products_shown'], 3, 8, 'products_shown');
        }

        return $values;
    }

    private function text(mixed $value, string $field): string
    {
        if (is_string($value) && ! mb_check_encoding($value, 'UTF-8')) {
            throw ValidationException::withMessages([$field => __('chatbot.errors.text_plain')]);
        }

        // Format characters (bidi overrides, zero-width) are stripped, never stored (CB-19).
        $text = is_string($value) ? trim(str_replace("\r\n", "\n", (string) preg_replace('/\p{Cf}/u', '', $value))) : '';

        if ($text === '') {
            return '';
        }

        $plain = mb_check_encoding($text, 'UTF-8') && ! str_contains($text, '<') && preg_match('/[^\P{Cc}\n]/u', $text) !== 1;

        if (! $plain) {
            throw ValidationException::withMessages([$field => __('chatbot.errors.text_plain')]);
        }

        if (mb_strlen($text) > ChatSettings::TEXT_MAX) {
            throw ValidationException::withMessages([$field => __('chatbot.errors.text_too_long')]);
        }

        if (BrandSafeText::violates($text)) {
            throw ValidationException::withMessages([$field => __('chatbot.errors.brand')]);
        }

        return $text;
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, array{open: bool, from: string, to: string}>
     */
    private function hours(array $input): array
    {
        $hours = [];

        foreach (ChatSettings::DAYS as $day) {
            $row = is_array($input[$day] ?? null) ? $input[$day] : [];
            $open = filter_var($row['open'] ?? false, FILTER_VALIDATE_BOOLEAN);
            $from = is_string($row['from'] ?? null) ? $row['from'] : '';
            $to = is_string($row['to'] ?? null) ? $row['to'] : '';
            $valid = fn (string $time): bool => preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $time) === 1;

            if (! $valid($from) || ! $valid($to) || ($open && $from >= $to)) {
                throw ValidationException::withMessages(["hours.{$day}" => __('chatbot_admin.settings.hours_invalid')]);
            }

            $hours[$day] = ['open' => $open, 'from' => $from, 'to' => $to];
        }

        return $hours;
    }

    /**
     * @param  list<string>  $allowed
     */
    private function choice(mixed $value, array $allowed, string $field): string
    {
        if (! is_string($value) || ! in_array($value, $allowed, true)) {
            throw ValidationException::withMessages([$field => __('chatbot_admin.settings.choice_invalid')]);
        }

        return $value;
    }

    private function integer(mixed $value, int $min, int $max, string $field): int
    {
        $number = filter_var($value, FILTER_VALIDATE_INT);

        if ($number === false || $number < $min || $number > $max) {
            throw ValidationException::withMessages([$field => __('chatbot_admin.settings.number_invalid', ['min' => $min, 'max' => $max])]);
        }

        return $number;
    }

    /**
     * @return int 1 when the stored value changed (and an audit row was written)
     */
    private function write(string $key, mixed $value, User $actor, ?string $ip, AuditAction $action): int
    {
        $row = Setting::query()->where('key', $key)->lockForUpdate()->first();

        if ($row !== null && $row->value === $value) {
            return 0;
        }

        if ($row === null) {
            Setting::query()->create(['key' => $key, 'group' => SettingsArea::Chatbot->group(), 'value' => $value]);
        } else {
            $row->forceFill(['group' => SettingsArea::Chatbot->group(), 'value' => $value])->save();
        }

        SettingsAudit::record(SettingsArea::Chatbot, $key, $action, $actor, $ip);

        return 1;
    }
}
