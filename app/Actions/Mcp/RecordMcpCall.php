<?php

namespace App\Actions\Mcp;

use App\Enums\McpAuthType;
use App\Enums\McpCallStatus;
use App\Mcp\Support\Redactor;
use App\Models\ApiToken;
use App\Models\McpCall;
use App\Models\User;
use App\Support\Mcp\ClientFamily;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

/**
 * Write one mcp_calls row (plan 13 §4, C22, C27). Arguments always pass
 * through the Redactor; the request's token and Authorization header are
 * never stored (only the token id). client_name is the User-Agent family.
 */
class RecordMcpCall
{
    /**
     * @param  array<array-key, mixed>  $arguments  raw tool arguments (redacted here)
     * @param  array<string, mixed>|null  $before  snapshot of the edited fields (writes)
     */
    public function handle(
        ?User $user,
        ?ApiToken $token,
        string $tool,
        McpCallStatus $status,
        array $arguments = [],
        int $durationMs = 0,
        ?Request $request = null,
        bool $isWrite = false,
        bool $isPublish = false,
        ?Model $subject = null,
        ?array $before = null,
    ): McpCall {
        $request ??= request();
        $ip = $request->ip();

        return McpCall::query()->create([
            'user_id' => $user?->id,
            'auth_type' => McpAuthType::Token,
            'api_token_id' => $token?->id,
            'client_name' => ClientFamily::of($request->userAgent()),
            'tool' => mb_substr($tool, 0, 64),
            'is_write' => $isWrite,
            'is_publish' => $isPublish,
            'status' => $status,
            'subject_type' => $subject?->getMorphClass(),
            'subject_id' => $subject?->getKey(),
            'arguments' => Redactor::arguments($arguments),
            'before' => $before,
            'duration_ms' => max(0, $durationMs),
            'ip' => $ip !== null ? mb_substr($ip, 0, 45) : null,
        ]);
    }
}
