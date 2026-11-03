<?php

namespace App\Http\Controllers\Webhooks;

use App\Actions\Telegram\HandleTelegramUpdate;
use App\Actions\Telegram\TelegramUpdateFailed;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

/**
 * POST /api/telegram/webhook (plan 14 v2 §9). Reached only after
 * LimitJsonBody → VerifyTelegramSecret → throttle:telegram-webhook.
 *
 * Always answers 200 once the secret matched (condition 3): Telegram would
 * otherwise redeliver the same update. Any failure is reported as a
 * TelegramUpdateFailed carrying the update_id and the exception class only,
 * never the payload or the original message (condition 18).
 */
class TelegramWebhookController extends Controller
{
    public function __invoke(Request $request, HandleTelegramUpdate $handleUpdate): JsonResponse
    {
        $payload = json_decode((string) $request->getContent(), true);

        if (is_array($payload)) {
            try {
                $handleUpdate->handle($payload);
            } catch (Throwable $exception) {
                report(new TelegramUpdateFailed(is_int($payload['update_id'] ?? null) ? $payload['update_id'] : null, $exception::class));
            }
        }

        return response()->json(['ok' => true]);
    }
}
