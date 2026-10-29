<?php

namespace App\Models;

use App\Enums\PriceLevel;
use App\Enums\QuoteStatus;
use App\Support\Search;
use App\Support\Vat;
use Carbon\CarbonImmutable;
use Database\Factories\QuoteFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Carbon;

/**
 * Quote / tijorat taklifi (plan 06). Written only through App\Actions\Quotes\*.
 *
 * Access (one rule for QuotePolicy and the list scopes):
 *  - quotes.viewAny + quotes.view; quotes.view-all (Admin) sees every quote;
 *  - otherwise a quote is the user's when they are its manager or the
 *    manager of its customer;
 *  - editing needs quotes.update on such a quote, and only draft / sent
 *    quotes change (QuoteStatus::isEditable()).
 *
 * Prices include VAT 12% (QQS): vat_amount is the VAT part of total.
 *
 * @property int $id
 * @property string $number
 * @property string $ulid
 * @property int $customer_id
 * @property int|null $lead_id
 * @property int|null $manager_id
 * @property int|null $created_by_id
 * @property QuoteStatus $status
 * @property PriceLevel $price_level
 * @property string $locale
 * @property CarbonImmutable $valid_until
 * @property int $subtotal
 * @property int $vat_amount
 * @property int $total
 * @property string|null $notes
 * @property Carbon|null $sent_at
 * @property Carbon|null $accepted_at
 * @property Carbon|null $status_changed_at
 * @property Carbon $created_at
 */
#[Fillable([
    'number', 'ulid', 'customer_id', 'lead_id', 'manager_id', 'created_by_id',
    'status', 'price_level', 'locale', 'valid_until',
    'subtotal', 'vat_amount', 'total', 'notes', 'sent_at', 'accepted_at', 'status_changed_at',
])]
class Quote extends Model
{
    /** @use HasFactory<QuoteFactory> */
    use HasFactory;

    /**
     * VAT (QQS) rate included in every price, percent.
     */
    public const VAT_PERCENT = Vat::PERCENT;

    /**
     * Working days (Mon–Fri) a new quote is valid by default.
     */
    public const VALID_WORKING_DAYS = 5;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => QuoteStatus::class,
            'price_level' => PriceLevel::class,
            'valid_until' => 'immutable_date',
            'manager_id' => 'integer',
            'customer_id' => 'integer',
            'subtotal' => 'integer',
            'vat_amount' => 'integer',
            'total' => 'integer',
            'sent_at' => 'datetime',
            'accepted_at' => 'datetime',
            'status_changed_at' => 'datetime',
        ];
    }

    /**
     * The VAT part of a VAT-inclusive amount (App\Support\Vat::of()).
     */
    public static function vatOf(int $total): int
    {
        return Vat::of($total);
    }

    /**
     * The quote's validity ended (by date, Tashkent day) or it was marked expired.
     */
    public function isExpired(): bool
    {
        return $this->status === QuoteStatus::Expired
            || $this->valid_until->lt(now(Lead::TIMEZONE)->startOfDay()->toDateString());
    }

    /**
     * @return BelongsTo<Customer, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /**
     * @return BelongsTo<Lead, $this>
     */
    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function manager(): BelongsTo
    {
        return $this->belongsTo(User::class, 'manager_id');
    }

    /**
     * @return HasMany<QuoteItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(QuoteItem::class)->orderBy('sort')->orderBy('id');
    }

    /**
     * @return MorphMany<Activity, $this>
     */
    public function activities(): MorphMany
    {
        return $this->morphMany(Activity::class, 'subject');
    }

    public function isVisibleTo(User $user): bool
    {
        if (! $user->can('quotes.viewAny') || ! $user->can('quotes.view')) {
            return false;
        }

        return $user->can('quotes.view-all') || $this->isOwnedBy($user);
    }

    public function isEditableBy(User $user): bool
    {
        return $user->can('quotes.update') && ($user->can('quotes.view-all') || $this->isOwnedBy($user));
    }

    /**
     * The user is the quote's manager or the manager of its customer.
     */
    public function isOwnedBy(User $user): bool
    {
        if ($this->manager_id === $user->id) {
            return true;
        }

        $customerManager = $this->relationLoaded('customer')
            ? $this->customer?->manager_id
            : Customer::query()->whereKey($this->customer_id)->value('manager_id');

        return $customerManager !== null && (int) $customerManager === $user->id;
    }

    /**
     * @param  Builder<self>  $query
     */
    public function scopeVisibleTo(Builder $query, User $user): void
    {
        if (! $user->can('quotes.viewAny') || ! $user->can('quotes.view')) {
            $query->whereRaw('1 = 0');

            return;
        }

        $this->applyOwnership($query, $user);
    }

    /**
     * @param  Builder<self>  $query
     */
    public function scopeEditableBy(Builder $query, User $user): void
    {
        if (! $user->can('quotes.update')) {
            $query->whereRaw('1 = 0');

            return;
        }

        $this->applyOwnership($query, $user);
    }

    /**
     * "Q-2026-0001", the customer's name (or part of either).
     *
     * @param  Builder<self>  $query
     */
    public function scopeSearch(Builder $query, string $term): void
    {
        $term = trim($term);

        if ($term === '') {
            return;
        }

        $pattern = Search::likePattern($term);

        $query->where(function (Builder $inner) use ($pattern): void {
            $inner->whereRaw("lower(quotes.number) like ? escape '!'", [$pattern])
                ->orWhereIn('quotes.customer_id', Customer::query()->select('id')->whereRaw("lower(customers.name) like ? escape '!'", [$pattern]));
        });
    }

    /**
     * @param  Builder<self>  $query
     */
    private function applyOwnership(Builder $query, User $user): void
    {
        if ($user->can('quotes.view-all')) {
            return;
        }

        $query->where(fn (Builder $inner) => $inner
            ->where('quotes.manager_id', $user->id)
            ->orWhereIn('quotes.customer_id', Customer::query()->select('id')->where('manager_id', $user->id)));
    }
}
