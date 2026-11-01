<?php

use App\Models\Category;
use App\Models\NewsPost;
use App\Support\Content\PostAudit;

function auditCodes(NewsPost $post): array
{
    return array_map(fn (array $issue): string => $issue['code'].'@'.($issue['locale'] ?? '-'), PostAudit::issues($post));
}

it('reports codes only, with locale and severity', function () {
    $post = NewsPost::factory()->create();

    foreach (PostAudit::issues($post) as $issue) {
        expect(array_keys($issue))->toBe(['code', 'locale', 'severity'])
            ->and($issue['severity'])->toBeIn(['error', 'warning', 'info']);
    }
});

it('flags a missing uz title as an error and missing ru/en titles as warnings', function () {
    $post = NewsPost::factory()->create(['title' => ['uz' => '', 'ru' => '', 'en' => 'English']]);
    $issues = collect(PostAudit::issues($post));

    expect($issues->firstWhere(fn ($i) => $i['code'] === 'title_missing' && $i['locale'] === 'uz')['severity'])->toBe('error')
        ->and($issues->firstWhere(fn ($i) => $i['code'] === 'title_missing' && $i['locale'] === 'ru')['severity'])->toBe('warning')
        ->and(auditCodes($post))->not->toContain('meta_title_missing@ru');
});

it('checks meta title and description lengths', function () {
    $post = NewsPost::factory()->create([
        'meta_title' => ['uz' => str_repeat('a', 61), 'ru' => 'ok', 'en' => null],
        'meta_description' => ['uz' => str_repeat('b', 69), 'ru' => str_repeat('b', 161), 'en' => str_repeat('b', 100)],
    ]);
    $codes = auditCodes($post);

    expect($codes)->toContain('meta_title_too_long@uz', 'meta_title_missing@en', 'meta_description_length@uz', 'meta_description_length@ru')
        ->not->toContain('meta_title_too_long@ru', 'meta_description_length@en', 'meta_description_missing@en');
});

it('flags excerpt, short body, cover and links', function () {
    $post = NewsPost::factory()->create(['excerpt' => ['uz' => '', 'ru' => 'x', 'en' => 'x']]);
    $codes = auditCodes($post);

    expect($codes)->toContain('excerpt_missing@uz', 'body_short@uz', 'cover_missing@-', 'links_missing@-')
        ->not->toContain('excerpt_missing@ru');

    $post->categories()->attach(Category::factory()->create());

    expect(auditCodes($post->fresh()))->not->toContain('links_missing@-');
});

it('does not contain raw content', function () {
    $post = NewsPost::factory()->create(['title' => ['uz' => 'SECRET-TITLE-SENTINEL', 'ru' => '', 'en' => '']]);

    expect(json_encode(PostAudit::issues($post)))->not->toContain('SECRET-TITLE-SENTINEL');
});
