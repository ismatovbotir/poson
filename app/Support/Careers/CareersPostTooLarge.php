<?php

namespace App\Support\Careers;

use App\Enums\VacancyDepartment;
use App\Models\Vacancy;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * A careers application body above post_max_size (plan 31, CR-1): PHP
 * dropped the whole body and ValidatePostSize throws before routing and
 * before the session starts, so the form page is answered with a redirect
 * carrying ?upload=too_large (a fixed flag, never echoed). The target is
 * rebuilt with route() from a supported locale and a published vacancy or a
 * known department only (CR-12); anything else is not handled here (null =
 * Laravel's normal 413).
 */
final class CareersPostTooLarge
{
    public const QUERY = 'upload';

    public const VALUE = 'too_large';

    public static function redirect(Request $request): ?RedirectResponse
    {
        if (! $request->isMethod('POST')) {
            return null;
        }

        $segments = explode('/', trim($request->path(), '/'));
        $locales = config('app.supported_locales');

        if (count($segments) !== 4 || $segments[1] !== 'careers' || ! in_array($segments[0], $locales, true)) {
            return null;
        }

        [$locale, , $third, $fourth] = $segments;
        $query = [self::QUERY => self::VALUE];

        if ($third === 'apply' && ($department = VacancyDepartment::tryFrom($fourth)) !== null) {
            return redirect()->to(route('site.careers.open', ['locale' => $locale, 'department' => $department->value, ...$query]).'#careers-apply');
        }

        if ($fourth === 'apply' && preg_match('/^[a-z0-9-]{1,120}$/', $third) === 1) {
            $vacancy = Vacancy::query()->where(function ($builder) use ($third, $locales): void {
                foreach ($locales as $code) {
                    $builder->orWhere("slug_{$code}", $third);
                }
            })->first();

            if ($vacancy !== null && $vacancy->isPublic()) {
                return redirect()->to($vacancy->url($locale).'?'.http_build_query($query).'#careers-apply');
            }
        }

        return null;
    }
}
