<?php

namespace App\Models;

use App\Enums\ChatAction;
use App\Models\Concerns\StoresTranslations;
use Database\Factories\ChatRuleFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Translatable\HasTranslations;

/**
 * One rule of the chat bot (plan 28): keywords lead to an action. The answer
 * is plain text per locale (uz required for the "answer" action). "buttons"
 * is a list of {label: {uz, ru, en}, rule_id?: int, url?: string}.
 *
 * Written only through SaveChatRule / DeleteChatRule, which validate params,
 * normalize keywords and bump the rule cache (RuleIndex). Never exposed to
 * MCP. A system rule (chatbot:install-defaults) keeps its key and action and
 * cannot be deleted.
 *
 * @property int $id
 * @property string $key
 * @property string $name
 * @property ChatAction $action
 * @property array<string, mixed>|null $params
 * @property array<int, array<string, mixed>>|null $buttons
 * @property bool $on_web
 * @property bool $on_telegram
 * @property bool $in_menu
 * @property int $menu_sort
 * @property int $priority
 * @property bool $is_active
 * @property bool $is_system
 * @property int|null $created_by_id
 * @property int|null $updated_by_id
 */
#[Fillable([
    'key', 'name', 'action', 'params', 'answer', 'buttons', 'on_web', 'on_telegram',
    'in_menu', 'menu_sort', 'priority', 'is_active', 'is_system', 'created_by_id', 'updated_by_id',
])]
class ChatRule extends Model
{
    /** @use HasFactory<ChatRuleFactory> */
    use HasFactory, HasTranslations, StoresTranslations;

    /**
     * @var list<string>
     */
    public array $translatable = ['answer'];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'action' => ChatAction::class,
            'params' => 'array',
            'buttons' => 'array',
            'on_web' => 'boolean',
            'on_telegram' => 'boolean',
            'in_menu' => 'boolean',
            'menu_sort' => 'integer',
            'priority' => 'integer',
            'is_active' => 'boolean',
            'is_system' => 'boolean',
        ];
    }

    /**
     * @return HasMany<ChatRuleKeyword, $this>
     */
    public function keywords(): HasMany
    {
        return $this->hasMany(ChatRuleKeyword::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by_id');
    }
}
