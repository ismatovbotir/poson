<?php

use App\Support\Chat\ChatSettings;
use App\Support\Chat\RuleIndex;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Plan 36 I4 (P36-18, LOW-11): chat bot hand-off target per rule.
 *
 * Data only, idempotent, and written with the query builder and frozen literals
 * (no SaveChatRule, no seeder file): a rule is changed only while its stored
 * action, params and normalized keywords still equal the values shipped by
 * chatbot_defaults.php, otherwise the owner edited it and it is skipped (the
 * key is logged, nothing else). A fresh or test database has no system rules,
 * and the installer creates everything from the updated defaults, so this is
 * then a no-op.
 *
 *  - repair_intake gets {handoff_target: service}; availability, dealer and
 *    complaint get {handoff_target: sales} (so a later global "service" never
 *    moves them);
 *  - the setup / integration keywords leave warranty_service, then the system
 *    rule setup_integration (lead dialog, service flow, priority above
 *    warranty_service and integration_1c) is created when its key is missing.
 *    "1c" stays with integration_1c.
 *
 * The rule cache version is bumped after commit.
 *
 * Keyword changes of the defaults file made after this was frozen (repair_intake fault phrases, label_ribbon_fit
 * guards, product_search stems, heavier warranty_service weights) are install-only. Production: repair_intake and
 * label_ribbon_fit are created fresh by install-defaults; product_search and warranty_service are edited by the
 * owner in Admin → Chat bot. Stored phrases carry no trailing "*" (the installer strips it).
 */
