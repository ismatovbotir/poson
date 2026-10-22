<?php

namespace App\Models;

use App\Support\Phone;
use Database\Factories\ContactFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A person at a customer (plan 05; screens in 5b).
 *
 * @property int $id
 * @property int $customer_id
 * @property string $name
 * @property string|null $position
 * @property string|null $phone
 * @property string|null $email
 * @property string|null $telegram
 * @property bool $is_primary
 */
#[Fillable(['customer_id', 'name', 'position', 'phone', 'email', 'telegram', 'is_primary'])]
class Contact extends Model
{
    /** @use HasFactory<ContactFactory> */
    use HasFactory;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_primary' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<Customer, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /**
     * Contacts with this phone number (any input format).
     *
     * @param  Builder<self>  $query
     */
    public function scopeMatchingPhone(Builder $query, ?string $phone): void
    {
        $normalized = Phone::normalize($phone);

        $normalized === null ? $query->whereRaw('1 = 0') : $query->where('contacts.phone', $normalized);
    }
}
