<?php

namespace App\Actions\Ai;

use App\Enums\AiErrorCode;
use App\Enums\AiRequestStatus;
use App\Models\AiRequest;
use App\Services\Ai\AiAssist;
use App\Services\Ai\AiException;
use App\Services\Ai\AiTextProvider;
use App\Services\Ai\BrandGuard;
use App\Services\Ai\TextAssistants;
use App\Services\Ai\TextResult;
use App\Services\Ai\TextTask;
use App\Support\HtmlSanitizer;
use RuntimeException;
use Throwable;

/**
 * Run a pending text request (called by the RunAiRequest job) on the
 * provider and model stored on its row (plan 10: a provider switch in
 * Settings never affects requests already queued): pending →
 * running → succeeded (texts cleaned, BrandGuard warnings, tokens, cost,
 * duration) or failed with a safe code (and the billed usage, if any).
 *
 * Every transition is a conditional update on the current status, so a
 * second worker or the stale check (FailStaleAiRequest) can't overwrite a
 * newer state: pending → running only from pending, the final write only
 * from running, a failure only while the row is still open.
 * Exceptions are never logged with their message; unexpected ones are
 * reported by class name only.
 */
class ProcessAiRequest
{
    public function handle(int $requestId, TextTask $task): ?AiRequest
    {
        $request = AiRequest::query()->find($requestId);

        if ($request === null || $request->status !== AiRequestStatus::Pending) {
            return $request;
        }

        if (! AiAssist::isEnabled()) {
            $this->fail($request, AiErrorCode::Disabled);

            return $request->refresh();
        }

        // Plan 12: a field removed from the registry while the request was queued.
        if (! $task->isRegistered()) {
            $this->fail($request, AiErrorCode::FieldRetired);

            return $request->refresh();
        }

        $claimed = AiRequest::query()
            ->whereKey($request->id)
            ->where('status', AiRequestStatus::Pending->value)
            ->update(['status' => AiRequestStatus::Running->value, 'started_at' => now(), 'updated_at' => now()]);

        if ($claimed === 0) {
            return $request->refresh();
        }

        $started = hrtime(true);

        try {
            $model = (string) ($request->model ?: AiTextProvider::model($request->provider));
            $assistant = TextAssistants::for($request->provider);
            $result = $task->isTranslation() ? $assistant->translate($task, $model) : $assistant->generate($task, $model);
        } catch (AiException $exception) {
            $this->fail($request, $exception->errorCode, $started, $exception);

            return $request->refresh();
        } catch (Throwable $exception) {
            report(new RuntimeException('AI request #'.$request->id.' failed unexpectedly ('.$exception::class.').'));
            $this->fail($request, AiErrorCode::ProviderError, $started);

            return $request->refresh();
        }

        $texts = $this->clean($task, $result);

        // Only from running: a request failed meanwhile (stale) keeps its state.
        AiRequest::query()
            ->whereKey($request->id)
            ->where('status', AiRequestStatus::Running->value)
            ->first()
            ?->update([
                'status' => AiRequestStatus::Succeeded,
                'model' => $result->model,
                'response' => $texts,
                'brand_warnings' => BrandGuard::check($texts),
                'input_tokens' => $result->inputTokens,
                'output_tokens' => $result->outputTokens,
                'cost_micro_usd' => $result->costMicroUsd(),
                'duration_ms' => $this->elapsed($started),
                'finished_at' => now(),
            ]);

        return $request->refresh();
    }

    /**
     * Mark a request failed while it is still open (pending / running); also
     * used when the job dies or times out. Billed usage on the exception is
     * stored and costed. Returns whether the row changed.
     */
    public function fail(AiRequest $request, AiErrorCode $code, ?int $started = null, ?AiException $exception = null, ?AiRequestStatus $from = null): bool
    {
        $values = [
            'status' => AiRequestStatus::Failed->value,
            'error_code' => $code->value,
            'finished_at' => now(),
            'updated_at' => now(),
        ];

        if ($started !== null) {
            $values['duration_ms'] = $this->elapsed($started);
        }

        if ($exception !== null && ($exception->inputTokens > 0 || $exception->outputTokens > 0 || $exception->billedMicroUsd !== null)) {
            $values['input_tokens'] = $exception->inputTokens;
            $values['output_tokens'] = $exception->outputTokens;
            $values['cost_micro_usd'] = $exception->costMicroUsd((string) $request->model);
        }

        $statuses = $from !== null ? [$from->value] : [AiRequestStatus::Pending->value, AiRequestStatus::Running->value];

        return AiRequest::query()->whereKey($request->id)->whereIn('status', $statuses)->update($values) > 0;
    }

    /**
     * HTML fields sanitized like the rich-text editor; plain fields stripped of tags.
     *
     * @return array<string, string>
     */
    private function clean(TextTask $task, TextResult $result): array
    {
        $field = $task->field();
        $texts = [];

        foreach (config('app.supported_locales') as $locale) {
            $text = (string) ($result->texts[$locale] ?? '');
            $texts[$locale] = $field->isHtml()
                ? HtmlSanitizer::clean($text)
                : trim((string) preg_replace('/[ \t]+/u', ' ', html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8')));
        }

        return $texts;
    }

    private function elapsed(int $started): int
    {
        return (int) round((hrtime(true) - $started) / 1_000_000);
    }
}
