<?php

use App\Actions\Api\SyncPrices;
use App\Actions\Chat\AiAnswer;
use App\Actions\Chat\AnswerWithAi;
use App\Actions\Chat\BuildAiKnowledge;
use App\Actions\Chat\FindAiKnowledge;
use App\Actions\Chat\Knowledge\IndexKnowledgeSource;
use App\Actions\Chat\Knowledge\KnowledgeSources;
use App\Actions\Chat\Knowledge\RebuildAiKnowledge;
use App\Enums\AiKnowledgeSource;
use App\Enums\ChatAiStatus;
use App\Enums\ChatChannel;
use App\Jobs\IndexAiKnowledge;
use App\Jobs\RebuildAiKnowledgeIndex;
use App\Models\AiKnowledgeChunk;
use App\Models\Category;
use App\Models\Page;
use App\Models\Product;
use App\Support\Chat\ChatContext;
use App\Support\Chat\KnowledgeStats;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Tests\Support\FakeChatModel;

/**
 * Plan 33 §15 abuse cases that the phase A feature tests did not assert yet:
 * 2 (static block / site block), 5 (candidates), 11, 12 (SyncPrices), 16.
 */
function kcPage(string $en, string $title = 'Service terms', array $attributes = []): Page
{
    return Page::factory()->create(array_merge([
        'title' => ['uz' => $title, 'ru' => $title, 'en' => $title],
        'body' => ['uz' => '<p>'.$en.'</p>', 'ru' => '<p>'.$en.'</p>', 'en' => '<p>'.$en.'</p>'],
    ], $attributes));
}

it('never lets a money amount into the <site> block or the static knowledge block (case 2, 16)', function (string $sentence, bool $masked) {
    kcPage("Warehouse scanner delivery. {$sentence}", 'Delivery page', ['slug' => 'delivery-payment']);
    kcPage("Warehouse scanner setup. {$sentence}", 'Setup page');
    app(RebuildAiKnowledge::class)->handle();

    $site = FindAiKnowledge::block(app(FindAiKnowledge::class)->handle('warehouse scanner', 'en'));
    $static = app(BuildAiKnowledge::class)->handle('en', ChatChannel::Telegram);
    $filter = app(BuildAiKnowledge::class)->outputFilter();
    $siteText = preg_replace(['~https?://\S+~', '~\[S\d+\]~'], '', $site);

    expect($static === '' || ! $masked || str_contains($static, 'Delivery page'))->toBeTrue()
        ->and($siteText)->not->toMatch('/\d|\$|%|ming/iu')
        ->and($static)->not->toMatch('/50 000|\$20|20%|ming/iu')
        ->and(trim($siteText) === '' ? null : $filter->check($siteText))->toBeNull();
})->with([
    'fee' => ['The delivery fee is 50 000 UZS.', true],
    'ming' => ['Delivery costs 50 ming soʻm.', true],
    'dollar' => ['Delivery is $20 in town.', false],
]);

it('removes a deactivated category service from the candidates and the <site> block without a product save (case 5)', function () {
    $service = Product::factory()->service()->create(['sku' => 'SRV-KC1', 'description' => ['uz' => '<p>Xizmat.</p>', 'ru' => '<p>Услуга.</p>', 'en' => '<p>Printer installation service for warehouses.</p>']]);
    app(RebuildAiKnowledge::class)->handle();
    expect(app(FindAiKnowledge::class)->handle('printer installation warehouses', 'en'))->toHaveCount(1);

    Category::query()->findOrFail($service->category_id)->update(['is_active' => false]);
    $hits = app(FindAiKnowledge::class)->handle('printer installation warehouses', 'en');

    expect($hits)->toBe([])
        ->and(FindAiKnowledge::block($hits))->toBe('')
        ->and(Product::query()->visible()->whereKey($service->id)->exists())->toBeFalse();
});

it('never hands a uz chunk to a ru question (case 11)', function () {
    $page = Page::factory()->create(['body' => ['uz' => '<p>Omborxona skanerlarini oʻrnatamiz.</p>', 'ru' => '<p>Мы настраиваем принтеры.</p>', 'en' => '<p>We set up printers.</p>']]);
    app(RebuildAiKnowledge::class)->handle();

    $ru = app(FindAiKnowledge::class)->handle('Omborxona skanerlarini', 'ru');
    $uz = app(FindAiKnowledge::class)->handle('Omborxona skanerlarini', 'uz');

    expect(AiKnowledgeChunk::query()->where('source_id', $page->id)->where('locale', 'uz')->exists())->toBeTrue()
        ->and($ru)->toBe([])
        ->and($uz)->toHaveCount(1);
});

