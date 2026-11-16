<?php

use App\Actions\Chat\Knowledge\QueueKnowledgeRebuild;
use App\Actions\Chat\Knowledge\RebuildAiKnowledge;
use App\Enums\AuditAction;
use App\Enums\Role;
use App\Enums\SettingsArea;
use App\Jobs\RebuildAiKnowledgeIndex;
use App\Livewire\Admin\Chatbot\Console;
use App\Livewire\Admin\Chatbot\Help;
use App\Livewire\Admin\Chatbot\Settings;
use App\Models\AiKnowledgeChunk;
use App\Models\Page;
use App\Models\SettingsAudit;
use App\Services\Ai\ChatModel;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

/**
 * Plan 33 A2: Chat bot → Settings → Website knowledge (K-11) and the console
 * preview of the <site> block (K-14).
 */
function kwPage(array $attributes = []): Page
{
    return Page::factory()->create(array_merge([
        'title' => ['uz' => 'Servis', 'ru' => 'Сервис', 'en' => 'Warranty service'],
        'body' => ['uz' => '<p>Skaner ta’mirlash xizmati.</p>', 'ru' => '<p>Ремонт сканеров.</p>', 'en' => '<p>Scanner repair service.</p>'],
    ], $attributes));
}

it('shows the card with counts, skip counters and the last indexed sources with admin links', function () {
    $page = kwPage();
    kwPage(['body' => ['uz' => '<p>chegirma</p>', 'ru' => '<p>скидки</p>', 'en' => '<p>Fine text.</p>'], 'title' => ['uz' => 'Boshqa', 'ru' => 'Другая', 'en' => 'Other page']]);
    app(RebuildAiKnowledge::class)->handle();

    $html = $this->actingAs(staffUser(Role::Admin))->get(route('admin.chatbot.settings'))->assertOk()->getContent();

    expect($html)->toContain('data-knowledge-card')->toContain('data-knowledge-rebuild')
        ->toContain('data-knowledge-recent')->toContain(route('admin.pages.edit', $page->id))
        ->toContain('data-knowledge-skipped')
        ->and(AiKnowledgeChunk::count())->toBe(4);
});

it('rebuilds now for admin and content manager only', function (Role $role, bool $allowed) {
    Queue::fake();
    $user = staffUser($role);

    $component = Livewire::actingAs($user)->test(Settings::class);

    if (! $allowed) {
        $component->assertForbidden();
        Queue::assertNothingPushed();

        return;
    }

    $component->call('rebuildKnowledge')->assertDispatched('toast');
    Queue::assertPushed(RebuildAiKnowledgeIndex::class, 1);
    expect(SettingsAudit::query()->where('key', 'ai_knowledge_rebuild')->where('area', SettingsArea::Chatbot->value)->where('action', AuditAction::Generated->value)->where('user_id', $user->id)->count())->toBe(1);
})->with([
    'admin' => [Role::Admin, true],
    'content manager' => [Role::ContentManager, true],
    'sales manager' => [Role::SalesManager, false],
    'service engineer' => [Role::ServiceEngineer, false],
]);

it('respects the five-minute lock: a second click dispatches and audits nothing', function () {
    Queue::fake();
    $component = Livewire::actingAs(staffUser(Role::Admin))->test(Settings::class);

    $component->call('rebuildKnowledge')->call('rebuildKnowledge');

    Queue::assertPushed(RebuildAiKnowledgeIndex::class, 1);
    expect(SettingsAudit::query()->where('key', 'ai_knowledge_rebuild')->count())->toBe(1);

    $this->travel(6)->minutes();
    Cache::forget(QueueKnowledgeRebuild::LOCK_KEY);
    Cache::lock('laravel_unique_job:'.RebuildAiKnowledgeIndex::class.'ai-knowledge-rebuild')->forceRelease();
    $component->call('rebuildKnowledge');
    Queue::assertPushed(RebuildAiKnowledgeIndex::class, 2);
});

it('refuses the action itself without the permission', function () {
    Queue::fake();

    expect(fn () => app(QueueKnowledgeRebuild::class)->handle(staffUser(Role::SalesManager), '127.0.0.1'))->toThrow(AuthorizationException::class);
    Queue::assertNothingPushed();
});

it('runs the queued rebuild with no payload', function () {
    kwPage();

    $job = new RebuildAiKnowledgeIndex;
    $job->handle(app(RebuildAiKnowledge::class));

    expect(AiKnowledgeChunk::count())->toBe(3)->and($job->uniqueId())->toBe('ai-knowledge-rebuild')->and($job->queue)->toBe('knowledge');
});

it('shows the website passages in the console without ever resolving the chat model', function () {
    kwPage();
    app(RebuildAiKnowledge::class)->handle();
    $this->mock(ChatModel::class)->shouldNotReceive('complete');

    Livewire::actingAs(staffUser(Role::ContentManager))->test(Console::class)
        ->set('phrase', 'scanner repair')->set('locale', 'en')->call('run')
        ->assertSee('Warranty service')->assertSee('[S1]');
});

it('escapes console output and refuses the console without chatbot.rules', function () {
    kwPage(['title' => ['uz' => 'X', 'ru' => 'X', 'en' => '<img src=x onerror=alert(1)> scanner']]);
    app(RebuildAiKnowledge::class)->handle();

    Livewire::actingAs(staffUser(Role::ContentManager))->test(Console::class)
        ->set('phrase', 'scanner repair')->set('locale', 'en')->call('run')
        ->assertDontSeeHtml('<img src=x onerror')->assertSee('‹img', false);

    Livewire::actingAs(staffUser(Role::SalesManager))->test(Console::class)->assertForbidden();
});

it('has the help section for people with chatbot.settings, in all three languages', function () {
    expect(Help::SECTIONS)->toHaveKey('knowledge');

    foreach (['uz', 'ru', 'en'] as $locale) {
        expect(trans('chatbot_help.sections.knowledge.steps', [], $locale))->toHaveCount(5)
            ->and(implode(' ', trans('chatbot_help.sections.knowledge.steps', [], $locale)))->not->toMatch('/chegirma|скидк|discount|arzon|cheap/iu');
    }
});
