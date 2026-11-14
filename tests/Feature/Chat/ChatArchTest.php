<?php

/**
 * Plan 28 C1 / CB-9, CB-1, amended by plan 30 (AI-4, P1): the chat code never
 * makes HTTP calls; the Anthropic SDK is used only in App\Services\Ai; the
 * rule engine (App\Support\Chat) never touches the AI services, HTTP or
 * Telegram; the chat actions and the AI job reach the model only through the
 * ChatModel interface; matching code uses no regular expressions.
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
    ->expect(['App\Support\Chat', 'App\Actions\Chat', 'App\Jobs\SendBotAnswer', 'App\Jobs\AnswerChatWithAi'])
    ->not->toUse(['OpenAI', 'Anthropic', 'Laravel\Ai', 'Prism', 'Gemini', 'Illuminate\Support\Facades\Http']);

arch('the Anthropic SDK is used only by the AI service')
    ->expect('Anthropic')
    ->toOnlyBeUsedIn('App\Services\Ai');

arch('the AI service uses no other AI SDK')
    ->expect('App\Services\Ai')
    ->not->toUse(['OpenAI', 'Gemini', 'Prism', 'Laravel\Ai', 'EchoLabs']);

it('requires no other AI SDK than anthropic-ai/sdk', function () {
    $require = array_keys((array) json_decode((string) file_get_contents(base_path('composer.json')), true)['require']);

    expect(array_values(array_filter($require, fn (string $package): bool => preg_match('~openai|gemini|prism|laravel/ai|echolabs|llm|mistral|ollama|anthropic~i', $package) === 1)))
        ->toBe(['anthropic-ai/sdk']);
});

arch('the rule engine never uses the AI services, HTTP or Telegram')
    ->expect('App\Support\Chat')
    ->not->toUse(['App\Services\Ai', 'App\Services\Telegram', 'App\Jobs', 'Illuminate\Http\Client', 'GuzzleHttp']);

arch('chat actions and the AI job use only the ChatModel interface')
    ->expect(['App\Actions\Chat', 'App\Jobs\AnswerChatWithAi'])
    ->not->toUse(['App\Services\Ai\AnthropicChatModel', 'GuzzleHttp']);

arch('only the AI job and its fakes resolve the chat model')
    ->expect('App\Services\Ai\ChatModel')
    ->toOnlyBeUsedIn(['App\Services\Ai', 'App\Actions\Chat\AnswerWithAi', 'App\Jobs\AnswerChatWithAi', 'App\Providers\AppServiceProvider']);

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
