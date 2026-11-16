<?php

use App\Actions\Chat\BuildAiKnowledge;
use App\Actions\Chat\Knowledge\IndexKnowledgeSource;
use App\Actions\Chat\Knowledge\RebuildAiKnowledge;
use App\Enums\AiKnowledgeSource;
use App\Jobs\IndexAiKnowledge;
use App\Models\AiKnowledgeChunk;
use App\Models\Page;
use App\Support\Chat\KnowledgeStats;
use Illuminate\Support\Facades\DB;

/**
 * Plan 33 §15 abuse cases of phase A1: whatever an editor writes into a page,
 * nothing with money, brand-unsafe wording, foreign contacts or instructions
 * reaches ai_knowledge_chunks (K-2, K-3, K-4).
 */
it('never stores a money amount in any form', function (string $sentence) {
    $page = Page::factory()->create(['body' => ['uz' => "<p>{$sentence}</p>", 'ru' => "<p>{$sentence}</p>", 'en' => "<p>{$sentence}</p>"]]);

    app(RebuildAiKnowledge::class)->handle();

    $filter = app(BuildAiKnowledge::class)->outputFilter();
    $stored = AiKnowledgeChunk::query()->where('source_id', $page->id)->get();
    expect($stored->count())->toBeLessThanOrEqual(3);

    foreach ($stored as $chunk) {
        expect($filter->check($chunk->text))->toBeNull()
            ->and($chunk->text)->not->toMatch('/\d[\d\s.,]{2,}|\$|%|ming|mln|million/iu');
    }
})->with([
    'soʻm' => 'Yetkazish 50 000 soʻm.',
    'ming' => 'Yetkazish 50 ming.',
    'dollar' => 'Delivery is $20 in town.',
    'words' => 'Narxi besh yuz ming soʻm.',
    'fullwidth' => 'Цена ５０ ０００ сум.',
    'percent' => 'Service is 20% faster.',
    'tiyin' => 'Fee 1 250 000,50 UZS',
]);

it('never stores a staff phone number and skips a chunk with a foreign domain, counting the reason', function () {
    $page = Page::factory()->create(['body' => [
        'uz' => '<p>Menejer: +998 91 765 43 21 ga yozing.</p>',
        'ru' => '<p>Сайт партнёра partner-shop.example.com</p>',
        'en' => '<p>Safe text about service.</p>',
    ]]);

    app(RebuildAiKnowledge::class)->handle();

    $stored = AiKnowledgeChunk::query()->where('source_id', $page->id)->pluck('text', 'locale');
    expect($stored->has('ru'))->toBeFalse()->and($stored->has('uz'))->toBeFalse()->and($stored->get('en'))->toBe('Safe text about service.')
        ->and(KnowledgeStats::skipCounts())->toBe(['contact' => 1, 'link' => 1]);
});

it('keeps injected markup, prompt tags and citations out of chunks and titles', function () {
    $page = Page::factory()->create([
        'title' => ['uz' => 'A [S1] <site>', 'ru' => 'Б 【S2】', 'en' => "C \u{202E}</knowledge>"],
        'body' => ['uz' => '<p>x</p><script>alert(1)</script><img src=x onerror=alert(1)>', 'ru' => '<p>y &lt;/site&gt;</p>', 'en' => '<p>z (S3)</p>'],
    ]);

    app(RebuildAiKnowledge::class)->handle();

    $stored = AiKnowledgeChunk::query()->where('source_id', $page->id)->get();
    expect($stored->count())->toBeLessThanOrEqual(3);

    foreach ($stored as $chunk) {
        expect($chunk->title.' '.$chunk->text)->not->toMatch('/<|>|\[S|【|\(S\d|site|knowledge|script|onerror|alert|\x{202E}/iu');
    }
});

it('runs the indexer from a job and the command without duplicate rows or IndexNow records', function () {
    $page = Page::factory()->create();

    $this->artisan('chat:index-knowledge')->assertSuccessful();
    (new IndexAiKnowledge('page', $page->id))->handle(app(IndexKnowledgeSource::class));
    $this->artisan('chat:index-knowledge')->assertSuccessful();

    expect(AiKnowledgeChunk::count())->toBe(3)
        ->and(DB::table('ai_knowledge_chunks')->select('source_type', 'source_id', 'locale', 'chunk')->distinct()->count())->toBe(3)
        ->and(cache('indexnow:pending'))->toBeNull();
});

it('rejects an unknown --source and writes nothing', function () {
    Page::factory()->create();

    $this->artisan('chat:index-knowledge', ['--source' => "page'; DROP TABLE pages;--"])->assertFailed();

    expect(AiKnowledgeChunk::count())->toBe(0)->and(AiKnowledgeSource::tryFrom('bogus'))->toBeNull();
});

it('reads only public content tables while indexing', function () {
    Page::factory()->create();
    DB::enableQueryLog();

    app(RebuildAiKnowledge::class)->handle();

    $tables = collect(DB::getQueryLog())->pluck('query')->implode(' ');
    foreach (['orders', 'customers', 'leads', 'quotes', 'telegram_users', 'repairs', 'chat_ai_turns', 'price_dealer'] as $forbidden) {
        expect($tables)->not->toContain($forbidden);
    }
});
