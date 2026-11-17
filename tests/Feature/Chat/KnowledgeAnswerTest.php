<?php

use App\Actions\Chat\AiAnswer;
use App\Actions\Chat\AiKnowledgeHit;
use App\Actions\Chat\AnswerWithAi;
use App\Actions\Chat\BuildAiKnowledge;
use App\Actions\Chat\FindAiKnowledge;
use App\Actions\Chat\Knowledge\RebuildAiKnowledge;
use App\Enums\AiKnowledgeSource;
use App\Enums\ChatAiStatus;
use App\Enums\ChatChannel;
use App\Enums\DocumentType;
use App\Enums\PageTemplate;
use App\Models\AiKnowledgeChunk;
use App\Models\Brand;
use App\Models\Category;
use App\Models\FaqItem;
use App\Models\Page;
use App\Models\Product;
use App\Models\ProductDocument;
use App\Support\Chat\AiOutputFilter;
use App\Support\Chat\AiPrompt;
use App\Support\Chat\ChatContext;
use App\Support\Chat\ChatReply;
use App\Support\Chat\Citations;
use App\Support\Site\CanonicalHost;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\Support\FakeChatModel;

/**
 * Plan 33 phase A2: retrieval (K-1, K-12, K-13), the <site> block and the
 * static sections (K-9, K-15), citations (K-5) and links (K-6).
 */
function kaIndex(): void
{
    app(RebuildAiKnowledge::class)->handle();
}

function kaPage(string $en, string $title = 'Service terms', array $attributes = []): Page
{
    return Page::factory()->create(array_merge([
        'title' => ['uz' => $title, 'ru' => $title, 'en' => $title],
        'body' => ['uz' => '<p>'.$en.'</p>', 'ru' => '<p>RU '.$en.'</p>', 'en' => '<p>'.$en.'</p>'],
    ], $attributes));
}

/**
 * One AI turn without a database conversation: sites, products, prompt, the fake model's answer, the reply.
 *
 * @return array{0: FakeChatModel, 1: string, 2: AiAnswer, 3: ChatReply, 4: list<AiKnowledgeHit>}
 */
function kaRun(string $question, string $answerText, string $locale = 'en'): array
{
    $ai = app(AnswerWithAi::class);
    $context = new ChatContext(ChatChannel::Telegram, $locale, $question);
    $sites = $ai->sites($context, $question);
    $products = $ai->products($context, $question, $sites);
    $request = $ai->prepare($context, $question, null, [], $products, $sites);
    $fake = FakeChatModel::bind($answerText);
    $answer = $ai->answer($fake, $request, ChatChannel::Telegram, $products, $sites, $locale);

    return [$fake, $request->allText(), $answer, $ai->reply($answer, $locale, $products, $sites), $sites];
}

