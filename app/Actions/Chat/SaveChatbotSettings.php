<?php

namespace App\Actions\Chat;

use App\Enums\AiUsageChannel;
use App\Enums\AuditAction;
use App\Enums\ChatChannel;
use App\Enums\Role;
use App\Enums\SettingsArea;
use App\Models\Setting;
use App\Models\SettingsAudit;
use App\Models\User;
use App\Rules\BrandSafeText;
use App\Support\Chat\AiOutputFilter;
use App\Support\Chat\AiSwitch;
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
 * AI answers (plan 30 §9, P2, AI-10, AI-24): "ai_telegram_enabled" /
 * "ai_web_enabled": switching ON is Admin only (AuthorizationException) and
 * refused (ValidationException) while ANTHROPIC_API_KEY is missing, the
 * privacy gate fails or (web) the widget is not ready; switching OFF is open
 * to everyone with chatbot.settings. The daily caps ("ai_daily_requests",
 * "ai_daily_tokens") and the instructions ("ai_instructions") are Admin
 * only when they change. Instructions: plain, brand-safe, at most
 * chatbot.ai.instructions_max characters, no links, @handles, emails,
 * phone- or card-like numbers or price-level words; audited with the old and
 * new text (SettingsAudit::recordText).
 *
 * AI product wizard (plan 32, PW-24): "ai_admin_enabled" changes only for an
 * Admin (on or off) and switching on is refused without the key; the daily
 * caps of the admin usage channels ("ai_adm_vision_daily_requests", …)
 * are Admin only when they change. Audited like the other switches.
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
        [$aiSwitches, $aiValues, $instructions] = $this->ai($data, $actor);

        $changed = DB::transaction(function () use ($values, $switches, $aiSwitches, $aiValues, $instructions, $actor, $ip): int {
            $changed = 0;

            foreach ([...$switches, ...$aiSwitches] as $key => $enabled) {
                $changed += $this->write($key, $enabled, $actor, $ip, $enabled ? AuditAction::Enabled : AuditAction::Disabled);
            }

            foreach ($aiValues as $key => $value) {
                $changed += $this->write($key, $value, $actor, $ip, AuditAction::Updated);
            }

            if ($instructions !== null) {
                $changed += $this->writeInstructions($instructions, $actor, $ip);
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

        foreach (ChatChannel::bots() as $channel) {
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
     * The AI answer settings that change: switches, caps, instructions.
     *
     * @param  array<string, mixed>  $data
     * @return array{0: array<string, bool>, 1: array<string, int>, 2: ?string}
     *
     * @throws AuthorizationException
     * @throws ValidationException
     */
    private function ai(array $data, User $actor): array
    {
        $isAdmin = $actor->hasRole(Role::Admin);
        $switches = [];

        foreach (ChatChannel::bots() as $channel) {
            $field = 'ai_'.$channel->value.'_enabled';

            if (! array_key_exists($field, $data)) {
                continue;
            }

            $want = filter_var($data[$field], FILTER_VALIDATE_BOOLEAN);
            $key = AiSwitch::keyFor($channel);

            if ($want === ($this->stored($key) === true)) {
                continue;
            }

            if ($want) {
                if (! $isAdmin) {
                    throw new AuthorizationException;
                }

                if (! AiSwitch::keyConfigured()) {
                    throw ValidationException::withMessages([$field => __('chatbot_admin.settings.ai_key_missing')]);
                }

                if ($channel === ChatChannel::Web && ! config('chatbot.web_ready')) {
                    throw ValidationException::withMessages([$field => __('chatbot_admin.settings.web_not_ready')]);
                }

                if (! ChatSettings::privacyReady(fresh: true)) {
                    throw ValidationException::withMessages([$field => __('chatbot_admin.settings.privacy_gate')]);
                }
            }

            $switches[$key] = $want;
        }

        $values = [];

        foreach (['ai_daily_requests' => 'requests', 'ai_daily_tokens' => 'tokens'] as $field => $cap) {
            if (! array_key_exists($field, $data)) {
                continue;
            }

            $value = $this->integer($data[$field], 0, (int) config("chatbot.ai.caps.{$cap}_max"), $field);
            $current = filter_var($this->stored(ChatSettings::key($field)), FILTER_VALIDATE_INT);

            if ($value === ($current === false ? (int) config("chatbot.ai.caps.{$cap}") : $current)) {
                continue;
            }

            if (! $isAdmin) {
                throw new AuthorizationException;
            }

            $values[ChatSettings::key($field)] = $value;
        }

        // Plan 32: the AI product wizard switch is Admin only in both directions (§14 plan change 8);
        // switching it on is refused while ANTHROPIC_API_KEY is missing (PW-24).
        if (array_key_exists('ai_admin_enabled', $data)) {
            $want = filter_var($data['ai_admin_enabled'], FILTER_VALIDATE_BOOLEAN);
            $key = ChatSettings::key('ai_admin_enabled');

            if ($want !== ($this->stored($key) === true)) {
                if (! $isAdmin) {
                    throw new AuthorizationException;
                }

                if ($want && ! AiSwitch::keyConfigured()) {
                    throw ValidationException::withMessages(['ai_admin_enabled' => __('chatbot_admin.settings.ai_key_missing')]);
                }

                $switches[$key] = $want;
            }
        }

        // Plan 32 (PW-12): daily caps of the admin usage channels, Admin only when they change.
        foreach (AiUsageChannel::cases() as $channel) {
            if (! $channel->isAdmin()) {
                continue;
            }

            foreach (['requests', 'tokens'] as $cap) {
                $field = "ai_{$channel->value}_daily_{$cap}";

                if (! array_key_exists($field, $data)) {
                    continue;
                }

                $value = $this->integer($data[$field], 0, (int) config("chatbot.ai.caps.{$cap}_max"), $field);
                $current = filter_var($this->stored(ChatSettings::key($field)), FILTER_VALIDATE_INT);

                if ($value === ($current === false ? (int) config("chatbot.ai.caps.{$channel->value}.{$cap}", 0) : $current)) {
                    continue;
                }

                if (! $isAdmin) {
                    throw new AuthorizationException;
                }

                $values[ChatSettings::key($field)] = $value;
            }
        }

        $instructions = null;

        if (array_key_exists('ai_instructions', $data)) {
            $text = $this->instructions($data['ai_instructions']);
            $current = $this->stored(ChatSettings::key('ai_instructions'));

            if ($text !== (is_string($current) ? $current : '')) {
                if (! $isAdmin) {
                    throw new AuthorizationException;
                }

                $instructions = $text;
            }
        }

        return [$switches, $values, $instructions];
    }

    /**
     * AI instructions (AI-10): they sit in the system prompt, which is
     * treated as public, so nothing internal or contact-like is accepted.
     */
    private function instructions(mixed $value): string
    {
        $field = 'ai_instructions';

        if (is_string($value) && ! mb_check_encoding($value, 'UTF-8')) {
            throw ValidationException::withMessages([$field => __('chatbot.errors.text_plain')]);
        }

        $text = is_string($value) ? trim(str_replace("\r\n", "\n", (string) preg_replace('/\p{Cf}/u', '', $value))) : '';

        if ($text === '') {
            return '';
        }

        if (str_contains($text, '<') || preg_match('/[^\P{Cc}\n]/u', $text) === 1) {
            throw ValidationException::withMessages([$field => __('chatbot.errors.text_plain')]);
        }

        if (mb_strlen($text) > (int) config('chatbot.ai.instructions_max', 2000)) {
            throw ValidationException::withMessages([$field => __('chatbot_admin.settings.ai_instructions_too_long', ['max' => (int) config('chatbot.ai.instructions_max', 2000)])]);
        }

        if (BrandSafeText::violates($text)) {
            throw ValidationException::withMessages([$field => __('chatbot.errors.brand')]);
        }

        // NFKC first, so fullwidth or ideographic dots cannot hide a host; any host is refused (H2).
        $lower = mb_strtolower(AiOutputFilter::normalize($text));
        $forbidden = [
            '~[a-z][a-z0-9+.-]{1,15}://|www\.|t\.me/~u',
            AiOutputFilter::DOMAIN,
            '/@/u',
            '/\+?\d(?:[ \-().\/]{0,2}\d){6,}/u',
            '/\bdealers?\b/u',
            '/(?<!\pL)diler/u',
            '/(?<!\pL)дилер/u',
            '/\bwholesal/u',
            '/(?<!\pL)ulgurji/u',
            '/(?<!\pL)опт(?:ов\pL*|ом)?(?!\pL)/u',
        ];

        foreach ($forbidden as $pattern) {
            if (preg_match($pattern, $lower) === 1) {
                throw ValidationException::withMessages([$field => __('chatbot_admin.settings.ai_instructions_forbidden')]);
            }
        }

        return $text;
    }

    private function stored(string $key): mixed
    {
        return Setting::query()->where('key', $key)->where('group', SettingsArea::Chatbot->group())->first()?->value;
    }

    /**
     * @return int 1 when the instructions changed (audited with the old and new text)
     */
    private function writeInstructions(string $text, User $actor, ?string $ip): int
    {
        $key = ChatSettings::key('ai_instructions');
        $row = Setting::query()->where('key', $key)->lockForUpdate()->first();
        $old = is_string($row?->value) ? $row->value : '';

        if ($row !== null && $old === $text) {
            return 0;
        }

        if ($row === null) {
            Setting::query()->create(['key' => $key, 'group' => SettingsArea::Chatbot->group(), 'value' => $text]);
        } else {
            $row->forceFill(['group' => SettingsArea::Chatbot->group(), 'value' => $text])->save();
        }

        SettingsAudit::recordText(SettingsArea::Chatbot, $key, AuditAction::Updated, $actor, $ip, $old, $text);

        return 1;
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
