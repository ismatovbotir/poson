<?php

namespace App\Livewire\Admin\PriceSync;

use App\Enums\ApiTokenType;
use App\Enums\PriceSyncStatus;
use App\Livewire\Admin\Concerns\WithAdminPagination;
use App\Models\ApiToken;
use App\Models\PriceSyncLog;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Throwable;

/**
 * Catalog → Price sync (/admin/price-sync, plan 09): the price API batch
 * log, newest first, filtered by token, status and date (Tashkent days).
 * price-sync.view (Admin only), checked on every request.
 */
#[Layout('layouts::admin')]
class Index extends Component
{
    use WithAdminPagination;

    public const PER_PAGE = 25;

    public const FILTERS = ['token', 'status', 'from', 'to'];

    #[Url(except: '')]
    public string $token = '';

    #[Url(except: '')]
    public string $status = '';

    /**
     * Y-m-d (Tashkent), inclusive.
     */
    #[Url(except: '')]
    public string $from = '';

    #[Url(except: '')]
    public string $to = '';

    public function boot(): void
    {
        $this->authorize('viewAny', PriceSyncLog::class);
    }

    public function updated(string $property): void
    {
        if (in_array($property, self::FILTERS, true)) {
            $this->resetPage();
        }
    }

    public function clearFilters(): void
    {
        $this->reset(...self::FILTERS);
        $this->resetPage();
    }

    /**
     * @return array<int, string>
     */
    #[Computed]
    public function tokenOptions(): array
    {
        return ApiToken::query()->ofType(ApiTokenType::PriceApi)->orderBy('name')->pluck('name', 'id')->all();
    }

    public function render(): View
    {
        $logs = PriceSyncLog::query()
            ->select(['id', 'api_token_id', 'token_name', 'ip', 'status', 'received', 'updated', 'unchanged', 'unknown', 'invalid', 'error', 'duration_ms', 'created_at'])
            ->when(ctype_digit($this->token), fn (Builder $query) => $query->where('api_token_id', (int) $this->token))
            ->when(PriceSyncStatus::tryFrom($this->status) !== null, fn (Builder $query) => $query->where('status', $this->status))
            ->when($this->day($this->from), fn (Builder $query, Carbon $day) => $query->where('created_at', '>=', $day->startOfDay()->utc()))
            ->when($this->day($this->to), fn (Builder $query, Carbon $day) => $query->where('created_at', '<=', $day->endOfDay()->utc()))
            ->latest('id')
            ->paginate(self::PER_PAGE);

        return view('livewire.admin.price-sync.index', [
            'logs' => $logs,
            'filtering' => $this->token !== '' || $this->status !== '' || $this->from !== '' || $this->to !== '',
        ])->title(__('api.sync.title'));
    }

    private function day(string $value): ?Carbon
    {
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return null;
        }

        try {
            return Carbon::createFromFormat('Y-m-d', $value, PriceSyncLog::TIMEZONE) ?: null;
        } catch (Throwable) {
            return null;
        }
    }
}
