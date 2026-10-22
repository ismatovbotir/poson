<?php

namespace App\Models;

use Database\Factories\TrafficDailyPageFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One of the top pages of a day (per kind, and per bot for crawlers),
 * rebuilt by App\Actions\Analytics\AggregateTraffic, kept 25 months.
 *
 * @property int $id
 * @property string $date Y-m-d (kept as a string: a date cast would store a time on SQLite)
 * @property string $kind
 * @property string $bot
 * @property string $path
 * @property int $status
 * @property string|null $route_name
 * @property int|null $product_id
 * @property int|null $category_id
 * @property int $hits
 * @property int $visitors
 * @property Carbon|null $updated_at
 */
#[Fillable(['date', 'kind', 'bot', 'path', 'status', 'route_name', 'product_id', 'category_id', 'hits', 'visitors'])]
class TrafficDailyPage extends Model
{
    /** @use HasFactory<TrafficDailyPageFactory> */
    use HasFactory;

    public const CREATED_AT = null;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => 'integer',
            'product_id' => 'integer',
            'category_id' => 'integer',
            'hits' => 'integer',
            'visitors' => 'integer',
        ];
    }

    /**
     * Product of the page (withTrashed: the report outlives a deletion).
     *
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class)->withTrashed();
    }
}
