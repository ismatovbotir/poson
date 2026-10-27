<?php

namespace Database\Factories;

use App\Enums\BannerPlacement;
use App\Models\Banner;
use App\Models\Category;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Http\UploadedFile;

/**
 * An active home slider banner without dates, translated in uz, ru and en,
 * with an internal link and no images.
 * active() / inactive() / scheduled() / expired() set the state,
 * category() places it on a category listing, withImages() adds the desktop
 * (and optionally mobile) image: conversions run on a sync queue only, so
 * tests fake the "local" and "public" disks.
 *
 * @extends Factory<Banner>
 */
class BannerFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $title = ucfirst(fake()->words(3, true));

        return [
            'placement' => BannerPlacement::HomeHero,
            'category_id' => null,
            'title' => ['uz' => $title, 'ru' => 'RU '.$title, 'en' => 'EN '.$title],
            'text' => ['uz' => 'Matn '.$title.'.', 'ru' => 'Текст '.$title.'.', 'en' => 'Text '.$title.'.'],
            'button_label' => ['uz' => 'Batafsil', 'ru' => 'Подробнее', 'en' => 'Learn more'],
            'link' => '/uz/catalog',
            'starts_at' => null,
            'ends_at' => null,
            'is_active' => true,
            'sort' => fake()->numberBetween(1, 100),
        ];
    }

    /**
     * Shown now: active, started yesterday, ends in a week.
     */
    public function active(): static
    {
        return $this->state(fn (array $attributes) => ['is_active' => true, 'starts_at' => now()->subDay(), 'ends_at' => now()->addWeek()]);
    }

    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => ['is_active' => false]);
    }

    /**
     * Starts at the given moment (default: in three days).
     */
    public function scheduled(mixed $at = null): static
    {
        return $this->state(fn (array $attributes) => ['is_active' => true, 'starts_at' => $at ?? now()->addDays(3), 'ends_at' => null]);
    }

    /**
     * Ended at the given moment (default: yesterday).
     */
    public function expired(mixed $at = null): static
    {
        return $this->state(fn (array $attributes) => ['is_active' => true, 'starts_at' => null, 'ends_at' => $at ?? now()->subDay()]);
    }

    /**
     * On the listing of the given (or a new) category.
     */
    public function category(?Category $category = null): static
    {
        return $this->state(fn (array $attributes) => [
            'placement' => BannerPlacement::Category,
            'category_id' => $category?->id ?? Category::factory(),
        ]);
    }

    /**
     * With a desktop image (and a mobile one when asked), alt per locale.
     */
    public function withImages(bool $mobile = false): static
    {
        return $this->afterCreating(function (Banner $banner) use ($mobile): void {
            $banner->addMedia(UploadedFile::fake()->image('desktop.jpg', Banner::DESKTOP_WIDTH, Banner::DESKTOP_HEIGHT))
                ->withCustomProperties(['alt' => ['uz' => 'Skaner', 'ru' => 'Сканер', 'en' => 'Scanner']])
                ->toMediaCollection(Banner::DESKTOP);

            if ($mobile) {
                $banner->addMedia(UploadedFile::fake()->image('mobile.jpg', Banner::MOBILE_WIDTH, Banner::MOBILE_HEIGHT))
                    ->withCustomProperties(['alt' => ['uz' => 'Skaner mobil', 'ru' => 'Сканер мобильный', 'en' => 'Scanner mobile']])
                    ->toMediaCollection(Banner::MOBILE);
            }

            $banner->unsetRelation('media');
        });
    }
}
