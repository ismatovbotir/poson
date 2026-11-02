<?php

namespace App\Mcp\Tools\Content;

use App\Actions\Mcp\CreateMcpVideoDraft;
use App\Mcp\Support\VideoOwner;
use App\Mcp\Tools\PosonTool;
use App\Models\User;
use App\Models\Video;
use App\Rules\BrandSafeText;
use App\Support\Site\CanonicalHost;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

/**
 * attach_video (plan 19, YT-S13): attaches a YouTube video to an existing
 * product, category, brand or post as an INACTIVE draft. No id, status, order
 * or id-of-video input: existing videos are never changed, activated or removed.
 */
#[Name('attach_video')]
#[Description('Attaches a YouTube video (link or 11-character video ID) to an existing item as an inactive draft that staff review and activate in the admin panel; you cannot edit existing items, reorder, activate or remove videos — suggest changes to the user instead. owner is "product:ID", "category:ID", "brand:ID" or "post:ID". title (uz, ru, en) and published_on (YYYY-MM-DD) are optional; the title from YouTube is fetched later. Never write discount or low-price wording. Returns id, owner, youtube_id, is_active=false and admin_url.')]
class AttachVideo extends PosonTool
{
    protected function permission(): ?string
    {
        return null;
    }

    /**
     * Anyone who may update some video owner (products incl. services, categories, brands, news).
     */
    public function permits(User $user): bool
    {
        return $user->can('products.update') || $user->can('products.manage-services')
            || $user->can('categories.update') || $user->can('brands.update') || $user->can('news.update');
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
        $rules = [
            'owner' => ['required', 'string', 'max:30', 'regex:'.VideoOwner::PATTERN],
            'url' => ['required', 'string', 'max:500'],
            'title' => ['nullable', $locales],
            'published_on' => ['nullable', 'date_format:Y-m-d', 'after:2005-04-22', 'before_or_equal:today'],
        ];

        foreach (config('app.supported_locales') as $locale) {
            $rules["title.{$locale}"] = ['nullable', 'string', 'max:'.Video::TITLE_MAX, new BrandSafeText];
        }

        return $rules;
    }

    protected function run(Request $request, User $user): Response|ResponseFactory
    {
        $video = app(CreateMcpVideoDraft::class)->handle($user, $request->all());

        $this->subject = $video;

        return Response::structured([
            'id' => $video->id,
            'owner' => (string) $request->get('owner'),
            'youtube_id' => $video->youtube_id,
            'is_active' => (bool) $video->is_active,
            'admin_url' => $this->adminUrl($video),
        ]);
    }

    private function adminUrl(Video $video): string
    {
        $id = (int) $video->videoable_id;

        return CanonicalHost::using(fn (): string => match ($video->videoable_type) {
            'product' => route('admin.products.edit', $id),
            'category' => route('admin.categories.edit', $id),
            'brand' => route('admin.brands.edit', $id),
            default => route('admin.news.edit', $id),
        });
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'owner' => $schema->string()->description('product:ID, category:ID, brand:ID or post:ID.')->required(),
            'url' => $schema->string()->description('YouTube link or 11-character video ID (max 500).')->required(),
            'title' => $schema->object([
                'uz' => $schema->string(),
                'ru' => $schema->string(),
                'en' => $schema->string(),
            ])->description('Optional title per locale (max 200 each).'),
            'published_on' => $schema->string()->description('Optional publication date, YYYY-MM-DD.'),
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
            'youtube_id' => $schema->string()->required(),
            'is_active' => $schema->boolean()->required(),
            'admin_url' => $schema->string()->required(),
        ];
    }
}
