<?php

namespace App\Livewire\Forms\Admin\Videos;

use App\Models\Video;
use App\Rules\BrandSafeText;
use Livewire\Form;

/**
 * State and validation of the add / edit video editor: the pasted link (add
 * only), an optional manual title per locale and an optional published date.
 *
 * Properties are untyped (mixed) on purpose: a tampered request (an array
 * for the link, a string for the titles) must end in a validation error,
 * never a TypeError. The link is never echoed back in a message.
 */
class VideoForm extends Form
{
    public mixed $url = '';

    /** @var mixed array<string, string> locale => text */
    public mixed $title = ['uz' => '', 'ru' => '', 'en' => ''];

    public mixed $published_on = '';

    public function setVideo(?Video $video): void
    {
        $this->resetValidation();

        if ($video === null) {
            $this->reset('url', 'title', 'published_on');

            return;
        }

        $this->url = '';
        $this->title = collect(config('app.supported_locales'))
            ->mapWithKeys(fn (string $locale): array => [$locale => (string) ($video->getTranslations('title')[$locale] ?? '')])
            ->all();
        $this->published_on = $video->published_on?->format('Y-m-d') ?? '';
    }

    /**
     * @return array<string, mixed>
     */
    protected function rules(): array
    {
        $rules = [
            'url' => ['required', 'string', 'max:500'],
            'title' => ['nullable', 'array:'.implode(',', config('app.supported_locales'))],
            'published_on' => ['nullable', 'date_format:Y-m-d', 'after:2005-04-22', 'before_or_equal:today'],
        ];

        foreach (config('app.supported_locales') as $locale) {
            $rules["title.{$locale}"] = ['nullable', 'string', 'max:'.Video::TITLE_MAX, new BrandSafeText];
        }

        return $rules;
    }

    /**
     * @return array<string, string>
     */
    protected function validationAttributes(): array
    {
        $attributes = [
            'url' => __('admin.videos.link'),
            'title' => __('admin.videos.manual_title'),
            'published_on' => __('admin.videos.published_on'),
        ];

        foreach (config('app.supported_locales') as $locale) {
            $attributes["title.{$locale}"] = __('admin.videos.manual_title').' ('.strtoupper($locale).')';
        }

        return $attributes;
    }

    /**
     * Validate for adding (link required) or editing (no link).
     *
     * @return array<string, mixed>
     */
    public function validated(bool $adding): array
    {
        $rules = $this->rules();

        if (! $adding) {
            unset($rules['url']);
        }

        return $this->validate($rules);
    }

    /**
     * @return array{title: mixed, published_on: mixed}
     */
    public function data(): array
    {
        return [
            'title' => $this->title,
            'published_on' => $this->published_on === '' ? null : $this->published_on,
        ];
    }
}
