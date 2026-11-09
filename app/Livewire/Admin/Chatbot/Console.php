<?php

namespace App\Livewire\Admin\Chatbot;

use App\Actions\Chat\TestChatPhrase;
use App\Enums\ChatChannel;
use App\Models\ChatRule;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Chat bot → Test console (plan 28 C2, CB-11, chatbot.rules): type a phrase,
 * pick a language and channel and see which rule would answer, with the score
 * of every matched keyword and the rendered reply. A dry run by
 * TestChatPhrase: nothing is written or sent, and lookups, lead dialogs,
 * product search and hand-offs are only reported ("would run").
 */
#[Layout('layouts::admin')]
class Console extends Component
{
    public const MAX_CHARS = 500;

    public string $phrase = '';

    public string $locale = 'uz';

    public string $channel = 'telegram';

    /**
     * @var array<string, mixed>|null
     */
    public ?array $result = null;

    public function mount(): void
    {
        $this->authorize('viewAny', ChatRule::class);
    }

    public function hydrate(): void
    {
        $this->authorize('viewAny', ChatRule::class);
    }

    public function run(TestChatPhrase $testChatPhrase): void
    {
        $this->authorize('viewAny', ChatRule::class);

        $this->phrase = mb_substr($this->phrase, 0, self::MAX_CHARS);
        $this->validate([
            'phrase' => ['required', 'string', 'max:'.self::MAX_CHARS],
            'locale' => ['required', 'in:'.implode(',', config('app.supported_locales'))],
            'channel' => ['required', 'in:'.implode(',', array_column(ChatChannel::cases(), 'value'))],
        ]);

        // A cheap guard so a stuck key or script cannot hammer the cache and the database.
        if (RateLimiter::tooManyAttempts('chatbot-console:'.auth()->id(), 120)) {
            $this->addError('phrase', __('chatbot_admin.console.too_many'));

            return;
        }

        RateLimiter::hit('chatbot-console:'.auth()->id(), 60);

        $this->result = $testChatPhrase->handle(auth()->user(), $this->phrase, $this->locale, ChatChannel::from($this->channel));
    }

    public function render(): View
    {
        $this->authorize('viewAny', ChatRule::class);

        return view('livewire.admin.chatbot.console')->title(__('chatbot_admin.console.title'));
    }
}