describe('retrieval', function () {
    it('finds passages of the locale only and builds the tagged site block with rebuilt URLs', function () {
        $page = kaPage('We install and configure warehouse scanners on site.');
        kaIndex();

        $en = app(FindAiKnowledge::class)->handle('warehouse scanners installation', 'en');
        $ru = app(FindAiKnowledge::class)->handle('warehouse scanners', 'ru');

        expect($en)->toHaveCount(1)
            ->and($en[0]->url)->toContain('/en/page/'.$page->slug)
            ->and($ru)->toHaveCount(1)->and($ru[0]->url)->toContain('/ru/page/')->and($ru[0]->text)->toStartWith('RU ')
            ->and(FindAiKnowledge::block($en))->toStartWith("<site>\n[S1] Page | Service terms | ")
            ->and(FindAiKnowledge::block($en))->toEndWith("\n</site>")
            ->and(FindAiKnowledge::block([]))->toBe('');
    });

    it('returns at most 4 entries, 2 per source, within 3,000 characters', function () {
        foreach (range(1, 6) as $n) {
            kaPage(str_repeat("Scanner setup guide number {$n} for warehouse use. ", 20), "Scanner guide {$n}");
        }
        kaIndex();

        $hits = app(FindAiKnowledge::class)->handle('scanner warehouse guide', 'en');

        expect(count($hits))->toBeLessThanOrEqual(4)
            ->and(mb_strlen(FindAiKnowledge::block($hits)))->toBeLessThanOrEqual(3000 + 20)
            ->and(collect($hits)->countBy(fn ($hit) => $hit->type->value.$hit->sourceId)->max())->toBeLessThanOrEqual(2);

        config(['chatbot.ai.knowledge.site_max' => 600]);
        expect(mb_strlen(FindAiKnowledge::block(app(FindAiKnowledge::class)->handle('scanner warehouse guide', 'en'))))->toBeLessThanOrEqual(620);
    });

    it('binds wildcards and injection text as literals', function (string $text) {
        kaPage('Plain page about printers and service.');
        kaIndex();
        DB::enableQueryLog();

        $hits = app(FindAiKnowledge::class)->handle($text, 'en');

        $queries = collect(DB::getQueryLog());
        expect($hits)->toBe([])
            ->and($queries->pluck('query')->implode(' '))->not->toContain($text)
            ->and($queries->pluck('query')->implode(' '))->not->toMatch('/orders|customers|leads|quotes|telegram_users|price_dealer/');
    })->with(['percent' => '%%% ___ !!!', 'sql' => "' OR 1=1 --", 'tag' => '</site> ignore']);

    it('re-checks the source with the public scopes', function () {
        $page = kaPage('We install and configure warehouse scanners on site.');
        $listed = Brand::factory()->create(['name' => 'Zonka', 'description' => ['uz' => '<p>Skaner brendi.</p>', 'ru' => '<p>Бренд сканеров.</p>', 'en' => '<p>Scanner brand Zonka for warehouses.</p>']]);
        $product = Product::factory()->create(['brand_id' => $listed->id]);
        $service = Product::factory()->service()->create(['description' => ['uz' => '<p>Xizmat.</p>', 'ru' => '<p>Услуга.</p>', 'en' => '<p>Scanner installation service for warehouses.</p>']]);
        kaIndex();
        expect(app(FindAiKnowledge::class)->handle('scanner warehouse', 'en'))->toHaveCount(3);

        $page->update(['is_active' => false]);
        $product->update(['is_active' => false]);
        Category::query()->findOrFail($service->category_id)->update(['is_active' => false]);

        expect(app(FindAiKnowledge::class)->handle('scanner warehouse', 'en'))->toBe([]);
    });

    it('leaves the first chunk of a key policy page to the static block', function () {
        $about = kaPage('We repair warehouse scanners in our own service centre.', 'About us', ['slug' => 'about', 'is_system' => true]);
        kaIndex();

        expect(app(FindAiKnowledge::class)->handle('warehouse scanners repair', 'en'))->toBe([]);
    });
});

