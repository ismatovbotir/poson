<?php

namespace App\Livewire\Admin\Seo;

use App\Actions\Seo\RefreshSitemap;
use App\Support\Site\Sitemap as SitemapDocument;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * SEO → Sitemap (/admin/sitemap): status of the cached /sitemap.xml, a Refresh
 * button, and a preview (URL table with search and pagination, raw XML view).
 *
 * sitemap.view opens the screen; sitemap.refresh (Admin + Content manager) is
 * checked again inside refresh(), which is rate-limited per user and runs the
 * RefreshSitemap action. The URL list and the XML are never component state:
 * they are read from the cache in computed properties on each render.
 */
#[Layout('layouts::admin')]
class Sitemap extends Component
{
    use WithPagination;

    public const PER_PAGE = 50;

    public const SEARCH_MAX = 200;

    public const RAW_LINES = 200;

    public const RAW_BYTES = 65536;

    public const REFRESH_PER_MINUTE = 6;

    public const TIMEZONE = 'Asia/Tashkent';

    #[Url(as: 'q', except: '')]
    public string $search = '';

    /**
     * "urls" (table) or "xml" (raw view).
     */
    #[Url(except: 'urls')]
    public string $tab = 'urls';

    public function mount(): void
    {
        $this->authorize('sitemap.view');
    }

    public function updatedSearch(): void
    {
        $this->search = mb_substr($this->search, 0, self::SEARCH_MAX);
        $this->resetPage();
    }

    public function showTab(string $tab): void
    {
        $this->authorize('sitemap.view');

        $this->tab = $tab === 'xml' ? 'xml' : 'urls';
    }

    public function refresh(RefreshSitemap $refreshSitemap): void
    {
        $this->authorize('sitemap.refresh');

        $performed = false;
        $built = null;

        RateLimiter::attempt(
            'sitemap-refresh:'.auth()->id(),
            self::REFRESH_PER_MINUTE,
            function () use ($refreshSitemap, &$performed, &$built): void {
                $performed = true;
                $built = $refreshSitemap->handle();
            },
            60,
        );

        if (! $performed) {
            $this->dispatch('toast', title: __('admin.toast.error'), body: __('sitemap.rate_limited'), type: 'error');

            return;
        }

        if ($built === null) {
            $this->dispatch('toast', title: __('admin.toast.error'), body: __('sitemap.busy'), type: 'error');

            return;
        }

        $this->resetPage();
        unset($this->payload, $this->rawXml);
        $this->dispatch('toast', title: __('sitemap.refreshed'), body: __('sitemap.refreshed_body', ['count' => array_sum($built['counts'])]));
    }

    /**
     * @return array{xml: string, generated_at: string, expires_at: string, counts: array<string, int>}|null
     */
    #[Computed]
    public function payload(): ?array
    {
        return app(SitemapDocument::class)->payload();
    }

    #[Computed]
    public function generatedAt(): ?string
    {
        return $this->localTime($this->payload['generated_at'] ?? null);
    }

    #[Computed]
    public function expiresAt(): ?string
    {
        return $this->localTime($this->payload['expires_at'] ?? null);
    }

    /**
     * The first RAW_LINES lines (of at most RAW_BYTES bytes) of the cached XML,
     * one tag per line for reading; shown in an escaped <pre>.
     *
     * @return array{text: string, truncated: bool}|null
     */
    #[Computed]
    public function rawXml(): ?array
    {
        $xml = $this->payload['xml'] ?? null;

        if ($xml === null) {
            return null;
        }

        $capped = mb_strcut($xml, 0, self::RAW_BYTES);
        $lines = explode("\n", str_replace('><', ">\n<", $capped), self::RAW_LINES + 1);
        $truncated = strlen($capped) < strlen($xml) || count($lines) > self::RAW_LINES;

        return ['text' => implode("\n", array_slice($lines, 0, self::RAW_LINES)), 'truncated' => $truncated];
    }

    /**
     * @return LengthAwarePaginator<int, array{loc: string, lastmod: ?string, locale: string, type: string}>|null
     */
    #[Computed]
    public function urls(): ?LengthAwarePaginator
    {
        $all = app(SitemapDocument::class)->urls();

        if ($all === null) {
            return null;
        }

        $needle = mb_strtolower(mb_substr(trim($this->search), 0, self::SEARCH_MAX));

        if ($needle !== '') {
            $all = array_values(array_filter($all, fn (array $row): bool => str_contains(mb_strtolower($row['loc']), $needle)));
        }

        $page = max(1, $this->getPage());

        return new LengthAwarePaginator(
            array_slice($all, ($page - 1) * self::PER_PAGE, self::PER_PAGE),
            count($all),
            self::PER_PAGE,
            $page,
            ['path' => request()->url(), 'pageName' => 'page'],
        );
    }

    public function render(): View
    {
        return view('livewire.admin.seo.sitemap', [
            'canRefresh' => auth()->user()->can('sitemap.refresh'),
        ]);
    }

    private function localTime(?string $iso): ?string
    {
        return $iso === null ? null : CarbonImmutable::parse($iso)->timezone(self::TIMEZONE)->format('d.m.Y H:i');
    }
}
