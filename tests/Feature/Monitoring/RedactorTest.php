<?php

use App\Support\Monitoring\Redactor;

// All keys below are FAKE.

it('replaces the literal value of a configured secret first', function () {
    config(['services.telegram.webhook_secret' => 'fake-literal-secret-Value']);
    freshSecrets(['services.telegram.webhook_secret' => 'fake-literal-secret-Value']);

    $text = 'Callback failed for fake-literal-secret-Value in handler';

    expect(Redactor::clean($text))->toBe('Callback failed for [redacted] in handler');
});

it('replaces APP_KEY and the database password literally', function () {
    config(['app.key' => 'base64:FAKEAPPKEYFAKEAPPKEY', 'database.connections.'.config('database.default').'.password' => 'fake-db-pass-9']);

    expect(Redactor::clean('x base64:FAKEAPPKEYFAKEAPPKEY y fake-db-pass-9 z'))->toBe('x [redacted] y [redacted] z');
});

it('removes a Telegram bot token, also inside an API URL', function () {
    $out = Redactor::clean('POST https://api.telegram.org/bot123456789:AAFAKEFAKEFAKEFAKEFAKEFAKEFAKEFAKE12/sendMessage failed');

    expect($out)->not->toContain('AAFAKE')->not->toContain('123456789')->toContain('[token]');
});

it('cuts after (Connection: and keeps the SQLSTATE', function () {
    $out = Redactor::clean('SQLSTATE[HY000] [2002] Connection refused (Connection: mysql, SQL: select * from users where email = x)');

    expect($out)->toContain('SQLSTATE[HY000] [2002] Connection refused')
        ->not->toContain('select')->not->toContain('mysql')->not->toContain('users');
});

it('cuts after SQL: without the Connection marker', function () {
    expect(Redactor::clean('SQLSTATE[23000]: Integrity constraint violation SQL: insert into leads (phone) values (x)'))
        ->toStartWith('SQLSTATE[23000]: Integrity constraint violation')->not->toContain('insert');
});

it('replaces the application path with a tilde', function () {
    $out = Redactor::clean('Error in '.base_path('app/Support/Foo.php').' on line 5');

    expect($out)->toBe('Error in ~/app/Support/Foo.php on line 5');
});

it('masks Auth header values', function () {
    $out = Redactor::clean("Auth: fake-header-value-1\nAuthorization: Token fake-header-value-2\nX-Api-Key: fake-header-value-3");

    expect($out)->not->toContain('fake-header-value')->toContain('Auth: [redacted]');
});

it('masks Bearer and Basic credentials', function () {
    $out = Redactor::clean('sent Bearer fake.jwt-like_value and Basic ZmFrZTpmYWtl done');

    expect($out)->toBe('sent Bearer [redacted] and Basic [redacted] done');
});

it('masks key=value secrets', function () {
    $out = Redactor::clean('password=fake1 secret="fake two" api_token=fake3 sign=fake4 mode=prod');

    expect($out)->toBe('password=[redacted] secret=[redacted] api_token=[redacted] sign=[redacted] mode=prod');
});

it('removes query strings', function () {
    expect(Redactor::clean('GET https://example.test/pay?order=5&x=fake failed'))->toBe('GET https://example.test/pay?[query] failed');
});

it('masks emails', function () {
    expect(Redactor::clean('mail to ali.valiyev+1@example.co.uz bounced'))->toBe('mail to [email] bounced');
});

it('masks +998 numbers and long digit runs', function () {
    $out = Redactor::clean('call +998 90 123 45 67 or +998901234567, id 12345678, order 12345');

    expect($out)->toBe('call [phone] or [phone], id [number], order 12345');
});

it('masks long hex and base64 runs but not paths or class names', function () {
    $hex = md5('fake');
    $b64 = 'Zm9vYmFyQmF6UXV4MTIzNDU2Nzg5MGFiY2Q=';
    $out = Redactor::clean("hash {$hex} blob {$b64} in App\\Actions\\Orders\\PlaceOrder at ~/app/Support/Monitoring/Redactor.php");

    expect($out)->toBe('hash [hash] blob [hash] in App\Actions\Orders\PlaceOrder at ~/app/Support/Monitoring/Redactor.php');
});

it('strips control characters and bad UTF-8', function () {
    expect(Redactor::clean("a\x00b\x1b[31mc\x07d\xC3"))->toBe('ab[31mcd?');
});

it('cuts at the stack trace and limits the length by characters', function () {
    expect(Redactor::clean("Boom happened\nStack trace:\n#0 /x"))->toBe('Boom happened')
        ->and(Redactor::clean("Boom\n#0 frame"))->toBe('Boom')
        ->and(Redactor::clean(str_repeat('Oʻ', 400), 300))->toHaveLength(300);
});

it('returns the first non-empty line', function () {
    expect(Redactor::line("\n  \nFirst ops@poson.test\nsecond"))->toBe('First [email]');
});

it('replaces mail, redis and previous app key secrets and their escaped forms', function () {
    config([
        'mail.mailers.smtp.password' => 'fake smtp"pass/word',
        'database.redis.default.password' => 'fake-redis-pw-1',
        'app.previous_keys' => ['base64:FAKEPREVIOUSKEYFAKEPREV'],
    ]);

    $json = trim(json_encode('fake smtp"pass/word'), '"');
    $url = rawurlencode('fake smtp"pass/word');
    $out = Redactor::clean("a fake smtp\"pass/word b {$json} c {$url} d fake-redis-pw-1 e FAKEPREVIOUSKEYFAKEPREV f base64:FAKEPREVIOUSKEYFAKEPREV");

    expect($out)->not->toContain('smtp')->not->toContain('fake-redis')->not->toContain('FAKEPREVIOUS')
        ->and($out)->toBe('a [redacted] b [redacted] c [redacted] d [redacted] e [redacted] f [redacted]');
});
