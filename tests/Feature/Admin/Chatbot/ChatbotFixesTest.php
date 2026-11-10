<?php

use App\Actions\Chat\SaveChatbotSettings;
use App\Actions\Chat\SaveChatRule;
use App\Enums\ChatChannel;
use App\Enums\Role;
use App\Livewire\Admin\Chatbot\Settings as ChatbotSettingsScreen;
use App\Models\Page;
use App\Support\Chat\ChatbotSwitch;
use App\Support\Chat\ChatSettings;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

it('refuses invalid UTF-8 in a setting text instead of saving it empty', function () {
    $cm = staffUser(Role::ContentManager);

    expect(fn () => app(SaveChatbotSettings::class)->handle(['texts' => ['greeting' => ['uz' => "Salom \xC3\x28"]]], $cm))
        ->toThrow(function (ValidationException $e) {
            expect(array_keys($e->errors()))->toContain('texts.greeting.uz');
        });

    expect(ChatSettings::all()['texts']['greeting']['uz'])->toBe('');
});

it('refuses invalid UTF-8 in a rule name, answer or label', function (array $override, string $field) {
    $input = array_merge(['key' => 'utf_topic', 'name' => 'Topic', 'action' => 'answer', 'answer' => ['uz' => 'Salom']], $override);

    expect(fn () => app(SaveChatRule::class)->handle($input, staffUser(Role::ContentManager)))
        ->toThrow(function (ValidationException $e) use ($field) {
            expect(array_keys($e->errors()))->toContain($field);
        });
})->with([
    'name' => [['name' => "Bad \xC3\x28"], 'name'],
    'answer' => [['answer' => ['uz' => "Bad \xC3\x28"]], 'answer.uz'],
]);

it('shows a paused badge when a switch is stored on but the privacy page is not ready, and lets an Admin clear it', function () {
    $page = Page::factory()->system('privacy')->create(['is_active' => true, 'needs_review' => false]);
    $admin = staffUser(Role::Admin);

    app(SaveChatbotSettings::class)->handle(['telegram_enabled' => true], $admin);

    $page->update(['needs_review' => true]);
    ChatbotSwitch::forget();

    expect(ChatbotSwitch::isEnabled(ChatChannel::Telegram))->toBeFalse();

    $component = Livewire::actingAs($admin)->test(ChatbotSettingsScreen::class)
        ->assertSee('data-paused="telegram"', false)
        ->assertSet('form.telegram_enabled', true);

    $component->set('form.telegram_enabled', false)->call('save')->assertHasNoErrors();

    expect(ChatbotSwitch::isStored(ChatChannel::Telegram))->toBeFalse();

    Livewire::actingAs($admin)->test(ChatbotSettingsScreen::class)->assertDontSee('data-paused="telegram"', false);
});
