<?php

use Illuminate\Support\Facades\Process;

/**
 * Plan 40 (YD-1): resources/js/order-url.js, run with node when it is installed
 * (no JS test runner in the project; skipped without node).
 */
function ydNode(): ?string
{
    $node = trim((string) shell_exec('command -v node 2>/dev/null'));

    return $node !== '' && is_executable($node) ? $node : null;
}

it('accepts only same-origin /o/<ULID> URLs with lang and pay', function () {
    $node = ydNode();

    if ($node === null) {
        $this->markTestSkipped('node is not installed');
    }

    $ulid = '01JD3Z8Q4X5V6W7Y8Z9A0B1C2D';
    $cases = [
        "/o/{$ulid}" => "https://poson.uz/o/{$ulid}",
        "/o/{$ulid}?lang=ru&pay=uzqr" => "https://poson.uz/o/{$ulid}?lang=ru&pay=uzqr",
        '//evil.com' => null,
        '/\\evil.com' => null,
        "https://evil.com/o/{$ulid}" => null,
        '/o/%2e%2e/x' => null,
        "/o/{$ulid}?next=//evil.com" => null,
        '/o/'.strtolower($ulid) => null,
        "/o/{$ulid}#top" => null,
        "/o/{$ulid}?lang=de" => null,
        "/o/{$ulid}?pay=payme" => null,
        "/o/{$ulid}?lang=en&lang=ru" => null,
        '/o/81JD3Z8Q4X5V6W7Y8Z9A0B1C2D' => null,
        "/o/{$ulid}/../x" => null,
        "/o/user:pass@evil.com/{$ulid}" => null,
    ];

    $script = 'import { safeOrderUrl } from '.json_encode('file://'.resource_path('js/order-url.js')).';'
        .'const cases = JSON.parse(process.argv[1]);'
        .'console.log(JSON.stringify(cases.map((next) => safeOrderUrl(next, "https://poson.uz"))));';

    $result = Process::run([$node, '--input-type=module', '-e', $script, json_encode(array_keys($cases))]);

    expect($result->successful())->toBeTrue($result->errorOutput())
        ->and(json_decode($result->output(), true))->toBe(array_values($cases));
});
