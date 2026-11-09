<?php

namespace App\Actions\Chat;

use App\Enums\KeywordMatch;
use App\Models\ChatRule;
use App\Models\ChatRuleKeyword;
use App\Models\ChatUnmatched;
use App\Models\User;
use App\Support\Chat\ChatText;
use App\Support\Chat\RuleIndex;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Teach the bot from the "unmatched" list (plan 28 §5): add the phrase the bot
 * did not understand as a keyword of an existing rule, then mark the row
 * resolved by that rule. The phrase comes from the stored (already redacted)
 * "normalized" text, never from the browser. Needs chatbot.rules.
 *
 *  - A phrase under 2 characters (for example only masks) is refused. Masked
 *    parts ("#" in the sample) are already gone from the normalized text.
 *  - A keyword of the same type and normalized form on the rule is not added
 *    twice (the row is still resolved).
 *  - The keyword cap per rule applies.
 *  - The row is locked and must still be open; the compiled-rule cache is
 *    invalidated after the commit.
 */
class AddKeywordFromUnmatched
{
    /**
     * @throws ValidationException
     */
    public function handle(ChatUnmatched $unmatched, ChatRule $rule, User $actor, KeywordMatch $type = KeywordMatch::Phrase, int $weight = 10): ChatRuleKeyword
    {
        Gate::forUser($actor)->authorize('update', $unmatched);
        Gate::forUser($actor)->authorize('update', $rule);

        if ($weight < 1 || $weight > 100) {
            throw ValidationException::withMessages(['weight' => __('chatbot.errors.keyword_invalid')]);
        }

        return DB::transaction(function () use ($unmatched, $rule, $type, $weight): ChatRuleKeyword {
            $row = ChatUnmatched::query()->whereKey($unmatched->getKey())->lockForUpdate()->first();

            if ($row === null || $row->resolved_at !== null) {
                throw ValidationException::withMessages(['unmatched' => __('chatbot_admin.unmatched.already_resolved')]);
            }

            $locked = ChatRule::query()->whereKey($rule->getKey())->lockForUpdate()->firstOrFail();
            $phrase = $row->normalized;

            if (mb_strlen($phrase) < 2 || ChatText::normalize($phrase) !== $phrase) {
                throw ValidationException::withMessages(['unmatched' => __('chatbot_admin.unmatched.not_teachable')]);
            }

            $keyword = $locked->keywords()->where('match_type', $type->value)->where('normalized', $phrase)->first();

            if ($keyword === null) {
                if ($locked->keywords()->count() >= (int) config('chatbot.max_keywords_per_rule', 50)) {
                    throw ValidationException::withMessages(['unmatched' => __('chatbot.errors.too_many_keywords', ['max' => config('chatbot.max_keywords_per_rule', 50)])]);
                }

                $keyword = $locked->keywords()->create([
                    'locale' => $row->locale,
                    'phrase' => $phrase,
                    'normalized' => $phrase,
                    'match_type' => $type,
                    'weight' => $weight,
                ]);
            }

            $row->forceFill(['resolved_at' => now(), 'resolved_rule_id' => $locked->id])->save();

            RuleIndex::bump();

            return $keyword;
        });
    }
}
