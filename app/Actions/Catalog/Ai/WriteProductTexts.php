<?php

namespace App\Actions\Catalog\Ai;

use App\Enums\AiDraftStatus;
use App\Enums\AiFieldKind;
use App\Enums\AiUsageChannel;
use App\Models\AiProductDraft;
use App\Models\Product;
use App\Rules\BrandSafeText;
use App\Services\Ai\AiRequest;
use App\Services\Ai\AiResult;
use App\Support\Content\AiFill\AiAnswerParser;
use App\Support\Content\AiFill\UzApostrophes;
use App\Support\Content\AiProduct\AiSpecParser;
use App\Support\Content\AiProduct\AiWritePrompt;
use App\Support\Content\AiProduct\ProductTextGuard;
use JsonException;

/**
 * Stage B "write" (plan 32 §2.1, §6, PW-13, PW-15, PW-16): claude-sonnet-5-5,
 * effort medium, NO images, from the confirmed facts, the main info, the
 * notes, (improve) the current texts and the category attributes. Texts go
 * through AiAnswerParser (whitelist, sanitizer, limits, brand rule, ʻ/ʼ) and
 * ProductTextGuard (links/contacts removed, money/discount = error), specs
 * through AiSpecParser (current category, existing option ids), alts the
 * same as plain texts. Only validated values are stored, with message keys.
 */
class WriteProductTexts
{
    public const ALT_MAX = 255;

    public function __construct(
        private readonly AiWritePrompt $prompt,
        private readonly AiAnswerParser $answers,
        private readonly AiSpecParser $specs,
        private readonly ProductTextGuard $guard,
        private readonly AiDraftContext $context,
    ) {}

    public function channel(): AiUsageChannel
    {
        return AiUsageChannel::AdminWrite;
    }

    public function request(AiProductDraft $draft): AiRequest
    {
        $product = $draft->isImprove() && $draft->product_id !== null ? Product::query()->find($draft->product_id) : null;
        $structured = (bool) config('chatbot.ai.product.structured', true);
        $images = $this->imageBlock($draft);

        $base = $this->prompt->build(
            $this->context->mainInfo($draft),
            (array) ($draft->facts ?? []),
            AiWritePrompt::notes((string) ($draft->input['notes'] ?? '')),
            $product !== null ? $this->context->current($product) : [],
            $this->context->attributes($draft, $product),
            $images,
            $structured,
        );

        return new AiRequest(
            system: $base->system,
            messages: $base->messages,
            maxTokens: $base->maxTokens,
            model: (string) config('chatbot.ai.product.write.model'),
            effort: (string) config('chatbot.ai.product.write.effort', 'medium'),
            timeout: (float) config('chatbot.ai.product.write.timeout', 120),
            outputSchema: $structured ? AiWritePrompt::schema(count($images)) : null,
        );
    }

    /**
     * @return array{status: AiDraftStatus, values: array<string, mixed>}
     */
    public function store(AiProductDraft $draft, AiResult $result): array
    {
        $data = $this->decode($result->text);

        if ($data === null) {
            return ['status' => $result->isTruncated() ? AiDraftStatus::Truncated : AiDraftStatus::Failed, 'values' => [
                'error' => $result->isTruncated() ? 'truncated' : 'invalid_answer',
                'result' => [...($draft->result ?? []), 'texts' => [], 'specs' => [], 'alts' => [], 'messages' => []],
            ]];
        }

        $messages = [];
        $texts = $this->texts($data, $messages);
        $specs = $this->specs->parse($data['specs'] ?? [], (int) ($draft->input['info']['category_id'] ?? 0) ?: null);

        if ($specs['dropped'] > 0) {
            $messages[] = ['key' => 'specs', 'locale' => null, 'message' => 'admin.ai_wizard.guard.specs_dropped', 'params' => ['count' => $specs['dropped']]];
        }

        $alts = $this->alts($data['alts'] ?? [], $draft, $messages);

        return ['status' => $result->isTruncated() ? AiDraftStatus::Truncated : AiDraftStatus::Ready, 'values' => [
            'error' => null,
            'result' => [
                ...($draft->result ?? []),
                'texts' => $texts,
                'specs' => array_values($specs['specs']),
                'alts' => $alts,
                'messages' => array_slice($messages, 0, 30),
            ],
        ]];
    }

