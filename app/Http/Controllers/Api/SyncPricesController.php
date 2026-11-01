<?php

namespace App\Http\Controllers\Api;

use App\Actions\Api\SyncPrices;
use App\Http\Controllers\Controller;
use App\Http\Middleware\AuthenticateApiToken;
use App\Models\ApiToken;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use JsonException;

/**
 * POST /api/v1/prices (plan 09; contract in .claude/docs/api/prices.md). Runs
 * after LimitJsonBody, AuthenticateApiToken and the price-api throttle.
 *
 * Only Content-Type application/json (else 415); the body is decoded here
 * with objects (so {} and [] stay distinct) and integers stay integers
 * (1.0 or "100" are rejected per item). Whole-batch problems → 415 / 422
 * with a machine "error" code, an unexpected failure → 500 server_error,
 * each logged as a rejected batch; otherwise 200 with per-item statuses.
 */
class SyncPricesController extends Controller
{
    /**
     * Human hints for whole-batch error codes (English, for the 1C developer).
     */
    public const MESSAGES = [
        'unsupported_media_type' => 'Send the body as Content-Type: application/json.',
        'invalid_json' => 'The body is not valid JSON.',
        'body_not_object' => 'The body must be a JSON object: {"items": [...]}.',
        'items_missing' => 'The "items" key is required.',
        'items_not_list' => '"items" must be a JSON array.',
        'items_empty' => '"items" must contain at least one item.',
        'too_many_items' => '"items" may contain at most 100 items.',
        'server_error' => 'The batch could not be applied and nothing was changed. Retrying is safe.',
    ];

    public function __invoke(Request $request, SyncPrices $syncPrices): JsonResponse
    {
        /** @var ApiToken $token */
        $token = $request->attributes->get(AuthenticateApiToken::ATTRIBUTE);
        $ip = $request->ip();

        if (! $this->isJson($request)) {
            return $this->rejected($syncPrices->reject('unsupported_media_type', $token, $ip)->log->id, 'unsupported_media_type', 415);
        }

        try {
            $payload = json_decode((string) $request->getContent(), false, 32, JSON_BIGINT_AS_STRING | JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return $this->rejected($syncPrices->reject('invalid_json', $token, $ip)->log->id, 'invalid_json', 422);
        }

        $result = $syncPrices->handle($payload, $token, $ip);

        if ($result->isRejected()) {
            $error = (string) $result->log->error;

            return $this->rejected($result->log->id, $error, $error === SyncPrices::SERVER_ERROR ? 500 : 422);
        }

        return response()->json($result->toArray());
    }

    private function isJson(Request $request): bool
    {
        $type = strtolower(trim(explode(';', (string) $request->headers->get('Content-Type'))[0]));

        return $type === 'application/json';
    }

    private function rejected(int $syncId, string $error, int $status): JsonResponse
    {
        return response()->json([
            'sync_id' => $syncId,
            'error' => $error,
            'message' => self::MESSAGES[$error] ?? 'The batch was rejected.',
        ], $status);
    }
}