it('queues no knowledge job when SyncPrices changes a service price (case 12)', function () {
    config(['chatbot.ai.knowledge.hooks' => true]);
    Queue::fake();
    $service = Product::factory()->service()->create(['sku' => 'KC-SYNC-1']);
    foreach (Queue::pushed(IndexAiKnowledge::class) as $job) {
        Cache::lock('laravel_unique_job:'.IndexAiKnowledge::class.$job->uniqueId())->forceRelease();
    }
    Queue::fake();

    $payload = json_decode(json_encode(['items' => [['code' => 'KC-SYNC-1', 'retail' => 150000, 'dealer' => 140000, 'wholesale' => 130000]]]));
    $result = app(SyncPrices::class)->handle($payload, null, '127.0.0.1');

    expect($result->log->updated)->toBe(1)->and($service->fresh()->price)->toBe(15000000);
    Queue::assertNotPushed(IndexAiKnowledge::class);
});

it('changes the static block when a page is deleted and a new one with the same slug and chunk count is indexed (case 16)', function () {
    $about = kcPage('First policy text.', 'About Poson', ['slug' => 'about']);
    app(RebuildAiKnowledge::class)->handle();
    $first = app(BuildAiKnowledge::class)->handle('en', ChatChannel::Telegram);
    $count = AiKnowledgeChunk::count();
    $version = KnowledgeStats::version();

    $about->delete();
    app(RebuildAiKnowledge::class)->handle();
    kcPage('Replaced policy text.', 'About Poson', ['slug' => 'about']);
    app(RebuildAiKnowledge::class)->handle();
    $second = app(BuildAiKnowledge::class)->handle('en', ChatChannel::Telegram);

    expect(AiKnowledgeChunk::count())->toBe($count)
        ->and(KnowledgeStats::version())->toBeGreaterThan($version)
        ->and($first)->toContain('First policy text.')
        ->and($second)->toContain('Replaced policy text.')->not->toContain('First policy text.');
});

/**
 * Follow-up security review cases F1 to F10.
 *
 * @return array{0: AiAnswer, 1: list<array<string, mixed>>, 2: string}
 */
function kcRun(string $question, string $answerText, array $history = [], int $products = 0): array
{
    config(['app.url' => 'https://poson.test']);
    $ai = app(AnswerWithAi::class);
    $context = new ChatContext(ChatChannel::Telegram, 'en', $question);
    $sites = $ai->sites($context, $question);
    $candidates = $ai->products($context, $question, $sites);
    $request = $ai->prepare($context, $question, null, $history, $candidates, $sites);
    $answer = $ai->answer(FakeChatModel::bind($answerText), $request, ChatChannel::Telegram, $candidates, $sites);
    $reply = $ai->reply($answer, 'en', $candidates, $sites);

    return [$answer, $reply->buttons, $reply->text];
}

it('indexes a 195-character title with a percent or an amount, and a failed write leaks no chunk text (F1)', function (string $tail) {
    $title = str_repeat('a', 195).' '.$tail;
    $page = Page::factory()->create(['title' => ['uz' => $title, 'ru' => $title, 'en' => $title], 'body' => ['uz' => '<p>Xizmat matni.</p>', 'ru' => '<p>Текст услуги.</p>', 'en' => '<p>Service text.</p>']]);

    app(IndexKnowledgeSource::class)->handle(AiKnowledgeSource::Page, $page->id);

    $stored = AiKnowledgeChunk::query()->where('source_id', $page->id)->get();
    expect($stored->every(fn (AiKnowledgeChunk $chunk): bool => mb_strlen($chunk->title) <= 200))->toBeTrue();
})->with(['percent' => '5% more', 'amount' => '50 000 soʻm']);

it('rethrows a failed chunk write with the exception class only, never the text (F1)', function () {
    $page = Page::factory()->create(['body' => ['uz' => '<p>Maxfiy-matn-uz.</p>', 'ru' => '<p>Секретный-текст.</p>', 'en' => '<p>Secret-chunk-text.</p>']]);
    DB::unprepared("CREATE TRIGGER kc_fail BEFORE INSERT ON ai_knowledge_chunks BEGIN SELECT RAISE(ABORT, 'boom'); END");
    Log::spy();

    try {
        (new IndexAiKnowledge('page', $page->id))->handle(app(IndexKnowledgeSource::class));
        $caught = null;
    } catch (Throwable $caught) {
    }

    expect($caught)->toBeInstanceOf(RuntimeException::class)
        ->and($caught->getMessage())->not->toContain('Secret-chunk-text')->not->toContain('Maxfiy')->toContain('QueryException')
        ->and($caught->getPrevious())->toBeNull();

    (new IndexAiKnowledge('page', $page->id))->failed($caught);
    Log::shouldHaveReceived('warning')->withArgs(fn (string $message, array $context): bool => ! str_contains(json_encode($context), 'Secret'));
});

