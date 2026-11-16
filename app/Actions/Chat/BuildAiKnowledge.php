<?php

namespace App\Actions\Chat;

use App\Actions\Chat\Knowledge\KnowledgeSources;
use App\Enums\AiKnowledgeSource;
use App\Enums\ChatAction;
use App\Enums\ChatChannel;
use App\Models\AiKnowledgeChunk;
use App\Models\Brand;
use App\Models\Category;
use App\Models\FaqItem;
use App\Models\Page;
use App\Models\Setting;
use App\Support\Catalog\CategoryTree;
use App\Support\Chat\AiOutputFilter;
use App\Support\Chat\AiPriceCandidate;
use App\Support\Chat\AiPrompt;
use App\Support\Chat\KnowledgeStats;
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
    /**
     * Pages whose first chunk goes into the static "Key policies" section.
     *
     * @var list<string>
     */
    public const KEY_POLICY_SLUGS = ['about', 'warranty-service', 'delivery-payment', 'contacts', 'b2b'];

    private const POLICY_MAX = 1200;

    private const POLICY_SHORT = 600;

    private const PAGE_LIMIT = 30;

    private const FAQ_LIMIT = 30;

    private const RULE_LIMIT = 40;

    private const CATEGORY_LIMIT = 80;

    private const FIELD_MAX = 500;

    private const BRANDS_TTL = 300;

    public function __construct(
        private readonly RuleIndex $index,
        private readonly FaqSkill $faq,
        private readonly CompanyInfoSkill $company,
        private readonly CompanySettings $settings,
        private readonly KnowledgeSources $sources,
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
            KnowledgeStats::version(),
        ])), 0, 16);
    }

    /**
     * The URLs the server writes into the static knowledge block of a locale
     * (catalog categories and site pages, built with route()): the only
     * static ones an AI answer may repeat (K-6). Cached with the block's
     * content version.
     *
     * @return list<string>
     */
    public function urls(string $locale): array
    {
        $key = 'chatbot:ai:knowledge:urls:'.$locale.':'.$this->contentVersion();

        return Cache::remember($key, (int) config('chatbot.ai.knowledge_ttl', 3600), function () use ($locale): array {
            $urls = [];

            foreach (CategoryTree::flattened() as $node) {
                if (CategoryTree::isVisible($node['id'])) {
                    $urls[] = CanonicalHost::using(fn (): string => route('site.catalog.category', ['locale' => $locale, 'category' => $node['slug']]));
                }
            }

            foreach ($this->pageChunks($locale) as $page) {
                $urls[] = $page['url'];
            }

            return $urls;
        });
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

    /**
     * The AI output filter with the site's own hosts and the public company
     * contacts allowed (shared by the chat answers, the taught rules and
     * "Translate with Claude").
     *
     * @param  list<AiPriceCandidate>  $amounts  The only amounts the text may name
     * @param  list<string>|null  $urls  The own-host URLs of this request (AiOutputFilter::normalizeUrl), null = any
     */
    public function outputFilter(array $amounts = [], ?array $urls = null): AiOutputFilter
    {
        // The configured host and the one CanonicalHost builds URLs on (the hit and product URLs), each with its www twin.
        $canonical = strtolower((string) CanonicalHost::using(fn (): ?string => parse_url(url('/'), PHP_URL_HOST)));
        $hosts = [];

        foreach ([strtolower((string) parse_url((string) config('app.url'), PHP_URL_HOST)), $canonical] as $host) {
            if ($host !== '') {
                array_push($hosts, $host, str_starts_with($host, 'www.') ? substr($host, 4) : 'www.'.$host);
            }
        }

        $hosts = array_values(array_unique($hosts));
        $contacts = $this->contacts();

        return new AiOutputFilter($hosts, $contacts['phones'], $contacts['emails'], $amounts, $this->handles(), $this->brands(), $urls);
    }

    /**
     * The catalog brand names, lowercased (a brand after a messenger word is
     * not a handle, M2). Cached briefly (BRANDS_TTL).
     *
     * @return list<string>
     */
    public function brands(): array
    {
        return Cache::remember('chatbot:ai:brands', self::BRANDS_TTL, fn (): array => Brand::query()
            ->orderBy('id')
            ->limit(1000)
            ->pluck('name')
            ->map(fn (mixed $name): string => mb_strtolower(trim((string) $name)))
            ->filter(fn (string $name): bool => $name !== '')
            ->unique()
            ->values()
            ->all());
    }

    /**
     * The company's own messenger handles: the first path segment of each
     * social link ("https://t.me/poson_uz" → "poson_uz"), lowercased.
     *
     * @return list<string>
     */
    public function handles(): array
    {
        $handles = [];

        foreach ($this->settings->social() as $link) {
            $path = trim((string) parse_url((string) $link['url'], PHP_URL_PATH), '/');
            $handle = mb_strtolower(ltrim(explode('/', $path)[0], '@'));

            if ($handle !== '') {
                $handles[] = $handle;
            }
        }

        return array_values(array_unique($handles));
    }

    private function build(string $locale, ChatChannel $channel): string
    {
        $company = ['Company: '.AiPrompt::field($this->settings->name($locale), 100)];

        foreach ($this->company->facts($locale) as $line) {
            $company[] = AiPrompt::field($line, self::FIELD_MAX);
        }

        $pages = $this->pageChunks($locale);

        $sections = [
            'company' => $company,
            'policies' => $this->policies($pages, self::POLICY_MAX),
            'pages' => $this->pageList($pages),
            'categories' => $this->categories($locale),
            'answers' => $this->answers($locale, $channel),
            'faq' => $this->faqs($locale),
        ];

        $titles = [
            'company' => '## Company',
            'policies' => '## Key policies',
            'pages' => '## Site pages',
            'categories' => '## Catalog categories',
            'answers' => '## Ready answers of Poson',
            'faq' => '## FAQ',
        ];

        $max = (int) config('chatbot.ai.knowledge_max', 24000);

        foreach (['faq', 'answers', 'categories', 'pages'] as $cut) {
            while ($sections[$cut] !== [] && mb_strlen($this->render($sections, $titles)) > $max) {
                array_pop($sections[$cut]);
            }
        }

        if ($sections['policies'] !== [] && mb_strlen($this->render($sections, $titles)) > $max) {
            $sections['policies'] = $this->policies($pages, self::POLICY_SHORT);
        }

        while ($sections['policies'] !== [] && mb_strlen($this->render($sections, $titles)) > $max) {
            array_pop($sections['policies']);
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
     * The first chunk of every indexed public page in the locale (never a raw
     * page body): slug, title, text and URL, in a stable order. Each page is
     * checked again with the public scope (K-1).
     *
     * @return list<array{slug: string, title: string, text: string, url: string}>
     */
    private function pageChunks(string $locale): array
    {
        $keyIds = Page::query()->whereIn('slug', self::KEY_POLICY_SLUGS)->pluck('id')->all();
        $base = fn () => AiKnowledgeChunk::query()
            ->live($locale)
            ->where('source_type', AiKnowledgeSource::Page->value)
            ->where('chunk', 0)
            ->orderBy('source_id');

        // The key pages are read on their own, so a long list of other pages can never push them out.
        $chunks = $base()->whereIn('source_id', $keyIds)->get(['source_id', 'title', 'text'])
            ->concat($base()->whereNotIn('source_id', $keyIds)->limit(self::PAGE_LIMIT * 2)->get(['source_id', 'title', 'text']));

        $slugs = $this->sources->live(AiKnowledgeSource::Page, $chunks->pluck('source_id')->all(), $locale);
        $pages = [];

        foreach ($chunks as $chunk) {
            $slug = $slugs[$chunk->source_id] ?? null;

            if ($slug !== null) {
                $pages[] = [
                    'slug' => $slug,
                    'title' => AiPrompt::field($chunk->title, 200),
                    'text' => AiPrompt::field($chunk->text, self::POLICY_MAX),
                    'url' => $this->sources->url(AiKnowledgeSource::Page, $slug, $locale),
                ];
            }
        }

        return $pages;
    }

    /**
     * "Key policies": the excerpts of the key pages, in the order of KEY_POLICY_SLUGS.
     *
     * @param  list<array{slug: string, title: string, text: string, url: string}>  $pages
     * @return list<string>
     */
    private function policies(array $pages, int $max): array
    {
        $lines = [];

        foreach (self::KEY_POLICY_SLUGS as $slug) {
            foreach ($pages as $page) {
                if ($page['slug'] === $slug) {
                    $lines[] = '- '.$page['title'].': '.str_replace("\n", ' ', AiPrompt::field($page['text'], $max));
                }
            }
        }

        return $lines;
    }

    /**
     * "Site pages": title and URL of the indexed public pages.
     *
     * @param  list<array{slug: string, title: string, text: string, url: string}>  $pages
     * @return list<string>
     */
    private function pageList(array $pages): array
    {
        $lines = [];

        foreach (array_slice($pages, 0, self::PAGE_LIMIT) as $page) {
            $lines[] = '- '.$page['title'].': '.$page['url'];
        }

        return $lines;
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
