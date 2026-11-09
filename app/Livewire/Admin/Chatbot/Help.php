<?php

namespace App\Livewire\Admin\Chatbot;

use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Chat bot → Instructions (plan 28 §15, /admin/chatbot/help): a read-only
 * page of short steps for the people who run the bot. Open to anyone with a
 * Chat bot permission (chatbot.rules, chatbot.settings or telegram.inbox);
 * each section is shown only when the user has the permission its screen
 * needs. Texts live in lang/{uz,ru,en}/chatbot_help.php and must be updated
 * whenever a Chat bot screen changes.
 */
#[Layout('layouts::admin')]
class Help extends Component
{
    /**
     * Section key => permissions of which one opens it (empty = everyone who
     * can open the page).
     *
     * @var array<string, list<string>>
     */
    public const SECTIONS = [
        'how' => [],
        'teaching' => ['chatbot.rules'],
        'unmatched' => ['chatbot.rules'],
        'console' => ['chatbot.rules'],
        'answers' => ['chatbot.rules'],
        'stats' => ['chatbot.rules'],
        'settings' => ['chatbot.settings'],
        'inbox' => ['telegram.inbox'],
    ];

    public const PERMISSIONS = ['chatbot.rules', 'chatbot.settings', 'telegram.inbox'];

    public function mount(): void
    {
        $this->guard();
    }

    public function hydrate(): void
    {
        $this->guard();
    }

    /**
     * @return list<string>
     */
    public static function visibleSections(object $user): array
    {
        return array_keys(array_filter(
            self::SECTIONS,
            fn (array $permissions): bool => $permissions === [] || $user->canAny($permissions),
        ));
    }

    private function guard(): void
    {
        abort_unless(auth()->user()?->canAny(self::PERMISSIONS), 403);
    }

    public function render(): View
    {
        $this->guard();

        return view('livewire.admin.chatbot.help', [
            'sections' => self::visibleSections(auth()->user()),
        ])->title(__('chatbot_help.title'));
    }
}
