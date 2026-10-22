<?php

namespace App\Models;

use App\Support\Analytics\BotDefinition;
use App\Support\Analytics\BotRegistry;
use App\Support\Analytics\IpAddress;
use Database\Factories\BotHitFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Raw crawler request (plan 04 4c), kept 14 days. ip is packed (inet_pton);
 * verified: null = not checked, true = genuine, false = spoofed.
 *
 * @property int $id
 * @property Carbon $occurred_at
 * @property string $bot
 * @property string|null $ip
 * @property bool|null $verified
 * @property string|null $route_name
 * @property string $path
 * @property int|null $product_id
 * @property int|null $category_id
 * @property int $status
 */
#[Fillable(['occurred_at', 'bot', 'ip', 'verified', 'route_name', 'path', 'product_id', 'category_id', 'status'])]
class BotHit extends Model
{
    /** @use HasFactory<BotHitFactory> */
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
            'verified' => 'boolean',
            'product_id' => 'integer',
            'category_id' => 'integer',
            'status' => 'integer',
        ];
    }

    /**
     * The IP in text form (for verification and debugging, never shown for people).
     */
    public function ipAddress(): ?string
    {
        return IpAddress::unpack($this->ip);
    }

    public function definition(): ?BotDefinition
    {
        return BotRegistry::find($this->bot);
    }
}
