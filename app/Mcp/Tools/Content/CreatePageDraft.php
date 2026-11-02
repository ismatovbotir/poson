<?php

namespace App\Mcp\Tools\Content;

use App\Actions\Mcp\CreateMcpPageDraft;
use App\Mcp\Tools\PosonTool;
use App\Models\Page;
use App\Models\User;
use App\Support\Site\CanonicalHost;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

/**
 * create_page_draft (plan 13 M3b, M3-S1, S3, S5): a NEW inactive static page
 * that needs review. No id, slug or template input.
 */
#[Name('create_page_draft')]
#[Description('Creates a NEW static page as an inactive draft that needs review; you cannot edit existing items — suggest changes to the user instead. title.uz is required; body (HTML), meta_title and meta_description are optional per locale (uz, ru, en). The slug is generated and the page stays hidden until staff activate it in the admin panel. Never write discount or low-price wording. Returns id, slug, is_active=false, needs_review and admin_url.')]
class CreatePageDraft extends PosonTool
{
    protected function permission(): ?string
    {
        return 'pages.create';
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
        $locales = ['nullable', 'array:'.implode(',', config('app.supported_locales'))];

        return [
            'title' => ['required', 'array:'.implode(',', config('app.supported_locales'))],
            'title.uz' => ['required', 'string', 'max:255'],
            'title.ru' => ['nullable', 'string', 'max:255'],
            'title.en' => ['nullable', 'string', 'max:255'],
            'body' => $locales,
            'body.*' => ['nullable', 'string', 'max:'.Page::BODY_MAX],
            'meta_title' => $locales,
            'meta_title.*' => ['nullable', 'string', 'max:255'],
            'meta_description' => $locales,
            'meta_description.*' => ['nullable', 'string', 'max:500'],
        ];
    }

    protected function run(Request $request, User $user): Response|ResponseFactory
    {
        $page = app(CreateMcpPageDraft::class)->handle($request->all());

        $this->subject = $page;

        return Response::structured([
            'id' => $page->id,
            'slug' => $page->slug,
            'is_active' => (bool) $page->is_active,
            'needs_review' => (bool) $page->needs_review,
            'admin_url' => CanonicalHost::using(fn (): string => route('admin.pages.edit', $page)),
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
            'title' => $locales('Title per locale; uz is required (max 255).')->required(),
            'body' => $locales('Body HTML per locale.'),
            'meta_title' => $locales('SEO title per locale (max 255).'),
            'meta_description' => $locales('SEO description per locale (max 500).'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function outputSchema(JsonSchema $schema): array
    {
        return [
            'id' => $schema->integer()->required(),
            'slug' => $schema->string()->required(),
            'is_active' => $schema->boolean()->required(),
            'needs_review' => $schema->boolean()->required(),
            'admin_url' => $schema->string()->required(),
        ];
    }
}
