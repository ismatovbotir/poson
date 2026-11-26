<?php

namespace App\Actions\Office;

use App\Actions\Chat\RecordAiUsage;
use App\Enums\AiUsageChannel;
use App\Enums\ChatAiStatus;
use App\Services\Ai\AiFailed;
use App\Services\Ai\AiRequest;
use App\Services\Ai\AiResult;
use App\Services\Ai\AiUnavailable;
use App\Services\Ai\AnthropicChatModel;
use App\Services\Ai\ChatModel;
use App\Support\Chat\AiSwitch;
use Throwable;

/**
 * One Virtual office model call with its reservation (plan 41 VO-11, plan 44
 * OG-11), shared by the business-chat and the group analysis: only an
 * allowed model on a vo_* channel (any other channel is refused); reserve before the call (over the cap →
 * "capped", no call), settle in finally; a 401/403 pauses the office AI.
 * Returns the result, or the outcome key (capped, refused, api_error …).
 */
class CallOfficeModel
{
    /**
     * The only channels an office call may spend on (plan 44 L6).
     */
    public const CHANNELS = [AiUsageChannel::OfficeRoute, AiUsageChannel::OfficeSales, AiUsageChannel::OfficeService, AiUsageChannel::OfficeSupport];

    public function __construct(protected RecordAiUsage $usage) {}

    /**
     * @param  array{input: int, output: int, cache: int}  $tokens
     * @param  array<string, string>  $models
     */
    public function handle(ChatModel $model, ?AiUsageChannel $channel, AiRequest $request, array &$tokens, array &$models, string $stage): AiResult|string
    {
        if ($channel === null || ! in_array($channel, self::CHANNELS, true) || ! in_array($request->model, AnthropicChatModel::MODELS, true)) {
            return 'bad_model';
        }

        $estimate = $request->estimatedBilledTokens();
        $day = $this->usage->reserve($channel, $estimate);

        if ($day === null) {
            $this->usage->capped($channel);

            return 'capped';
        }

        $status = ChatAiStatus::Failed;
        $result = null;

        try {
            $result = $model->complete($request);
            $status = $result->isRefusal() ? ChatAiStatus::Refused : ChatAiStatus::Answered;
        } catch (AiUnavailable) {
            return 'unavailable';
        } catch (AiFailed $failure) {
            if (in_array($failure->status, [401, 403], true)) {
                AiSwitch::markAuthFailed();
            }

            return 'api_error';
        } finally {
            try {
                $this->usage->settle($channel, $day, $estimate, $status, $result);
            } catch (Throwable) {
                // Counters only: a failed settle must not hide the outcome.
            }
        }

        $tokens['input'] += $result->inputTokens;
        $tokens['output'] += $result->outputTokens;
        $tokens['cache'] += $result->cacheReadTokens;
        $models[$stage] = $result->model !== '' ? $result->model : (string) $request->model;

        return $result->isRefusal() ? 'refused' : $result;
    }
}
