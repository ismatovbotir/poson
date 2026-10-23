<?php

namespace App\Livewire\Admin\Ai;

use App\Actions\Ai\FailStaleAiRequest;
use App\Actions\Ai\GenerateFieldText;
use App\Actions\Ai\QueueTextRequest;
use App\Actions\Ai\TranslateFieldText;
use App\Enums\AiErrorCode;
use App\Enums\AiFeature;
use App\Enums\AiRequestStatus;
use App\Models\AiRequest;
use App\Models\User;
use App\Services\Ai\AiException;
use App\Services\Ai\AiQuota;
use App\Support\Ai\AiContext;
use App\Support\Ai\FieldRegistry;
use App\Support\Ai\FieldSpec;
use App\Support\Ai\PageAiContext;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Str;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * The AI assistant slide-over (bottom sheet on phones), mounted once in the
 * admin layout for users who may use AI (plan 02 §2).
 *
 * Opened by "ai-open" from a form using WithAiAssist (field, target, active
 * locale, the context built server-side, the current values). Generate
 * (uz/ru/en) or Translate from the active locale → GenerateFieldText /
 * TranslateFieldText queue a request → wire:poll while it is open → three
 * preview cards → Apply sends "ai-apply" back to that form only.
 * The texts applied always come from the stored request, never the browser.
 */
class Assistant extends Component
{
    public const MODE_GENERATE = 'generate';

    public const MODE_TRANSLATE = 'translate';

    public bool $open = false;

    /**
     * Livewire id of the form that opened the panel.
     */
    #[Locked]
    public string $owner = '';

    #[Locked]
    public string $target = '';

    #[Locked]
    public string $fieldKey = '';

    #[Locked]
    public string $locale = 'uz';

    /** @var array<string, mixed> */
    #[Locked]
    public array $context = [];

    /** @var array<string, string> */
    #[Locked]
    public array $current = [];

    public string $mode = self::MODE_GENERATE;

    public string $hint = '';

    #[Locked]
    public ?int $requestId = null;

    /**
     * Refusal before a request was queued (disabled, quota, ...).
     */
    #[Locked]
    public ?string $errorCode = null;

    /**
     * Every request (mount and each update: generate, poll, apply, mode,
     * hint reset) needs ai.use, so a revoked permission takes effect at once.
     */
    public function boot(): void
    {
        $this->authorize('create', AiRequest::class);
    }

    /**
     * @param  array<string, mixed>  $context
     * @param  array<string, mixed>  $current
     */
    #[On('ai-open')]
    public function openFor(string $owner, string $target, string $field, string $locale, array $context = [], array $current = []): void
    {
        $this->authorize('create', AiRequest::class);

        if (! FieldRegistry::has($field)) {
            return;
        }

        // The subject is re-resolved here: only a record of the field's kind that the user may view.
        $context = AiContext::fromArray($context);
        $context = [...$context->toArray(), ...$this->subjectFor($field, $context->subjectType, $context->subjectId)];

        // Never the body of a stored legal page, whatever the event carries.
        if (PageAiContext::refuses($field, AiContext::fromArray($context))) {
            return;
        }

        $locales = config('app.supported_locales');

        $this->resetErrorBag();
        $this->owner = $owner;
        $this->target = $target;
        $this->fieldKey = $field;
        $this->locale = in_array($locale, $locales, true) ? $locale : $locales[0];
        $this->context = $context;
        $this->current = [];

        foreach ($locales as $code) {
            $value = $current[$code] ?? '';
            $this->current[$code] = is_string($value) ? Str::limit($value, QueueTextRequest::SOURCE_MAX, '') : '';
        }

        $this->mode = self::MODE_GENERATE;
        $this->hint = $this->defaultHint();
        $this->requestId = null;
        $this->errorCode = null;
        unset($this->request);
        $this->open = true;

        if (! in_array($locale, $locales, true)) {
            $this->addError('locale', __('ai.errors.unknown_locale'));
        }
    }

    public function updatedMode(): void
    {
        if (! in_array($this->mode, [self::MODE_GENERATE, self::MODE_TRANSLATE], true) || ($this->mode === self::MODE_TRANSLATE && ! $this->canTranslate())) {
            $this->mode = self::MODE_GENERATE;
        }

        $this->hint = $this->defaultHint();
        $this->resetErrorBag();
    }

    public function resetHint(): void
    {
        $this->hint = $this->defaultHint();
    }

    public function generate(GenerateFieldText $generate, TranslateFieldText $translate): void
    {
        $this->authorize('create', AiRequest::class);

        if (! $this->open || ! FieldRegistry::has($this->fieldKey)) {
            return;
        }

        $this->errorCode = null;
        $this->requestId = null;
        $this->resetErrorBag();
        unset($this->request);

        $context = AiContext::fromArray($this->context);

        try {
            $request = $this->mode === self::MODE_TRANSLATE
                ? $translate->handle($this->user(), $this->fieldKey, $this->hint, $context, $this->locale, (string) ($this->current[$this->locale] ?? ''))
                : $generate->handle($this->user(), $this->fieldKey, $this->hint, $context);
        } catch (AiException $exception) {
            $this->errorCode = $exception->errorCode->value;

            return;
        }

        $this->requestId = $request->id;
    }

