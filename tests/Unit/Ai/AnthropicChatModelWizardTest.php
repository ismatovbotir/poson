<?php

use App\Services\Ai\AiFailed;
use App\Services\Ai\AiRequest;
use App\Services\Ai\AnthropicChatModel;
use App\Support\Content\AiProduct\AiFactsParser;
use GuzzleHttp\Psr7\Response;
use Illuminate\Support\Facades\File;
use Psr\Http\Message\RequestInterface;
use Tests\Support\ImageFixtures;

/**
 * Plan 32 §9, PW-5, PW-10: the adapter's wizard overrides (model, effort,
 * timeout, images, output schema) and their limits. Only canned PSR-7
 * responses through the SDK middleware; fake keys only (AI-25). Images live in
 * a real folder under storage/app/private/ai-drafts (the adapter's own root),
 * removed after each test.
 */
const WIZARD_FAKE_KEY = 'sk-ant-test-WIZARDKEY-0123456789abcdefgh';

/**
 * @param  array<string, mixed>|null  $seen  The request body and options the middleware saw (null = never called)
 */
function wizardAdapter(?array &$seen = null, int $status = 200): AnthropicChatModel
{
    return new AnthropicChatModel([function (RequestInterface $request, callable $next, $options = null) use (&$seen, $status): Response {
        $seen = ['body' => json_decode((string) $request->getBody(), true), 'raw' => (string) $request->getBody(), 'timeout' => $options?->timeout];

        return new Response($status, ['content-type' => 'application/json'], json_encode($status === 200 ? [
            'id' => 'msg_test', 'type' => 'message', 'role' => 'assistant', 'model' => 'claude-haiku-5-5',
            'content' => [['type' => 'text', 'text' => '{"ok":true}']], 'stop_reason' => 'end_turn', 'stop_sequence' => null,
            'usage' => ['input_tokens' => 10, 'output_tokens' => 5],
        ] : ['type' => 'error', 'error' => ['type' => 'authentication_error', 'message' => 'bad key '.WIZARD_FAKE_KEY]]));
    }]);
}

/**
 * @param  array<string, mixed>  $overrides
 */
function wizardRequest(array $overrides = []): AiRequest
{
    return new AiRequest(...array_merge([
        'system' => [['text' => 'System rules', 'cache' => true]],
        'messages' => [['role' => 'user', 'content' => '<main_info>{}</main_info>']],
        'maxTokens' => 1500,
    ], $overrides));
}

beforeEach(function () {
    config(['services.anthropic.key' => WIZARD_FAKE_KEY]);
    $this->root = storage_path('app/private/ai-drafts');
    $this->folder = $this->root.'/phpunit-'.bin2hex(random_bytes(5));
    mkdir($this->folder, 0777, true);
});

afterEach(function () {
    File::deleteDirectory($this->folder);
    @rmdir($this->root);
});

/**
 * @return list<array{mediaType: string, path: string}>
 */
function wizardImages(string $folder, int $count): array
{
    $images = [];

    for ($i = 1; $i <= $count; $i++) {
        $images[] = ['mediaType' => 'image/jpeg', 'path' => ImageFixtures::bordered("{$folder}/{$i}.jpg", 120, 90, 40)];
    }

    return $images;
}

describe('no overrides', function () {
    it('sends exactly the chat bot request: configured model, effort low, no format, no images', function () {
        wizardAdapter($seen)->complete(wizardRequest(['maxTokens' => 400]));

        expect($seen['body'])->toBe([
            'max_tokens' => 400,
            'messages' => [['role' => 'user', 'content' => '<main_info>{}</main_info>']],
            'model' => 'claude-haiku-5-5',
            'output_config' => ['effort' => 'low'],
            'system' => [['type' => 'text', 'text' => 'System rules', 'cache_control' => ['type' => 'ephemeral']]],
        ]);
    });

    it('keeps messages as plain strings (no content blocks) without images', function () {
        wizardAdapter($seen)->complete(wizardRequest());

        expect($seen['body']['messages'][0]['content'])->toBeString();
    });
});

