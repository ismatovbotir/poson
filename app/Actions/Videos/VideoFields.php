<?php

namespace App\Actions\Videos;

use App\Models\Video;
use App\Rules\BrandSafeText;
use App\Support\Content\VideoText;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Re-checks the optional fields of a video whatever the caller (admin form or
 * MCP): the manual title per locale and the published date. Error messages
 * never repeat the input.
 */
class VideoFields
{
    public const EARLIEST_DATE = '2005-04-23';

    /**
     * @param  mixed  $titles  locale => text
     * @return array<string, string> cleaned, only the filled locales
     *
     * @throws ValidationException
     */
    public function titles(mixed $titles): array
    {
        if ($titles === null || $titles === '') {
            return [];
        }

        $errors = [];
        $clean = [];

        if (! is_array($titles)) {
            throw ValidationException::withMessages(['title.uz' => __('validation.array', ['attribute' => __('admin.videos.manual_title')])]);
        }

        foreach ($titles as $locale => $text) {
            if (! in_array($locale, config('app.supported_locales'), true) || ($text !== null && ! is_string($text))) {
                $errors["title.{$locale}"] = __('validation.array', ['attribute' => __('admin.videos.manual_title')]);

                continue;
            }

            $attribute = __('admin.videos.manual_title').' ('.strtoupper($locale).')';
            $value = VideoText::clean($text, PHP_INT_MAX);

            if ($value === null) {
                continue;
            }

            if (mb_strlen($value) > Video::TITLE_MAX) {
                $errors["title.{$locale}"] = __('validation.max.string', ['attribute' => $attribute, 'max' => Video::TITLE_MAX]);
            } elseif (BrandSafeText::violates($value)) {
                $errors["title.{$locale}"] = __('validation.brand_safe', ['attribute' => $attribute]);
            } else {
                $clean[$locale] = $value;
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return $clean;
    }

    /**
     * @throws ValidationException
     */
    public function publishedOn(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        $date = null;

        if ($value instanceof Carbon) {
            $date = $value->copy()->startOfDay();
        } elseif (is_string($value) && preg_match('/\A\d{4}-\d{2}-\d{2}\z/', $value) === 1) {
            try {
                $parsed = Carbon::createFromFormat('!Y-m-d', $value);
                $date = $parsed !== null && $parsed->format('Y-m-d') === $value ? $parsed : null;
            } catch (Throwable) {
                $date = null;
            }
        }

        if ($date === null || $date->lt(Carbon::parse(self::EARLIEST_DATE)) || $date->gt(today())) {
            throw ValidationException::withMessages([
                'published_on' => __('validation.date', ['attribute' => __('admin.videos.published_on')]),
            ]);
        }

        return $date->format('Y-m-d');
    }
}
