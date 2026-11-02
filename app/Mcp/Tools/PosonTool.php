<?php

namespace App\Mcp\Tools;

use App\Actions\Mcp\RecordMcpCall;
use App\Enums\McpCallStatus;
use App\Http\Middleware\AuthenticateMcpToken;
use App\Models\ApiToken;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tool;
use Throwable;

/**
 * Base of every Poson MCP tool (plan 13 §5, C17, C23, C27, C30).
 *
 * handle() is final and always runs the same pipeline:
 *   user → permission (Gate, also used by shouldRegister) → write / publish
 *   limiter → validate rules() → run() → RecordMcpCall (in finally).
 * Subclasses implement run() and declare permission(), group(), isWrite()
 * and isPublish(). Writes set $subject / $before for the audit row.
 *
 * Errors: validation messages are returned as a tool error; anything else is
 * reported to the log and answered with a generic message (no stack trace,
 * SQL or exception text, C27). The locale is en while the tool runs (D8).
 */
abstract class PosonTool extends Tool
{
    /**
     * mcp-write: writes per user per hour (posts, FAQ, pages, products together).
     */
    public const WRITE_LIMIT_PER_HOUR = 30;

    /**
     * mcp-publish: publishes (incl. scheduled) per user per day.
     */
    public const PUBLISH_LIMIT_PER_DAY = 5;

    /**
     * Byte cap of the encoded structured result (M2-S2).
     */
    public const RESPONSE_CAP_BYTES = 65536;

    /**
     * Record changed by a write (audit subject).
     */
    protected ?Model $subject = null;

    /**
     * Snapshot of the fields a write changed, taken before the change (C22).
     *
     * @var array<string, mixed>|null
     */
    protected ?array $before = null;

    /**
     * Set by run() right after a committed Published save (a draft fallback
     * or a failure before the save leaves it false). Only then is the call
     * logged as a publish (M3-S8), whatever happens later in the request, so
     * Unpublish always finds the row; the publish limiter still counts the intent.
     */
    protected bool $published = false;

    /**
     * Permission the user needs (null = any MCP holder).
     */
    abstract protected function permission(): ?string;

    /**
     * Group shown by get_my_access (account, stats, content, catalog).
     */
    abstract public function group(): string;

    abstract protected function run(Request $request, User $user): Response|ResponseFactory;

    protected function isWrite(): bool
    {
        return false;
    }

    protected function isPublish(Request $request): bool
    {
        return false;
    }

    /**
     * Validation rules of the arguments.
     *
     * @return array<string, mixed>
     */
    protected function rules(): array
    {
        return [];
    }

    /**
     * Listed in tools/list only for users who may call it (C30).
     */
    public function shouldRegister(Request $request): bool
    {
        $user = $request->user();

        return $user instanceof User && $this->permits($user);
    }

    public function permits(User $user): bool
    {
        $permission = $this->permission();

        return $permission === null || $user->can($permission);
    }

    final public function handle(Request $request): Response|ResponseFactory
    {
        $startedAt = hrtime(true);
        $locale = app()->getLocale();
        app()->setLocale('en');

        $this->subject = null;
        $this->before = null;
        $this->published = false;
        $status = McpCallStatus::Error;
        $publish = false;
        $user = $request->user();

        try {
            if (! $user instanceof User || ! $this->permits($user)) {
                $status = McpCallStatus::Denied;

                return Response::error('You do not have permission to use this tool.');
            }

            $publish = $this->isPublish($request);

            if ($limited = $this->hitLimiters($user, $publish)) {
                $status = McpCallStatus::RateLimited;

                return Response::error($limited);
            }

            if ($this->isWrite()) {
                $this->rejectUnknownArguments($request);
            }

            if ($this->rules() !== []) {
                $request->validate($this->rules());
            }

            $response = $this->run($request, $user);
            $status = McpCallStatus::Ok;

            return $response;
        } catch (ValidationException $exception) {
            $status = McpCallStatus::Invalid;

            return Response::error(implode(' ', $exception->validator->errors()->all()));
        } catch (Throwable $exception) {
            $status = McpCallStatus::Error;
            report($exception);

            return Response::error('The tool failed because of a server error. The call was logged; try again later or tell the site administrator.');
        } finally {
            $this->record($request, $user instanceof User ? $user : null, $status, $startedAt);
            app()->setLocale($locale);
        }
    }