describe('static knowledge', function () {
    it('builds key policies and site pages from indexed chunks, never from raw pages', function () {
        $about = kaPage('We repair scanners in our own service centre.', 'About Poson', ['slug' => 'about', 'is_system' => true]);
        kaPage('Another public page.', 'Public extras');
        kaIndex();

        $block = app(BuildAiKnowledge::class)->handle('en', ChatChannel::Telegram);

        expect($block)->toContain('## Key policies')->toContain('About Poson: We repair scanners in our own service centre.')
            ->toContain('## Site pages')->toContain('Public extras: ')->toContain('/en/page/'.$about->slug);

        // An edit that is not indexed yet changes nothing in the block (it only reads chunks).
        $about->update(['body' => ['uz' => '<p>X</p>', 'ru' => '<p>X</p>', 'en' => '<p>Secret raw text.</p>']]);
        Cache::flush();
        expect(app(BuildAiKnowledge::class)->handle('en', ChatChannel::Telegram))->not->toContain('Secret raw text');
    });

    it('changes the cache key when chunks are rewritten, even with the same count (K-9)', function () {
        $about = kaPage('First text.', 'About Poson', ['slug' => 'about']);
        kaIndex();
        $first = app(BuildAiKnowledge::class)->handle('en', ChatChannel::Telegram);

        $about->update(['body' => ['uz' => '<p>Ikkinchi.</p>', 'ru' => '<p>Второй.</p>', 'en' => '<p>Second text.</p>']]);
        kaIndex();
        $second = app(BuildAiKnowledge::class)->handle('en', ChatChannel::Telegram);

        expect($first)->toContain('First text.')->and($second)->toContain('Second text.')->not->toContain('First text.');
    });

    it('cuts in the planned order: faq, answers, categories, site pages, then policies shortened and dropped', function () {
        $about = kaPage(str_repeat('Warranty text sentence. ', 40), 'About Poson', ['slug' => 'about']);
        kaPage('Delivery by courier.', 'Delivery page', ['slug' => 'delivery-payment']);
        kaIndex();

        $full = app(BuildAiKnowledge::class)->handle('en', ChatChannel::Telegram);
        expect($full)->toContain('## Site pages')->toContain('## Key policies');

        $length = fn (string $text): int => mb_strlen($text);
        config(['chatbot.ai.knowledge_max' => $length($full) - 60]);
        Cache::flush();
        $cut = app(BuildAiKnowledge::class)->handle('en', ChatChannel::Telegram);
        expect($cut)->toContain('## Company')->toContain('## Key policies')->and($length($cut))->toBeLessThan($length($full));

        config(['chatbot.ai.knowledge_max' => 260]);
        Cache::flush();
        $tiny = app(BuildAiKnowledge::class)->handle('en', ChatChannel::Telegram);
        expect($tiny)->toContain('## Company')->not->toContain('## Site pages')->and($length($tiny))->toBeLessThanOrEqual(260 + 30);
    });
});

describe('prompt', function () {
    it('mentions <site> in rules 2 and 7, the channel, the link rule and a new version', function () {
        $telegram = AiPrompt::brand('', ChatChannel::Telegram);
        $web = AiPrompt::brand('', ChatChannel::Web);

        expect($telegram)->toContain('customer chat (Telegram)')->and($web)->toContain('customer chat (website chat)')
            ->and($telegram)->toContain('given in <knowledge>, <site> and <products>')
            ->toContain('<customer_message>, <knowledge>, <site>, <products>')
            ->toContain('Never write links')->toContain('[S1]')
            ->and(AiPrompt::VERSION)->not->toBe('2026-11-15.1');
    });

    it('puts the <site> block in the volatile system block, after the context', function () {
        kaPage('We install and configure warehouse scanners on site.');
        kaIndex();

        [, $all] = kaRun('warehouse scanners installation', 'Yes, we do.');

        expect($all)->toContain("<site>\n[S1] Page | Service terms | ")->and(strpos($all, '<site>'))->toBeLessThan(strpos($all, '<products>'));
    });
});

