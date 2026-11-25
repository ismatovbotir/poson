<?php

use Illuminate\Support\Facades\Process;

/**
 * Plan 42: resources/js/lead-modal-click.js (which clicks open the quote modal), run with
 * node when it is installed (no JS test runner in the project; skipped without node).
 */
it('opens the modal only for a plain left click on a same-tab link', function () {
    $node = trim((string) shell_exec('command -v node 2>/dev/null'));

    if ($node === '' || ! is_executable($node)) {
        $this->markTestSkipped('node is not installed');
    }

    $plain = ['button' => 0, 'metaKey' => false, 'ctrlKey' => false, 'shiftKey' => false, 'altKey' => false, 'defaultPrevented' => false];
    $cases = [
        'plain left click' => [$plain, [], true],
        'target _self' => [$plain, ['target' => '_self'], true],
        'middle click' => [[...$plain, 'button' => 1], [], false],
        'right click' => [[...$plain, 'button' => 2], [], false],
        'cmd click' => [[...$plain, 'metaKey' => true], [], false],
        'ctrl click' => [[...$plain, 'ctrlKey' => true], [], false],
        'shift click' => [[...$plain, 'shiftKey' => true], [], false],
        'alt click' => [[...$plain, 'altKey' => true], [], false],
        'already handled' => [[...$plain, 'defaultPrevented' => true], [], false],
        'target _blank' => [$plain, ['target' => '_blank'], false],
        'download' => [$plain, ['download' => ''], false],
    ];

    $script = 'import { opensLeadModal } from '.json_encode('file://'.resource_path('js/lead-modal-click.js')).';'
        .'const cases = JSON.parse(process.argv[1]);'
        .'const link = (attrs) => ({ getAttribute: (n) => (n in attrs ? attrs[n] : null), hasAttribute: (n) => n in attrs });'
        .'console.log(JSON.stringify(cases.map(([event, attrs]) => opensLeadModal(event, link(attrs)))));';

    $result = Process::run([$node, '--input-type=module', '-e', $script, json_encode(array_map(fn (array $case): array => [$case[0], (object) $case[1]], array_values($cases)))]);

    expect($result->successful())->toBeTrue($result->errorOutput())
        ->and(array_combine(array_keys($cases), json_decode($result->output(), true)))
        ->toBe(array_map(fn (array $case): bool => $case[2], $cases));
});
