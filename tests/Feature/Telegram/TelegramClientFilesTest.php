<?php

use App\Services\Telegram\TelegramClient;
use App\Services\Telegram\TelegramException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

require_once __DIR__.'/../../Helpers/Careers.php';

beforeEach(function () {
    careersTelegram();
});

it('sends a document as multipart with the caption, HTML mode and buttons', function () {
    $sent = app(TelegramClient::class)->sendDocument('-1008888888888', careersPdf(), 'CV-5.pdf', '<b>#J-5</b>', [['text' => 'Open', 'url' => 'https://poson.example.uz/admin/careers/applications/5']]);

    expect($sent['document']['file_id'])->toBe('BQACAgIAAxkBAAIFAKE');

    Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/sendDocument') && $request->isMultipart());
    expect(careersSentDocuments()[0])->toMatchArray(['chat_id' => '-1008888888888', 'caption' => '<b>#J-5</b>', 'parse_mode' => 'HTML', 'filename' => 'CV-5.pdf']);
});

it('refuses an unsafe file_path from getFile and never downloads it', function (string $path) {
    careersTelegram(responses: ['getFile' => Http::response(['ok' => true, 'result' => ['file_unique_id' => 'AgADFAKE', 'file_path' => $path]])]);

    expect(fn () => app(TelegramClient::class)->getFile('BQAC'))->toThrow(TelegramException::class);
    expect(fn () => app(TelegramClient::class)->downloadFile($path, 1000))->toThrow(TelegramException::class);

    expect(careersTelegramMethods())->toBe(['getFile']);
})->with(['../../etc/passwd', 'documents/../secret', 'https://evil.example/x.pdf', '/abs/path.pdf', 'documents//file.pdf', 'documents/fi le.pdf', str_repeat('a', 257)]);

it('downloads only from the API host without redirects and stops above the size limit', function () {
    expect(app(TelegramClient::class)->downloadFile('documents/file_7.pdf', 10_000))->toStartWith('%PDF-');

    Http::assertSent(fn (Request $request): bool => str_starts_with($request->url(), TelegramClient::API.'/file/bot'));

    careersTelegram(responses: ['file' => Http::response('', 302, ['Location' => 'https://evil.example/x'])]);
    expect(fn () => app(TelegramClient::class)->downloadFile('documents/file_7.pdf', 10_000))->toThrow(TelegramException::class);

    careersTelegram(responses: ['file' => Http::response(str_repeat('A', 2000), 200, ['Content-Length' => '2000'])]);
    expect(fn () => app(TelegramClient::class)->downloadFile('documents/file_7.pdf', 1000))->toThrow(TelegramException::class);

    careersTelegram(responses: ['file' => Http::response(str_repeat('A', 2000))]);
    expect(fn () => app(TelegramClient::class)->downloadFile('documents/file_7.pdf', 1000))->toThrow(TelegramException::class);
});

it('never puts the token in exceptions or logs', function () {
    Log::spy();
    Http::fake(['api.telegram.org/*' => fn () => throw new ConnectionException('cURL error 7 for https://api.telegram.org/bot'.TG_BOT_TOKEN.'/sendDocument')]);

    foreach ([
        fn () => app(TelegramClient::class)->sendDocument('-1008888888888', careersPdf(), 'CV-1.pdf', 'x'),
        fn () => app(TelegramClient::class)->getFile('BQAC'),
        fn () => app(TelegramClient::class)->downloadFile('documents/file_7.pdf', 1000),
    ] as $call) {
        try {
            $call();
            $this->fail('expected a TelegramException');
        } catch (TelegramException $exception) {
            expect((string) $exception)->not->toContain(TG_BOT_TOKEN)
                ->and($exception->getPrevious())->toBeNull();
        }
    }

    Log::shouldNotHaveReceived('warning', [Mockery::on(fn ($m) => str_contains((string) $m, TG_BOT_TOKEN))]);
});