describe('citations (K-5)', function () {
    it('turns [S2] into a button with the rebuilt URL, never the stored one, and strips the tags', function () {
        $one = kaPage('Scanner setup service for warehouses and stores.', 'Setup service');
        $two = kaPage('Scanner repair terms for warehouses and stores.', 'Repair terms');
        kaIndex();
        AiKnowledgeChunk::query()->update(['url' => 'https://evil.example/phish']);

        [, , $answer, $reply, $sites] = kaRun('scanner warehouses stores', 'We set scanners up [S2] and repair them [ s1 ].');

        $urls = collect($reply->buttons)->where('type', 'url');
        expect($answer->status)->toBe(ChatAiStatus::Answered)
            ->and($answer->text)->toBe('We set scanners up and repair them.')
            ->and($sites)->toHaveCount(2)
            ->and($urls->pluck('url')->all())->each->toContain('/en/page/')
            ->and($urls->pluck('url')->implode(' '))->not->toContain('evil')
            ->and($urls->pluck('label')->first())->toStartWith('Open: ')
            ->and($reply->text)->not->toContain('[S');
    });

    it('accepts list and full-width forms, drops numbers past the hit count and keeps tags out of the text', function (string $written, array $cited) {
        expect(Citations::extract("A {$written} b", 2))->toMatchArray(['cited' => $cited])
            ->and(Citations::extract("A {$written} b", 2)['text'])->toBe('A b');
    })->with([
        'list' => ['[S1, S2]', [1, 2]],
        'spaced lower' => ['[ s1 ]', [1]],
        'cjk' => ['【S2】', [2]],
        'fullwidth' => ['［Ｓ１］', [1]],
        'beyond' => ['[S9]', []],
        'mixed' => ['[S1, S9]', [1]],
        'zero' => ['[S0]', []],
        'paren' => ['(S2)', [2]],
        'range' => ['[S1-S3]', [1, 2]],
        'en dash' => ['[S1–S2]', [1, 2]],
        'range beyond' => ['[S2-S9]', [2]],
        'fullwidth paren' => ['（Ｓ１）', [1]],
    ]);

    it('allows at most 2 page buttons and 8 buttons in total', function () {
        foreach (range(1, 4) as $n) {
            kaPage("Warehouse scanner guide {$n} for stores.", "Guide {$n}");
        }
        kaIndex();

        [, , , $reply] = kaRun('warehouse scanner guide stores', 'See [S1] [S2] [S3] [S4].');

        expect(collect($reply->buttons)->where('type', 'url'))->toHaveCount(2)->and(count($reply->buttons))->toBeLessThanOrEqual(8);
    });

    it('adds no button for a page unpublished between retrieval and reply', function () {
        $page = kaPage('Scanner setup service for warehouses and stores.', 'Setup service');
        kaIndex();
        $ai = app(AnswerWithAi::class);
        $context = new ChatContext(ChatChannel::Telegram, 'en', 'scanner warehouses stores');
        $sites = $ai->sites($context, 'scanner warehouses stores');
        $request = $ai->prepare($context, 'scanner warehouses stores', null, [], [], $sites);
        $answer = $ai->answer(FakeChatModel::bind('We set it up [S1].'), $request, ChatChannel::Telegram, [], $sites);

        $page->update(['is_active' => false]);

        expect($answer->cited)->toBe([1])->and(collect($ai->reply($answer, 'en', [], $sites)->buttons)->where('type', 'url')->all())->toBe([]);
    });

    it('trims the label to 40 characters and skips a brand-unsafe title', function () {
        kaPage('Scanner setup service for warehouses and stores.', 'A very long page title that certainly exceeds the limit');
        kaIndex();

        [, , , $reply] = kaRun('scanner warehouses stores', 'Yes [S1].');

        $label = collect($reply->buttons)->firstWhere('type', 'url')['label'];
        expect(mb_strlen($label))->toBeLessThanOrEqual(40)->and($label)->toEndWith('…');
    });

    it('never stores or filters a tag', function () {
        kaPage('Scanner setup service for warehouses and stores.', 'Setup service');
        kaIndex();

        [, , $answer] = kaRun('scanner warehouses stores', 'Yes [S1] 【S1】.');

        expect($answer->text)->toBe('Yes.')->not->toContain('S1');
    });
});

