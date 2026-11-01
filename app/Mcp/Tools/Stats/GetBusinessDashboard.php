<?php

namespace App\Mcp\Tools\Stats;

use App\Mcp\Support\Period;
use App\Mcp\Tools\PosonTool;
use App\Models\NewsPost;
use App\Models\User;
use App\Support\Analytics\TrafficReport;
use App\Support\Dashboard\BusinessMetrics;
use App\Support\Orders\OrderMetrics;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

/**
 * get_business_dashboard (plan 13 M2d, S5, S35): one overview of the
 * business. Every section has its own permission; a section the user may
 * not see is left out entirely and only its name is listed in
 * omitted_sections. All numbers come from the shared metrics classes.
 */
#[Name('get_business_dashboard')]
#[Description('Business overview in sections: leads (open counts you may see), quotes (counts by status and open value in tiyin), orders (booked, collected, refunded, net, average for the period), payments_attention (counts only), traffic (visitors, page views, leads, conversion), ai_bots (AI crawler hits and verified hits), content (published and draft news posts) and catalog (counts). days 1-366 (default 30) sets the period of orders, traffic and ai_bots; compare=true adds a previous block for the same-length period before. Sections your role may not see are left out and listed in omitted_sections. Money is integer tiyin. Contains no customer data.')]
#[IsReadOnly]
#[IsIdempotent]
class GetBusinessDashboard extends PosonTool
{
    public const SECTIONS = ['leads', 'quotes', 'orders', 'payments_attention', 'traffic', 'ai_bots', 'content', 'catalog'];

    protected function permission(): ?string
    {
        return null;
    }

    public function group(): string
    {
        return 'stats';
    }

    protected function rules(): array
    {
        return [
            'days' => ['nullable', 'integer', 'between:1,'.Period::MAX_DAYS],
            'from' => ['prohibited'],
            'to' => ['prohibited'],
            'compare' => ['nullable', 'boolean'],
        ];
    }

    protected function run(Request $request, User $user): Response|ResponseFactory
    {
        $period = Period::make(['days' => $request->get('days')]);
        $compare = filter_var($request->get('compare'), FILTER_VALIDATE_BOOLEAN);
        $business = new BusinessMetrics($user);
        $orders = new OrderMetrics($user);

        $canSee = [
            'leads' => $user->can('leads.viewAny'),
            'quotes' => $user->can('quotes.viewAny'),
            'orders' => $user->can('orders.viewAny'),
            'payments_attention' => $user->can('payments.viewAny') && $user->can('orders.viewAny'),
            'traffic' => $user->can('analytics.view'),
            'ai_bots' => $user->can('analytics.view'),
            'content' => $user->can('news.viewAny'),
            'catalog' => $user->can('categories.viewAny') || $user->can('attributes.viewAny') || $user->can('brands.viewAny') || $user->can('products.viewAny'),
        ];

        $catalog = $canSee['catalog'] ? $business->catalogCounts() : [];
        $canSee['catalog'] = $catalog !== [];

        $payload = ['period' => ['from' => $period->fromDate(), 'to' => $period->toDate(), 'days' => $period->days()]];
        $traffic = $canSee['traffic'] || $canSee['ai_bots'] ? new TrafficReport($period->days()) : null;

        if ($canSee['leads']) {
            $payload['leads'] = $business->leadCounts();
        }

        if ($canSee['quotes']) {
            $payload['quotes'] = $business->quoteSummary();
        }

        if ($canSee['orders']) {
            $payload['orders'] = $orders->summary($period);
        }

        if ($canSee['payments_attention']) {
            $payload['payments_attention'] = ['counts' => $orders->attentionCounts()];
        }

        if ($canSee['traffic']) {
            $payload['traffic'] = $this->trafficSection($traffic);
        }

        if ($canSee['ai_bots']) {
            $payload['ai_bots'] = $this->botsSection($traffic);
        }

        if ($canSee['content']) {
            $payload['content'] = [
                'posts_published' => NewsPost::query()->visible()->count(),
                'posts_drafts' => NewsPost::query()->where('status', 'draft')->count(),
            ];
        }

        if ($canSee['catalog']) {
            $payload['catalog'] = $catalog;
        }

        if ($compare) {
            $previous = $period->previous();
            // TrafficReport always ends today: the previous window is the
            // double-length report minus the current one (all values are sums).
            $double = $traffic === null ? null : new TrafficReport($period->days() * 2);
            $block = [];

            if ($canSee['orders']) {
                $block['orders'] = $orders->summary($previous);
            }

            if ($canSee['traffic']) {
                $block['traffic'] = $this->trafficSection($double, $traffic);
            }

            if ($canSee['ai_bots']) {
                $block['ai_bots'] = $this->botsSection($double, $traffic);
            }

            $payload['previous'] = ['period' => ['from' => $previous->fromDate(), 'to' => $previous->toDate(), 'days' => $previous->days()], ...$block];
        }

        $payload['omitted_sections'] = array_values(array_filter(self::SECTIONS, fn (string $section): bool => ! $canSee[$section]));

        return $this->structuredCapped($payload, 'omitted_sections');
    }

    /**
     * @return array{visitors: int, page_views: int, leads: int, conversion_percent: float|null}
     */
    private function trafficSection(TrafficReport $report, ?TrafficReport $current = null): array
    {
        $totals = $report->totals();

        if ($current !== null) {
            $own = $current->totals();
            $totals = [
                'visitors' => $totals['visitors'] - $own['visitors'],
                'page_views' => $totals['page_views'] - $own['page_views'],
                'leads' => $totals['leads'] - $own['leads'],
            ];
            $totals['conversion'] = TrafficReport::conversion($totals['leads'], $totals['visitors']);
        }

        return [
            'visitors' => $totals['visitors'],
            'page_views' => $totals['page_views'],
            'leads' => $totals['leads'],
            'conversion_percent' => $totals['conversion'],
        ];
    }

    /**
     * @return array{hits: int, verified_hits: int}
     */
    private function botsSection(TrafficReport $report, ?TrafficReport $current = null): array
    {
        $bots = $report->bots();
        $hits = array_sum(array_column($bots, 'hits'));
        $verified = array_sum(array_column($bots, 'verified'));

        if ($current !== null) {
            $own = $current->bots();
            $hits -= array_sum(array_column($own, 'hits'));
            $verified -= array_sum(array_column($own, 'verified'));
        }

        return ['hits' => $hits, 'verified_hits' => $verified];
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'days' => $schema->integer()->description('Last N days including today, 1-366 (default 30).'),
            'compare' => $schema->boolean()->description('Add a previous block (orders, traffic, ai_bots) for the same-length period before.'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function outputSchema(JsonSchema $schema): array
    {
        $period = $schema->object([
            'from' => $schema->string()->required(),
            'to' => $schema->string()->required(),
            'days' => $schema->integer()->required(),
        ]);

        return [
            'period' => $period->required(),
            'leads' => $schema->object([]),
            'quotes' => $schema->object([]),
            'orders' => $schema->object([]),
            'payments_attention' => $schema->object([]),
            'traffic' => $schema->object([]),
            'ai_bots' => $schema->object([]),
            'content' => $schema->object([]),
            'catalog' => $schema->object([]),
            'previous' => $schema->object([]),
            'omitted_sections' => $schema->array()->items($schema->string())->required(),
            'truncated' => $schema->boolean()->required(),
        ];
    }
}
