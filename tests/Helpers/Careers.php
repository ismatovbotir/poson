<?php

use App\Models\Vacancy;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

/*
| Plan 31 phase B test helpers (careers form, CV forward, download). Fake
| token and chat ids only (tests/Pest.php TG_* constants).
*/

if (! function_exists('careersPdf')) {
    /**
     * A small valid-looking PDF (finfo reports application/pdf).
     */
    function careersPdf(string $body = '1 0 obj << /Type /Catalog /Pages 2 0 R >> endobj'): string
    {
        return "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n9 0 obj << >> endobj\n{$body}\ntrailer << /Root 1 0 R >>\nstartxref\n0\n%%EOF\n";
    }

    function careersUpload(?string $bytes = null, string $name = 'Ali Valiyev CV.pdf'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, $bytes ?? careersPdf());
    }

    /**
     * A form token as the page renders it, $age seconds old.
     *
     * @param  array{vacancy_id: int}|array{department: string}  $target
     */
    function careersToken(array $target, int $age = 5, ?string $nonce = null): string
    {
        return Crypt::encryptString((string) json_encode(['t' => now()->getTimestamp() - $age, ...$target, 'nonce' => $nonce ?? Str::random(32)]));
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    function careersFields(array $target, array $overrides = []): array
    {
        return array_replace([
            'name' => 'Ali Valiyev',
            'phone' => '+998 90 123 45 67',
            'email' => 'Ali@Example.uz',
            'message' => 'Five years of scanner repair.',
            'fax_ext' => '',
            'form_token' => careersToken($target),
        ], $overrides);
    }

    /**
     * POST the vacancy form (multipart when a CV is given).
     *
     * @param  array<string, mixed>  $overrides
     */
    function postVacancyApplication(Vacancy $vacancy, array $overrides = [], ?UploadedFile $cv = null, string $locale = 'uz'): TestResponse
    {
        $data = careersFields(['vacancy_id' => $vacancy->id], $overrides);

        if ($cv !== null) {
            $data['cv'] = $cv;
        }

        return test()->post(route('site.careers.apply', ['locale' => $locale, 'slug' => $vacancy->slugFor($locale)]), $data);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    function postOpenApplication(string $department, array $overrides = [], ?UploadedFile $cv = null): TestResponse
    {
        $data = careersFields(['department' => $department], $overrides);

        if ($cv !== null) {
            $data['cv'] = $cv;
        }

        return test()->post(route('site.careers.open.apply', ['locale' => 'uz', 'department' => $department]), $data);
    }

    /**
     * Bot + management chat configured, stray requests refused, Bot API faked.
     *
     * @param  array<string, mixed>  $chat  getChat result
     * @param  array<string, mixed>  $responses  method => Http response (overrides)
     */
    function careersTelegram(array $chat = ['id' => -1008888888888, 'type' => 'supergroup'], array $responses = []): void
    {
        telegramBotConfigured();
        // A fresh client factory: a second call replaces the stubs (the first matching stub would win otherwise).
        Http::swap(new Factory(app('events')));
        Http::preventStrayRequests();

        $defaults = [
            'getChat' => Http::response(['ok' => true, 'result' => $chat]),
            'sendDocument' => Http::response(['ok' => true, 'result' => ['message_id' => 701, 'document' => ['file_id' => 'BQACAgIAAxkBAAIFAKE', 'file_unique_id' => 'AgADFAKE']]]),
            'sendMessage' => Http::response(['ok' => true, 'result' => ['message_id' => 702]]),
            'getFile' => Http::response(['ok' => true, 'result' => ['file_id' => 'BQACAgIAAxkBAAIFAKE', 'file_unique_id' => 'AgADFAKE', 'file_size' => 120, 'file_path' => 'documents/file_7.pdf']]),
            'deleteMessage' => Http::response(['ok' => true, 'result' => true]),
            'file' => Http::response(careersPdf(), 200, ['Content-Type' => 'application/octet-stream']),
        ];
        $map = array_replace($defaults, $responses);

        $fakes = [];

        foreach ($map as $method => $response) {
            $fakes[$method === 'file' ? 'api.telegram.org/file/bot*' : "api.telegram.org/bot*/{$method}"] = $response;
        }

        Http::fake($fakes);
    }

}

if (! function_exists('careersTelegramMethods')) {
    /**
     * Bot API methods called (in order).
     *
     * @return list<string>
     */
    function careersTelegramMethods(): array
    {
        return array_column(telegramApiCalls(), 'method');
    }
}

if (! function_exists('careersSentDocuments')) {
    /**
     * Multipart fields of the recorded sendDocument calls (name => contents; the file part as "document").
     *
     * @return list<array<string, mixed>>
     */
    function careersSentDocuments(): array
    {
        return Http::recorded()
            ->filter(fn (array $pair): bool => str_ends_with(parse_url($pair[0]->url(), PHP_URL_PATH), '/sendDocument'))
            ->map(function (array $pair): array {
                $fields = [];

                foreach ($pair[0]->data() as $part) {
                    $fields[$part['name']] = $part['contents'];

                    if ($part['name'] === 'document') {
                        $fields['filename'] = $part['filename'] ?? null;
                    }
                }

                return $fields;
            })
            ->values()->all();
    }
}