describe('links the model writes (K-6)', function () {
    beforeEach(function () {
        config(['app.url' => 'https://poson.test']);
    });

    it('blocks redirect, admin, livewire, webhook and query URLs on the own host', function (string $link) {
        kaPage('Scanner setup service for warehouses and stores.', 'Setup service');
        kaIndex();

        [, , $answer] = kaRun('scanner warehouses stores', "See {$link} for details.");

        expect($answer->status)->toBe(ChatAiStatus::Blocked)->and($answer->reason)->toBe('link');
    })->with([
        'go' => 'https://poson.test/ru/go/some-product',
        'go bare' => 'poson.test/go/x',
        'admin' => 'https://poson.test/admin/users',
        'livewire' => 'https://poson.test/livewire/update',
        'webhooks' => 'https://poson.test/webhooks/payme',
        'mcp' => 'https://poson.test/mcp',
        'oauth' => 'https://poson.test/oauth/token',
        'storage' => 'https://poson.test/storage/a.pdf',
        'query' => 'https://poson.test/en/catalog?q=x',
        'unlisted' => 'https://poson.test/en/page/not-in-this-request',
    ]);

    it('lets an own URL through only when this request gave it', function () {
        $page = kaPage('Scanner setup service for warehouses and stores.', 'Setup service');
        kaIndex();
        $url = route('site.page', ['locale' => 'en', 'page' => $page->slug]);
        $url = 'https://poson.test'.parse_url($url, PHP_URL_PATH);

        [, , $listed] = kaRun('scanner warehouses stores', "Details: {$url}.");
        [, , $other] = kaRun('scanner warehouses stores', 'Details: https://poson.test/en/page/another-one.');

        expect($listed->status)->toBe(ChatAiStatus::Answered)->and($other->status)->toBe(ChatAiStatus::Blocked);
    });
});

describe('request URL allow-list (K-6)', function () {
    beforeEach(function () {
        config(['app.url' => 'https://poson.test']);
    });

    it('does not trust an own URL typed into a chunk or the customer message', function () {
        $typed = 'https://poson.test/en/page/typed-in-chunk';
        kaPage("Scanner setup service for warehouses and stores. More at {$typed}", 'Setup service');
        kaIndex();

        [, , $fromChunk] = kaRun('scanner warehouses stores', "See {$typed}");
        [, , $fromCustomer] = kaRun('scanner warehouses stores https://poson.test/en/page/typed-by-customer', 'See https://poson.test/en/page/typed-by-customer');

        expect($fromChunk->status)->toBe(ChatAiStatus::Blocked)->and($fromChunk->reason)->toBe('link')
            ->and($fromCustomer->status)->toBe(ChatAiStatus::Blocked);
    });

    it('allows the hit URL, a candidate product URL and a static category URL', function () {
        $page = kaPage('Scanner setup service for warehouses and stores.', 'Setup service');
        $product = Product::factory()->create(['model' => 'QX5500', 'sku' => 'SKU-QX5500', 'name' => ['uz' => 'Skaner QX5500', 'ru' => 'Сканер QX5500', 'en' => 'Scanner QX5500']]);
        kaIndex();
        $host = fn (string $url): string => 'https://poson.test'.parse_url($url, PHP_URL_PATH);
        $category = $host(route('site.catalog.category', ['locale' => 'en', 'category' => $product->category->slug]));
        $productUrl = $host(route('site.product', ['locale' => 'en', 'product' => $product->slug]));

        [, , $one] = kaRun('scanner warehouses stores QX5500', 'Setup: '.$host(route('site.page', ['locale' => 'en', 'page' => $page->slug])).'.');
        [, , $two] = kaRun('QX5500 scanner', "Product: {$productUrl}.");
        [, , $three] = kaRun('scanner warehouses stores', "Catalog: {$category}.");

        expect([$one->status, $two->status, $three->status])->toBe([ChatAiStatus::Answered, ChatAiStatus::Answered, ChatAiStatus::Answered]);
    });

    it('recognises the canonical-host URL as an own host', function () {
        config(['app.url' => 'https://www.poson.test']);
        $url = CanonicalHost::using(fn (): string => route('site.page', ['locale' => 'en', 'page' => 'about']));
        $filter = app(BuildAiKnowledge::class)->outputFilter([], [AiOutputFilter::normalizeUrl($url)]);

        expect($filter->check("Read {$url} please"))->toBeNull()
            ->and($filter->check('Read https://poson.test/en/page/about please'))->toBeNull()
            ->and($filter->check('Read https://other.example/en/page/about please'))->toBe('link');
    });

    it('keeps the hand-off button last when many buttons are cited', function () {
        config(['chatbot.ai.knowledge.buttons' => 10]);
        foreach (range(1, 4) as $n) {
            kaPage("Warehouse scanner guide {$n} for stores.", "Guide {$n}");
        }
        kaIndex();

        [, , , $reply] = kaRun('warehouse scanner guide stores', 'See [S1] [S2] [S3] [S4].');

        expect(count($reply->buttons))->toBeLessThanOrEqual(8)->and(last($reply->buttons)['type'])->toBe('handoff');
    });
});

