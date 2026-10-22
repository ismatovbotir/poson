<?php

namespace App\Models;

use App\Enums\LeadLostReason;
use App\Enums\LeadSource;
use App\Enums\LeadStatus;
use App\Enums\LeadType;
use App\Enums\TrafficChannel;
use App\Support\Phone;
use App\Support\Search;
use Database\Factories\LeadFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Carbon;

/**
 * Lead / Murojaat (plan 05). Reference "L-{id}". Written only through
 * App\Actions\Leads\* (CreateLead for every channel).
 *
 * Access (plan 05 §5, decisions 2026-10-22), one rule for the policy and
 * the list scopes (isVisibleTo / visibleTo, isEditableBy / editableBy):
 *  - Sales types (callback, quote, checkout) need leads.sales, service
 *    requests need leads.service (LeadType::workPermission()).
 *  - With that permission a user sees and edits their own and unassigned
 *    leads of the type; leads.view-all (supervisor, Admin) widens both to
 *    every lead of the type.
 *  - leads.service-view: read-only view of every service request (sales managers).
 *  - Admins pass every check (Gate::before makes can() true).
 *
 * @property int $id
 * @property LeadSource $source
 * @property LeadType $type
 * @property LeadStatus $status
 * @property LeadLostReason|null $lost_reason
 * @property string|null $lost_note
 * @property string $name
 * @property string $phone
 * @property string|null $email
 * @property string|null $company
 * @property string|null $inn
 * @property string|null $message
 * @property int|null $customer_id
 * @property int|null $manager_id
 * @property int|null $created_by_id
 * @property int|null $duplicate_of_id
 * @property int|null $estimated_amount
 * @property string $locale
 * @property string|null $page_url
 * @property TrafficChannel|null $traffic_channel
 * @property string|null $traffic_source
 * @property string|null $landing_path
 * @property array<string, string>|null $utm
 * @property Carbon|null $status_changed_at
 * @property Carbon|null $closed_at
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
#[Fillable([
    'source', 'type', 'status', 'lost_reason', 'lost_note',
    'name', 'phone', 'email', 'company', 'inn', 'message',
    'customer_id', 'manager_id', 'created_by_id', 'duplicate_of_id', 'estimated_amount',
    'locale', 'page_url', 'traffic_channel', 'traffic_source', 'landing_path', 'utm',
    'status_changed_at', 'closed_at',
])]
class Lead extends Model
{
    /** @use HasFactory<LeadFactory> */
    use HasFactory;

    /**
     * Same phone within this many days → the new lead is flagged as a duplicate (decision 2026-10-22).
     */
    public const DUPLICATE_WINDOW_DAYS = 30;

    public const REFERENCE_PREFIX = 'L-';

    /**
     * Business day of the CRM screens ("today" counters, date filters, shown times).
     */
    public const TIMEZONE = 'Asia/Tashkent';

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'source' => LeadSource::class,
            'type' => LeadType::class,
            'status' => LeadStatus::class,
            'lost_reason' => LeadLostReason::class,
            'traffic_channel' => TrafficChannel::class,
            'manager_id' => 'integer',
            'estimated_amount' => 'integer',
            'utm' => 'array',
            'status_changed_at' => 'datetime',
            'closed_at' => 'datetime',
        ];
    }

    /**
     * "L-152".
     */
    public function reference(): string
    {
        return self::REFERENCE_PREFIX.$this->id;
    }

    public function isOpen(): bool
    {
        return $this->status->isOpen();
    }

    public function isAssignedTo(User $user): bool
    {
        return $this->manager_id === $user->id;
    }

    /**
     * @return BelongsTo<Customer, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function manager(): BelongsTo
    {
        return $this->belongsTo(User::class, 'manager_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_id');
    }

    /**
     * The earlier lead with the same phone this one duplicates.
     *
     * @return BelongsTo<Lead, $this>
     */
    public function duplicateOf(): BelongsTo
    {
        return $this->belongsTo(Lead::class, 'duplicate_of_id');
    }

    /**
     * @return HasMany<Lead, $this>
     */
    public function duplicates(): HasMany
    {
        return $this->hasMany(Lead::class, 'duplicate_of_id');
    }

    /**
     * @return HasMany<LeadItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(LeadItem::class)->orderBy('sort')->orderBy('id');
    }

    /**
     * @return MorphMany<Activity, $this>
     */
    public function activities(): MorphMany
    {
        return $this->morphMany(Activity::class, 'subject');
    }

    /**
     * Whether the user may see this lead (the policy's view rule; matches scopeVisibleTo).
     */
    public function isVisibleTo(User $user): bool
    {
        if (! $user->can('leads.viewAny') || ! $user->can('leads.view')) {
            return false;
        }

        if ($this->type->isService() && $user->can('leads.service-view')) {
            return true;
        }

        return $this->isInWorkScopeOf($user);
    }

    /**
     * Whether the user may change this lead (matches scopeEditableBy).
     */
    public function isEditableBy(User $user): bool
    {
        return $user->can('leads.update') && $this->isInWorkScopeOf($user);
    }

    /**
     * The user works with this lead's type and the lead is theirs, unassigned, or they see all.
     */
    public function isInWorkScopeOf(User $user): bool
    {
        if (! $user->can($this->type->workPermission())) {
            return false;
        }

        return $this->manager_id === null || $this->manager_id === $user->id || $user->can('leads.view-all');
    }

    /**
     * Leads the user may see (index screens, counters, badges).
     *
     * @param  Builder<self>  $query
     */
    public function scopeVisibleTo(Builder $query, User $user): void
    {
        if (! $user->can('leads.viewAny') || ! $user->can('leads.view')) {
            $query->whereRaw('1 = 0');

            return;
        }

        $this->applyWorkScope($query, $user, $user->can('leads.service-view'));
    }

    /**
     * Leads the user may change.
     *
     * @param  Builder<self>  $query
     */
    public function scopeEditableBy(Builder $query, User $user): void
    {
        if (! $user->can('leads.update')) {
            $query->whereRaw('1 = 0');

            return;
        }

        $this->applyWorkScope($query, $user, false);
    }

    /**
     * @param  Builder<self>  $query
     */
    public function scopeOpen(Builder $query): void
    {
        $query->whereIn('leads.status', LeadStatus::valuesOf(LeadStatus::open()));
    }

    /**
     * @param  Builder<self>  $query
     */
    public function scopeClosed(Builder $query): void
    {
        $query->whereIn('leads.status', LeadStatus::valuesOf(LeadStatus::closed()));
    }

    /**
     * Leads with this phone number (any input format).
     *
     * @param  Builder<self>  $query
     */
    public function scopeMatchingPhone(Builder $query, ?string $phone): void
    {
        $normalized = Phone::normalize($phone);

        $normalized === null ? $query->whereRaw('1 = 0') : $query->where('leads.phone', $normalized);
    }

    /**
     * Admin search: "L-123" / "#123" finds the lead by reference; otherwise
     * name, company or email contain the term, the phone contains its
     * digits (at least 3, so "90 123" finds "+998901234567"), or a 9-digit
     * term equals the INN.
     *
     * @param  Builder<self>  $query
     */
    public function scopeSearch(Builder $query, string $term): void
    {
        $term = trim($term);

        if ($term === '') {
            return;
        }

        if (preg_match('/^(?:L-?|#)\s*(\d{1,18})$/i', $term, $matches)) {
            $query->whereKey((int) $matches[1]);

            return;
        }

        $pattern = Search::likePattern($term);
        $digits = (string) preg_replace('/\D+/', '', $term);

        $innOnly = (bool) preg_match('/^\d{9}$/', $term);

        $query->where(function (Builder $inner) use ($pattern, $digits, $innOnly): void {
            $inner->whereRaw("lower(leads.name) like ? escape '!'", [$pattern])
                ->orWhereRaw("lower(leads.company) like ? escape '!'", [$pattern])
                ->orWhereRaw("lower(leads.email) like ? escape '!'", [$pattern]);

            if (strlen($digits) >= 3) {
                $inner->orWhere('leads.phone', 'like', '%'.$digits.'%');
            }

            if ($innOnly) {
                $inner->orWhere('leads.inn', $digits);
            }
        });
    }

    /**
     * Sales types the user works with + service requests (own and unassigned,
     * or all with leads.view-all; every service request with $allService).
     *
     * @param  Builder<self>  $query
     */
    private function applyWorkScope(Builder $query, User $user, bool $allService): void
    {
        $sales = $user->can('leads.sales');
        $service = $user->can('leads.service');
        $all = $user->can('leads.view-all');

        if (! $sales && ! $service && ! $allService) {
            $query->whereRaw('1 = 0');

            return;
        }

        $ownOrUnassigned = fn (Builder $inner) => $inner->whereNull('leads.manager_id')->orWhere('leads.manager_id', $user->id);

        $query->where(function (Builder $scope) use ($sales, $service, $all, $allService, $ownOrUnassigned): void {
            if ($sales) {
                $scope->orWhere(function (Builder $inner) use ($all, $ownOrUnassigned): void {
                    $inner->whereIn('leads.type', LeadType::salesValues());

                    if (! $all) {
                        $inner->where($ownOrUnassigned);
                    }
                });
            }

            if ($allService || ($service && $all)) {
                $scope->orWhere('leads.type', LeadType::ServiceRequest->value);
            } elseif ($service) {
                $scope->orWhere(fn (Builder $inner) => $inner->where('leads.type', LeadType::ServiceRequest->value)->where($ownOrUnassigned));
            }
        });
    }
}