return new class extends Migration
{
    private const SETUP_KEY = 'setup_integration';

    /**
     * Frozen shipped keywords as "match_type|normalized" (all weight 10, any locale).
     *
     * @var array<string, list<string>>
     */
    private const SHIPPED_KEYWORDS = [
        'repair_intake' => ['word|buzildi', 'word|ishlamayapti', 'phrase|ne rabotaet', 'prefix|slomal', 'phrase|not working'],
        'availability' => ['prefix|omborda bor', 'word|mavjudmi', 'phrase|v nalichii', 'word|nalichie', 'phrase|in stock', 'word|availability'],
        'dealer' => [
            'prefix|diler', 'prefix|dealer', 'word|reseller', 'phrase|qayta sotuvchi', 'prefix|pereprodaj',
            'prefix|integrator', 'word|ulgurji', 'prefix|optov', 'word|optom', 'word|wholesale',
        ],
        'complaint' => [
            'prefix|shikoyat', 'prefix|pretenziya', 'prefix|qaytarish', 'prefix|jalob', 'prefix|pretenzi',
            'prefix|vozvrat', 'prefix|complaint', 'prefix|refund', 'prefix|return',
        ],
        'warranty_service' => [
            'prefix|kafolat', 'prefix|garantiy', 'prefix|garanti', 'word|warranty', 'word|guarantee', 'prefix|servis', 'word|service',
            'prefix|remont', 'prefix|tamir', 'word|repair', 'prefix|sozlash', 'prefix|sozlab', 'prefix|nastroyk', 'word|nastroit',
            'prefix|ornatish', 'prefix|ustanovk', 'word|setup', 'prefix|integratsiya', 'prefix|integrats',
        ],
    ];

    /**
     * What warranty_service loses: its setup / integration keywords.
     *
     * @var list<string>
     */
    private const MOVED_KEYWORDS = [
        'prefix|sozlash', 'prefix|sozlab', 'prefix|nastroyk', 'word|nastroit', 'prefix|ornatish',
        'prefix|ustanovk', 'word|setup', 'prefix|integratsiya', 'prefix|integrats',
    ];

    private const WARRANTY_LABEL = ['uz' => 'Kafolat va servis', 'ru' => 'Гарантия и сервис', 'en' => 'Warranty and service'];

    /**
     * The original shipped phrases of the keywords warranty_service loses, for down().
     *
     * @var array<string, string>
     */
    private const MOVED_PHRASES = [
        'prefix|sozlash' => 'sozlash', 'prefix|sozlab' => 'sozlab', 'prefix|nastroyk' => 'настройк', 'word|nastroit' => 'настроить',
        'prefix|ornatish' => 'ornatish', 'prefix|ustanovk' => 'установк', 'word|setup' => 'setup',
        'prefix|integratsiya' => 'integratsiya', 'prefix|integrats' => 'интеграц',
    ];

    /**
     * @var array<string, string>
     */
    private const TARGETS = ['repair_intake' => 'service', 'availability' => 'sales', 'dealer' => 'sales', 'complaint' => 'sales'];

    /**
     * The new rule: normalized keywords as "match_type|normalized|phrase".
     *
     * @var list<string>
     */
    private const SETUP_KEYWORDS = [
        'prefix|sozlash|sozlash', 'prefix|sozlab|sozlab', 'prefix|ornatish|oʻrnatish', 'prefix|nastroyk|настройк',
        'word|nastroit|настроить', 'prefix|ustanovk|установк', 'prefix|vnedren|внедрен', 'prefix|integrats|интеграц',
        'prefix|integratsiya|integratsiya', 'word|setup|setup', 'word|install|install', 'word|installation|installation', 'word|installing|installing', 'word|integration|integration',
        'prefix|deploy|deploy',
    ];

    public function up(): void
    {
        if (! DB::table('chat_rules')->where('is_system', true)->exists()) {
            return;
        }

        $changed = false;
        $skipped = [];

        DB::transaction(function () use (&$changed, &$skipped): void {
            foreach (self::TARGETS as $key => $target) {
                $rule = $this->rule($key);

                if ($rule === null) {
                    continue;
                }

                if (($rule->params['handoff_target'] ?? null) === $target && $this->isShipped($rule, 'handoff', ['handoff_target' => $target], $key)) {
                    continue;
                }

                if (! $this->isShipped($rule, 'handoff', [], $key)) {
                    $skipped[] = $key;

                    continue;
                }

                DB::table('chat_rules')->where('id', $rule->id)->update([
                    'params' => json_encode(['handoff_target' => $target]),
                    'updated_at' => now(),
                ]);
                $changed = true;
            }

            $warranty = $this->rule('warranty_service');

            if ($warranty !== null) {
                if ($this->isShipped($warranty, 'answer', ['label' => self::WARRANTY_LABEL], 'warranty_service')) {
                    $removed = $this->movedKeywordIds($warranty);

                    if ($removed !== []) {
                        DB::table('chat_rule_keywords')->whereIn('id', $removed)->delete();
                        $changed = true;
                    }
                } elseif (! $this->hasNoMovedKeywords($warranty)) {
                    $skipped[] = 'warranty_service';
                }
            }

            if (! DB::table('chat_rules')->where('key', self::SETUP_KEY)->exists()) {
                $this->createSetupRule();
                $changed = true;
            }
        });

        Log::info('chatbot service flow migration', [
            'global_handoff_target' => $this->globalTarget(),
            'skipped_owner_edited_rules' => $skipped,
            'changed' => $changed,
        ]);

        if ($changed) {
            RuleIndex::bump();
        }
    }

    /**
     * Best effort, for a development rollback: removes the params this migration added, deletes setup_integration
     * only while its keywords still equal the shipped set (an owner-edited rule stays), and puts the moved
     * keywords back on warranty_service with their original phrases.
     */
    public function down(): void
    {
        if (! DB::table('chat_rules')->where('is_system', true)->exists()) {
            return;
        }

        DB::transaction(function (): void {
            foreach (array_keys(self::TARGETS) as $key) {
                $rule = $this->rule($key);

                if ($rule !== null && array_keys($rule->params) === ['handoff_target']) {
                    DB::table('chat_rules')->where('id', $rule->id)->update(['params' => null, 'updated_at' => now()]);
                }
            }

            $setup = DB::table('chat_rules')->where('key', self::SETUP_KEY)->where('is_system', true)->first();

            if ($setup !== null && $this->hasShippedSetupKeywords($setup)) {
                DB::table('chat_rules')->where('id', $setup->id)->delete();
            }

            $warranty = $this->rule('warranty_service');

            if ($warranty !== null) {
                $present = DB::table('chat_rule_keywords')->where('chat_rule_id', $warranty->id)->get()
                    ->map(fn (object $row): string => $row->match_type.'|'.$row->normalized)->all();

                foreach (self::MOVED_PHRASES as $signature => $phrase) {
                    if (! in_array($signature, $present, true)) {
                        $this->insertKeyword($warranty->id, explode('|', $signature)[0], explode('|', $signature)[1], $phrase);
                    }
                }
            }
        });

        RuleIndex::bump();
    }

    private function hasShippedSetupKeywords(object $rule): bool
    {
        $current = DB::table('chat_rule_keywords')->where('chat_rule_id', $rule->id)->get()
            ->map(fn (object $row): string => $row->locale === null && (int) $row->weight === 10 ? $row->match_type.'|'.$row->normalized : 'other|'.$row->id)
            ->sort()->values()->all();
        $shipped = array_map(fn (string $keyword): string => implode('|', array_slice(explode('|', $keyword), 0, 2)), self::SETUP_KEYWORDS);
        sort($shipped);

        return $current === $shipped;
    }

    /**
     * @return object{id: int, action: string, params: array<string, mixed>}|null
     */
    private function rule(string $key): ?object
    {
        $rule = DB::table('chat_rules')->where('key', $key)->where('is_system', true)->first();

        if ($rule === null) {
            return null;
        }

        $params = is_string($rule->params) ? json_decode($rule->params, true) : null;
        $rule->params = is_array($params) ? $params : [];

        return $rule;
    }

    /**
     * Action, params and the full normalized keyword set equal what shipped.
     *
     * @param  array<string, mixed>  $params
     */
    private function isShipped(object $rule, string $action, array $params, string $key): bool
    {
        if ($rule->action !== $action || $this->canonical($rule->params) !== $this->canonical($params)) {
            return false;
        }

        $current = DB::table('chat_rule_keywords')->where('chat_rule_id', $rule->id)->get()
            ->map(fn (object $row): string => $row->locale === null && (int) $row->weight === 10 ? $row->match_type.'|'.$row->normalized : 'other|'.$row->id)
            ->sort()->values()->all();
        $shipped = self::SHIPPED_KEYWORDS[$key];
        sort($shipped);

        return $current === $shipped;
    }

    /**
     * Already without the moved keywords (a re-run, or the owner removed them): nothing to log.
     */
    private function hasNoMovedKeywords(object $rule): bool
    {
        return $this->movedKeywordIds($rule) === [];
    }

    /**
     * @return list<int>
     */
    private function movedKeywordIds(object $rule): array
    {
        return DB::table('chat_rule_keywords')->where('chat_rule_id', $rule->id)->get()
            ->filter(fn (object $row): bool => in_array($row->match_type.'|'.$row->normalized, self::MOVED_KEYWORDS, true))
            ->map(fn (object $row): int => (int) $row->id)->values()->all();
    }

    /**
     * @param  array<string, mixed>  $value
     */
    private function canonical(array $value): string
    {
        ksort($value);

        foreach ($value as $name => $item) {
            if (is_array($item)) {
                ksort($item);
                $value[$name] = $item;
            }
        }

        return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    private function createSetupRule(): void
    {
        $id = DB::table('chat_rules')->insertGetId([
            'key' => self::SETUP_KEY,
            'name' => 'Setup and integration request',
            'action' => 'lead_dialog',
            'params' => json_encode(['flow' => 'service']),
            'answer' => json_encode([
                'uz' => 'Sozlash va integratsiya boʻyicha servis soʻrovini qoldiring: qurilma yoki dastur va vazifani yozing, servis muhandisi siz bilan bogʻlanadi.',
                'ru' => 'Оставьте заявку на настройку или интеграцию: опишите устройство или программу и задачу, инженер сервиса свяжется с вами.',
                'en' => 'Leave a service request for setup or integration: describe the device or software and your task, and a service engineer will contact you.',
            ], JSON_UNESCAPED_UNICODE),
            'on_web' => true,
            'on_telegram' => true,
            'in_menu' => false,
            'menu_sort' => 0,
            'priority' => 11,
            'is_active' => true,
            'is_system' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        foreach (self::SETUP_KEYWORDS as $keyword) {
            [$type, $normalized, $phrase] = explode('|', $keyword);
            $this->insertKeyword($id, $type, $normalized, $phrase);
        }
    }

    private function insertKeyword(int $ruleId, string $type, string $normalized, string $phrase): void
    {
        DB::table('chat_rule_keywords')->insert([
            'chat_rule_id' => $ruleId,
            'locale' => null,
            'phrase' => $phrase,
            'normalized' => $normalized,
            'match_type' => $type,
            'weight' => 10,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function globalTarget(): string
    {
        try {
            return (string) ChatSettings::all()['handoff_target'];
        } catch (Throwable) {
            return 'unknown';
        }
    }
};
