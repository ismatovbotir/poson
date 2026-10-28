<?php

namespace App\Models;

use Database\Factories\TrafficDailyFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Daily traffic roll-up row (see the create migration for the dimensions),
 * rebuilt by App\Actions\Analytics\AggregateTraffic, kept 25 months.
 *
 * @property int $id
 * @property string $date Y-m-d (kept as a string: a date cast would store a time on SQLite)
 * @property string $kind
 * @property string $dimension
 * @property string $channel
 * @property string $value
 * @property int $visitors
 * @property int $page_views
 * @property int $entries
 * @property int $leads
 * @property int $partner_clicks
 * @property int $add_to_carts
 * @property int $hits
 * @property int $verified_hits
 * @property int $spoofed_hits
 * @property int $errors_4xx
 * @property int $errors_5xx
 * @property Carbon|null $last_seen_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['date', 'kind', 'dimension', 'channel', 'value', 'visitors', 'page_views', 'entries', 'leads', 'partner_clicks', 'add_to_carts', 'hits', 'verified_hits', 'spoofed_hits', 'errors_4xx', 'errors_5xx', 'last_seen_at'])]
class TrafficDaily extends Model
{
    /** @use HasFactory<TrafficDailyFactory> */
    use HasFactory;

    public const KIND_VISITS = 'visits';

    public const KIND_BOTS = 'bots';

    /**
     * traffic_daily_pages only: partner clicks per /{locale}/go/{slug} path.
     */
    public const KIND_PARTNER = 'partner';

    public const DIM_TOTAL = 'total';

    public const DIM_CHANNEL = 'channel';

    public const DIM_SOURCE = 'source';

    public const DIM_DEVICE = 'device';

    public const DIM_LOCALE = 'locale';

    public const DIM_BOT = 'bot';

    public const CREATED_AT = null;

    protected $table = 'traffic_daily';

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'last_seen_at' => 'datetime',
            'visitors' => 'integer',
            'page_views' => 'integer',
            'entries' => 'integer',
            'leads' => 'integer',
            'partner_clicks' => 'integer',
            'add_to_carts' => 'integer',
            'hits' => 'integer',
            'verified_hits' => 'integer',
            'spoofed_hits' => 'integer',
            'errors_4xx' => 'integer',
            'errors_5xx' => 'integer',
        ];
    }
}
