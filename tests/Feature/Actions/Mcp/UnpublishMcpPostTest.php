<?php

use App\Actions\Mcp\UnpublishMcpPost;
use App\Enums\AuditAction;
use App\Enums\NewsStatus;
use App\Enums\Role;
use App\Enums\SettingsArea;
use App\Livewire\Admin\Settings\McpAccess;
use App\Models\FaqItem;
use App\Models\McpCall;
use App\Models\NewsPost;
use App\Models\SettingsAudit;
use App\Support\Mcp\McpAlerts;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

beforeEach(function () {
    mcpTelegramConfigured();
    config(['app.url' => 'https://poson.test']);
});

function publishCall(NewsPost $post, $ceo, array $state = []): McpCall
{
    return McpCall::factory()->for($ceo)->publishOf($post)->create($state);
}

it('unpublishes a published post: draft, published_at kept, audit row and one alert', function () {
    [$ceo] = mcpHolder();
    $post = NewsPost::factory()->create(['title' => ['uz' => 'Yangi <b>skaner</b> kelib tushdi']]);
    $publishedAt = $post->published_at;
    $call = publishCall($post, $ceo);
    $this->actingAs($ceo);
    confirmAdminPassword();

    Livewire::test(McpAccess::class)->set('tab', 'calls')
        ->assertSee('data-unpublish="'.$call->id.'"', false)
        ->call('unpublish', $call->id)
        ->assertDispatched('toast');

    $post->refresh();
    expect($post->status)->toBe(NewsStatus::Draft)
        ->and($post->published_at->equalTo($publishedAt))->toBeTrue()
        ->and(SettingsAudit::query()->where('key', 'mcp_posts.'.$post->id)->sole())
        ->action->toBe(AuditAction::Unpublished)->area->toBe(SettingsArea::Mcp)->user_id->toBe($ceo->id);

    $alerts = mcpAlertTexts();
    expect($alerts)->toHaveCount(1)
        ->and($alerts[0])->toContain('Yangi &lt;b&gt;skaner&lt;/b&gt; kelib tushdi')
        ->and($alerts[0])->toContain('https://poson.test/uz/news/'.$post->slug)
        ->and($alerts[0])->toContain('https://poson.test/admin/news/'.$post->id.'/edit')
        ->and($alerts[0])->toContain($ceo->name)
        ->and($alerts[0])->not->toContain($ceo->email);
});

it('unpublishes a scheduled post and hides the button once it is a draft', function () {
    [$ceo] = mcpHolder();
    $post = NewsPost::factory()->scheduled()->create();
    $call = publishCall($post, $ceo);
    $this->actingAs($ceo);
    confirmAdminPassword();

    $component = Livewire::test(McpAccess::class)->set('tab', 'calls')->assertSee('data-unpublish="'.$call->id.'"', false);
    $component->call('unpublish', $call->id);

    expect($post->fresh()->status)->toBe(NewsStatus::Draft);
    $component->assertDontSee('data-unpublish', false);
});

it('forbids a direct Livewire call from other roles (M3-S9)', function (Role $role) {
    [$ceo] = mcpHolder();
    $post = NewsPost::factory()->create();
    $call = publishCall($post, $ceo);
    $this->actingAs($ceo);
    confirmAdminPassword();
    $component = Livewire::test(McpAccess::class);
    $this->actingAs(staffUser($role));
    confirmAdminPassword();

    $component->call('unpublish', $call->id)->assertForbidden();

    expect($post->fresh()->status)->toBe(NewsStatus::Published)->and(mcpAlertTexts())->toBe([]);
})->with([Role::ContentManager, Role::SalesManager, Role::ServiceEngineer]);

it('needs a recent password confirmation', function () {
    [$ceo] = mcpHolder();
    $post = NewsPost::factory()->create();
    $call = publishCall($post, $ceo);
    $this->actingAs($ceo);

    Livewire::test(McpAccess::class)->call('unpublish', $call->id)->assertRedirect(route('admin.confirm-password'));

    expect($post->fresh()->status)->toBe(NewsStatus::Published);
});

it('refuses with one generic message for an already-draft post, a second click, a missing id and wrong calls', function () {
    [$ceo] = mcpHolder();
    $draft = NewsPost::factory()->draft()->create();
    $live = NewsPost::factory()->create();
    $faq = FaqItem::factory()->create();
    $writeOnly = McpCall::factory()->for($ceo)->publishOf($live)->create(['is_publish' => false]);
    $otherTool = McpCall::factory()->for($ceo)->publishOf($live)->create(['tool' => 'update_post']);
    $faqCall = McpCall::factory()->for($ceo)->publishOf($faq)->create();
    $draftCall = publishCall($draft, $ceo);
    $liveCall = publishCall($live, $ceo);
    $this->actingAs($ceo);
    confirmAdminPassword();
    $messages = [];

    $attempt = function (int $id) use (&$messages): void {
        try {
            app(UnpublishMcpPost::class)->handle($id, auth()->user());
        } catch (ValidationException $exception) {
            $messages[] = $exception->errors()['call'][0];
        }
    };

    foreach ([$draftCall->id, $writeOnly->id, $otherTool->id, $faqCall->id, 999999] as $id) {
        $attempt($id);
    }
    expect($live->fresh()->status)->toBe(NewsStatus::Published);

    Livewire::test(McpAccess::class)->call('unpublish', $liveCall->id)->call('unpublish', $liveCall->id);
    $attempt($liveCall->id);

    expect($messages)->toHaveCount(6)->and(array_unique($messages))->toHaveCount(1)
        ->and(SettingsAudit::query()->where('action', AuditAction::Unpublished)->count())->toBe(1)
        ->and(mcpAlertTexts())->toHaveCount(1)
        ->and($faq->fresh())->not->toBeNull();
});

it('sends no alert and keeps the post when the transaction rolls back', function () {
    [$ceo] = mcpHolder();
    $post = NewsPost::factory()->create();
    $call = publishCall($post, $ceo);
    SettingsAudit::creating(fn () => throw new RuntimeException('audit failed'));

    expect(fn () => app(UnpublishMcpPost::class)->handle($call->id, $ceo))->toThrow(RuntimeException::class);

    expect($post->fresh()->status)->toBe(NewsStatus::Published)->and(mcpAlertTexts())->toBe([]);
});

it('formats publish alerts with the title, https URLs and the staff name only', function (bool $scheduled) {
    $staff = staffUser(Role::Admin);
    $post = NewsPost::factory()->article()->create(['title' => ['uz' => "Qo'llanma\u{202E}\n<script>x</script>"]]);

    McpAlerts::postPublished($post, $staff, $scheduled);

    $alerts = mcpAlertTexts();
    expect($alerts)->toHaveCount(1)
        ->and($alerts[0])->toContain('&lt;script&gt;x&lt;/script&gt;')
        ->and($alerts[0])->not->toContain("\u{202E}")
        ->and($alerts[0])->toContain('https://poson.test/uz/articles/'.$post->slug)
        ->and($alerts[0])->toContain('https://poson.test/admin/articles/'.$post->id.'/edit')
        ->and($alerts[0])->toContain($staff->name)
        ->and($alerts[0])->not->toContain($staff->email)->not->toContain($post->getTranslation('body', 'uz'));
})->with([false, true]);
