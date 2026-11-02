<?php

namespace App\Support\Mcp;

use App\Enums\McpRevokeReason;
use App\Jobs\SendTelegramMessage;
use App\Mcp\Support\UntrustedText;
use App\Models\ApiToken;
use App\Models\NewsPost;
use App\Models\User;
use App\Services\Telegram\TelegramClient;
use App\Support\Secrets;
use App\Support\Site\CanonicalHost;
use App\Support\Telegram\TelegramLocale;
use App\Support\Telegram\TelegramMessage;
use Throwable;

/**
 * Every MCP alert goes to the Telegram management chat (plan 13 C26),
 * queued (SendTelegramMessage, after the transaction commits) in the
 * Telegram language. Values are escaped with e(). Never a token, prefix
 * or hash; IP and a short User-Agent only in security alerts. Skipped
 * quietly by the job while the bot or the chat isn't configured.
 */
final class McpAlerts
{
    public const CHAT = 'management';

    /**
     * Bot token and management chat are both set (GenerateMcpToken refuses in production otherwise).
     */
    public static function isConfigured(): bool
    {
        return app(TelegramClient::class)->isConfigured() && Secrets::isConfigured('telegram.chats.'.self::CHAT);
    }

    public static function accessGranted(User $holder, ?User $by, ?User $previous = null): void
    {
        self::send($previous ? 'transferred' : 'granted', [
            'holder' => $holder->name,
            'previous' => $previous?->name,
            'by' => $by?->name,
        ]);
    }

    public static function accessRevoked(User $holder, McpRevokeReason $reason, ?User $by, int $tokens): void
    {
        self::send('access_revoked', [
            'holder' => $holder->name,
            'reason' => self::reason($reason),
            'tokens' => (string) $tokens,
            'by' => $by?->name,
        ]);
    }

    public static function tokenGenerated(ApiToken $token, User $holder, ?User $by, bool $regenerated): void
    {
        self::send($regenerated ? 'token_regenerated' : 'token_generated', [
            'holder' => $holder->name,
            'label' => $token->name,
            'by' => $by?->name,
        ]);
    }

    public static function tokenRevoked(ApiToken $token, McpRevokeReason $reason, ?User $by): void
    {
        $token->loadMissing('user');

        self::send('token_revoked', [
            'holder' => $token->user?->name,
            'label' => $token->name,
            'reason' => self::reason($reason),
            'by' => $by?->name,
        ]);
    }

    public static function tokenSettingsChanged(ApiToken $token, ?User $by): void
    {
        $token->loadMissing('user');

        self::send('token_settings', [
            'holder' => $token->user?->name,
            'label' => $token->name,
            'by' => $by?->name,
        ]);
    }

    public static function firstUse(ApiToken $token, ?string $ip, ?string $userAgent): void
    {
        self::security('first_use', $token, $ip, $userAgent);
    }

    public static function newIp(ApiToken $token, ?string $ip, ?string $userAgent): void
    {
        self::security('new_ip', $token, $ip, $userAgent);
    }

    public static function newClient(ApiToken $token, ?string $ip, ?string $userAgent): void
    {
        self::security('new_client', $token, $ip, $userAgent);
    }

    public static function revokedUsed(ApiToken $token, ?string $ip, ?string $userAgent): void
    {
        self::security('revoked_used', $token, $ip, $userAgent);
    }

    public static function ipBlocked(ApiToken $token, ?string $ip, ?string $userAgent): void
    {
        self::security('ip_blocked', $token, $ip, $userAgent);
    }

    public static function rotationDue(ApiToken $token): void
    {
        $token->loadMissing('user');

        self::send('rotation', [
            'holder' => $token->user?->name,
            'label' => $token->name,
            'days' => (string) (int) $token->created_at->diffInDays(now()),
        ]);
    }

    /**
     * Claude published (or scheduled) a post. Event, title, URLs and staff
     * name only: no email, IP, User-Agent or body (M3-S15).
     */
    public static function postPublished(NewsPost $post, User $user, bool $scheduled): void
    {
        self::send($scheduled ? 'post_scheduled' : 'post_published', self::postValues($post, $user), 'post_hint');
    }

    public static function postUnpublished(NewsPost $post, User $by): void
    {
        self::send('post_unpublished', self::postValues($post, $by), 'post_hint');
    }

    /**
     * @return array<string, string|null>
     */
    private static function postValues(NewsPost $post, User $staff): array
    {
        $title = (string) UntrustedText::clean($post->getTranslation('title', 'uz', false) ?: $post->title, 200);

        return [
            'title' => $title,
            'public_url' => self::httpsUrl(fn (): string => $post->url('uz')),
            'admin_url' => self::httpsUrl(fn (): string => route($post->kind->adminRoute('edit'), $post)),
            'by' => $staff->name,
        ];
    }

    /**
     * @param  callable(): string  $build
     */
    private static function httpsUrl(callable $build): ?string
    {
        // Always APP_URL (this may run from a queue worker or the console).
        $url = CanonicalHost::using($build);

        return strtolower((string) parse_url($url, PHP_URL_SCHEME)) === 'https' && filter_var($url, FILTER_VALIDATE_URL) ? $url : null;
    }

    private static function security(string $event, ApiToken $token, ?string $ip, ?string $userAgent): void
    {
        $token->loadMissing('user');

        self::send($event, [
            'holder' => $token->user?->name,
            'label' => $token->name,
            'ip' => $ip !== null ? mb_substr($ip, 0, 45) : null,
            'client' => ClientFamily::short($userAgent),
        ]);
    }

    private static function reason(McpRevokeReason $reason): string
    {
        return __('enums.mcp_revoke_reason.'.$reason->value, [], TelegramLocale::current());
    }

    /**
     * @param  array<string, string|null>  $values  label key => value (null rows are left out)
     */
    private static function send(string $event, array $values, string $hint = 'hint'): void
    {
        try {
            self::dispatch($event, $values, $hint);
        } catch (Throwable $exception) {
            // An alert must never break the request or the admin action it reports.
            report($exception);
        }
    }

    /**
     * @param  array<string, string|null>  $values
     */
    private static function dispatch(string $event, array $values, string $hint): void
    {
        $locale = TelegramLocale::current();

        $lines = ['<b>'.e(__('telegram.mcp.events.'.$event, [], $locale)).'</b>'];

        foreach ($values as $label => $value) {
            if ($value === null || $value === '') {
                continue;
            }

            $lines[] = e(__('telegram.mcp.labels.'.$label, [], $locale)).': '.e($value);
        }

        $lines[] = e(__('telegram.mcp.'.$hint, [], $locale));

        $buttons = TelegramMessage::adminButton(route('admin.settings.mcp', absolute: false), __('telegram.mcp.open', [], $locale));

        SendTelegramMessage::dispatch(self::CHAT, implode("\n", $lines), $buttons, 'mcp.'.$event)->afterCommit();
    }
}