it('lets "Rebuild now" repair a tampered chunk while the hourly cursor stays in Brand and its own cursor is cleared (F2)', function () {
    Queue::fake();
    $page = Page::factory()->create(['body' => ['uz' => '<p>Original uz.</p>', 'ru' => '<p>Оригинал.</p>', 'en' => '<p>Original text.</p>']]);
    app(RebuildAiKnowledge::class)->handle();
    DB::table('ai_knowledge_chunks')->where('source_id', $page->id)->update(['text' => 'tampered', 'content_hash' => 'tampered']);
    Cache::forever(RebuildAiKnowledge::CURSOR_KEY, 'brand:0');

    (new RebuildAiKnowledgeIndex)->handle(app(RebuildAiKnowledge::class));

    expect(AiKnowledgeChunk::query()->where('source_id', $page->id)->where('text', 'tampered')->exists())->toBeFalse()
        ->and(AiKnowledgeChunk::query()->where('source_id', $page->id)->where('locale', 'en')->value('text'))->toBe('Original text.')
        ->and(Cache::get(RebuildAiKnowledge::CURSOR_KEY))->toBe('brand:0')
        ->and(Cache::has(RebuildAiKnowledgeIndex::CURSOR_KEY))->toBeFalse();
    Queue::assertNotPushed(RebuildAiKnowledgeIndex::class);
});

it('resumes a rebuild over later hops and indexes every source exactly once (F3)', function () {
    Queue::fake();
    $pages = collect(range(1, 3))->map(fn () => Page::factory()->create());
    $indexed = [];
    $slow = new class(app(KnowledgeSources::class), app(BuildAiKnowledge::class), $indexed) extends IndexKnowledgeSource
    {
        public function __construct(KnowledgeSources $sources, BuildAiKnowledge $knowledge, public array &$log)
        {
            parent::__construct($sources, $knowledge);
        }

        public function handle(AiKnowledgeSource $type, int $id, int $wait = 10): array
        {
            $this->log[] = $type->value.':'.$id;
            $result = parent::handle($type, $id, $wait);
            usleep(1_100_000);

            return $result;
        }
    };
    $budgeted = new class(new RebuildAiKnowledge(app(KnowledgeSources::class), $slow)) extends RebuildAiKnowledge
    {
        public function __construct(private RebuildAiKnowledge $inner) {}

        public function handle(?AiKnowledgeSource $only = null, ?int $seconds = null, string $cursorKey = self::CURSOR_KEY): array
        {
            return $this->inner->handle($only, 1, $cursorKey);
        }
    };

    $hops = 0;
    do {
        Queue::fake();
        (new RebuildAiKnowledgeIndex)->handle($budgeted);
        $hops++;
        $more = Queue::pushed(RebuildAiKnowledgeIndex::class)->isNotEmpty();
    } while ($more && $hops < 6);

    expect($hops)->toBe(3)
        ->and($indexed)->toBe($pages->map(fn (Page $page): string => 'page:'.$page->id)->all())
        ->and(AiKnowledgeChunk::count())->toBe(9)
        ->and(Cache::has(RebuildAiKnowledgeIndex::CURSOR_KEY))->toBeFalse();
});

it('keeps valid UTF-8 with an ideographic full stop before an amount and leaves no amount (F4)', function () {
    $page = Page::factory()->create(['body' => ['uz' => '<p>İstanbul Xizmat。 ٥٠ ٠٠٠ soʻm turadi.</p>', 'ru' => '<p>Сервис.</p>', 'en' => '<p>Service。 50 000 UZS.</p>']]);

    app(IndexKnowledgeSource::class)->handle(AiKnowledgeSource::Page, $page->id);

    $chunks = AiKnowledgeChunk::query()->where('source_id', $page->id)->get();
    expect($chunks)->not->toBeEmpty();
    foreach ($chunks as $chunk) {
        expect(mb_check_encoding($chunk->text, 'UTF-8'))->toBeTrue()->and($chunk->text)->not->toMatch('/[0-9٠-٩]/u');
    }
});