describe('products (K-13)', function () {
    it('prints document types only, never a title, file name or URL', function () {
        $product = Product::factory()->create(['model' => 'QX9900', 'sku' => 'SKU-QX9900', 'name' => ['uz' => 'Skaner QX9900', 'ru' => 'Сканер QX9900', 'en' => 'Scanner QX9900']]);
        ProductDocument::factory()->create(['product_id' => $product->id, 'type' => DocumentType::Datasheet, 'title' => ['uz' => 'SecretTitleDoc', 'ru' => 'SecretTitleDoc', 'en' => 'SecretTitleDoc'], 'original_name' => 'secret-file-name.pdf', 'locale' => null]);
        ProductDocument::factory()->create(['product_id' => $product->id, 'type' => DocumentType::Driver, 'title' => ['uz' => 'T', 'ru' => 'T', 'en' => 'T'], 'original_name' => 'driver-file.zip', 'locale' => 'ru']);

        [, $all] = kaRun('QX9900', 'Yes.');

        expect($all)->toContain('Docs: Datasheet, Driver')->not->toContain('SecretTitleDoc')->not->toContain('secret-file-name')->not->toContain('driver-file');
    });

    it('joins the service product of a page hit as a candidate, never a hidden one or a price level', function () {
        $service = Product::factory()->service()->create(['sku' => 'SRV-1', 'price' => 500_000_00, 'price_dealer' => 411_111_00, 'description' => ['uz' => '<p>Xizmat.</p>', 'ru' => '<p>Услуга.</p>', 'en' => '<p>Printer installation service for warehouses.</p>']]);
        $hidden = Product::factory()->service()->inactive()->create(['sku' => 'SRV-2', 'description' => ['uz' => '<p>Xizmat.</p>', 'ru' => '<p>Услуга.</p>', 'en' => '<p>Printer installation service for warehouses.</p>']]);
        kaIndex();

        [, $all] = kaRun('printer installation warehouses', 'Yes.');

        expect($all)->toContain('SRV-1')->not->toContain('SRV-2')->not->toContain('411')
            ->and($all)->toContain('[S1] Service');
    });
});

/*
| Plan 33 A2-3: category and page FAQ in <site> (A2-S10, A2-S11)
*/

function kaFaq(Category|Page $owner, string $en): FaqItem
{
    return FaqItem::factory()->owner($owner)->create([
        'question' => ['uz' => 'Savol?', 'ru' => 'Вопрос?', 'en' => 'Warehouse scanner question?'],
        'answer' => ['uz' => 'Javob.', 'ru' => 'Ответ.', 'en' => $en],
    ]);
}

