<?php

namespace App\Actions\Chat;

use App\Enums\ChatAction;
use App\Enums\ChatChannel;
use App\Models\Category;
use App\Models\FaqItem;
use App\Models\Setting;
use App\Support\Catalog\CategoryTree;
use App\Support\Chat\AiPrompt;
use App\Support\Chat\RuleIndex;
use App\Support\Chat\Skills\CompanyInfoSkill;
use App\Support\Chat\Skills\FaqSkill;
use App\Support\Chat\Skills\MenuSkill;
use App\Support\CompanySettings;
use App\Support\Site\CanonicalHost;
use Illuminate\Support\Facades\Cache;

/**
 * The static knowledge block of the AI prompt (plan 30 §5, AI-8, AI-9): only
 * what is already public on the site, per locale and channel, wrapped in
 * <knowledge>. Sections:
 *  - company: name, address, hours, phone, email (CompanyInfoSkill, CB-7);
 *  - categories: the visible category tree with localized catalog URLs;
 *  - answers: the answer texts of the active "answer" rules on the channel
 *    (never names, keywords or params);
 *  - FAQ: the active general FAQ, brand-safe only.
 * Every field is stripped of control/format characters and angle brackets
 * and capped (AiPrompt::field). Over chatbot.ai.knowledge_max characters the
 * FAQ goes first, then the rule answers, then the categories. Cached
 * (chatbot.ai.knowledge_ttl, keyed by the rules version, the prompt version
 * and contentVersion()).
 * Reads no order, repair, customer, lead or Telegram table.
 */
class BuildAiKnowledge
{
    private const FAQ_LIMIT = 30;

    private const RULE_LIMIT = 40;

    private const CATEGORY_LIMIT = 80;

    private const FIELD_MAX = 500;

    public function __construct(
        private readonly RuleIndex $index,
        private readonly FaqSkill $faq,
        private readonly CompanyInfoSkill $company,
        private readonly CompanySettings $settings,
    ) {}

    public function handle(string $locale, ChatChannel $channel): string
    {
        $key = 'chatbot:ai:knowledge:'.$channel->value.':'.$locale.':'.RuleIndex::version().':'.AiPrompt::VERSION.':'.$this->contentVersion();

        return Cache::remember($key, (int) config('chatbot.ai.knowledge_ttl', 3600), fn (): string => $this->build($locale, $channel));
    }

    /**
     * A version of the content behind the block (code review 3): the latest
     * change of the FAQ, the categories and the settings (company data,
     * working hours), so an edit shows in the next answer, not after the TTL.
     * Three indexed aggregate reads, in the job only.
     */
    private function contentVersion(): string
    {
        return substr(hash('sha256', implode('|', [
            FaqItem::query()->max('updated_at'),
            FaqItem::query()->count(),
            Category::query()->max('updated_at'),
            Category::query()->count(),
            Setting::query()->max('updated_at'),
        ])), 0, 16);
    }

    /**
     * The public company phones and email (allowed in AI answers, AI-11).
     *
     * @return array{phones: list<string>, emails: list<string>}
     */
    public function contacts(): array
    {
        return $this->company->publicContacts();
    }

    private function build(string $locale, ChatChannel $channel): string
    {
        $company = ['Company: '.AiPrompt::field($this->settings->name($locale), 100)];

        foreach ($this->company->facts($locale) as $line) {
            $company[] = AiPrompt::field($line, self::FIELD_MAX);
        }

        $sections = [
            'company' => $company,
            'categories' => $this->categories($locale),
            'answers' => $this->answers($locale, $channel),
            'faq' => $this->faqs($locale),
        ];

        $titles = [
            'company' => '## Company',
            'categories' => '## Catalog categories',
            'answers' => '## Ready answers of Poson',
            'faq' => '## FAQ',
        ];

        $max = (int) config('chatbot.ai.knowledge_max', 24000);

        foreach (['faq', 'answers', 'categories'] as $cut) {
            while ($sections[$cut] !== [] && mb_strlen($this->render($sections, $titles)) > $max) {
                array_pop($sections[$cut]);
            }
        }

        return "<knowledge>\n".mb_substr($this->render($sections, $titles), 0, $max)."\n</knowledge>";
    }

    /**
     * @param  array<string, list<string>>  $sections
     * @param  array<string, string>  $titles
     */
    private function render(array $sections, array $titles): string
    {
        $parts = [];

        foreach ($sections as $name => $lines) {
            if ($lines !== []) {
                $parts[] = $titles[$name]."\n".implode("\n", $lines);
            }
        }

        return implode("\n\n", $parts);
    }

    /**
     * @return list<string>
     */
    private function categories(string $locale): array
    {
        $lines = [];

        foreach (CategoryTree::flattened() as $node) {
            if (! CategoryTree::isVisible($node['id'])) {
                continue;
            }

            $name = AiPrompt::field(CategoryTree::name($node['id'], $locale), 100);

            if ($name === '') {
                continue;
            }

            $url = CanonicalHost::using(fn (): string => route('site.catalog.category', ['locale' => $locale, 'category' => $node['slug']]));
            $lines[] = str_repeat('  ', max(0, $node['depth'] - 1)).'- '.$name.': '.$url;

            if (count($lines) >= self::CATEGORY_LIMIT) {
                break;
            }
        }

        return $lines;
    }

    /**
     * @return list<string>
     */
    private function answers(string $locale, ChatChannel $channel): array
    {
        $data = $this->index->load();
        $lines = [];

        foreach ($data['rules'] ?? [] as $rule) {
            if ($rule['action'] !== ChatAction::Answer->value || ! MenuSkill::visibleOn($rule, $channel)) {
                continue;
            }

            $answer = AiPrompt::field((string) ($rule['answer'][$locale] ?? '') !== '' ? $rule['answer'][$locale] : ($rule['answer']['uz'] ?? ''), self::FIELD_MAX);

            if ($answer !== '') {
                $lines[] = '- '.str_replace("\n", ' ', $answer);
            }

            if (count($lines) >= self::RULE_LIMIT) {
                break;
            }
        }

        return $lines;
    }

    /**
     * @return list<string>
     */
    private function faqs(string $locale): array
    {
        $lines = [];

        foreach ($this->faq->generalItems($locale, self::FAQ_LIMIT) as $item) {
            $lines[] = 'Q: '.str_replace("\n", ' ', AiPrompt::field($item['question'], 200))."\nA: ".str_replace("\n", ' ', AiPrompt::field($item['answer'], self::FIELD_MAX));
        }

        return $lines;
    }
}