    /**
     * Retry after a failure: a new request with the same settings.
     */
    public function retry(GenerateFieldText $generate, TranslateFieldText $translate): void
    {
        $this->generate($generate, $translate);
    }

    /**
     * wire:poll while the request is open; an old open request becomes "timeout".
     */
    public function refreshRequest(FailStaleAiRequest $failStale): void
    {
        unset($this->request);

        if ($this->request !== null && $failStale->handle($this->request)) {
            unset($this->request);
        }
    }

    public function apply(string $locale): void
    {
        $texts = $this->resultTexts();

        if (! in_array($locale, config('app.supported_locales'), true) || ! $this->canApply($locale, $texts[$locale] ?? '')) {
            return;
        }

        $this->sendApply([$locale => $texts[$locale]]);
        $this->dispatch('toast', title: __('ai.applied_title'), body: __('ai.applied_one', ['locale' => strtoupper($locale)]), type: 'success');
    }

    public function applyAll(): void
    {
        $texts = array_filter($this->resultTexts(), fn (string $text, string $locale): bool => $this->canApply($locale, $text), ARRAY_FILTER_USE_BOTH);

        if ($texts === []) {
            return;
        }

        $this->sendApply($texts);
        $this->open = false;
        $this->dispatch('toast', title: __('ai.applied_title'), body: __('ai.applied_all'), type: 'success');
    }

    public function close(): void
    {
        $this->open = false;
    }

    /**
     * The user's own current request.
     */
    #[Computed]
    public function request(): ?AiRequest
    {
        if ($this->requestId === null) {
            return null;
        }

        return AiRequest::query()->whereKey($this->requestId)->where('user_id', $this->user()->id)->first();
    }

    #[Computed]
    public function field(): ?FieldSpec
    {
        return FieldRegistry::has($this->fieldKey) ? FieldRegistry::get($this->fieldKey) : null;
    }

    public function canTranslate(): bool
    {
        return trim(strip_tags((string) ($this->current[$this->locale] ?? ''))) !== '';
    }

    /**
     * A plain-text result longer than the field's maximum (Apply disabled).
     */
    public function isTooLong(string $text): bool
    {
        $field = $this->field;

        return $field !== null && ! $field->isHtml() && mb_strlen($text) > $field->maxLength;
    }

    /**
     * The card can be applied: has text, isn't the translation source, fits the field.
     */
    public function canApply(string $locale, string $text): bool
    {
        return $text !== '' && ! $this->isSource($locale) && ! $this->isTooLong($text);
    }

    /**
     * The source card of a translation (shown, not applied).
     */
    public function isSource(string $locale): bool
    {
        return $this->request?->source_locale === $locale && $this->request->feature === AiFeature::TextTranslate;
    }

    public function render(): View
    {
        $request = $this->request;
        $error = $this->errorCode !== null
            ? AiErrorCode::tryFrom($this->errorCode)
            : ($request?->status === AiRequestStatus::Failed || $request?->status === AiRequestStatus::Rejected ? $request->error_code : null);

        return view('livewire.admin.ai.assistant', [
            'request' => $request,
            'error' => $error,
            'pending' => $request !== null && $request->status->isOpen(),
            'texts' => $this->resultTexts(),
            'remaining' => $this->open ? AiQuota::remainingToday($this->user()) : null,
            'limit' => AiQuota::dailyLimit(),
        ]);
    }

    /**
     * @return array<string, string>
     */
    private function resultTexts(): array
    {
        $request = $this->request;

        if ($request === null || $request->status !== AiRequestStatus::Succeeded || ! is_array($request->response)) {
            return [];
        }

        return array_map(fn (mixed $text): string => is_string($text) ? $text : '', $request->response);
    }

    /**
     * @param  array<string, string>  $values
     */
    private function sendApply(array $values): void
    {
        $this->dispatch('ai-apply', owner: $this->owner, target: $this->target, field: $this->fieldKey, values: $values);
    }

    private function defaultHint(): string
    {
        $field = $this->field;

        if ($field === null) {
            return '';
        }

        return $this->mode === self::MODE_TRANSLATE
            ? $field->translateHintFor($this->locale)
            : $field->hintFor(AiContext::fromArray($this->context));
    }

    /**
     * subject_type / subject_id for the log: kept only when the record exists,
     * matches the field's kind and the user may view it.
     *
     * @return array{subject_type: string|null, subject_id: int|null}
     */
    private function subjectFor(string $field, ?string $type, ?int $id): array
    {
        $none = ['subject_type' => null, 'subject_id' => null];
        $allowed = match (Str::before($field, '.')) {
            'product' => ['product'],
            'category' => ['category'],
            'brand' => ['brand'],
            'page' => ['page'],
            'news' => ['news_post'],
            'faq' => ['category', 'product', 'page'],
            default => [],
        };

        if ($type === null || $id === null || ! in_array($type, $allowed, true)) {
            return $none;
        }

        $class = Relation::getMorphedModel($type);
        $model = $class !== null ? $class::query()->find($id) : null;

        return $model !== null && $this->user()->can('view', $model) ? ['subject_type' => $type, 'subject_id' => $id] : $none;
    }

    private function user(): User
    {
        /** @var User */
        return auth()->user();
    }
}
