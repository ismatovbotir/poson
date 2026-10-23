<?php

namespace App\Models;

use App\Enums\CustomerType;
use App\Enums\PriceLevel;
use App\Support\Phone;
use App\Support\Search;
use Database\Factories\CustomerFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * CRM customer (plan 05; screens in 5b). Leads match a customer by phone
 * (the customer's own or one of its contacts', CreateLead). price_level is
 * changed by Admin only (customers.price-level).
 *
 * @property int $id
 * @property CustomerType $type
 * @property string $name
 * @property string|null $inn
 * @property string|null $phone
 * @property string|null $email
 * @property string|null $address
 * @property string|null $notes
 * @property PriceLevel $price_level
 * @property int|null $manager_id
 * @property int|null $telegram_id
 * @property string $locale
 */
#[Fillable(['type', 'name', 'inn', 'phone', 'email', 'address', 'notes', 'price_level', 'manager_id', 'telegram_id', 'locale'])]
class Customer extends Model
{
    /** @use HasFactory<CustomerFactory> */
    use HasFactory;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => CustomerType::class,
            'price_level' => PriceLevel::class,
            'manager_id' => 'integer',
            'telegram_id' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function manager(): BelongsTo
    {
        return $this->belongsTo(User::class, 'manager_id');
    }

    /**
     * @return HasMany<Contact, $this>
     */
    public function contacts(): HasMany
    {
        return $this->hasMany(Contact::class);
    }

    /**
     * @return HasOne<Contact, $this>
     */
    public function primaryContact(): HasOne
    {
        return $this->hasOne(Contact::class)->where('is_primary', true);
    }

    /**
     * @return HasMany<Lead, $this>
     */
    public function leads(): HasMany
    {
        return $this->hasMany(Lead::class);
    }

    /**
     * @return HasMany<Quote, $this>
     */
    public function quotes(): HasMany
    {
        return $this->hasMany(Quote::class);
    }

    /**
     * @return MorphMany<Activity, $this>
     */
    public function activities(): MorphMany
    {
        return $this->morphMany(Activity::class, 'subject');
    }

    /**
     * Admin search: name contains the term, a 9-digit term equals the INN,
     * or the customer's or a contact's phone contains its digits (at least 3).
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
        $innOnly = (bool) preg_match('/^\d{9}$/', $term);

        $query->where(function (Builder $inner) use ($pattern, $digits, $innOnly): void {
            $inner->whereRaw("lower(customers.name) like ? escape '!'", [$pattern])
                ->orWhereRaw("lower(customers.email) like ? escape '!'", [$pattern]);

            if ($innOnly) {
                $inner->orWhere('customers.inn', $digits);
            }

            if (strlen($digits) >= 3) {
                $inner->orWhere('customers.phone', 'like', '%'.$digits.'%')
                    ->orWhereIn('customers.id', Contact::query()->select('customer_id')->where('phone', 'like', '%'.$digits.'%'));
            }
        });
    }

    /**
     * Customers whose own phone is this number (any input format).
     *
     * @param  Builder<self>  $query
     */
    public function scopeMatchingPhone(Builder $query, ?string $phone): void
    {
        $normalized = Phone::normalize($phone);

        $normalized === null ? $query->whereRaw('1 = 0') : $query->where('customers.phone', $normalized);
    }

    /**
     * Customers the user may see (customers.viewAny + view: every customer, plan 05 §5).
     *
     * @param  Builder<self>  $query
     */
    public function scopeVisibleTo(Builder $query, User $user): void
    {
        if (! $user->can('customers.viewAny') || ! $user->can('customers.view')) {
            $query->whereRaw('1 = 0');
        }
    }

    /**
     * Customers the user may edit: all with customers.update-any, else their
     * own and unassigned ones with customers.update.
     *
     * @param  Builder<self>  $query
     */
    public function scopeEditableBy(Builder $query, User $user): void
    {
        if ($user->can('customers.update-any')) {
            return;
        }

        if (! $user->can('customers.update')) {
            $query->whereRaw('1 = 0');

            return;
        }

        $query->where(fn (Builder $inner) => $inner->whereNull('customers.manager_id')->orWhere('customers.manager_id', $user->id));
    }
}