    /**
     * Structured result capped at RESPONSE_CAP_BYTES: the tail of the rows
     * list is cut and `truncated` is set. Never throws.
     *
     * @param  array<string, mixed>  $payload
     */
    protected function structuredCapped(array $payload, string $rowsKey = 'rows'): ResponseFactory
    {
        return Response::structured(self::capPayload($payload, $rowsKey));
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public static function capPayload(array $payload, string $rowsKey = 'rows'): array
    {
        $payload['truncated'] = false;

        try {
            $fits = fn (array $data): bool => self::encodedBytes($data) <= self::RESPONSE_CAP_BYTES;

            if ($fits($payload)) {
                return $payload;
            }

            $payload['truncated'] = true;
            $rows = is_array($payload[$rowsKey] ?? null) ? array_values($payload[$rowsKey]) : [];
            $low = 0;
            $high = count($rows);

            while ($low < $high) {
                $middle = intdiv($low + $high + 1, 2);

                if ($fits([...$payload, $rowsKey => array_slice($rows, 0, $middle)])) {
                    $low = $middle;
                } else {
                    $high = $middle - 1;
                }
            }

            $payload[$rowsKey] = array_slice($rows, 0, $low);

            if (! $fits($payload)) {
                return [$rowsKey => [], 'truncated' => true];
            }

            return $payload;
        } catch (Throwable $exception) {
            report($exception);

            return [$rowsKey => [], 'truncated' => true];
        }
    }

    private static function encodedBytes(mixed $data): int
    {
        return strlen((string) json_encode($data, JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR));
    }

    /**
     * Write / publish limits (C17). Returns the error text when over a limit.
     */
    private function hitLimiters(User $user, bool $publish): ?string
    {
        $limits = [];

        if ($this->isWrite() || $publish) {
            $limits[] = ['mcp-write:'.$user->id, self::WRITE_LIMIT_PER_HOUR, 3600];
        }

        if ($publish) {
            $limits[] = ['mcp-publish:'.$user->id, self::PUBLISH_LIMIT_PER_DAY, 86400];
        }

        // Atomic: increment first, then compare, so parallel calls can't both pass at the limit (M3-S7).
        foreach ($limits as [$key, $max, $decay]) {
            if (RateLimiter::increment($key, $decay) > $max) {
                return 'Rate limit reached. Retry after '.RateLimiter::availableIn($key).' seconds.';
            }
        }

        return null;
    }

    /**
     * A write tool accepts only the arguments named in rules() (M3-S2). The
     * message lists the allowed names and never echoes what was sent (M3-S13).
     */
    private function rejectUnknownArguments(Request $request): void
    {
        $allowed = array_values(array_unique(array_map(
            fn (string $key): string => explode('.', $key)[0],
            array_keys($this->rules()),
        )));

        if (array_diff(array_keys($request->all()), $allowed) !== []) {
            throw ValidationException::withMessages([
                'arguments' => 'Unknown argument. Allowed arguments: '.implode(', ', $allowed).'.',
            ]);
        }
    }

    private function record(Request $request, ?User $user, McpCallStatus $status, int|float $startedAt): void
    {
        try {
            $token = request()->attributes->get(AuthenticateMcpToken::ATTRIBUTE);

            app(RecordMcpCall::class)->handle(
                $user,
                $token instanceof ApiToken ? $token : null,
                $this->name(),
                $status,
                $request->all(),
                (int) round((hrtime(true) - $startedAt) / 1_000_000),
                isWrite: $this->isWrite(),
                isPublish: $this->published,
                subject: $this->subject,
                before: $this->before,
            );
        } catch (Throwable $exception) {
            report($exception);
        }
    }
}
