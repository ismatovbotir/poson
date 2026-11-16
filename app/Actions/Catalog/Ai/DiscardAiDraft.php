<?php

namespace App\Actions\Catalog\Ai;

use App\Enums\AiDraftStatus;
use App\Models\AiProductDraft;

/**
 * Cancel / discard an AI draft (plan 32 §5, PW-8, PW-28): one conditional
 * UPDATE to expired from any state but applying / applied / expired, so a
 * running stage loses (the job stores only while its status is still
 * seeing / writing). The vision copies, cleaned images and previews are deleted; kept
 * originals of an earlier apply are left to the prune.
 */
class DiscardAiDraft
{
    public function __construct(private readonly PrepareAiDraftImages $images) {}

    public function handle(AiProductDraft $draft): bool
    {
        $from = array_values(array_filter(AiDraftStatus::cases(), fn (AiDraftStatus $status): bool => ! in_array($status, [AiDraftStatus::Applying, AiDraftStatus::Applied, AiDraftStatus::Expired], true)));

        if (! AiDraftState::move($draft->id, $from, AiDraftStatus::Expired, ['error' => null], $draft->user_id)) {
            return false;
        }

        $this->images->deleteVision($draft->uuid);
        $this->images->deleteClean($draft->uuid);
        $this->images->deletePreview($draft->uuid);

        return true;
    }
}