describe('overrides', function () {
    it('uses an allowed model, effort and output schema', function () {
        wizardAdapter($seen)->complete(wizardRequest(['model' => 'claude-sonnet-5-5', 'effort' => 'medium', 'outputSchema' => AiFactsParser::schema()]));

        expect($seen['body']['model'])->toBe('claude-sonnet-5-5')
            ->and($seen['body']['output_config']['effort'])->toBe('medium')
            ->and($seen['body']['output_config']['format']['type'])->toBe('json_schema')
            ->and($seen['body']['output_config']['format']['schema']['required'])->toContain('device_type', 'views', 'main_image');
    });

    it('clamps the timeout and the output tokens', function (float $asked, float $used) {
        wizardAdapter($seen)->complete(wizardRequest(['timeout' => $asked, 'maxTokens' => 99999]));

        expect($seen['timeout'])->toBe($used)->and($seen['body']['max_tokens'])->toBe(AnthropicChatModel::MAX_TOKENS);
    })->with([
        'too long' => [900.0, AnthropicChatModel::MAX_TIMEOUT],
        'too short' => [0.01, AnthropicChatModel::MIN_TIMEOUT],
        'in range' => [45.0, 45.0],
    ]);

    it('refuses a model, effort or token override outside the whitelist before any client exists', function (array $override) {
        $seen = null;

        expect(fn () => wizardAdapter($seen)->complete(wizardRequest($override)))->toThrow(LogicException::class)
            ->and($seen)->toBeNull();
    })->with([
        'unknown model' => [['model' => 'claude-opus-9']],
        'model typo with suffix' => [['model' => 'claude-sonnet-5-5-latest']],
        'empty model' => [['model' => '']],
        'effort high' => [['effort' => 'high']],
        'effort max' => [['effort' => 'max']],
    ]);

    it('checks the whitelist before the key, so a bad model fails even with no key', function () {
        config(['services.anthropic.key' => '']);

        expect(fn () => wizardAdapter()->complete(wizardRequest(['model' => 'gpt-5'])))->toThrow(LogicException::class);
    });
});

describe('images (PW-5)', function () {
    it('attaches base64 JPEGs before the text of the last user message', function () {
        $images = wizardImages($this->folder, 2);

        wizardAdapter($seen)->complete(wizardRequest([
            'model' => 'claude-haiku-5-5',
            'messages' => [['role' => 'user', 'content' => 'earlier'], ['role' => 'assistant', 'content' => 'ok'], ['role' => 'user', 'content' => '<main_info>{}</main_info>']],
            'images' => $images,
        ]));

        $messages = $seen['body']['messages'];
        $last = end($messages);

        expect($messages[0]['content'])->toBe('earlier')
            ->and($last['content'])->toHaveCount(3)
            ->and($last['content'][0]['type'])->toBe('image')
            ->and($last['content'][0]['source'])->toBe(['type' => 'base64', 'media_type' => 'image/jpeg', 'data' => base64_encode((string) file_get_contents($images[0]['path']))])
            ->and($last['content'][1]['source']['data'])->toBe(base64_encode((string) file_get_contents($images[1]['path'])))
            ->and($last['content'][2])->toBe(['type' => 'text', 'text' => '<main_info>{}</main_info>']);
    });

    it('allows six images and refuses seven without a call', function () {
        wizardAdapter($seen)->complete(wizardRequest(['images' => wizardImages($this->folder, 6)]));
        expect($seen['body']['messages'][0]['content'])->toHaveCount(7);

        $seen = null;

        expect(fn () => wizardAdapter($seen)->complete(wizardRequest(['images' => wizardImages($this->folder, 7)])))->toThrow(LogicException::class)
            ->and($seen)->toBeNull();
    });

    it('refuses an unsafe image reference before any request', function (string $case) {
        $good = ImageFixtures::bordered($this->folder.'/ok.jpg', 80, 60, 20);
        $outside = ImageFixtures::bordered(sys_get_temp_dir().'/wizard-outside-'.bin2hex(random_bytes(4)).'.jpg', 80, 60, 20);
        $png = tempnam($this->folder, 'png');
        imagepng(ImageFixtures::canvas(10, 10), $png);
        file_put_contents($this->folder.'/big.jpg', "\xFF\xD8\xFF".str_repeat('A', AnthropicChatModel::IMAGE_MAX_BYTES + 1));
        @symlink($outside, $this->folder.'/link.jpg');

        $image = match ($case) {
            'outside the root' => ['mediaType' => 'image/jpeg', 'path' => $outside],
            'traversal' => ['mediaType' => 'image/jpeg', 'path' => $this->folder.'/../../../../../etc/hosts'],
            'traversal into the root' => ['mediaType' => 'image/jpeg', 'path' => $this->root.'/../ai-drafts/../'.basename($outside)],
            'symlink out of the root' => ['mediaType' => 'image/jpeg', 'path' => $this->folder.'/link.jpg'],
            'root itself' => ['mediaType' => 'image/jpeg', 'path' => $this->root],
            'missing file' => ['mediaType' => 'image/jpeg', 'path' => $this->folder.'/missing.jpg'],
            'png media type' => ['mediaType' => 'image/png', 'path' => $good],
            'webp media type' => ['mediaType' => 'image/webp', 'path' => $good],
            'no media type' => ['path' => $good],
            'array path' => ['mediaType' => 'image/jpeg', 'path' => [$good]],
            'over 5 MB' => ['mediaType' => 'image/jpeg', 'path' => $this->folder.'/big.jpg'],
        };

        $seen = null;

        expect(fn () => wizardAdapter($seen)->complete(wizardRequest(['images' => [$image]])))->toThrow(LogicException::class)
            ->and($seen)->toBeNull();

        @unlink($outside);
    })->with([
        'outside the root', 'traversal', 'traversal into the root', 'symlink out of the root', 'root itself', 'missing file',
        'png media type', 'webp media type', 'no media type', 'array path', 'over 5 MB',
    ]);

    it('sends nothing when the file is not a JPEG inside (fails as AiFailed, no request)', function () {
        file_put_contents($this->folder.'/fake.jpg', 'GIF89a-not-a-jpeg');
        $seen = null;

        try {
            wizardAdapter($seen)->complete(wizardRequest(['images' => [['mediaType' => 'image/jpeg', 'path' => $this->folder.'/fake.jpg']]]));
            $this->fail('No exception.');
        } catch (AiFailed $failure) {
            expect($failure->errorClass)->toBe('LogicException');
        }

        expect($seen)->toBeNull();
    });

    it('refuses images when the request has no last user message', function () {
        $seen = null;

        expect(fn () => wizardAdapter($seen)->complete(wizardRequest([
            'messages' => [['role' => 'assistant', 'content' => 'hi']],
            'images' => wizardImages($this->folder, 1),
        ])))->toThrow(AiFailed::class, 'LogicException')->and($seen)->toBeNull();
    });

    it('does not count images in the text of a request', function () {
        $request = wizardRequest(['images' => wizardImages($this->folder, 2)]);

        expect($request->allText())->not->toContain($this->folder)->not->toContain('base64')
            ->and($request->estimatedInputTokens())->toBeGreaterThanOrEqual(2 * AiRequest::IMAGE_TOKENS);
    });
});

