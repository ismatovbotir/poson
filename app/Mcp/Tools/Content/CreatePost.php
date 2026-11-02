<?php

namespace App\Mcp\Tools\Content;

use App\Actions\Mcp\CreateMcpPost;
use App\Enums\NewsKind;
use App\Mcp\Support\Presenters\ContentPresenter;
use App\Mcp\Tools\PosonTool;
use App\Models\NewsPost;
use App\Models\User;
use App\Support\Content\NewsPostRules;
use App\Support\Content\PostAudit;
use App\Support\Site\CanonicalHost;
use Closure;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Validation\Rule;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Throwable;

/**
 * create_post (plan 13 M3b, M3-S1, S5, S6, S8, S15): creates a NEW news post
 * or article as a draft. Published only on publish + confirm_publish with no
 * external link; a draft otherwise, with a warning. Never edits a post.
 */
#[Name('create_post')]
#[Description('Creates a NEW news post or knowledge article (kind news or article) as a draft; you cannot edit existing items — suggest changes to the user instead. Give title.uz (required) and optional excerpt, body (HTML: p, h2, h3, ul, ol, li, strong, em, br, a, tables), meta_title, meta_description per locale (uz, ru, en), product_ids and category_ids (up to 20 each). It is saved as a draft unless publish is true AND confirm_publish is true (ask the user first) AND the body has no link leaving the site (external, mailto); then it is saved as a draft with a warning. published_at (ISO 8601, Asia/Tashkent) schedules a publication from now up to 90 days ahead and needs publish. The slug is generated. Never write discount or low-price wording. Returns id, status, published_at, scheduled, public_url (when live), admin_url, issues (SEO issue codes) and warnings.')]
class CreatePost extends PosonTool
{
    protected function permission(): ?string
    {
        return 'news.create';
    }

    public function group(): string
    {
        return 'content';
    }

    protected function isWrite(): bool
    {
        return true;
    }

    protected function isPublish(Request $request): bool
    {
        return $request->get('publish') === true && $request->get('confirm_publish') === true;
    }

    protected function rules(): array
    {
        $locales = ['nullable', 'array:'.implode(',', config('app.supported_locales'))];

        return [
            ...NewsPostRules::rules(),
            'kind' => ['nullable', 'string', Rule::in(array_column(NewsKind::cases(), 'value'))],
            'title' => ['required', 'array:'.implode(',', config('app.supported_locales'))],
            'excerpt' => $locales,
            'body' => $locales,
            'meta_title' => $locales,
            'meta_description' => $locales,
            'publish' => ['nullable', $this->jsonBoolean(...)],
            'confirm_publish' => ['nullable', $this->jsonBoolean(...)],
            'published_at' => ['nullable', 'string', 'max:40'],
        ];
    }

    /**
     * Only real JSON booleans: 1, "1" or "true" never count as a publish wish (M3-L1).
     */
    private function jsonBoolean(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_bool($value)) {
            $fail('The '.str_replace('_', ' ', $attribute).' field must be true or false.');
        }
    }

    protected function run(Request $request, User $user): Response|ResponseFactory
    {
        $result = app(CreateMcpPost::class)->handle($user, $request->all());
        /** @var NewsPost $post */
        $post = $result['post'];

        // Set right after the committed save: whatever fails below, the call is logged as a publish.
        $this->subject = $post;
        $this->published = $result['published'];

        return $this->respond($post, $result['scheduled'], $result['warnings']);
    }

    /**
     * The result. Built after the save, so it must not fail the call: a
     * failing SEO audit only leaves `issues` empty.
     *
     * @param  list<array<string, mixed>>  $warnings
     */
    protected function respond(NewsPost $post, bool $scheduled, array $warnings): Response|ResponseFactory
    {
        return Response::structured([
            'id' => $post->id,
            'kind' => $post->kind->value,
            'status' => $post->status->value,
            'state' => $post->state()->value,
            'published_at' => ContentPresenter::date($post->published_at),
            'scheduled' => $scheduled,
            'public_url' => $post->isVisible() ? CanonicalHost::using(fn (): string => $post->url('uz')) : null,
            'admin_url' => CanonicalHost::using(fn (): string => route($post->kind->adminRoute('edit'), $post)),
            'issues' => $this->auditIssues($post),
            'warnings' => $warnings,
        ]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    protected function auditIssues(NewsPost $post): array
    {
        try {
            return PostAudit::issues($post);
        } catch (Throwable $exception) {
            report($exception);

            return [];
        }
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
            'kind' => $schema->string()->enum(array_column(NewsKind::cases(), 'value'))->description('news (default) or article.'),
            'title' => $locales('Title per locale; uz is required.')->required(),
            'excerpt' => $locales('Short summary per locale (max 500 characters).'),
            'body' => $locales('Body HTML per locale.'),
            'meta_title' => $locales('SEO title per locale (max 255).'),
            'meta_description' => $locales('SEO description per locale (max 500).'),
            'product_ids' => $schema->array()->items($schema->integer())->description('Related product ids (max 20).'),
            'category_ids' => $schema->array()->items($schema->integer())->description('Related category ids (max 20).'),
            'publish' => $schema->boolean()->description('Wish to publish. Needs confirm_publish too, else it stays a draft.'),
            'confirm_publish' => $schema->boolean()->description('Set true only after the user explicitly agreed to publish.'),
            'published_at' => $schema->string()->description('Schedule: ISO 8601 date-time from now up to 90 days ahead (needs publish).'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function outputSchema(JsonSchema $schema): array
    {
        return [
            'id' => $schema->integer()->required(),
            'kind' => $schema->string()->required(),
            'status' => $schema->string()->required(),
            'state' => $schema->string()->required(),
            'published_at' => $schema->string()->nullable(),
            'scheduled' => $schema->boolean()->required(),
            'public_url' => $schema->string()->nullable(),
            'admin_url' => $schema->string()->required(),
            'issues' => $schema->array()->items($schema->object([
                'code' => $schema->string()->required(),
                'locale' => $schema->string()->nullable(),
                'severity' => $schema->string()->required(),
            ]))->required(),
            'warnings' => $schema->array()->items($schema->object([
                'code' => $schema->string()->required(),
                'hosts' => $schema->array()->items($schema->string()),
            ]))->required(),
        ];
    }
}