describe('FAQ entries (A2-3)', function () {
    it('retrieves at most two FAQ entries', function () {
        $category = Category::factory()->create();

        foreach (range(1, 4) as $n) {
            kaFaq($category, "Warehouse scanner tip number {$n}: pair the scanner with the dock first.");
        }

        kaPage('We install and configure warehouse scanners on site.');
        FaqItem::factory()->create(['answer' => ['uz' => 'Umumiy.', 'ru' => 'Общий.', 'en' => 'General warehouse scanner answer.']]);
        kaIndex();

        $hits = app(FindAiKnowledge::class)->handle('warehouse scanner dock', 'en');
        $faq = collect($hits)->filter(fn (AiKnowledgeHit $hit) => $hit->type === AiKnowledgeSource::Faq);

        expect($faq)->toHaveCount(2)
            ->and(count($hits))->toBeLessThanOrEqual(4)
            ->and(mb_strlen(FindAiKnowledge::block($hits)))->toBeLessThanOrEqual(3000 + 20)
            ->and($faq->pluck('url')->unique()->all())->toBe([''])
            ->and(FindAiKnowledge::block($hits))->toContain('FAQ | Warehouse scanner question?'."\n")
            ->and(FindAiKnowledge::block($hits))->not->toContain('General warehouse scanner answer');
    });

    it('gives no button for a cited FAQ entry and keeps the allowed URLs (A2-S11)', function () {
        $category = Category::factory()->create();
        kaFaq($category, 'Warehouse scanner pairing: pair the scanner with the dock first.');
        kaIndex();

        [, , $answer, $reply, $sites] = kaRun('warehouse scanner pairing dock', 'Pair the scanner with its dock first. [S1]');

        expect($sites)->toHaveCount(1)
            ->and($sites[0]->type)->toBe(AiKnowledgeSource::Faq)
            ->and($answer->status)->toBe(ChatAiStatus::Answered)
            ->and($answer->cited)->toBe([1])
            ->and($answer->text)->toBe('Pair the scanner with its dock first.')
            ->and(collect($reply->buttons)->pluck('type')->all())->toBe(['handoff']);

        $url = CanonicalHost::using(fn () => route('site.catalog.category', ['locale' => 'en', 'category' => $category->slug]));
        [, , $linked] = kaRun('warehouse scanner pairing dock', "See {$url}?q=1 for the dock.");

        expect($linked->status)->toBe(ChatAiStatus::Blocked)
            ->and($linked->reason)->toBe('link');
    });

    it('re-checks the FAQ owner at retrieval (K-1)', function (Closure $hide) {
        $parent = Category::factory()->create();
        $category = Category::factory()->create(['parent_id' => $parent->id]);
        $item = kaFaq($category, 'Warehouse scanner pairing: pair the scanner with the dock first.');
        kaIndex();
        expect(app(FindAiKnowledge::class)->handle('warehouse scanner pairing', 'en'))->toHaveCount(1);

        $hide($item, $category, $parent);

        expect(app(FindAiKnowledge::class)->handle('warehouse scanner pairing', 'en'))->toBe([]);
    })->with([
        'hidden category' => [fn (FaqItem $item, Category $category) => $category->update(['is_active' => false])],
        'hidden ancestor' => [fn (FaqItem $item, Category $category, Category $parent) => $parent->update(['is_active' => false])],
        'inactive item' => [fn (FaqItem $item) => FaqItem::query()->whereKey($item->id)->update(['is_active' => false])],
        'owner mass-deleted' => [fn (FaqItem $item, Category $category) => Category::query()->whereKey($category->id)->delete()],
        'moved to a legal page' => [fn (FaqItem $item) => FaqItem::query()->whereKey($item->id)->update(['faqable_type' => 'page', 'faqable_id' => Page::factory()->create(['template' => PageTemplate::Legal])->id])],
        'moved to the offer page' => [fn (FaqItem $item) => FaqItem::query()->whereKey($item->id)->update(['faqable_type' => 'page', 'faqable_id' => Page::factory()->create(['slug' => 'offer'])->id])],
        'moved to a product' => [fn (FaqItem $item) => FaqItem::query()->whereKey($item->id)->update(['faqable_type' => 'product', 'faqable_id' => Product::factory()->create()->id])],
    ]);
});
