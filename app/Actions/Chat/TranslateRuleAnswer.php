<?php

namespace App\Actions\Chat;

use App\Enums\AiFieldKind;
use App\Enums\AiUsageChannel;
use App\Enums\ChatAiStatus;
use App\Models\ChatRule;
use App\Models\User;
use App\Services\Ai\AiFailed;
use App\Services\Ai\AiRequest;
use App\Services\Ai\AiResult;
use App\Services\Ai\ChatModel;
use App\Support\Chat\AiOutputFilter;
use App\Support\Chat\AiPrompt;
use App\Support\Chat\AiSwitch;
use App\Support\Chat\ChatSettings;
use App\Support\Content\AiFill\AiAnswerParser;
use App\Support\Content\AiFill\AiField;
use App\Support\Content\AiFill\UzApostrophes;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * "Translate with Claude" in the chat rule editor (plan 30 §16, AI-B14):
 * one locale's answer text, as the editor typed it, translated into the two
 * other locales. Returns the texts for the form fields only: nothing is
 * stored or sent; the editor still saves the rule (SaveChatRule).
 *
 *  - Who: chatbot.rules (Admin, Content manager). The per-user (20/h) and
 *    global limits are hit by the component before this runs; here the
 *    kill switch (chatbot.ai_translate_enabled, Admin-only to enable), the
 *    API key, the auth pause and the daily cap of AiUsageChannel::AdminTranslate
 *    (RecordAiUsage::reserve, atomic in the database, AI-18).
 *  - Input: at most chatbot.ai.translate.source_max (1,000) characters,
 *    control / format characters removed, inside <source_text> as untrusted
 *    data. No history, customer text or knowledge. max_tokens 800.
 *  - Output: AiAnswerParser (JSON, whitelisted key and locales, plain text,
 *    Cc/Cf stripped, UzApostrophes, BrandSafeText) must give exactly the two
 *    target locales; each then passes the AI output filter, whose only
 *    allowed amounts are those value-parsed from the source text.
 *  - Errors: a fixed message (lang key) as a ValidationException on
 *    "translate"; logs carry the error class and HTTP status only.
 */
class TranslateRuleAnswer
{
    public function __construct(
        private readonly BuildAiKnowledge $knowledge,
        private readonly RecordAiUsage $usage,
        private readonly AiAnswerParser $parser,
    ) {}

    /**
     * @return array<string, string> target locale => translated text
     *
     * @throws ValidationException
     */
    public function handle(string $text, string $source, User $actor): array
    {
        Gate::forUser($actor)->authorize('create', ChatRule::class);

        $locales = config('app.supported_locales');

        if (! in_array($source, $locales, true)) {
            $this->fail('invalid');
        }

        if (! self::isAvailable()) {
            $this->fail('off');
        }

        $text = trim(AiAnswerParser::cleanCharacters(mb_scrub($text, 'UTF-8')));

        if ($text === '') {
            $this->fail('empty');
        }

        if (mb_strlen($text) > (int) config('chatbot.ai.translate.source_max', 1000)) {
            $this->fail('too_long', ['max' => (int) config('chatbot.ai.translate.source_max', 1000)]);
        }

        $targets = array_values(array_diff($locales, [$source]));
        $request = new AiRequest(
            system: [['text' => AiPrompt::translate($source, $targets), 'cache' => false]],
            messages: [['role' => 'user', 'content' => "<source_text lang=\"{$source}\">\n".AiPrompt::field($text, (int) config('chatbot.ai.translate.source_max', 1000))."\n</source_text>"]],
            maxTokens: (int) config('chatbot.ai.translate.max_tokens', 800),
        );

        $channel = AiUsageChannel::AdminTranslate;
        $reserved = $request->estimatedBilledTokens();
        $day = $this->usage->reserve($channel, $reserved);

        if ($day === null) {
            $this->usage->capReached($channel);
            $this->fail('cap');
        }

        $status = ChatAiStatus::Failed;
        $result = null;

        try {
            $result = app(ChatModel::class)->complete($request);

            if ($result->isRefusal()) {
                $status = ChatAiStatus::Refused;
                $this->fail('failed');
            }

            $translated = $this->read($result, $targets, $text);
            $status = ChatAiStatus::Answered;

            return $translated;
        } catch (ValidationException $exception) {
            if ($status === ChatAiStatus::Failed && $result !== null) {
                $status = ChatAiStatus::Blocked;
            }

            throw $exception;
        } catch (Throwable $error) {
            $code = $error instanceof AiFailed ? $error->status : null;

            if (in_array($code, [401, 403], true)) {
                AiSwitch::markAuthFailed();
            }

            Log::warning('chatbot.ai.translate_failed', [
                'error' => $error instanceof AiFailed ? $error->errorClass : class_basename($error),
                'status' => $code,
            ]);

            $this->fail('failed');
        } finally {
            $this->usage->settle($channel, $day, $reserved, $status, $result);
        }
    }

    /**
     * The switch is on, a key is set and the API has not refused it lately.
     */
    public static function isAvailable(): bool
    {
        return ChatSettings::all()['ai']['translate_enabled'] && AiSwitch::keyConfigured() && ! AiSwitch::authFailed();
    }

    /**
     * @param  list<string>  $targets
     * @return array<string, string>
     *
     * @throws ValidationException
     */
    private function read(AiResult $result, array $targets, string $source): array
    {
        $parsed = $this->parser->parse($result->text, [
            new AiField('answer', 'chatbot_admin.rules.fields.answer', AiFieldKind::Plain, (int) config('chatbot.answer_max', 1000), multiline: true),
        ]);
        $values = $parsed->values['answer'] ?? [];

        if ($parsed->errors !== [] || ! is_array($values)) {
            $this->fail('blocked');
        }

        $keys = array_keys($values);
        sort($keys);
        $expected = $targets;
        sort($expected);

        if ($keys !== $expected) {
            $this->fail('blocked');
        }

        $filter = $this->knowledge->outputFilter(AiOutputFilter::amountsIn($source));
        $out = [];

        foreach ($targets as $locale) {
            $text = trim((string) $values[$locale]);

            if ($filter->check($text) !== null) {
                $this->fail('blocked');
            }

            $out[$locale] = $locale === 'uz' ? UzApostrophes::normalize($text) : $text;

        }

        return $out;
    }

    /**
     * @param  array<string, int|string>  $replace
     *
     * @throws ValidationException
     */
    private function fail(string $reason, array $replace = []): never
    {
        throw ValidationException::withMessages(['translate' => __('chatbot_admin.rules.translate.'.$reason, $replace)]);
    }
}
