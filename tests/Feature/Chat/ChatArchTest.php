<?php

/**
 * Plan 28 C1 / CB-9, CB-1: the chat code never makes HTTP calls or uses an AI
 * SDK, and matching code uses no regular expressions.
 */
arch('chat support and actions make no HTTP calls')
    ->expect(['App\Support\Chat', 'App\Actions\Chat'])
    ->not->toUse([
        'Illuminate\Support\Facades\Http',
        'Illuminate\Http\Client',
        'GuzzleHttp',
        'Illuminate\Support\Facades\Process',
        'App\Services\Telegram\TelegramClient',
    ]);

arch('chat code uses no AI SDK')
    ->expect(['App\Support\Chat', 'App\Actions\Chat', 'App\Jobs\SendBotAnswer'])
    ->not->toUse(['OpenAI', 'Anthropic', 'Laravel\Ai', 'Prism', 'Gemini', 'Illuminate\Support\Facades\Http']);

it('does not use preg functions in the matching code', function (string $file) {
    $code = (string) file_get_contents(base_path($file));
    $code = preg_replace('~/\*.*?\*/|//[^\n]*~s', '', $code);

    expect($code)->not->toMatch('/\bpreg_[a-z_]+\s*\(/');
})->with([
    'app/Support/Chat/Engine.php',
    'app/Support/Chat/RuleIndex.php',
    'app/Support/Chat/LinkTargets.php',
    'app/Support/Chat/Skills/FaqSkill.php',
    'app/Support/Chat/Skills/CompanyInfoSkill.php',
    'app/Support/Chat/Skills/LinkSkill.php',
    'app/Support/Chat/Skills/MenuSkill.php',
    'app/Actions/Chat/SaveChatRule.php',
]);

it('never calls an HTTP client from the chat files', function () {
    $files = array_merge(glob(base_path('app/Support/Chat/*.php')), glob(base_path('app/Support/Chat/*/*.php')), glob(base_path('app/Actions/Chat/*.php')));

    expect($files)->not->toBeEmpty();

    foreach ($files as $file) {
        expect(file_get_contents($file))->not->toMatch('/Http::|new Client\(|curl_init|file_get_contents\(\s*[\'"]http/');
    }
});
