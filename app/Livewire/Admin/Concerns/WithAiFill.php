<?php

namespace App\Livewire\Admin\Concerns;

use App\Support\Content\AiFill\AiAnswerParser;
use App\Support\Content\AiFill\AiField;
use App\Support\Content\AiFill\AiPromptBuilder;
use App\Support\HtmlSanitizer;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * "AI prompt" and "Fill from AI answer" on an admin content form (plan 29).
 * No AI runs in the app: the prompt is plain text for an external AI, and the
 * pasted JSON answer is parsed, validated and written into the form fields
 * only (nothing is saved; the normal save validates and sanitizes again).
 *
 * The component implements aiFields(), aiContext(), aiSubject() and
 * authorizeAiFill() (the same check its save() runs). Every public method
 * starts with that check; the pasted text only ever travels as a method
 * argument, is never stored in a property, session, log or message.
 */
trait WithAiFill
{
    /**
     * Applies per user and minute (batched calls bypass route throttles).
     */
    public const AI_APPLIES_PER_MINUTE = 20;

    public const AI_PROMPT_TOGGLES_PER_MINUTE = 60;

    #[Locked]
    public bool $aiPromptOpen = false;

    /**
     * Result of the last fill, shown under the box (fixed texts only).
     *
     * @var array{filled?: int, skipped?: int, errors?: list<string>, warnings?: list<string>}
     */
    #[Locked]
    public array $aiReport = [];

    /**
     * Fields the AI may write (a closed list; keys are form properties).
     *
     * @return list<AiField>
     */
    abstract protected function aiFields(): array;

    /**
     * Read-only facts about the record for the prompt (an explicit list:
     * never a whole model, prices, price levels or internal data).
     *
     * @return array<string, mixed>
     */
    abstract protected function aiContext(): array;

    /**
     * English name of the record kind, used in the prompt.
     */
    abstract protected function aiSubject(): string;

    /**
     * Throws unless the user may save this form (the same check as save()).
     */
    abstract protected function authorizeAiFill(): void;

    /**
     * Name of the Livewire Form object property.
     */
    protected function aiFormProperty(): string
    {
        return 'form';
    }

    /**
     * Whether the box is shown (cosmetic; every method re-checks).
     */
    #[Computed]
    public function aiAvailable(): bool
    {
        return $this->aiAllowed();
    }

    /**
     * The prompt, only while the panel is open and the user may save.
     */
    #[Computed]
    public function aiPrompt(): string
    {
        if (! $this->aiPromptOpen || ! $this->aiAllowed()) {
            return '';
        }

        $form = $this->{$this->aiFormProperty()};
        $current = [];

        foreach ($this->aiFields() as $field) {
            $values = $form->{$field->key};
            $current[$field->key] = is_array($values) ? $values : [];
        }

        return app(AiPromptBuilder::class)->build($this->aiSubject(), $this->aiFields(), $this->aiContext(), $current);
    }

    public function toggleAiPrompt(): void
    {
        $this->authorizeAiFill();

        $key = 'ai-fill-prompt:'.auth()->id();

        if (RateLimiter::tooManyAttempts($key, self::AI_PROMPT_TOGGLES_PER_MINUTE)) {
            return;
        }

        RateLimiter::hit($key, 60);

        $this->aiPromptOpen = ! $this->aiPromptOpen;
    }

    /**
     * Parse the pasted answer and write the valid values into the form.
     * Arguments come from the browser: anything but a string is rejected,
     * and only a literal true for $onlyEmpty keeps filled fields.
     */
    public function applyAiAnswer(mixed $json, mixed $onlyEmpty = true): void
    {
        $this->authorizeAiFill();

        $key = 'ai-fill:'.auth()->id();

        if (RateLimiter::tooManyAttempts($key, self::AI_APPLIES_PER_MINUTE)) {
            $this->dispatch('toast', title: __('admin.toast.error'), body: __('admin.ai_fill.throttled', ['seconds' => RateLimiter::availableIn($key)]), type: 'error');

            return;
        }

        RateLimiter::hit($key, 60);

        $fields = $this->aiFields();

        if (! is_string($json)) {
            $this->aiReport = ['filled' => 0, 'skipped' => 0, 'errors' => [__('admin.ai_fill.errors.invalid_json')], 'warnings' => []];

            return;
        }

        $result = app(AiAnswerParser::class)->parse($json, $fields);
        $property = $this->aiFormProperty();
        $labels = collect($fields)->mapWithKeys(fn (AiField $field): array => [$field->key => __($field->labelKey)])->all();
        $errors = [];
        $filled = 0;
        $skipped = 0;

        foreach ($fields as $field) {
            foreach (config('app.supported_locales') as $locale) {
                $this->resetErrorBag("{$property}.{$field->key}.{$locale}");
            }
        }

        foreach ($result->values as $fieldKey => $locales) {
            foreach ($locales as $locale => $text) {
                $path = "{$fieldKey}.{$locale}";

                if ($onlyEmpty === true && ! $this->aiFieldIsEmpty((string) data_get($this->{$property}, $path, ''))) {
                    $skipped++;

                    continue;
                }

                data_set($this->{$property}, $path, $text);
                $filled++;
            }
        }

        foreach ($result->errors as $error) {
            $message = __($error['message'], $error['params']);

            if ($error['key'] === null) {
                $errors[] = $message;

                continue;
            }

            $locale = $error['locale'] ?? config('app.supported_locales')[0];
            $this->addError("{$property}.{$error['key']}.{$locale}", $message);
            $errors[] = ($labels[$error['key']] ?? $error['key']).($error['locale'] !== null ? ' ('.strtoupper($error['locale']).')' : '').': '.$message;
        }

        $warnings = array_map(
            fn (array $warning): string => ($warning['key'] !== null ? ($labels[$warning['key']] ?? $warning['key']).($warning['locale'] !== null ? ' ('.strtoupper($warning['locale']).')' : '').': ' : '').__($warning['message'], $warning['params']),
            $result->warnings,
        );

        $this->aiReport = ['filled' => $filled, 'skipped' => $skipped, 'errors' => $errors, 'warnings' => $warnings];

        $this->dispatch('toast', title: __('admin.ai_fill.toast_title'), body: __('admin.ai_fill.toast_body', ['filled' => $filled, 'errors' => count($errors)]), type: $errors === [] ? 'success' : 'info');
    }

    /**
     * Server-side "empty" test: no visible text once sanitized (so "<p></p>" is empty).
     */
    private function aiFieldIsEmpty(string $current): bool
    {
        return trim(strip_tags(HtmlSanitizer::clean($current))) === '';
    }

    private function aiAllowed(): bool
    {
        try {
            $this->authorizeAiFill();
        } catch (AuthorizationException|HttpException) {
            return false;
        }

        return true;
    }
}
