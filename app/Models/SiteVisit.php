<?php

namespace App\Models;

use App\Enums\DeviceType;
use App\Enums\TrafficChannel;
use App\Enums\VisitType;
use Database\Factories\SiteVisitFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Raw human page view or lead (plan 04 4c), kept 30 days. Written with the
 * query builder by App\Support\Analytics\TrafficRecorder; the model is for
 * reading and tests. No IP, only a daily-salted visitor hash.
 *
 * @property int $id
 * @property Carbon $occurred_at
 * @property VisitType $type
 * @property string $visitor_hash
 * @property bool $is_entry
 * @property TrafficChannel $channel
 * @property string|null $source
 * @property string|null $utm_medium
 * @property string|null $utm_campaign
 * @property string|null $referrer_host
 * @property string|null $route_name
 * @property string $path
 * @property string|null $locale
 * @property int|null $product_id
 * @property int|null $category_id
 * @property DeviceType $device
 * @property int $status
 */
#[Fillable(['occurred_at', 'type', 'visitor_hash', 'is_entry', 'channel', 'source', 'utm_medium', 'utm_campaign', 'referrer_host', 'route_name', 'path', 'locale', 'product_id', 'category_id', 'device', 'status'])]
class SiteVisit extends Model
{
    /** @use HasFactory<SiteVisitFactory> */
    use HasFactory;

    public $timestamps = false;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'occurred_at' => 'datetime',
            'type' => VisitType::class,
            'is_entry' => 'boolean',
            'channel' => TrafficChannel::class,
            'device' => DeviceType::class,
            'product_id' => 'integer',
            'category_id' => 'integer',
            'status' => 'integer',
        ];
    }
}