it('skips a promotional percentage as brand-unsafe and masks a neutral one (F5)', function (string $sentence, bool $skipped) {
    $page = Page::factory()->create(['body' => ['uz' => "<p>{$sentence}</p>", 'ru' => "<p>{$sentence}</p>", 'en' => "<p>{$sentence}</p>"]]);

    app(IndexKnowledgeSource::class)->handle(AiKnowledgeSource::Page, $page->id);

    $chunks = AiKnowledgeChunk::query()->where('source_id', $page->id)->get();
    if ($skipped) {
        expect($chunks)->toBeEmpty()->and(KnowledgeStats::skipCounts())->toHaveKey('brand_unsafe');
    } else {
        expect($chunks)->toHaveCount(3)->and($chunks->pluck('text')->implode(' '))->not->toContain('%');
    }
})->with([
    'minus sign' => ['Narx −30% past.', true],
    'off' => ['Take 30% off today.', true],
    'gacha' => ['Narx 50% gacha.', true],
    'do' => ['Скидка до 30%.', true],
    'vat' => ['Price includes 12% QQS.', false],
    'original' => ['It is 100% original.', false],
]);

it('keeps every citation look-alike out of the customer text and the stored answer (F6)', function (string $written) {
    foreach (['Scanner setup guide for warehouses and stores.', 'Scanner repair guide for warehouses and stores.'] as $body) {
        kcPage($body, $body);
    }
    app(RebuildAiKnowledge::class)->handle();

    [$answer, , $text] = kcRun('scanner guide warehouses stores', "We help {$written} with it.");

    expect($answer->text)->not->toMatch('/s\s*\d/iu')->and($text)->not->toMatch('/s\s*\d|\(|\[|【|（/iu');
})->with(['paren' => '(S1)', 'range' => '[S1-S2]', 'en dash range' => '[S1–S2]', 'fullwidth paren' => '（Ｓ１）']);

it('blocks percent-encoded, double-slash and upper-case own links while an allow-list exists (F7)', function (string $link) {
    kcPage('Scanner setup guide for warehouses and stores.', 'Setup guide');
    app(RebuildAiKnowledge::class)->handle();

    [$answer] = kcRun('scanner guide warehouses stores', "See {$link} for more.");

    expect($answer->status)->toBe(ChatAiStatus::Blocked)->and($answer->reason)->toBe('link');
})->with(['percent' => 'poson.test/ru/%67o/slug', 'double slash' => 'poson.test//go/x', 'upper case' => 'POSON.TEST/RU/GO/X']);

it('never allows an own URL that only an earlier history turn contained (F8)', function () {
    $typed = 'https://poson.test/en/page/typed-in-history';
    kcPage('Scanner setup guide for warehouses and stores.', 'Setup guide');
    app(RebuildAiKnowledge::class)->handle();
    $history = [['role' => 'user', 'content' => "Look at {$typed}"], ['role' => 'assistant', 'content' => 'Ok.']];

    [$answer] = kcRun('scanner guide warehouses stores', "As before: {$typed}", $history);

    expect($answer->status)->toBe(ChatAiStatus::Blocked)->and($answer->reason)->toBe('link');
});

it('keeps the hand-off button last with six product buttons and two page citations (F9)', function () {
    config(['chatbot.ai.products.buttons' => 6, 'chatbot.ai.knowledge.buttons' => 2]);
    foreach (range(0, 5) as $n) {
        Product::factory()->create(['model' => 'QZ70'.$n, 'sku' => 'SKU-QZ70'.$n, 'name' => ['uz' => 'Skaner QZ70'.$n, 'ru' => 'Сканер QZ70'.$n, 'en' => 'Scanner QZ70'.$n]]);
    }
    foreach ([1, 2] as $n) {
        kcPage("Warehouse scanner guide {$n} for stores.", "Guide {$n}");
    }
    app(RebuildAiKnowledge::class)->handle();

    [, $buttons] = kcRun('QZ700 QZ701 QZ702 QZ703 QZ704 QZ705 warehouse scanner guide stores', 'QZ700 QZ701 QZ702 QZ703 QZ704 QZ705 fit. See [S1] [S2].');

    expect(count($buttons))->toBeLessThanOrEqual(8)->and(last($buttons)['type'])->toBe('handoff')
        ->and(collect($buttons)->where('type', 'handoff'))->toHaveCount(1);
});

it('does not let an own URL typed inside a chunk body into the allow-list (F10)', function () {
    $typed = 'https://poson.test/en/page/typed-in-chunk';
    kcPage("Scanner setup guide for warehouses and stores. Read {$typed}", 'Setup guide');
    app(RebuildAiKnowledge::class)->handle();

    [$answer] = kcRun('scanner guide warehouses stores', "Read {$typed}");

    expect($answer->status)->toBe(ChatAiStatus::Blocked)->and($answer->reason)->toBe('link');
});
