<?php

namespace App\Livewire\Admin\Ai;

use App\Enums\AiFeature;
use App\Enums\AiRequestStatus;
use App\Livewire\Admin\Concerns\WithAdminPagination;
use App\Models\AiRequest;
use App\Models\User;
use App\Services\Ai\AiAssist;
use App\Services\Ai\AiQuota;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * SI yordamchi → Foydalanish va limitlar (plan 02, A5; Admin only via
 * ai-requests.viewAny). Today and this month (Tashkent time) per user,
 * the monthly budget, and the request log with filters. Prompt and response
 * texts are loaded only for the expanded row.
 */
#[Layout('layouts::admin')]
class Usage extends Component
{
    use WithAdminPagination;

    public const PER_PAGE = 25;

    public const FILTERS = ['user', 'status', 'feature'];

    #[Url(except: '')]
    public string $user = '';

    #[Url(except: '')]
    public string $status = '';

    #[Url(except: '')]
    public string $feature = '';

    /**
     * Expanded row (shows its prompt and response).
     */
    #[Locked]
    public ?int $expanded = null;

    public function mount(): void
    {
        $this->authorize('viewAny', AiRequest::class);
    }

    public function updated(string $property): void
    {
        if (in_array($property, self::FILTERS, true)) {
            $this->expanded = null;
            $this->resetPage();
        }
    }

    public function clearFilters(): void
    {
        $this->reset(...self::FILTERS);
        $this->resetPage();
    }

    public function toggle(int $id): void
    {
        $this->authorize('viewAny', AiRequest::class);
        $this->expanded = $this->expanded === $id ? null : $id;
        unset($this->expandedRequest);
    }

    /**
     * Today / this month per user: requests, tokens and cost.
     *
     * @return Collection<int, object{user_id: int|null, name: string, today: int, month: int, tokens: int, cost: int}>
     */
    #[Computed]
    public function perUser(): Collection
    {
        $today = AiQuota::startOfDay()->utc();

        $rows = AiRequest::query()
            ->toBase()
            ->select('user_id')
            ->selectRaw('SUM(CASE WHEN created_at >= ? AND status <> ? THEN 1 ELSE 0 END) AS today', [$today, AiRequestStatus::Rejected->value])
            ->selectRaw('SUM(CASE WHEN status <> ? THEN 1 ELSE 0 END) AS month', [AiRequestStatus::Rejected->value])
            ->selectRaw('SUM(input_tokens + output_tokens) AS tokens')
            ->selectRaw('SUM(cost_micro_usd) AS cost')
            ->where('created_at', '>=', AiQuota::startOfMonth()->utc())
            ->groupBy('user_id')
            ->get();

        $names = User::query()->whereIn('id', $rows->pluck('user_id')->filter())->pluck('name', 'id');

        return $rows
            ->map(fn (object $row): object => (object) [
                'user_id' => $row->user_id !== null ? (int) $row->user_id : null,
                'name' => $row->user_id !== null ? (string) ($names[$row->user_id] ?? '#'.$row->user_id) : __('ai.usage.deleted_user'),
                'today' => (int) $row->today,
                'month' => (int) $row->month,
                'tokens' => (int) $row->tokens,
                'cost' => (int) $row->cost,
            ])
            ->sortByDesc('cost')
            ->values();
    }

    /**
     * @return array<int, string>
     */
    #[Computed]
    public function userOptions(): array
    {
        return User::query()->whereIn('id', AiRequest::query()->whereNotNull('user_id')->select('user_id'))->orderBy('name')->pluck('name', 'id')->all();
    }

    #[Computed]
    public function expandedRequest(): ?AiRequest
    {
        return $this->expanded !== null ? AiRequest::query()->find($this->expanded) : null;
    }

    public function render(): View
    {
        // Every request, not only mount: a revoked permission takes effect at once.
        $this->authorize('viewAny', AiRequest::class);

        $requests = AiRequest::query()
            ->select(['id', 'user_id', 'feature', 'provider', 'model', 'field_key', 'source_locale', 'status', 'error_code', 'brand_warnings', 'input_tokens', 'output_tokens', 'cost_micro_usd', 'duration_ms', 'created_at'])
            ->when(ctype_digit($this->user), fn (Builder $query) => $query->where('user_id', (int) $this->user))
            ->when(AiRequestStatus::tryFrom($this->status) !== null, fn (Builder $query) => $query->where('status', $this->status))
            ->when(AiFeature::tryFrom($this->feature) !== null, fn (Builder $query) => $query->where('feature', $this->feature))
            ->with('user:id,name')
            ->latest('id')
            ->paginate(self::PER_PAGE);

        $perUser = $this->perUser;

        return view('livewire.admin.ai.usage', [
            'requests' => $requests,
            'perUser' => $perUser,
            'todayTotal' => $perUser->sum('today'),
            'monthTotal' => $perUser->sum('month'),
            'monthCost' => (int) $perUser->sum('cost'),
            'budget' => AiQuota::budgetMicroUsd(),
            'dailyLimit' => AiQuota::dailyLimit(),
            'enabled' => AiAssist::isEnabled(),
            'configured' => AiAssist::isConfigured(),
            'filtering' => $this->user !== '' || $this->status !== '' || $this->feature !== '',
            'timezone' => AiQuota::timezone(),
        ])->title(__('ai.usage.title'));
    }

    /**
     * US dollars for display (4 decimals below one cent).
     */
    public static function usd(int $microUsd): string
    {
        $usd = $microUsd / AiRequest::MICRO;

        return '$'.number_format($usd, $usd > 0 && $usd < 0.01 ? 4 : 2, '.', ' ');
    }
}
