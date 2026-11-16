<?php

namespace App\Actions\Catalog\Ai;

use App\Enums\AiDraftStatus;
use App\Models\AiProductDraft;
use App\Support\Content\AiProduct\AiFactsParser;

/**
 * Mini-step 3a "confirm the facts" (plan 32 §2.1, PW-13): the facts the
 * user checked and edited are parsed again strictly (bounds, Cc/Cf/bidi,
 * ProductTextGuard: no links, contacts, money or discount wording) before
 * they may reach stage B. Stored with one conditional UPDATE while no stage
 * runs; "facts_confirmed" unlocks the write stage.
 *
 * prefill(): improve without new photos skips stage A; the facts are built
 * from the product's current fields (AiDraftContext::factsFromProduct).
 */
class ConfirmAiDraftFacts
{
    public function __construct(private readonly AiFactsParser $parser) {}

    /**
     * @param  array<mixed>  $facts
     * @return list<string> error keys (admin.ai_wizard.facts_errors.*), empty when stored
     */
    public function handle(AiProductDraft $draft, array $facts): array
    {
        $images = count((array) ($draft->input['vision_map'] ?? []));
        $parsed = $this->parser->parseConfirmed($facts, $images);

        if ($parsed['facts'] === null) {
            return $parsed['errors'] !== [] ? $parsed['errors'] : ['invalid'];
        }

        $draft->refresh();
        $result = [...($draft->result ?? []), 'facts_confirmed' => true];

        $editable = fn () => AiProductDraft::query()->whereKey($draft->id)->where('user_id', $draft->user_id)
            ->whereIn('status', AiDraftStatus::values(AiDraftStatus::writeFrom()))
            ->whereNotNull('facts')
            ->whereNull('apply_state');

        $stored = $editable()->update([
            'facts' => json_encode($parsed['facts'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'result' => json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'updated_at' => now(),
        ]);

        if ($stored === 1) {
            return [];
        }

        // MySQL reports changed rows (N7): an identical confirm in the same second is still a success.
        $fresh = $editable()->first();

        return $fresh !== null && AiDraftLock::sameJson($fresh->facts, $parsed['facts']) && AiDraftLock::sameJson($fresh->result, $result) ? [] : ['state'];
    }

    /**
     * Facts without a vision stage (improve, nothing new to look at).
     *
     * @param  array<string, mixed>  $facts
     */
    public function prefill(AiProductDraft $draft, array $facts): bool
    {
        // Never over a started / interrupted apply (L1): apply_state set means the draft is the product's now.
        return AiProductDraft::query()->whereKey($draft->id)->where('user_id', $draft->user_id)
            ->whereIn('status', AiDraftStatus::values(AiDraftStatus::visionFrom()))
            ->whereNull('apply_state')
            ->update([
                'status' => AiDraftStatus::FactsReady->value,
                'facts' => json_encode($facts, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'result' => json_encode([...($draft->result ?? []), 'facts_warnings' => [], 'facts_confirmed' => false], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'error' => null,
                'updated_at' => now(),
            ]) === 1;
    }
}
