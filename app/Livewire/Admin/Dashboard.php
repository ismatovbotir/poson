<?php

namespace App\Livewire\Admin;

use App\Enums\ActivityType;
use App\Enums\LeadStatus;
use App\Models\Activity;
use App\Models\Attribute;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Lead;
use App\Models\OrderPayment;
use App\Models\Product;
use App\Models\User;
use App\Models\UzqrTransaction;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Admin home: welcome with the user's role, plus role-aware stat cards
 * (a card shows only when the user may view the resource). Lead cards
 * (new, today, unassigned open, my open) and the latest leads count only
 * what Lead::visibleTo() lets the user see.
 */
#[Layout('layouts::admin')]
class Dashboard extends Component
{
    /**
     * Card key → [model class, icon]. Product cards (active, drafts, specs
     * incomplete) follow when the user may view products.
     *
     * @var array<string, array{0: class-string<Model>, 1: string}>
     */
    public const CARDS = [
        'categories' => [Category::class, 'folder'],
        'attributes' => [Attribute::class, 'sliders'],
        'brands' => [Brand::class, 'tag'],
    ];

    /**
     * The dashboard is open to every staff role (the "admin" middleware checks that).
     */
    public function mount(): void
    {
        abort_unless(auth()->user() instanceof User && auth()->user()->isStaff(), 403);
    }

    /**
     * Latest leads shown on the dashboard.
     */
    public const RECENT_LEADS = 5;

    /**
     * @return list<array{key: string, label: string, icon: string, count: int, href?: string}>
     */
    #[Computed]
    public function leadCards(): array
    {
        /** @var User $user */
        $user = auth()->user();

        if (! $user->can('viewAny', Lead::class)) {
            return [];
        }

        $visible = fn (): Builder => Lead::query()->visibleTo($user);
        $todayStart = now(Lead::TIMEZONE)->startOfDay();

        $cards = [
            'leads_new' => ['inbox', $visible()->where('status', LeadStatus::New->value)->count(), ['tab' => 'all', 'status' => LeadStatus::New->value]],
            'leads_today' => ['clock', $visible()->where('created_at', '>=', $todayStart->copy()->utc())->count(), ['tab' => 'all', 'from' => $todayStart->format('Y-m-d')]],
            'leads_unassigned' => ['alert', $visible()->open()->whereNull('manager_id')->count(), ['manager' => 'none']],
            'leads_mine' => ['users', $visible()->open()->where('manager_id', $user->id)->count(), ['manager' => 'me']],
        ];

        $result = [];

        foreach ($cards as $key => [$icon, $count, $query]) {
            $result[] = [
                'key' => $key,
                'label' => __("crm.dashboard.cards.{$key}"),
                'icon' => $icon,
                'count' => $count,
                'href' => route('admin.leads.index', $query),
            ];
        }

        return $result;
    }

    /**
     * Days of failed Telegram alerts shown in the warning card.
     */
    public const TELEGRAM_FAILURE_DAYS = 7;

    /**
     * Failed Telegram lead alerts of the last TELEGRAM_FAILURE_DAYS days
     * (Admin only, settings.integrations): the count and the 5 latest leads.
     *
     * @return array{count: int, leads: Collection<int, Lead>}|null
     */
    #[Computed]
    public function telegramFailures(): ?array
    {
        /** @var User $user */
        $user = auth()->user();

        if (! $user->can('settings.integrations')) {
            return null;
        }

        $failed = fn () => Activity::query()
            ->where('subject_type', (new Lead)->getMorphClass())
            ->where('type', ActivityType::Notification->value)
            ->where('meta->result', 'failed')
            ->where('created_at', '>=', now()->subDays(self::TELEGRAM_FAILURE_DAYS));

        $count = $failed()->count();

        if ($count === 0) {
            return null;
        }

        $leadIds = $failed()->latest('id')->limit(50)->pluck('subject_id')->unique()->take(self::RECENT_LEADS)->values()->all();
        $leads = Lead::query()->whereKey($leadIds)->get(['id', 'name', 'company'])->sortBy(fn (Lead $lead): int => array_search($lead->id, $leadIds, true))->values();

        return ['count' => $count, 'leads' => $leads];
    }

    /**
     * Unresolved unmatched dynamic UzQR payments (plan 11e, condition 50):
     * money received that no order took yet; for payment viewers.
     */
    #[Computed]
    public function uzqrUnmatched(): int
    {
        /** @var User $user */
        $user = auth()->user();

        if (! $user->can('viewAny', OrderPayment::class)) {
            return 0;
        }

        return UzqrTransaction::query()->unresolvedUnmatched()
            ->when(! $user->can('payments.test'), fn ($query) => $query->whereHas('order', fn ($order) => $order->where('is_test', false)))
            ->count();
    }

    /**
     * @return Collection<int, Lead>
     */
    #[Computed]
    public function recentLeads(): Collection
    {
        /** @var User $user */
        $user = auth()->user();

        if (! $user->can('viewAny', Lead::class)) {
            return new Collection;
        }

        return Lead::query()->visibleTo($user)->with(['manager:id,name', 'items'])->latest('id')->limit(self::RECENT_LEADS)->get();
    }

    /**
     * @return list<array{key: string, label: string, icon: string, count: int, href?: string}>
     */
    #[Computed]
    public function cards(): array
    {
        /** @var User $user */
        $user = auth()->user();
        $cards = [];

        foreach (self::CARDS as $key => [$model, $icon]) {
            if (! $user->can('viewAny', $model)) {
                continue;
            }

            $cards[] = [
                'key' => $key,
                'label' => __("admin.dashboard.cards.{$key}"),
                'icon' => $icon,
                'count' => $model::query()->count(),
            ];
        }

        if ($user->can('viewAny', Product::class)) {
            $products = [
                // Published = active product in an active category (as on the site).
                'products_active' => ['box', Product::query()->active()->count(), ['tab' => 'active']],
                'products_drafts' => ['edit', Product::query()->where('is_active', false)->count(), ['tab' => 'drafts']],
                'products_incomplete' => ['alert', Product::query()->where('has_missing_specs', true)->count(), ['tab' => 'incomplete']],
            ];

            foreach ($products as $key => [$icon, $count, $query]) {
                $cards[] = [
                    'key' => $key,
                    'label' => __("admin.dashboard.cards.{$key}"),
                    'icon' => $icon,
                    'count' => $count,
                    'href' => route('admin.products.index', $query),
                ];
            }
        }

        return $cards;
    }

    public function render(): View
    {
        /** @var User $user */
        $user = auth()->user();

        return view('livewire.admin.dashboard', [
            'user' => $user,
            'role' => $user->staffRole(),
        ])->title(__('admin.dashboard.title'));
    }
}