describe('failures', function () {
    it('keeps the key and the image bytes out of an AiFailed from a vision call', function () {
        $images = wizardImages($this->folder, 1);

        try {
            wizardAdapter($seen, 401)->complete(wizardRequest(['images' => $images, 'model' => 'claude-haiku-5-5']));
            $this->fail('No exception.');
        } catch (AiFailed $failure) {
            $text = $failure->getMessage().(string) $failure;

            expect($failure->status)->toBe(401)
                ->and($failure->getPrevious())->toBeNull()
                ->and($text)->not->toContain(WIZARD_FAKE_KEY)->not->toContain('sk-ant')
                ->and($text)->not->toContain(base64_encode((string) file_get_contents($images[0]['path'])));
        }
    });
});

describe('configuration (PW-5, PW-10)', function () {
    it('names only whitelisted models and efforts for both stages', function (string $stage) {
        expect(AnthropicChatModel::MODELS)->toContain(config("chatbot.ai.product.{$stage}.model"))
            ->and(AnthropicChatModel::EFFORTS)->toContain(config("chatbot.ai.product.{$stage}.effort"))
            ->and(config('chatbot.ai.product.vision.effort'))->toBe('low')
            ->and(config('chatbot.ai.product.write.effort'))->toBe('medium')
            ->and(array_keys(config('chatbot.ai.product.prices')))->toContain(config("chatbot.ai.product.{$stage}.model"));
    })->with(['vision', 'write']);

    it('keeps retry_after above the job timeout above the SDK timeout plus connect (and the adapter clamp)', function (string $stage) {
        $retryAfter = (int) config('queue.connections.database.retry_after');
        $job = (int) config("chatbot.ai.product.{$stage}.job_timeout");
        $sdk = (float) config("chatbot.ai.product.{$stage}.timeout");
        $connect = (float) config('chatbot.ai.connect_timeout');

        expect($retryAfter)->toBeGreaterThan($job)
            ->and($job)->toBeGreaterThan($sdk + $connect)
            ->and($sdk)->toBeLessThanOrEqual(AnthropicChatModel::MAX_TIMEOUT)
            ->and(AnthropicChatModel::MAX_TIMEOUT + $connect)->toBeLessThan((int) config('chatbot.ai.product.write.job_timeout'))
            ->and((int) config("chatbot.ai.product.{$stage}.max_tokens"))->toBeLessThanOrEqual(AnthropicChatModel::MAX_TOKENS);
    })->with(['vision', 'write']);

    it('allows at most the adapter image count', function () {
        expect((int) config('chatbot.ai.product.max_images'))->toBeLessThanOrEqual(AnthropicChatModel::MAX_IMAGES);
    });
});
