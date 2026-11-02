<?php

namespace App\Mcp\Tools\Content;

use App\Actions\Mcp\CreateMcpFaqDraft;
use App\Mcp\Support\FaqOwner;
use App\Mcp\Tools\PosonTool;
use App\Models\FaqItem;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Gate;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

/**
 * create_faq_draft (plan 13 M3b, M3-S1, S3): a NEW inactive FAQ item for an
 * owner. No id input: existing items are never changed.
 */
#[Name('create_faq_draft')]
#[Description('Creates a NEW FAQ item as an inactive draft; you cannot edit existing items — suggest changes to the user instead. owner is "general" (default, Admin only), "category:ID", "product:ID" or "page:ID". question.uz and answer.uz are required (ru and en optional). Staff review and activate it in the admin panel. Never write discount or low-price wording. Returns id, owner and is_active=false.')]
class CreateFaqDraft extends PosonTool
{
    protected function permission(): ?string
    {
        return null;
    }

    /**
     * Anyone who may add a FAQ item to some owner (general = Admin only).
     */
    public function permits(User $user): bool
    {
        return Gate::forUser($user)->allows('create', [FaqItem::class, null])
            || $user->can('pages.update') || $user->can('categories.update') || $user->can('products.update');
    }

    public function group(): string
    {
        return 'content';
    }

    protected function isWrite(): bool
    {
        return true;
    }

    protected function rules(): array
    {
        $locales = 'array:'.implode(',', config('app.supported_locales'));

        return [
            'owner' => ['nullable', 'string', 'max:30', 'regex:'.FaqOwner::PATTERN],
            'question' => ['required', $locales],
            'question.uz' => ['required', 'string', 'max:'.FaqItem::QUESTION_MAX],
            'question.ru' => ['nullable', 'string', 'max:'.FaqItem::QUESTION_MAX],
            'question.en' => ['nullable', 'string', 'max:'.FaqItem::QUESTION_MAX],
            'answer' => ['required', $locales],
            'answer.uz' => ['required', 'string', 'max:'.FaqItem::ANSWER_MAX],
            'answer.ru' => ['nullable', 'string', 'max:'.FaqItem::ANSWER_MAX],
            'answer.en' => ['nullable', 'string', 'max:'.FaqItem::ANSWER_MAX],
        ];
    }

    protected function run(Request $request, User $user): Response|ResponseFactory
    {
        $item = app(CreateMcpFaqDraft::class)->handle($user, $request->all());

        $this->subject = $item;

        return Response::structured([
            'id' => $item->id,
            'owner' => FaqOwner::spec($request->get('owner')),
            'is_active' => (bool) $item->is_active,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        $locales = fn (string $description) => $schema->object([
            'uz' => $schema->string(),
            'ru' => $schema->string(),
            'en' => $schema->string(),
        ])->description($description);

        return [
            'owner' => $schema->string()->description('general (default), category:ID, product:ID or page:ID.'),
            'question' => $locales('Question per locale; uz is required (max 255).')->required(),
            'answer' => $locales('Answer per locale; uz is required (max 2000).')->required(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function outputSchema(JsonSchema $schema): array
    {
        return [
            'id' => $schema->integer()->required(),
            'owner' => $schema->string()->required(),
            'is_active' => $schema->boolean()->required(),
        ];
    }
}
