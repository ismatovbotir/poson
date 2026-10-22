<?php

use App\Enums\TrafficChannel;
use App\Support\Analytics\TrafficClassifier;

$own = ['poson.uz', 'www.poson.uz'];

it('classifies a visit by UTM tags first, then by the referrer host', function (?string $utmSource, ?string $referrer, ?TrafficChannel $channel, ?string $source) use ($own) {
    $result = (new TrafficClassifier)->classify($utmSource, TrafficClassifier::hostFromUrl($referrer), $own);

    expect($result->channel)->toBe($channel)
        ->and($result->source)->toBe($source)
        ->and($result->isInternal())->toBe($channel === null);
})->with([
    // AI assistants
    'chatgpt.com' => [null, 'https://chatgpt.com/', TrafficChannel::Ai, 'chatgpt.com'],
    'chat.openai.com' => [null, 'https://chat.openai.com/c/123', TrafficChannel::Ai, 'chat.openai.com'],
    'perplexity' => [null, 'https://www.perplexity.ai/search?q=x', TrafficChannel::Ai, 'perplexity.ai'],
    'copilot' => [null, 'https://copilot.microsoft.com/', TrafficChannel::Ai, 'copilot.microsoft.com'],
    'gemini before google search' => [null, 'https://gemini.google.com/app', TrafficChannel::Ai, 'gemini.google.com'],
    'claude.ai' => [null, 'https://claude.ai/chat/1', TrafficChannel::Ai, 'claude.ai'],
    'deepseek' => [null, 'https://chat.deepseek.com/', TrafficChannel::Ai, 'chat.deepseek.com'],
    'grok' => [null, 'https://grok.com/', TrafficChannel::Ai, 'grok.com'],
    'utm_source=chatgpt.com' => ['chatgpt.com', null, TrafficChannel::Ai, 'chatgpt.com'],
    'utm_source=ChatGPT word' => ['ChatGPT', 'https://example.org/', TrafficChannel::Ai, 'chatgpt.com'],
    'utm_source=perplexity' => ['perplexity', null, TrafficChannel::Ai, 'perplexity.ai'],
    // search engines
    'google.com' => [null, 'https://www.google.com/', TrafficChannel::Search, 'google.com'],
    'google.co.uz' => [null, 'https://www.google.co.uz/search?q=zebra', TrafficChannel::Search, 'google.co.uz'],
    'yandex.ru' => [null, 'https://yandex.ru/search/?text=x', TrafficChannel::Search, 'yandex.ru'],
    'yandex.uz' => [null, 'https://yandex.uz/', TrafficChannel::Search, 'yandex.uz'],
    'bing' => [null, 'https://www.bing.com/search?q=x', TrafficChannel::Search, 'bing.com'],
    'duckduckgo' => [null, 'https://duckduckgo.com/', TrafficChannel::Search, 'duckduckgo.com'],
    // social
    'telegram' => [null, 'https://t.me/poson_uz', TrafficChannel::Social, 't.me'],
    'instagram (l.)' => [null, 'https://l.instagram.com/?u=x', TrafficChannel::Social, 'l.instagram.com'],
    'facebook mobile' => [null, 'https://m.facebook.com/', TrafficChannel::Social, 'm.facebook.com'],
    'youtube' => [null, 'https://www.youtube.com/watch?v=1', TrafficChannel::Social, 'youtube.com'],
    'linkedin' => [null, 'https://www.linkedin.com/feed/', TrafficChannel::Social, 'linkedin.com'],
    // UTM campaigns win over the referrer
    'utm campaign' => ['Telegram_Ads', 'https://www.google.com/', TrafficChannel::Campaign, 'telegram_ads'],
    'utm newsletter' => ['newsletter', null, TrafficChannel::Campaign, 'newsletter'],
    // the rest
    'referral' => [null, 'https://partner.example.uz/catalog', TrafficChannel::Referral, 'partner.example.uz'],
    'not google' => [null, 'https://googleusercontent.com/x', TrafficChannel::Referral, 'googleusercontent.com'],
    'direct' => [null, null, TrafficChannel::Direct, null],
    'non-http referrer' => [null, 'android-app://com.google.android.gm/', TrafficChannel::Direct, null],
    'own host' => [null, 'https://poson.uz/uz/catalog', null, null],
    'own www host' => [null, 'https://www.poson.uz/ru', null, null],
    'own subdomain' => [null, 'https://shop.poson.uz/uz', null, null],
    'look-alike host' => [null, 'https://notposon.uz/', TrafficChannel::Referral, 'notposon.uz'],
]);

it('normalizes hosts', function (?string $input, ?string $expected) {
    expect(TrafficClassifier::normalizeHost($input))->toBe($expected);
})->with([
    ['WWW.Google.COM', 'google.com'],
    ['poson.uz:8443', 'poson.uz'],
    ['example.org.', 'example.org'],
    ['', null],
    [null, null],
    ['bad host!', null],
]);
