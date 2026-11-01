<?php

namespace App\Support\Dashboard;

use App\Enums\LeadStatus;
use App\Enums\QuoteStatus;
use App\Models\Attribute;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Lead;
use App\Models\OrderPayment;
use App\Models\Product;
use App\Models\Quote;
use App\Models\User;
use App\Models\UzqrTransaction;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Numbers behind the admin dashboard, shared with the MCP server. Every
 * method applies the user's permissions and Lead::visibleTo() itself, so a
 * caller cannot see more than the dashboard shows.
 */
class BusinessMetrics
{
    /**
     * Catalog models counted as plain totals (key → model class).
     *
     * @var array<string, class-string<Model>>
     */
    public const CATALOG_MODELS = [
        'categories' => Category::class,
        'attributes' => Attribute::class,
        'brands' => Brand::class,
    ];

    public function __construct(private readonly User $user) {}

    /**
     * Visible lead counts: leads_new, leads_today, leads_unassigned, leads_mine.
     * Empty when the user may not view leads.
     *
     * @return array<string, int>
     */
    public function leadCounts(): array
    {
        if (! $this->user->can('viewAny', Lead::class)) {
            return [];
        }

        $user = $this->user;
        $visible = fn (): Builder => Lead::query()->visibleTo($user);
        $todayStart = now(Lead::TIMEZONE)->startOfDay();

        return [
            'leads_new' => $visible()->where('status', LeadStatus::New->value)->count(),
            'leads_today' => $visible()->where('created_at', '>=', $todayStart->copy()->utc())->count(),
            'leads_unassigned' => $visible()->open()->whereNull('manager_id')->count(),
            'leads_mine' => $visible()->open()->where('manager_id', $user->id)->count(),
        ];
    }

    /**
     * Visible quote counts per status (every QuoteStatus key, zero-filled) and
     * the open value in tiyin (draft and sent quotes that have not expired by
     * date). Empty when the user may not view quotes.
     *
     * @return array{by_status: array<string, int>, open_count: int, open_total_tiyin: int}|array{}
     */
    public function quoteSummary(): array
    {
        if (! $this->user->can('quotes.viewAny')) {
            return [];
        }

        $byStatus = array_fill_keys(array_column(QuoteStatus::cases(), 'value'), 0);
        $openCount = 0;
        $openTotal = 0;
        $open = [QuoteStatus::Draft->value, QuoteStatus::Sent->value];
        $today = now(Lead::TIMEZONE)->toDateString();

        Quote::query()->visibleTo($this->user)
            ->selectRaw('status, COUNT(*) as aggregate_count')
            ->selectRaw('SUM(CASE WHEN valid_until >= ? THEN 1 ELSE 0 END) as open_count', [$today])
            ->selectRaw('COALESCE(SUM(CASE WHEN valid_until >= ? THEN total ELSE 0 END), 0) as open_sum', [$today])
            ->groupBy('status')
            ->toBase()->get()
            ->each(function (object $row) use (&$byStatus, &$openCount, &$openTotal, $open): void {
                $byStatus[(string) $row->status] = (int) $row->aggregate_count;

                if (in_array((string) $row->status, $open, true)) {
                    $openCount += (int) $row->open_count;
                    $openTotal += (int) $row->open_sum;
                }
            });

        return [
            'by_status' => $byStatus,
            'open_count' => $openCount,
            'open_total_tiyin' => $openTotal,
        ];
    }

    /**
     * Catalog counts for the resources the user may view: categories,
     * attributes, brands, products_active, products_drafts, products_incomplete.
     *
     * @return array<string, int>
     */
    public function catalogCounts(): array
    {
        $counts = [];

        foreach (self::CATALOG_MODELS as $key => $model) {
            if ($this->user->can('viewAny', $model)) {
                $counts[$key] = $model::query()->count();
            }
        }

        if ($this->user->can('viewAny', Product::class)) {
            // Published = active product in an active category (as on the site).
            $counts['products_active'] = Product::query()->active()->count();
            $counts['products_drafts'] = Product::query()->where('is_active', false)->count();
            $counts['products_incomplete'] = Product::query()->where('has_missing_specs', true)->count();
        }

        return $counts;
    }

    /**
     * Unresolved unmatched dynamic UzQR payments; test orders count only for
     * users with payments.test. 0 when the user may not view payments.
     */
    public function uzqrUnmatched(): int
    {
        if (! $this->user->can('viewAny', OrderPayment::class)) {
            return 0;
        }

        return UzqrTransaction::query()->unresolvedUnmatched()
            ->when(! $this->user->can('payments.test'), fn ($query) => $query->whereHas('order', fn ($order) => $order->where('is_test', false)))
            ->count();
    }
}
