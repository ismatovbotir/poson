<?php

namespace App\Actions\Catalog\Ai;

use App\Enums\AiDraftStatus;
use App\Enums\AiUsageChannel;
use App\Models\AiProductDraft;
use App\Services\Ai\AiRequest;
use App\Services\Ai\AiResult;
use App\Support\Content\AiProduct\AiFactsParser;
use App\Support\Content\AiProduct\AiVisionPrompt;

/**
 * Stage A "look at the photos" (plan 32 §2.1, §6): claude-haiku-5-5, effort
 * low, the vision copies attached, structured facts only. Builds the request
 * and turns the answer into facts; RunAiDraftStage does the guards, the call,
 * the usage and the state change.
 */
class SeeProductPhotos
{
    public function __construct(
        private readonly AiVisionPrompt $prompt,
        private readonly AiFactsParser $parser,
        private readonly AiDraftContext $context,
        private readonly PrepareAiDraftImages $images,
    ) {}

    public function channel(): AiUsageChannel
    {
        return AiUsageChannel::AdminVision;
    }

    /**
     * @throws AiStageSkipped when a vision copy is missing (pruned) or there is none (M4): no paid call
     */
    public function request(AiProductDraft $draft): AiRequest
    {
        $files = $this->images->visionFiles($draft);

        if ($files === [] || count($files) !== count((array) ($draft->input['vision_map'] ?? []))) {
            throw new AiStageSkipped('no_images');
        }
        $structured = (bool) config('chatbot.ai.product.structured', true);
        $base = $this->prompt->build($this->context->mainInfo($draft), count($files), $structured);

        return new AiRequest(
            system: $base->system,
            messages: $base->messages,
            maxTokens: $base->maxTokens,
            model: (string) config('chatbot.ai.product.vision.model'),
            effort: (string) config('chatbot.ai.product.vision.effort', 'low'),
            timeout: (float) config('chatbot.ai.product.vision.timeout', 45),
            images: array_map(fn (string $path): array => ['mediaType' => 'image/jpeg', 'path' => $path], $files),
            outputSchema: $structured ? AiFactsParser::schema() : null,
        );
    }

    /**
     * @return array{status: AiDraftStatus, values: array<string, mixed>}
     */
    public function store(AiProductDraft $draft, AiResult $result): array
    {
        $count = count($draft->input['vision_map'] ?? []);
        $parsed = $this->parser->parseAnswer($result->text, $count);

        if ($parsed['facts'] === null) {
            return ['status' => AiDraftStatus::Failed, 'values' => ['error' => $result->isTruncated() ? 'truncated' : 'invalid_answer']];
        }

        return ['status' => AiDraftStatus::FactsReady, 'values' => [
            'facts' => $parsed['facts'],
            'result' => [...($draft->result ?? []), 'facts_warnings' => array_slice($parsed['warnings'], 0, 20), 'facts_confirmed' => false],
            'error' => null,
        ]];
    }
}
