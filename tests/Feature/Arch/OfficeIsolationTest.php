<?php

use App\Actions\Office\CallOfficeModel;
use App\Enums\AiUsageChannel;
use App\Jobs\DeliverBotMessage;
use App\Jobs\SendBotAnswer;
use App\Jobs\SendBotPrompt;
use App\Jobs\SendTelegramMessage;
use App\Models\TelegramUser;
use App\Services\Ai\AiRequest;
use App\Services\Telegram\TelegramClient;
use App\Support\Telegram\Bot\BotLimits;
use App\Support\Telegram\Bot\BotSwitch;
use Tests\Support\FakeChatModel;

/*
| Plan 41 VO-2 / §1.5: phase 1 of the Virtual office never sends anything to
| Telegram and never reaches the customer bot.
*/

function officeRoot(string $path = ''): string
{
    return dirname(__DIR__, 3).($path === '' ? '' : '/'.$path);
}

arch('the business ingest never touches the customer bot')
    ->expect('App\Actions\Office\HandleBusinessUpdate')
    ->not->toUse([TelegramUser::class, BotLimits::class, BotSwitch::class, 'App\Actions\Telegram\AdvanceRegistration', SendBotPrompt::class, SendBotAnswer::class, DeliverBotMessage::class, SendTelegramMessage::class, TelegramClient::class]);

arch('Virtual office code never sends to Telegram')
    ->expect(['App\Actions\Office', 'App\Support\Office', 'App\Livewire\Admin\Office', 'App\Jobs\AnalyseBusinessChat', 'App\Jobs\AnalyseTelegramGroup', 'App\Jobs\WipeBusinessDataChunk'])
    ->not->toUse([TelegramClient::class, SendTelegramMessage::class, SendBotPrompt::class, SendBotAnswer::class, DeliverBotMessage::class, 'App\Jobs\SendAiCapAlert']);

arch('the business DTO is not the customer bot DTO')
    ->expect('App\Support\Telegram\Bot\BusinessUpdate')
    ->toBeFinal()
    ->not->toExtend('App\Support\Telegram\Bot\UpdateParser');

it('gives TelegramClient exactly one business method, and it only reads', function () {
    $methods = collect((new ReflectionClass(TelegramClient::class))->getMethods(ReflectionMethod::IS_PUBLIC))
        ->filter(fn (ReflectionMethod $method): bool => $method->class === TelegramClient::class)
        ->map(fn (ReflectionMethod $method): string => $method->name)
        ->filter(fn (string $name): bool => str_contains(strtolower($name), 'business'))
        ->values()->all();

    expect($methods)->toBe(['getBusinessConnection']);
});

it('never puts business_connection_id into a send, edit or copy', function () {
    $files = [];

    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(officeRoot('app'), FilesystemIterator::SKIP_DOTS)) as $file) {
        if ($file->getExtension() === 'php' && str_contains((string) file_get_contents($file->getPathname()), 'business_connection_id')) {
            $files[] = substr($file->getPathname(), strlen(officeRoot()) + 1);
        }
    }

    sort($files);

    // Parsing (read side), the one read call and the ingest's own table column only.
    expect($files)->toBe([
        'app/Actions/Office/HandleBusinessUpdate.php',
        'app/Models/BusinessChat.php',
        'app/Services/Telegram/TelegramClient.php',
        'app/Support/Telegram/Bot/BusinessUpdate.php',
        // Plan 44: the group parser only refuses a business message (read side).
        'app/Support/Telegram/Bot/GroupUpdate.php',
        'app/Support/Telegram/Bot/UpdateParser.php',
    ]);

    // In TelegramClient it appears once, inside getBusinessConnection().
    $client = (string) file_get_contents(officeRoot('app/Services/Telegram/TelegramClient.php'));
    $method = new ReflectionMethod(TelegramClient::class, 'getBusinessConnection');
    $body = implode("\n", array_slice(explode("\n", $client), $method->getStartLine() - 1, $method->getEndLine() - $method->getStartLine() + 1));

    expect(substr_count($client, 'business_connection_id'))->toBe(1)
        ->and($body)->toContain("\$this->call('getBusinessConnection', ['business_connection_id' => \$connectionId])");
});

// Plan 44 L6: the reserved office model call is used only by the two office runners.
arch('only the office runners use CallOfficeModel')
    ->expect('App\Actions\Office\CallOfficeModel')
    ->toOnlyBeUsedIn(['App\Actions\Office\RunBusinessChatAnalysis', 'App\Actions\Office\RunGroupAnalysis', 'App\Actions\Office\RunEducationDryRun']);

it('refuses a non-office channel in CallOfficeModel without a call (L6)', function () {
    $fake = FakeChatModel::bind('{}');
    $tokens = ['input' => 0, 'output' => 0, 'cache' => 0];
    $models = [];
    $request = new AiRequest(system: [['text' => 'x', 'cache' => false]], messages: [['role' => 'user', 'content' => 'x']], maxTokens: 10, model: 'claude-haiku-5-5');

    expect(app(CallOfficeModel::class)->handle($fake, AiUsageChannel::Telegram, $request, $tokens, $models, 'x'))->toBe('bad_model')
        ->and($fake->calls())->toBe(0);
});
