<?php

namespace App\Actions\Chat;

use App\Enums\ChatAction;
use App\Enums\KeywordMatch;
use App\Enums\Role;
use App\Models\ChatRule;
use App\Models\FaqItem;
use App\Models\User;
use App\Rules\BrandSafeText;
use App\Support\Chat\ChatText;
use App\Support\Chat\LinkTargets;
use App\Support\Chat\RuleIndex;
use App\Support\Chat\Skills\CompanyInfoSkill;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Create or update a chat rule with its keywords (plan 28, CB-1, 5, 6, 12).
 * The only writer of chat_rules / chat_rule_keywords besides DeleteChatRule.
 *
 * Input (all strings are plain text):
 *   key, name, action, params, answer {locale: text}, buttons [{label{}, rule_id | target [+slug] | url}],
 *   keywords [{phrase, locale?, match_type, weight}], on_web, on_telegram, in_menu, menu_sort, priority, is_active
 *
 * Rules enforced here, whoever the caller is:
 *  - action is read with ChatAction::tryFrom; params are rebuilt from a
 *    per-action whitelist (unknown keys dropped, values validated);
 *  - a system rule (is_system) keeps its key and action: those input values
 *    are ignored; is_system can only be set by the install command ($system);
 *  - answers, labels and the name are plain text: no "<", no control
 *    characters, caps (answer 1000 per locale, label 40, keyword 120), and
 *    answers and labels pass BrandSafeText (no low-price or discount wording);
 *  - links: a named site target (active page for "page") for anyone with
 *    chatbot.rules; an absolute https URL only for an Admin and only on an
 *    allowed host (LinkTargets::isSafeExternalUrl);
 *  - at most max_rules rules, max_keywords_per_rule keywords, max_buttons buttons;
 *  - keywords are stored with their ChatText::normalize() form, duplicates
 *    (same type and normalized form) collapse.
 *
 * handle() needs a signed-in user; installSystem() is the one trusted path
 * (InstallChatbotDefaults). The compiled-rule cache is invalidated after the
 * transaction commits.
 */
class SaveChatRule
{
    /**
     * Save a rule on behalf of a signed-in user (authorized by the policy;
     * an external URL needs the Admin role).
     *
     * @param  array<string, mixed>  $data
     *
     * @throws ValidationException
     */
    public function handle(array $data, User $actor, ?ChatRule $rule = null): ChatRule
    {
        $rule === null
            ? Gate::forUser($actor)->authorize('create', ChatRule::class)
            : Gate::forUser($actor)->authorize('update', $rule);

        return $this->persist($data, $rule, $actor, system: false, bump: true);
    }

