<?php

namespace App\Models;

use App\Enums\RepairCoverage;
use App\Enums\RepairDeviceType;
use App\Enums\RepairStatus;
use App\Support\Phone;
use App\Support\Repairs\RepairAccess;
use App\Support\Search;
use Database\Factories\RepairFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Carbon;

/**
 * A device in for service (plan 24 B1), reference "R-2026-0001". Written only
 * through App\Actions\Repairs\*. Never deleted: cancel it. Amounts are tiyin
 * and shown only with repairs.costs.
 *
 * Access is RepairAccess (one rule for policy and scopes): own + unassigned,
 * or everything with repairs.view-all (read-only) / for Admin.
 *
 * @property int $id
 * @property string $number
 * @property RepairStatus $status
 * @property string $locale
 * @property int|null $lead_id
 * @property int|null $customer_id
 * @property int|null $contact_id
 * @property string $contact_name
 * @property string $contact_phone
 * @property string|null $company
 * @property RepairDeviceType $device_type
 * @property string|null $device_brand
 * @property string|null $device_model
 * @property string|null $serial_number
 * @property string|null $accessories
 * @property string $intake_condition
 * @property string $problem
 * @property string|null $diagnosis
 * @property string|null $work_done
 * @property string|null $parts_used
 * @property RepairCoverage $coverage
 * @property string|null $warranty_reference
 * @property int|null $estimate_amount
 * @property int|null $final_amount
 * @property Carbon|null $estimate_approved_at
 * @property int|null $assigned_to_id
 * @property int|null $created_by_id
 * @property Carbon|null $received_at
 * @property Carbon|null $status_changed_at
 * @property Carbon|null $closed_at
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
#[Fillable([
    'number', 'status', 'locale', 'lead_id', 'customer_id', 'contact_id',
    'contact_name', 'contact_phone', 'company',
    'device_type', 'device_brand', 'device_model', 'serial_number', 'accessories',
    'intake_condition', 'problem', 'diagnosis', 'work_done', 'parts_used',
    'coverage', 'warranty_reference', 'estimate_amount', 'final_amount', 'estimate_approved_at',
    'assigned_to_id', 'created_by_id', 'received_at', 'status_changed_at', 'closed_at',
])]
class Repair extends Model
{
    /** @use HasFactory<RepairFactory> */
    use HasFactory;

    /**
     * Longest text field (staff forms).
     */
    public const TEXT_MAX = 5000;

    /**
     * Upper cap for an amount in whole soʻm (10 billion).
     */
    public const AMOUNT_MAX_SUMS = 10_000_000_000;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => RepairStatus::class,
            'device_type' => RepairDeviceType::class,
            'coverage' => RepairCoverage::class,
            'estimate_amount' => 'integer',
            'final_amount' => 'integer',
            'assigned_to_id' => 'integer',
            'estimate_approved_at' => 'datetime',
            'received_at' => 'datetime',
            'status_changed_at' => 'datetime',
            'closed_at' => 'datetime',
        ];
    }

    public function isOpen(): bool
    {
        return $this->status->isOpen();
    }

    public function isAssignedTo(User $user): bool
    {
        return $this->assigned_to_id === $user->id;
    }

    /**
     * Whether the user may see this repair (the policy's view rule; matches scopeVisibleTo).
     */
    public function isVisibleTo(User $user): bool
    {
        return RepairAccess::allows(RepairAccess::viewLevel($user), $this->assigned_to_id, $user);
    }

    /**
     * Whether the user may change this repair (matches scopeEditableBy).
     */
    public function isEditableBy(User $user): bool
    {
        return RepairAccess::allows(RepairAccess::editLevel($user), $this->assigned_to_id, $user);
    }

    /**
     * @return BelongsTo<Lead, $this>
     */
    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }

    /**
     * @return BelongsTo<Customer, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /**
     * @return BelongsTo<Contact, $this>
     */
    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function assignedTo(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_id');
    }

    /**
     * @return MorphMany<Activity, $this>
     */
    public function activities(): MorphMany
    {
        return $this->morphMany(Activity::class, 'subject');
    }

    /**
     * Repairs the user may see (lists, panels, search, counters).
     *
     * @param  Builder<self>  $query
     */
    public function scopeVisibleTo(Builder $query, User $user): void
    {
        RepairAccess::constrain($query, RepairAccess::viewLevel($user), $user);
    }

    /**
     * Repairs the user may change.
     *
     * @param  Builder<self>  $query
     */
    public function scopeEditableBy(Builder $query, User $user): void
    {
        RepairAccess::constrain($query, RepairAccess::editLevel($user), $user);
    }

    /**
     * @param  Builder<self>  $query
     */
    public function scopeOpen(Builder $query): void
    {
        $query->whereIn('repairs.status', RepairStatus::valuesOf(RepairStatus::open()));
    }

    /**
     * @param  Builder<self>  $query
     */
    public function scopeClosed(Builder $query): void
    {
        $query->whereIn('repairs.status', RepairStatus::valuesOf(RepairStatus::closed()));
    }

    /**
     * Admin search: the number ("R-2026-0001" or "2026-0001"), the phone (its
     * digits, at least 3) or the serial number. Wildcards in the term are
     * escaped (Search::likePattern); run this inside visibleTo().
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
        $digits = (string) preg_replace('/\D+/', '', $term);

        $query->where(function (Builder $inner) use ($pattern, $digits): void {
            $inner->whereRaw("lower(repairs.number) like ? escape '!'", [$pattern])
                ->orWhereRaw("lower(repairs.serial_number) like ? escape '!'", [$pattern]);

            if (strlen($digits) >= 3) {
                $inner->orWhere('repairs.contact_phone', 'like', '%'.$digits.'%');
            }
        });
    }

    /**
     * Repairs with this phone number (any input format).
     *
     * @param  Builder<self>  $query
     */
    public function scopeMatchingPhone(Builder $query, ?string $phone): void
    {
        $normalized = Phone::normalizeAny($phone);

        $normalized === null ? $query->whereRaw('1 = 0') : $query->where('repairs.contact_phone', $normalized);
    }
}
