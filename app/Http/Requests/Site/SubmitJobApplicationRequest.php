<?php

namespace App\Http\Requests\Site;

use App\Enums\VacancyDepartment;
use App\Models\Vacancy;
use App\Rules\PhoneNumber;
use App\Support\Careers\CareersSwitch;
use App\Support\Careers\FormToken;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\RedirectResponse;

/**
 * The careers application form (plan 31 §7, CR-1 / CR-2 / CR-12 / CR-16),
 * for both POST /{locale}/careers/{slug}/apply (a vacancy, by any of its
 * slugs) and POST /{locale}/careers/apply/{department} (an open application).
 *
 * validateResolved() runs, in this order (after the route throttle and CSRF):
 *  1. kill switch (careers.applications_enabled / open_applications_enabled):
 *     refused with a form error;
 *  2. bot check: honeypot "fax_ext" and the FormToken bound to this target;
 *     a bot is flagged (isBot()) and gets the normal success without any
 *     further check, counter or file inspection;
 *  3. field rules (cv: file, ≤ cv_max_kb, pdf by content and by extension).
 * Errors go to the "careers" error bag and redirect to the page URL built by
 * route() from the model or the enum (never back(), never input). Old input
 * never contains the file, the token or the honeypot.
 */
class SubmitJobApplicationRequest extends FormRequest
{
    public const ERROR_BAG = 'careers';

    /**
     * Fields never flashed back to the session.
     *
     * @var list<string>
     */
    public const DONT_FLASH = ['cv', 'form_token', 'fax_ext', '_token'];

    private bool $bot = false;

    private ?Vacancy $resolvedVacancy = null;

    private bool $vacancyResolved = false;

    public function authorize(): bool
    {
        return true;
    }

    public function validateResolved(): void
    {
        $enabled = $this->isOpenApplication() ? CareersSwitch::openApplicationsEnabled() : CareersSwitch::applicationsEnabled();

        if (! $enabled) {
            throw new HttpResponseException($this->refuse(__('careers.site.form.closed')));
        }

        $honeypot = $this->input('fax_ext');

        if ((is_string($honeypot) && $honeypot !== '') || (! is_string($honeypot) && $honeypot !== null)
            || ! FormToken::consume($this->input('form_token'), $this->tokenTarget())) {
            $this->bot = true;

            return;
        }

        parent::validateResolved();
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            'phone' => ['required', 'string', 'max:40', new PhoneNumber(public: true)],
            'email' => ['nullable', 'string', 'max:190', 'email:rfc'],
            'message' => ['nullable', 'string', 'max:2000'],
            'cv' => ['nullable', 'file', 'max:'.(int) config('careers.cv_max_kb', 5120), 'mimes:pdf', 'extensions:pdf'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'cv.uploaded' => __('careers.site.form.cv_too_large'),
            'cv.max' => __('careers.site.form.cv_too_large'),
            'cv.file' => __('careers.site.form.cv_invalid'),
            'cv.mimes' => __('careers.site.form.cv_invalid'),
            'cv.extensions' => __('careers.site.form.cv_invalid'),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'name' => __('careers.site.form.name'),
            'phone' => __('careers.site.form.phone'),
            'email' => __('careers.site.form.email'),
            'message' => __('careers.site.form.message'),
            'cv' => __('careers.site.form.cv'),
        ];
    }

    public function isBot(): bool
    {
        return $this->bot;
    }

    public function isOpenApplication(): bool
    {
        return $this->route('department') !== null;
    }

    /**
     * The department of an open application (null for an unknown value).
     */
    public function department(): ?VacancyDepartment
    {
        return VacancyDepartment::tryFrom((string) $this->route('department'));
    }

    /**
     * The vacancy named by any of its slugs, published or not (null when unknown).
     */
    public function vacancy(): ?Vacancy
    {
        if (! $this->vacancyResolved) {
            $slug = (string) $this->route('slug');
            $locale = app()->getLocale();

            $this->resolvedVacancy = preg_match('/^[a-z0-9-]{1,120}$/', $slug) !== 1 ? null : (
                Vacancy::query()->where("slug_{$locale}", $slug)->first()
                ?? Vacancy::query()->where(function ($query) use ($slug): void {
                    foreach (config('app.supported_locales') as $other) {
                        $query->orWhere("slug_{$other}", $slug);
                    }
                })->first()
            );
            $this->vacancyResolved = true;
        }

        return $this->resolvedVacancy;
    }

    /**
     * What the form token must be bound to.
     *
     * @return array{vacancy_id: int}|array{department: string}
     */
    public function tokenTarget(): array
    {
        return $this->isOpenApplication()
            ? ['department' => (string) $this->department()?->value]
            : ['vacancy_id' => (int) $this->vacancy()?->id];
    }

    /**
     * The form's page (CR-12: from route() and the model / enum only).
     */
    public function pageUrl(): string
    {
        $locale = app()->getLocale();

        if ($this->isOpenApplication()) {
            $department = $this->department();

            return $department !== null
                ? route('site.careers.open', ['locale' => $locale, 'department' => $department->value])
                : route('site.careers.index', ['locale' => $locale]);
        }

        $vacancy = $this->vacancy();

        return $vacancy !== null && $vacancy->isPublic()
            ? $vacancy->url($locale)
            : route('site.careers.index', ['locale' => $locale]);
    }

    /**
     * Redirect to the page with one form error and the old input (never the file).
     */
    public function refuse(string $message): RedirectResponse
    {
        return redirect()->to($this->pageUrl().'#careers-apply')
            ->withErrors(['form' => $message], self::ERROR_BAG)
            ->withInput($this->except(self::DONT_FLASH));
    }

    protected function failedValidation(Validator $validator): void
    {
        throw new HttpResponseException(
            redirect()->to($this->pageUrl().'#careers-apply')
                ->withErrors($validator->errors(), self::ERROR_BAG)
                ->withInput($this->except(self::DONT_FLASH))
        );
    }
}
