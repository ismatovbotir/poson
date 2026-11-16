<?php

namespace Database\Factories;

use App\Actions\Catalog\Ai\AiDraftSnapshot;
use App\Actions\Catalog\Ai\SaveAiDraftInfo;
use App\Enums\AiDraftStatus;
use App\Models\AiProductDraft;
use App\Models\Product;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<AiProductDraft>
 */
class AiProductDraftFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'uuid' => (string) Str::uuid(),
            'user_id' => User::factory(),
            'mode' => AiProductDraft::CREATE,
            'status' => AiDraftStatus::Open,
            'input' => ['info' => [], 'notes' => '', 'images' => [], 'attribute_ids' => []],
            'expires_at' => now()->addHours(24),
        ];
    }

    public function status(AiDraftStatus $status): static
    {
        return $this->state(fn (): array => ['status' => $status]);
    }

    public function expired(): static
    {
        return $this->state(fn (): array => ['expires_at' => now()->subHour()]);
    }

    public function forUser(User $user): static
    {
        return $this->state(fn (): array => ['user_id' => $user->id]);
    }

    /**
     * Step 1 values of the draft (merged over the current ones; info_base follows in create mode).
     *
     * @param  array<string, mixed>  $info
     */
    public function withInfo(array $info): static
    {
        return $this->state(function (array $attributes) use ($info): array {
            $input = $attributes['input'];
            $input['info'] = [...($input['info'] ?? []), ...$info];
            $input['info_base'] ??= $input['info'];

            return ['input' => $input];
        });
    }

    /**
     * An improve draft of the product, as the wizard opens it (info, snapshot, existing images).
     */
    public function improving(Product $product): static
    {
        return $this->state(function (array $attributes) use ($product): array {
            $info = SaveAiDraftInfo::baseInfo($product);
            $images = $product->getMedia(Product::IMAGES)->map(fn ($media): array => [
                'source' => 'media', 'ref' => (int) $media->id, 'clean' => false, 'action' => 'keep', 'replaces' => null,
            ])->values()->all();

            return [
                'mode' => AiProductDraft::IMPROVE,
                'product_id' => $product->id,
                'input' => [...$attributes['input'], 'info' => $info, 'info_base' => $info, 'images' => $images, 'next_upload' => 1],
                'snapshot' => AiDraftSnapshot::of($product),
            ];
        });
    }

    /**
     * Alias of improving() (the improve mode of the product).
     */
    public function improve(Product $product): static
    {
        return $this->improving($product);
    }

    /**
     * $count new uploads in the draft's image list (refs 1..n; the files are not written).
     */
    public function withImages(int $count = 1): static
    {
        return $this->state(function (array $attributes) use ($count): array {
            $input = $attributes['input'];
            $next = (int) ($input['next_upload'] ?? 1);

            for ($number = $next; $number < $next + $count; $number++) {
                $input['images'][] = ['source' => 'upload', 'ref' => $number, 'clean' => true, 'action' => 'add', 'replaces' => null];
            }

            $input['next_upload'] = $next + $count;

            return ['input' => $input];
        });
    }

    /**
     * Stage B finished without proposals yet (status ready, facts confirmed).
     */
    public function ready(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => AiDraftStatus::Ready,
            'facts' => $attributes['facts'] ?? ['device_type' => '', 'brand' => '', 'models' => [], 'part_numbers' => [], 'features' => [], 'accessories' => [], 'views' => [], 'main_image' => 1, 'unsure' => []],
            'result' => [...($attributes['result'] ?? []), 'facts_confirmed' => true, 'texts' => [], 'specs' => [], 'alts' => [], 'messages' => []],
        ]);
    }

    /**
     * Confirmed (or just proposed) facts.
     *
     * @param  array<string, mixed>  $facts
     */
    public function withFacts(array $facts, bool $confirmed = true): static
    {
        return $this->state(fn (array $attributes): array => [
            'facts' => $facts,
            'result' => [...($attributes['result'] ?? []), 'facts_warnings' => [], 'facts_confirmed' => $confirmed],
        ]);
    }

    /**
     * Stage B finished: validated proposals (texts field => locale => text, specs, alts by image index).
     *
     * @param  array<string, array<string, string>>  $texts
     * @param  list<array<string, mixed>>  $specs
     * @param  array<int, array<string, string>>  $alts
     */
    public function withProposals(array $texts, array $specs = [], array $alts = []): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => AiDraftStatus::Ready,
            'result' => [...($attributes['result'] ?? []), 'facts_confirmed' => true, 'texts' => $texts, 'specs' => $specs, 'alts' => $alts, 'messages' => []],
        ]);
    }
}
