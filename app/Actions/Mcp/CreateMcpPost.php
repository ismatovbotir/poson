<?php

namespace App\Actions\Mcp;

use App\Actions\News\SaveNewsPost;
use App\Enums\NewsStatus;
use App\Models\NewsPost;
use App\Models\User;
use App\Support\HtmlSanitizer;
use App\Support\Mcp\McpAlerts;
use Carbon\CarbonInterface;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * Create a news post or article for the MCP create_post tool (plan 13 M3,
 * M3-S1, S5, S6): always a NEW row, SaveNewsPost with a null model (an
 * existing post is never loaded) and the slug always generated.
 *
 * It is saved Published only when the caller sent publish === true AND
 * confirm_publish === true AND no body link leaves the site (external
 * host, protocol-relative, mailto; the raw and the sanitized HTML are both
 * scanned). Otherwise it is a Draft with a warning code. A published_at is
 * accepted only together with publish, from now to now + 90 days.
 */
class CreateMcpPost
{
    /**
     * Furthest publication date, in days.
     */
    public const MAX_AHEAD_DAYS = 90;

    /**
     * Hosts listed in an external_links warning.
     */
    public const MAX_HOSTS = 5;

    /**
     * Accepted published_at formats (M3-L2).
     *
     * @var list<string>
     */
    public const DATE_FORMATS = ['Y-m-d\TH:iP', 'Y-m-d\TH:i:sP', 'Y-m-d\TH:i', 'Y-m-d\TH:i:s'];

    public function __construct(private SaveNewsPost $save) {}

    /**
     * @param  array<string, mixed>  $data  validated tool arguments
     * @return array{post: NewsPost, published: bool, scheduled: bool, warnings: list<array<string, mixed>>}
     *
     * @throws ValidationException
     */
    public function handle(User $user, array $data): array
    {
        $publish = ($data['publish'] ?? null) === true;
        $confirmed = $publish && ($data['confirm_publish'] ?? null) === true;
        $publishedAt = $this->publishedAt($data['published_at'] ?? null, $publish);
        $warnings = [];

        if ($publish && ! $confirmed) {
            $warnings[] = ['code' => 'publish_needs_confirm'];
        }

        if ($confirmed && ($hosts = $this->externalHosts($data['body'] ?? [])) !== []) {
            $warnings[] = ['code' => 'external_links', 'hosts' => array_slice($hosts, 0, self::MAX_HOSTS)];
            $confirmed = false;
        }

        $post = $this->save->handle(null, [
            'kind' => $data['kind'] ?? 'news',
            'title' => $data['title'] ?? [],
            'excerpt' => $data['excerpt'] ?? [],
            'body' => $data['body'] ?? [],
            'meta_title' => $data['meta_title'] ?? [],
            'meta_description' => $data['meta_description'] ?? [],
            'status' => ($confirmed ? NewsStatus::Published : NewsStatus::Draft)->value,
            'published_at' => $confirmed ? $publishedAt : null,
            'product_ids' => array_values(array_map('intval', $data['product_ids'] ?? [])),
            'category_ids' => array_values(array_map('intval', $data['category_ids'] ?? [])),
        ], $user);

        $scheduled = $confirmed && $post->published_at?->isFuture() === true;

        if ($confirmed) {
            McpAlerts::postPublished($post, $user, $scheduled);
        }

        return ['post' => $post, 'published' => $confirmed, 'scheduled' => $scheduled, 'warnings' => $warnings];
    }

    /**
     * @throws ValidationException
     */
    private function publishedAt(mixed $value, bool $publish): ?CarbonInterface
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (! $publish) {
            throw ValidationException::withMessages(['published_at' => 'published_at can only be sent together with publish.']);
        }

        $at = $this->parseDate($value);

        if ($at === null) {
            throw ValidationException::withMessages(['published_at' => 'published_at must be an ISO 8601 date and time such as 2026-11-05T10:00 (Asia/Tashkent unless an offset is given).']);
        }

        if ($at->lessThan(now()->subMinute()) || $at->greaterThan(now()->addDays(self::MAX_AHEAD_DAYS))) {
            throw ValidationException::withMessages(['published_at' => 'published_at must be between now and '.self::MAX_AHEAD_DAYS.' days ahead.']);
        }

        return $at->lessThan(now()) ? now() : $at;
    }

    /**
     * Explicit ISO 8601 formats only: relative words ("tomorrow"), "@timestamp"
     * and overflowing dates are invalid.
     */
    private function parseDate(mixed $value): ?CarbonInterface
    {
        if (! is_string($value)) {
            return null;
        }

        $zone = new DateTimeZone(NewsPost::TIMEZONE);

        foreach (self::DATE_FORMATS as $format) {
            $date = DateTimeImmutable::createFromFormat('!'.$format, trim($value), $zone);
            $errors = DateTimeImmutable::getLastErrors();

            if ($date !== false && ($errors === false || ($errors['warning_count'] === 0 && $errors['error_count'] === 0))) {
                return Carbon::instance($date)->utc();
            }
        }

        return null;
    }

    /**
     * Hosts of every link that leaves the site in any locale body, found in
     * the raw HTML (so a stripped "//host" or "\\host" still counts) and in
     * the sanitized HTML. mailto counts as external for the publish gate.
     *
     * @param  array<string, mixed>  $bodies
     * @return list<string>
     */
    private function externalHosts(array $bodies): array
    {
        $hosts = [];

        foreach ($bodies as $html) {
            if (! is_string($html)) {
                continue;
            }

            foreach ([$html, HtmlSanitizer::clean($html)] as $source) {
                preg_match_all('/href\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s>]+))/i', $source, $matches, PREG_SET_ORDER);

                foreach ($matches as $match) {
                    $href = html_entity_decode(trim($match[1] ?: ($match[2] ?? '') ?: ($match[3] ?? '')), ENT_QUOTES | ENT_HTML5);

                    if ($href !== '' && $this->leavesSite($href)) {
                        $hosts[] = $this->label($href);
                    }
                }
            }
        }

        return array_values(array_unique($hosts));
    }

    private function leavesSite(string $href): bool
    {
        return HtmlSanitizer::isExternal($href) || str_starts_with(strtolower($href), 'mailto:');
    }

    /**
     * Short host label for the warning (no path, query or credentials).
     */
    private function label(string $href): string
    {
        if (str_starts_with(strtolower($href), 'mailto:')) {
            return 'mailto';
        }

        $host = strtolower((string) parse_url(str_starts_with($href, '//') ? 'https:'.$href : $href, PHP_URL_HOST));

        return $host !== '' ? mb_substr($host, 0, 80) : 'other';
    }
}
