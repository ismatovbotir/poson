<?php

namespace App\Actions\Mcp;

use App\Actions\Faq\SaveFaqItem;
use App\Mcp\Support\FaqOwner;
use App\Models\FaqItem;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Create an INACTIVE FAQ item for the MCP create_faq_draft tool (M3-S1, S3):
 * SaveFaqItem with a null item (no existing item is loaded) and is_active
 * passed as false explicitly (SaveFaqItem defaults to true). A missing owner
 * and an owner the user may not manage give the same error.
 */
class CreateMcpFaqDraft
{
    public const OWNER_NOT_FOUND = 'FAQ owner not found.';

    public function __construct(private SaveFaqItem $save) {}

    /**
     * @param  array{owner?: string|null, question: array<string, string|null>, answer: array<string, string|null>}  $data
     *
     * @throws ValidationException
     */
    public function handle(User $user, array $data): FaqItem
    {
        $spec = FaqOwner::spec($data['owner'] ?? null);
        $owner = FaqOwner::model($spec);

        if (($spec !== FaqOwner::GENERAL && ! $owner instanceof Model) || ! Gate::forUser($user)->allows('create', [FaqItem::class, $owner])) {
            throw ValidationException::withMessages(['owner' => self::OWNER_NOT_FOUND]);
        }

        return $this->save->handle($owner, null, [
            'question' => $data['question'],
            'answer' => $data['answer'],
            'is_active' => false,
        ]);
    }
}
