<?php

/**
 * Plan 28 C5a, WC-4 / CB-9: the website chat code never reaches AI, HTTP or
 * Telegram. The listed classes are the whole web chat surface of C5a and
 * the C5b widget (App\Livewire\Site\Chat, the launcher state and limits).
 */
$web = [
    'App\Actions\Chat\Web',
    'App\Support\Chat\WebChatToken',
    'App\Support\Chat\WebChatIp',
    'App\Support\Chat\WebChatVersion',
    'App\Support\Chat\WebChatDialog',
    'App\Support\Chat\WebChatInput',
    'App\Support\Chat\WebChatPage',
    'App\Support\Chat\WebChatSwitch',
    'App\Support\Chat\WebChatLimits',
    'App\Support\Chat\WebChatLauncher',
    'App\Livewire\Site\Chat',
    'App\Support\Chat\Adapters\WebAdapter',
    'App\Models\WebChat',
    'App\Models\WebChatMessage',
];

arch('web chat code never uses AI, the AI switch or HTTP')
    ->expect($web)
    ->not->toUse([
        'App\Services\Ai',
        'App\Services\Ai\ChatModel',
        'App\Support\Chat\AiSwitch',
        'App\Actions\Chat\GuardAiRequest',
        'App\Actions\Chat\AnswerWithAi',
        'App\Jobs\AnswerChatWithAi',
        'App\Actions\Chat\AiHistory',
        'Anthropic',
        'Illuminate\Support\Facades\Http',
        'Illuminate\Http\Client',
        'GuzzleHttp',
        'App\Services\Telegram',
    ]);

arch('the hand-off alert reaches Telegram only through the queued alert listener')
    ->expect('App\Events\Chat\WebChatHandedOff')
    ->not->toUse(['App\Services\Telegram', 'Illuminate\Support\Facades\Http', 'App\Services\Ai']);

it('never calls an HTTP client from the web chat files', function () {
    $files = glob(base_path('app/Actions/Chat/Web/*.php'));

    expect($files)->not->toBeEmpty();

    foreach ([...$files, ...glob(base_path('app/Support/Chat/WebChat*.php')), base_path('app/Support/Chat/Adapters/WebAdapter.php'), ...glob(base_path('app/Livewire/Site/Chat/*.php'))] as $file) {
        $code = (string) preg_replace('~/\*.*?\*/|//[^\n]*~s', '', (string) file_get_contents($file));

        expect($code)->not->toMatch('/Http::|new Client\(|curl_init|file_get_contents\(\s*[\'"]http|AiSwitch|ChatModel/');
    }
});