    /**
     * Draft images still in the product (index from 1 = draft index + 1) with their confirmed view note.
     *
     * @return list<array{index: int, note: string}>
     */
    private function imageBlock(AiProductDraft $draft): array
    {
        $notes = [];

        foreach ((array) ($draft->facts['views'] ?? []) as $view) {
            $position = (int) ($view['index'] ?? 0) - 1;
            $draftIndex = $draft->input['vision_map'][$position] ?? null;

            if ($draftIndex !== null) {
                $notes[(int) $draftIndex] = (string) ($view['note'] ?? '');
            }
        }

        $block = [];

        foreach ($draft->input['images'] ?? [] as $index => $image) {
            if (($image['action'] ?? '') === 'remove') {
                continue;
            }

            $block[] = ['index' => $index + 1, 'note' => mb_substr($notes[$index] ?? '', 0, 100)];
        }

        return array_slice($block, 0, 12);
    }

    /**
     * @param  array<mixed>  $data
     * @param  list<array<string, mixed>>  $messages
     * @return array<string, array<string, string>>
     */
    private function texts(array $data, array &$messages): array
    {
        $subset = array_intersect_key($data, array_flip(array_map(fn ($field): string => $field->key, AiWritePrompt::fields())));
        $parsed = $this->answers->parse((string) json_encode($subset === [] ? new \stdClass : $subset), AiWritePrompt::fields());
        $messages = [...$messages, ...$parsed->errors, ...$parsed->warnings];
        $html = [];

        foreach (AiWritePrompt::fields() as $field) {
            $html[$field->key] = $field->kind === AiFieldKind::Html;
        }

        $texts = [];

        foreach ($parsed->values as $field => $locales) {
            foreach ($locales as $locale => $text) {
                $checked = $this->guard->check($text, $html[$field] ?? false);

                foreach ($checked['warnings'] as $warning) {
                    $messages[] = ['key' => $field, 'locale' => $locale, 'message' => 'admin.ai_wizard.guard.'.$warning, 'params' => []];
                }

                if ($checked['errors'] !== [] || trim(strip_tags($checked['text'])) === '') {
                    foreach ($checked['errors'] as $error) {
                        $messages[] = ['key' => $field, 'locale' => $locale, 'message' => 'admin.ai_wizard.guard.'.$error, 'params' => []];
                    }

                    continue;
                }

                $texts[$field][$locale] = $checked['text'];
            }
        }

        return $texts;
    }

    /**
     * Alts by draft image index.
     *
     * @param  list<array<string, mixed>>  $messages
     * @return array<int, array<string, string>>
     */
    private function alts(mixed $items, AiProductDraft $draft, array &$messages): array
    {
        $count = count($draft->input['images'] ?? []);
        $alts = [];

        foreach (is_array($items) && array_is_list($items) ? array_slice($items, 0, 12) : [] as $item) {
            $index = is_array($item) ? filter_var($item['index'] ?? null, FILTER_VALIDATE_INT) : false;

            if ($index === false || $index < 1 || $index > $count) {
                continue;
            }

            foreach (config('app.supported_locales') as $locale) {
                $raw = $item[$locale] ?? null;
                $text = is_string($raw) && mb_check_encoding($raw, 'UTF-8') ? trim((string) preg_replace('/\s+/u', ' ', AiAnswerParser::cleanCharacters($raw))) : '';

                if ($text === '' || str_contains($text, '<') || mb_strlen($text) > self::ALT_MAX) {
                    continue;
                }

                $text = $locale === 'uz' ? UzApostrophes::normalize($text) : $text;
                $checked = $this->guard->check($text);

                if ($checked['errors'] !== [] || $checked['text'] === '' || BrandSafeText::violates($checked['text'])) {
                    $messages[] = ['key' => 'alts', 'locale' => $locale, 'message' => 'admin.ai_wizard.guard.alt_dropped', 'params' => []];

                    continue;
                }

                $alts[$index - 1][$locale] = $checked['text'];
            }
        }

        return $alts;
    }

    /**
     * @return array<mixed>|null
     */
    private function decode(string $raw): ?array
    {
        if (strlen($raw) > AiAnswerParser::MAX_BYTES || ! mb_check_encoding($raw, 'UTF-8')) {
            return null;
        }

        $raw = trim($raw);

        if (preg_match('/\A```[A-Za-z0-9_-]*[ \t]*\n(.*?)\n?```\z/s', $raw, $match) === 1) {
            $raw = trim($match[1]);
        }

        try {
            $data = json_decode($raw, true, 6, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        return is_array($data) && ! array_is_list($data) ? $data : null;
    }
}
