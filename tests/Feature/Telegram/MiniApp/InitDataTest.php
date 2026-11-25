<?php

use App\Support\Telegram\MiniApp\InitData;
use App\Support\Telegram\MiniApp\InvalidInitData;
use App\Support\Telegram\MiniApp\VerifiedInitData;

require_once __DIR__.'/../../../Helpers/MiniApp.php';

/**
 * Plan 20 MA-2: strict initData verification against a real-format vector signed
 * by an independent implementation (tests/Helpers/MiniApp.php).
 */
it('accepts the independently signed vector and keeps only id, language and the whitelisted start_param', function () {
    expect(InitData::sign(collect(explode('&', tmaVector()))->mapWithKeys(function (string $pair) {
        [$key, $value] = explode('=', $pair, 2);

        return [$key => rawurldecode($value)];
    })->all(), TG_BOT_TOKEN))->toBe(TMA_VECTOR_HASH);

    $result = InitData::verify(tmaVector(), TG_BOT_TOKEN, TMA_VECTOR_AUTH_DATE + 10);

    expect($result)->toBeInstanceOf(VerifiedInitData::class)
        ->and($result->telegramId)->toBe(5550001)
        ->and($result->languageCode)->toBe('ru')
        ->and($result->startParam)->toBe('p42')
        ->and($result->authDate)->toBe(TMA_VECTOR_AUTH_DATE);
});

it('refuses tampered, malformed and stale data', function (callable $make, string $reason) {
    $result = InitData::verify($make(), TG_BOT_TOKEN, TMA_VECTOR_AUTH_DATE + 10);

    expect($result)->toBeInstanceOf(InvalidInitData::class)
        ->and($result->reason)->toBe($reason);
})->with([
    'wrong hash' => [fn () => substr(tmaVector(), 0, -1).'0', InvalidInitData::SIGNATURE],
    'signature field tampered' => [fn () => str_replace('signature=6fbd', 'signature=7fbd', tmaVector()), InvalidInitData::SIGNATURE],
    'user tampered' => [fn () => str_replace('5550001', '5550002', tmaVector()), InvalidInitData::SIGNATURE],
    'other bot token' => [fn () => tmaInitData(5550001, authDate: TMA_VECTOR_AUTH_DATE, token: '987654321:AAOtherTokenForTestsOnly_0123456789xy'), InvalidInitData::SIGNATURE],
    'missing hash' => [fn () => preg_replace('/&hash=[0-9a-f]+$/', '', tmaVector()), InvalidInitData::MISSING],
    'missing user' => [fn () => tmaInitDataWithout('user'), InvalidInitData::MISSING],
    'missing auth_date' => [fn () => tmaInitDataWithout('auth_date'), InvalidInitData::MISSING],
    'duplicate key' => [fn () => tmaVector().'&auth_date=1764000000', InvalidInitData::DUPLICATE],
    'empty key' => [fn () => '=x&'.tmaVector(), InvalidInitData::MALFORMED],
    'pair without =' => [fn () => 'novalue&'.tmaVector(), InvalidInitData::MALFORMED],
    'empty pair' => [fn () => tmaVector().'&', InvalidInitData::MALFORMED],
    'over 4096 bytes' => [fn () => tmaVector().'&pad='.str_repeat('a', 4096), InvalidInitData::TOO_LONG],
    'expired' => [fn () => tmaInitData(5550001, authDate: TMA_VECTOR_AUTH_DATE + 10 - 3601), InvalidInitData::EXPIRED],
    'future' => [fn () => tmaInitData(5550001, authDate: TMA_VECTOR_AUTH_DATE + 10 + 61), InvalidInitData::FUTURE],
    'user without id' => [fn () => tmaInitData(5550001, ['user' => '{"first_name":"Ali"}'], TMA_VECTOR_AUTH_DATE), InvalidInitData::USER],
    'negative id' => [fn () => tmaInitData(5550001, ['user' => '{"id":-5}'], TMA_VECTOR_AUTH_DATE), InvalidInitData::USER],
    'string id' => [fn () => tmaInitData(5550001, ['user' => '{"id":"5550001"}'], TMA_VECTOR_AUTH_DATE), InvalidInitData::USER],
    'user too deep' => [fn () => tmaInitData(5550001, ['user' => '{"id":1,"a":{"b":{"c":1}}}'], TMA_VECTOR_AUTH_DATE), InvalidInitData::USER],
    'user not json' => [fn () => tmaInitData(5550001, ['user' => 'Ali'], TMA_VECTOR_AUTH_DATE), InvalidInitData::USER],
    'empty' => [fn () => '', InvalidInitData::MISSING],
]);

it('accepts exactly the TTL and 60 seconds of clock skew', function () {
    $now = TMA_VECTOR_AUTH_DATE + 10;

    expect(InitData::verify(tmaInitData(1, authDate: $now - 3600), TG_BOT_TOKEN, $now))->toBeInstanceOf(VerifiedInitData::class)
        ->and(InitData::verify(tmaInitData(1, authDate: $now + 60), TG_BOT_TOKEN, $now))->toBeInstanceOf(VerifiedInitData::class);
});

it('drops a start_param outside the whitelist', function (string $param, ?string $kept) {
    $result = InitData::verify(tmaInitData(1, ['start_param' => $param], TMA_VECTOR_AUTH_DATE), TG_BOT_TOKEN, TMA_VECTOR_AUTH_DATE);

    expect($result->startParam)->toBe($kept);
})->with([
    ['p12', 'p12'], ['c3', 'c3'], ['orders', 'orders'], ['o77', 'o77'],
    ['p', null], ['p12345678901', null], ['x1', null], ['https://evil.example', null], ['orders/../admin', null], ['o01ABCDEFGHJKMNPQRSTVWXYZ0', null],
]);

function tmaInitDataWithout(string $key): string
{
    $fields = ['query_id' => 'q', 'user' => '{"id":1}', 'auth_date' => (string) TMA_VECTOR_AUTH_DATE];
    unset($fields[$key]);
    $fields['hash'] = InitData::sign($fields, TG_BOT_TOKEN);

    return http_build_query($fields, '', '&', PHP_QUERY_RFC3986);
}
