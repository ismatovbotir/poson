<?php

namespace App\Http\Controllers\Site;

use App\Actions\Careers\SubmitJobApplication;
use App\Data\Careers\JobApplicationData;
use App\Http\Controllers\Controller;
use App\Http\Requests\Site\SubmitJobApplicationRequest;
use App\Support\Careers\CareersCv;
use App\Support\Careers\CvFile;
use App\Support\ForeignPhoneLimit;
use App\Support\Phone;
use App\Support\PlainText;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Throwable;

/**
 * The careers application POST (plan 31 §7, CR-1). After the request's
 * kill switch, bot check and field rules: the vacancy is published (or the
 * department exists and open applications are on), else 404 → per-IP limit
 * (careers-ip:) → per-phone limit (careers-phone:) → ForeignPhoneLimit →
 * CvFile::inspect (only while CVs are accepted; otherwise a sent file is
 * ignored) → SubmitJobApplication. A bot gets the same success redirect and
 * nothing is stored, counted or inspected. Logs: application id or error
 * code only (CR-16).
 */
class SubmitJobApplicationController extends Controller
{
    public const SENT_FLASH = 'careers_sent';

    public function __invoke(SubmitJobApplicationRequest $request, SubmitJobApplication $submit): RedirectResponse
    {
        if ($request->isBot()) {
            return $this->sent($request);
        }

        $vacancy = $request->isOpenApplication() ? null : $request->vacancy();
        $department = $request->isOpenApplication() ? $request->department() : null;

        abort_if($request->isOpenApplication() ? $department === null : ($vacancy === null || ! $vacancy->isPublic()), 404);

        $phone = (string) Phone::normalizeAny((string) $request->validated('phone'));

        if ($this->limitExceeded('careers-ip:'.sha1((string) $request->ip()), 'ip_limit')
            || $this->limitExceeded('careers-phone:'.hash_hmac('sha256', $phone, (string) config('app.key')), 'phone_limit')) {
            return $request->refuse(__('careers.site.form.rate_limited'));
        }

        if (ForeignPhoneLimit::exceeded($phone)) {
            return $request->refuse(__('validation.foreign_phone_limit'));
        }

        $cv = null;
        $file = $request->file('cv');

        if ($file instanceof UploadedFile && CareersCv::isAvailable()) {
            $cv = CvFile::inspect($file);

            if ($cv === null) {
                return $request->refuse(__('careers.site.form.cv_invalid'));
            }
        }

        $email = PlainText::clean((string) $request->validated('email'));
        $message = PlainText::clean((string) $request->validated('message'), multiline: true);

        try {
            $application = $submit->handle(new JobApplicationData(
                name: mb_substr(PlainText::clean((string) $request->validated('name')), 0, 120),
                phone: $phone,
                email: $email !== '' ? mb_strtolower($email) : null,
                message: $message !== '' ? mb_substr($message, 0, 2000) : null,
                locale: app()->getLocale(),
                vacancyId: $vacancy?->id,
                department: $department,
            ), $cv);
        } catch (Throwable $exception) {
            Log::error('Careers application failed', ['code' => class_basename($exception)]);

            return $request->refuse(__('careers.site.form.failed'));
        }

        Log::info('Careers application received', ['job_application' => $application->id]);

        return $this->sent($request);
    }

    private function sent(SubmitJobApplicationRequest $request): RedirectResponse
    {
        return redirect()->to($request->pageUrl().'#careers-apply')->with(self::SENT_FLASH, true);
    }

    /**
     * Count first, then compare (concurrent requests can't slip past the limit).
     */
    private function limitExceeded(string $key, string $config): bool
    {
        return RateLimiter::hit($key, (int) config("careers.{$config}.seconds")) > (int) config("careers.{$config}.attempts");
    }
}
