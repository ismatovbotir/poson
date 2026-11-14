<?php

namespace App\Support\Careers;

use App\Models\JobApplication;
use App\Support\Phone;
use App\Support\PlainText;
use App\Support\Telegram\TelegramLocale;
use App\Support\Telegram\TelegramMessage;
use Illuminate\Support\Facades\Route;

/**
 * The management-chat message of a job application (plan 31 §9, CR-10):
 * "#J-{id}", the vacancy title in the Telegram language (or "Open
 * application — {department}"), the name (PlainText::clean, cut to
 * NAME_MAX before e()), the phone (owner decision §17.3) and the
 * "Open in admin" button. Never the email, the message or the file name.
 * Rendered at send time from the stored row; the caption stays within
 * CAPTION_MAX characters after entity parsing.
 */
final class JobApplicationMessage
{
    public const NAME_MAX = 60;

    public const TITLE_MAX = 200;

    public const CAPTION_MAX = 1024;

    public const ADMIN_ROUTE = 'admin.careers.applications.show';

    public static function render(JobApplication $application, bool $cvNotDelivered = false): TelegramMessage
    {
        $locale = TelegramLocale::current();

        if ($application->isOpenApplication()) {
            $target = __('careers.telegram.open_application', ['department' => $application->department->label()], $locale);
        } else {
            $title = (string) ($application->vacancy?->getTranslation('title', $locale, false) ?: $application->vacancy?->getTranslation('title', 'uz', false));
            $target = __('careers.telegram.vacancy', [], $locale).': '.mb_substr(PlainText::clean($title), 0, self::TITLE_MAX);
        }

        $lines = [
            '<b>'.e(__('careers.telegram.new_application', [], $locale)).' #J-'.$application->id.'</b>',
            e($target),
            e(__('careers.telegram.name', [], $locale)).': '.e(mb_substr(PlainText::clean($application->name), 0, self::NAME_MAX)),
            e(__('careers.telegram.phone', [], $locale)).': '.e(Phone::display($application->phone)),
        ];

        if ($cvNotDelivered) {
            $lines[] = e(__('careers.telegram.cv_not_delivered', [], $locale));
        }

        $buttons = Route::has(self::ADMIN_ROUTE)
            ? TelegramMessage::adminButton(route(self::ADMIN_ROUTE, $application->id, absolute: false), __('careers.telegram.open_in_admin', [], $locale))
            : [];

        return new TelegramMessage(implode("\n", $lines), $buttons);
    }

    /**
     * Length of the text Telegram counts (entities parsed, tags removed).
     */
    public static function visibleLength(string $html): int
    {
        return mb_strlen(html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }
}