    /**
     * The trusted path for the built-in rules, used only by
     * InstallChatbotDefaults (an architecture test checks that nothing else
     * calls it): no signed-in user, treated as an Admin, and the rule is
     * created as a system rule. The caller bumps the rule cache once.
     *
     * @param  array<string, mixed>  $data
     *
     * @throws ValidationException
     */
    public function installSystem(array $data): ChatRule
    {
        return $this->persist($data, null, null, system: true, bump: false);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function persist(array $data, ?ChatRule $rule, ?User $actor, bool $system, bool $bump): ChatRule
    {
        $isAdmin = $actor === null || $actor->hasRole(Role::Admin);
        $locked = $rule !== null && $rule->is_system;

        $key = $locked ? $rule->key : $this->key($data['key'] ?? null, $rule);
        $action = $locked ? $rule->action : $this->action($data['action'] ?? null);
        $name = $this->plain($data['name'] ?? null, 120, 'name', required: true);
        $answer = $this->answers($data['answer'] ?? [], required: $action === ChatAction::Answer);
        $stored = $this->storedUrls($rule);
        $params = $this->params($action, (array) ($data['params'] ?? []), $isAdmin, (bool) ($data['in_menu'] ?? false), $stored);
        $buttons = $this->buttons((array) ($data['buttons'] ?? []), $isAdmin, $stored);
        $keywords = $this->keywords((array) ($data['keywords'] ?? []));

        if ($rule === null && ChatRule::query()->count() >= (int) config('chatbot.max_rules', 500)) {
            throw ValidationException::withMessages(['rule' => __('chatbot.errors.too_many_rules', ['max' => config('chatbot.max_rules', 500)])]);
        }

        return DB::transaction(function () use ($data, $rule, $actor, $system, $bump, $key, $action, $name, $answer, $params, $buttons, $keywords): ChatRule {
            $rule ??= new ChatRule;

            $rule->fill([
                'key' => $key,
                'name' => $name,
                'action' => $action,
                'params' => $params === [] ? null : $params,
                'buttons' => $buttons === [] ? null : $buttons,
                'on_web' => (bool) ($data['on_web'] ?? true),
                'on_telegram' => (bool) ($data['on_telegram'] ?? true),
                'in_menu' => (bool) ($data['in_menu'] ?? false),
                'menu_sort' => max(0, min(32000, (int) ($data['menu_sort'] ?? 0))),
                'priority' => max(-1000, min(1000, (int) ($data['priority'] ?? 0))),
                'is_active' => (bool) ($data['is_active'] ?? true),
            ]);
            $rule->storeTranslations('answer', $answer);

            if (! $rule->exists) {
                $rule->is_system = $system;
                $rule->created_by_id = $actor?->id;
            }

            $rule->updated_by_id = $actor?->id;
            $rule->save();

            $rule->keywords()->delete();

            foreach ($keywords as $keyword) {
                $rule->keywords()->create($keyword);
            }

            if ($bump) {
                RuleIndex::bump();
            }

            return $rule->fresh(['keywords']);
        });
    }

    /**
     * External URLs (params.url, buttons.*.url) currently stored on the rule.
     *
     * @return list<string>
     */
    private function storedUrls(?ChatRule $rule): array
    {
        if ($rule === null) {
            return [];
        }

        $urls = [];

        foreach ([$rule->params['url'] ?? null, ...array_map(fn ($button) => is_array($button) ? ($button['url'] ?? null) : null, (array) $rule->buttons)] as $url) {
            if (is_string($url) && $url !== '') {
                $urls[] = $url;
            }
        }

        return $urls;
    }

    private function key(mixed $value, ?ChatRule $rule): string
    {
        $key = is_string($value) ? trim($value) : '';
        $length = strlen($key);
        $valid = $length >= 2 && $length <= 60 && ctype_lower($key[0]) && strspn($key, 'abcdefghijklmnopqrstuvwxyz0123456789_') === $length;

        if (! $valid) {
            throw ValidationException::withMessages(['key' => __('chatbot.errors.key_invalid')]);
        }

        $taken = ChatRule::query()->where('key', $key)->when($rule !== null, fn ($query) => $query->whereKeyNot($rule->getKey()))->exists();

        if ($taken) {
            throw ValidationException::withMessages(['key' => __('chatbot.errors.key_taken')]);
        }

        return $key;
    }

    private function action(mixed $value): ChatAction
    {
        $action = $value instanceof ChatAction ? $value : (is_string($value) ? ChatAction::tryFrom($value) : null);

        return $action ?? throw ValidationException::withMessages(['action' => __('chatbot.errors.action_invalid')]);
    }

    /**
     * One plain text: trimmed, no "<", no control characters except line
     * breaks in multi-line texts, within the cap. Empty returns ''.
     *
     * @throws ValidationException
     */
    private function plain(mixed $value, int $max, string $field, bool $required = false, bool $multiline = false, bool $brand = false): string
    {
        $text = is_string($value) ? trim(str_replace("\r\n", "\n", $value)) : '';

        if ($text === '') {
            return $required
                ? throw ValidationException::withMessages([$field => __('chatbot.errors.label_required')])
                : '';
        }

        if (! mb_check_encoding($text, 'UTF-8') || str_contains($text, '<')) {
            throw ValidationException::withMessages([$field => __('chatbot.errors.text_plain')]);
        }

        for ($i = 0, $length = strlen($text); $i < $length; $i++) {
            $ord = ord($text[$i]);

            if (($ord < 32 && ! ($multiline && $ord === 10)) || $ord === 127) {
                throw ValidationException::withMessages([$field => __('chatbot.errors.text_plain')]);
            }
        }

        if (mb_strlen($text) > $max) {
            throw ValidationException::withMessages([$field => __('chatbot.errors.text_too_long')]);
        }

        if ($brand && BrandSafeText::violates($text)) {
            throw ValidationException::withMessages([$field => __('chatbot.errors.brand')]);
        }

        return $text;
    }

    /**
     * @param  mixed  $values  locale => text
     * @return array<string, string>
     */
    private function answers(mixed $values, bool $required): array
    {
        $values = is_array($values) ? $values : [];
        $answers = [];

        foreach (config('app.supported_locales') as $locale) {
            $text = $this->plain($values[$locale] ?? null, (int) config('chatbot.answer_max', 1000), "answer.{$locale}", multiline: true, brand: true);

            if ($text !== '') {
                $answers[$locale] = $text;
            }
        }

        if ($required && ! isset($answers['uz'])) {
            throw ValidationException::withMessages(['answer.uz' => __('chatbot.errors.answer_required')]);
        }

        return $answers;
    }

    /**
     * A label per locale (uz required): plain, brand-safe, 40 characters.
     *
     * @return array<string, string>
     */
    private function labels(mixed $values, string $field): array
    {
        $values = is_array($values) ? $values : [];
        $labels = [];

        foreach (config('app.supported_locales') as $locale) {
            $text = $this->plain($values[$locale] ?? null, (int) config('chatbot.label_max', 40), "{$field}.{$locale}", brand: true);

            if ($text !== '') {
                $labels[$locale] = $text;
            }
        }

        if (! isset($labels['uz'])) {
            throw ValidationException::withMessages(["{$field}.uz" => __('chatbot.errors.label_required')]);
        }

        return $labels;
    }

    /**
     * Params rebuilt from the whitelist of the action.
     *
     * @param  array<string, mixed>  $input
     * @param  list<string>  $stored  External URLs already saved on this rule
     * @return array<string, mixed>
     */
    private function params(ChatAction $action, array $input, bool $isAdmin, bool $inMenu, array $stored = []): array
    {
        $params = [];

        if ($inMenu) {
            $params['label'] = $this->labels($input['label'] ?? null, 'params.label');
        }

        switch ($action) {
            case ChatAction::Faq:
                if (isset($input['faq_id']) && $input['faq_id'] !== '') {
                    $id = filter_var($input['faq_id'], FILTER_VALIDATE_INT);

                    if ($id === false || ! FaqItem::query()->active()->whereKey($id)->exists()) {
                        throw ValidationException::withMessages(['params.faq_id' => __('chatbot.errors.faq_invalid')]);
                    }

                    $params['faq_id'] = $id;
                }
                break;
            case ChatAction::CompanyInfo:
                $topic = $input['topic'] ?? null;

                if (! is_string($topic) || ! in_array($topic, CompanyInfoSkill::TOPICS, true)) {
                    throw ValidationException::withMessages(['params.topic' => __('chatbot.errors.topic_invalid')]);
                }

                $params['topic'] = $topic;
                break;
            case ChatAction::Link:
                $params += $this->linkTarget($input, $isAdmin, 'params', $stored);
                $params['link_label'] = $this->labels($input['link_label'] ?? null, 'params.link_label');
                break;
            case ChatAction::LeadDialog:
                $flow = $input['flow'] ?? null;

                if (! is_string($flow) || ! in_array($flow, ['callback', 'quote', 'service'], true)) {
                    throw ValidationException::withMessages(['params.flow' => __('chatbot.errors.flow_invalid')]);
                }

                $params['flow'] = $flow;
                break;
            default:
                break;
        }

        return $params;
    }

    /**
     * @param  array<string, mixed>  $input  {target, slug?} or {url}
     * @return array<string, string>
     */
    private function linkTarget(array $input, bool $isAdmin, string $field, array $stored = []): array
    {
        $url = $input['url'] ?? null;

        if ($url !== null && $url !== '') {
            // A non-Admin may only keep a URL an Admin already stored on this same rule.
            $allowed = is_string($url) && ($isAdmin ? LinkTargets::isSafeExternalUrl($url) : in_array($url, $stored, true));

            if (! $allowed) {
                throw ValidationException::withMessages(["{$field}.url" => __('chatbot.errors.url_forbidden')]);
            }

            return ['url' => $url];
        }

        $target = $input['target'] ?? null;

        if (! is_string($target) || ! in_array($target, LinkTargets::targets(), true)) {
            throw ValidationException::withMessages(["{$field}.target" => __('chatbot.errors.target_invalid')]);
        }

        if ($target !== 'page') {
            return ['target' => $target];
        }

        $slug = $input['slug'] ?? null;

        if (LinkTargets::resolve(['target' => 'page', 'slug' => $slug], 'uz') === null) {
            throw ValidationException::withMessages(["{$field}.slug" => __('chatbot.errors.target_invalid')]);
        }

        return ['target' => 'page', 'slug' => $slug];
    }

    /**
     * @param  array<int, mixed>  $input
     * @return list<array<string, mixed>>
     */
    private function buttons(array $input, bool $isAdmin, array $stored = []): array
    {
        $max = (int) config('chatbot.max_buttons', 8);

        if (count($input) > $max) {
            throw ValidationException::withMessages(['buttons' => __('chatbot.errors.too_many_buttons', ['max' => $max])]);
        }

        $buttons = [];

        foreach (array_values($input) as $index => $button) {
            $button = is_array($button) ? $button : [];
            $field = "buttons.{$index}";
            $entry = ['label' => $this->labels($button['label'] ?? null, "{$field}.label")];

            if (isset($button['rule_id']) && $button['rule_id'] !== '') {
                $id = filter_var($button['rule_id'], FILTER_VALIDATE_INT);

                if ($id === false || ! ChatRule::query()->whereKey($id)->exists()) {
                    throw ValidationException::withMessages(["{$field}.rule_id" => __('chatbot.errors.button_invalid')]);
                }

                $entry['rule_id'] = $id;
            } else {
                $entry += $this->linkTarget($button, $isAdmin, $field, $stored);
            }

            $buttons[] = $entry;
        }

        return $buttons;
    }

    /**
     * @param  array<int, mixed>  $input
     * @return list<array{locale: ?string, phrase: string, normalized: string, match_type: KeywordMatch, weight: int}>
     */
    private function keywords(array $input): array
    {
        $max = (int) config('chatbot.max_keywords_per_rule', 50);

        if (count($input) > $max) {
            throw ValidationException::withMessages(['keywords' => __('chatbot.errors.too_many_keywords', ['max' => $max])]);
        }

        $keywords = [];

        foreach (array_values($input) as $index => $keyword) {
            $keyword = is_array($keyword) ? $keyword : [];
            $phrase = is_string($keyword['phrase'] ?? null) ? trim($keyword['phrase']) : '';
            $type = is_string($keyword['match_type'] ?? null) ? KeywordMatch::tryFrom($keyword['match_type']) : (($keyword['match_type'] ?? null) instanceof KeywordMatch ? $keyword['match_type'] : null);
            $weight = filter_var($keyword['weight'] ?? 10, FILTER_VALIDATE_INT);
            $locale = $keyword['locale'] ?? null;
            $normalized = ChatText::normalize($phrase, (int) config('chatbot.keyword_max', 120));

            $valid = $normalized !== ''
                && mb_strlen($phrase) <= (int) config('chatbot.keyword_max', 120)
                && $type !== null
                && $weight !== false && $weight >= 1 && $weight <= 100
                && ($locale === null || $locale === '' || (is_string($locale) && in_array($locale, config('app.supported_locales'), true)));

            if (! $valid) {
                throw ValidationException::withMessages(["keywords.{$index}" => __('chatbot.errors.keyword_invalid')]);
            }

            $keywords[$type->value.'|'.$normalized] = [
                'locale' => $locale === '' ? null : $locale,
                'phrase' => $phrase,
                'normalized' => $normalized,
                'match_type' => $type,
                'weight' => $weight,
            ];
        }

        return array_values($keywords);
    }
}
