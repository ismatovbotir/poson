@props(['endpoint', 'token' => null])

@php
    use App\Support\Mcp\ConnectionGuide;

    $t = 'mcp.guide';
    $stepNumber = 0;
@endphp

<div x-data="{ os: /Win/i.test(navigator.platform) ? 'win' : 'mac' }" {{ $attributes->class(['text-sm text-slate-700']) }} data-mcp-guide>
    <p class="text-slate-600">{{ __($t.'.intro') }}</p>
    <p class="mt-2 text-xs text-slate-500">{{ __($t.'.menu_note') }}</p>

    <div class="mt-4 flex flex-wrap items-center gap-3">
        <span class="text-xs font-semibold uppercase tracking-wide text-slate-500">{{ __($t.'.os_label') }}</span>
        <div class="inline-flex rounded-lg bg-slate-100 p-1" role="group" aria-label="{{ __($t.'.os_label') }}">
            @foreach (['mac' => 'os_mac', 'win' => 'os_win'] as $key => $label)
                <button type="button" x-on:click="os = '{{ $key }}'" x-bind:aria-pressed="os === '{{ $key }}'" x-bind:class="os === '{{ $key }}' ? 'bg-white text-brand-700 shadow-sm' : 'text-slate-600 hover:text-slate-800'" class="h-9 rounded-md px-4 text-sm font-semibold" data-os="{{ $key }}">{{ __($t.'.'.$label) }}</button>
            @endforeach
        </div>
    </div>

    <ol class="mt-5 space-y-6">
        {{-- 1 Node.js --}}
        <li class="flex gap-3">
            <span class="flex size-7 shrink-0 items-center justify-center rounded-full bg-brand-600 text-sm font-semibold text-white">{{ ++$stepNumber }}</span>
            <div class="min-w-0 flex-1 space-y-2">
                <h3 class="font-semibold text-slate-900">{{ __($t.'.steps.node.title') }}</h3>
                <p>{{ __($t.'.steps.node.body') }}</p>
                <x-admin.copy-block value="node --version" snippet="node" />
            </div>
        </li>

        {{-- 2 Open the config --}}
        <li class="flex gap-3">
            <span class="flex size-7 shrink-0 items-center justify-center rounded-full bg-brand-600 text-sm font-semibold text-white">{{ ++$stepNumber }}</span>
            <div class="min-w-0 flex-1 space-y-2">
                <h3 class="font-semibold text-slate-900">{{ __($t.'.steps.open.title') }}</h3>
                <p x-show="os === 'mac'">{{ __($t.'.steps.open.body_mac') }}</p>
                <p x-cloak x-show="os === 'win'">{{ __($t.'.steps.open.body_win') }}</p>
                <p class="text-xs font-semibold text-slate-500">{{ __($t.'.steps.open.path_label') }}</p>
                <x-admin.copy-block x-show="os === 'mac'" :value="ConnectionGuide::MAC_CONFIG_PATH" snippet="path-mac" />
                <x-admin.copy-block x-cloak x-show="os === 'win'" :value="ConnectionGuide::WIN_CONFIG_PATH" snippet="path-win" />
            </div>
        </li>

        {{-- 3 Add the server --}}
        <li class="flex gap-3">
            <span class="flex size-7 shrink-0 items-center justify-center rounded-full bg-brand-600 text-sm font-semibold text-white">{{ ++$stepNumber }}</span>
            <div class="min-w-0 flex-1 space-y-2">
                <h3 class="font-semibold text-slate-900">{{ __($t.'.steps.paste.title') }}</h3>
                <p>{{ __($t.'.steps.paste.body') }}</p>
                <x-admin.copy-block :value="ConnectionGuide::config($endpoint, $token)" snippet="desktop" />
                @if ($token === null)
                    <p class="text-xs text-slate-500">{{ __($t.'.placeholder_note') }}</p>
                    <p class="pt-1 text-xs font-semibold text-slate-500">{{ __($t.'.steps.paste.merge_title') }}</p>
                    <x-admin.copy-block :value="ConnectionGuide::mergeExample($endpoint)" snippet="merge-example" />
                @else
                    <p class="flex gap-2 text-xs text-amber-800"><x-admin.icon name="alert" size="size-4" class="mt-0.5 shrink-0" />{{ __($t.'.steps.paste.token_real') }}</p>
                @endif
                <details class="rounded-lg border border-slate-200 p-3">
                    <summary class="cursor-pointer text-sm font-semibold text-slate-700">{{ __($t.'.steps.paste.npx_title') }}</summary>
                    <p class="mt-2 text-slate-600">{{ __($t.'.steps.paste.npx_body') }}</p>
                </details>
            </div>
        </li>

        {{-- 4 Save and restart --}}
        <li class="flex gap-3">
            <span class="flex size-7 shrink-0 items-center justify-center rounded-full bg-brand-600 text-sm font-semibold text-white">{{ ++$stepNumber }}</span>
            <div class="min-w-0 flex-1 space-y-2">
                <h3 class="font-semibold text-slate-900">{{ __($t.'.steps.restart.title') }}</h3>
                <p x-show="os === 'mac'">{{ __($t.'.steps.restart.body_mac') }}</p>
                <p x-cloak x-show="os === 'win'">{{ __($t.'.steps.restart.body_win') }}</p>
                <p class="text-slate-600">{{ __($t.'.steps.restart.where') }}</p>
            </div>
        </li>

        {{-- 5 Protect the file --}}
        <li class="flex gap-3">
            <span class="flex size-7 shrink-0 items-center justify-center rounded-full bg-brand-600 text-sm font-semibold text-white">{{ ++$stepNumber }}</span>
            <div class="min-w-0 flex-1 space-y-2">
                <h3 class="font-semibold text-slate-900">{{ __($t.'.steps.protect.title') }}</h3>
                <div x-show="os === 'mac'" class="space-y-2">
                    <p>{{ __($t.'.steps.protect.body_mac') }}</p>
                    <x-admin.copy-block :value="ConnectionGuide::CHMOD_COMMAND" snippet="chmod" />
                </div>
                <p x-cloak x-show="os === 'win'">{{ __($t.'.steps.protect.body_win') }}</p>
            </div>
        </li>

        {{-- 6 Check the file --}}
        <li class="flex gap-3">
            <span class="flex size-7 shrink-0 items-center justify-center rounded-full bg-brand-600 text-sm font-semibold text-white">{{ ++$stepNumber }}</span>
            <div class="min-w-0 flex-1 space-y-2">
                <h3 class="font-semibold text-slate-900">{{ __($t.'.steps.check.title') }}</h3>
                <div x-show="os === 'mac'" class="space-y-2">
                    <p>{{ __($t.'.steps.check.body_mac') }}</p>
                    <x-admin.copy-block :value="ConnectionGuide::JSON_CHECK_COMMAND" snippet="json-check" />
                </div>
                <p x-cloak x-show="os === 'win'">{{ __($t.'.steps.check.body_win') }}</p>
            </div>
        </li>

        {{-- 7 Read-only check --}}
        <li class="flex gap-3">
            <span class="flex size-7 shrink-0 items-center justify-center rounded-full bg-brand-600 text-sm font-semibold text-white">{{ ++$stepNumber }}</span>
            <div class="min-w-0 flex-1 space-y-2">
                <h3 class="font-semibold text-slate-900">{{ __($t.'.steps.verify.title') }}</h3>
                <p>{{ __($t.'.steps.verify.body') }}</p>
                <x-admin.copy-block :value="__($t.'.steps.verify.prompt')" snippet="prompt" />
            </div>
        </li>
    </ol>

    {{-- Claude Code alternative --}}
    <div class="mt-6 space-y-2 border-t border-slate-100 pt-5">
        <h3 class="font-semibold text-slate-900">{{ __($t.'.code.title') }}</h3>
        <p>{{ __($t.'.code.body') }}</p>
        <x-admin.copy-block :value="ConnectionGuide::codeCommand($endpoint)" snippet="code" />
    </div>

    {{-- Optional terminal test --}}
    <div class="mt-6 space-y-2 border-t border-slate-100 pt-5">
        <h3 class="font-semibold text-slate-900">{{ __($t.'.terminal.title') }}</h3>
        <div x-show="os === 'mac'" class="space-y-2">
            <p>{{ __($t.'.terminal.body_mac') }}</p>
            <x-admin.copy-block :value="ConnectionGuide::terminalTest($endpoint)" snippet="terminal-test" />
            <p class="text-slate-600">{{ __($t.'.terminal.ok') }}</p>
        </div>
        <p x-cloak x-show="os === 'win'">{{ __($t.'.terminal.body_win') }}</p>
    </div>

    {{-- Troubleshooting --}}
    <div class="mt-6 border-t border-slate-100 pt-5">
        <h3 class="font-semibold text-slate-900">{{ __($t.'.trouble.title') }}</h3>
        <div class="mt-2 overflow-x-auto rounded-xl border border-slate-200">
            <table class="w-full text-left text-sm" data-trouble>
                <thead class="border-b border-slate-200 bg-slate-50/70 text-xs font-semibold uppercase tracking-wide text-slate-500">
                    <tr>
                        <th class="px-3 py-2">{{ __($t.'.trouble.code') }}</th>
                        <th class="px-3 py-2">{{ __($t.'.trouble.meaning') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @foreach (__($t.'.trouble.rows') as [$answer, $meaning])
                        <tr>
                            <td class="w-32 px-3 py-2 align-top font-mono text-xs font-semibold sm:w-48">{{ $answer }}</td>
                            <td class="min-w-[14rem] px-3 py-2">{{ $meaning }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        <p class="mt-3">{{ __($t.'.trouble.call_log') }}</p>
        <p class="mt-2 text-slate-600">{{ __($t.'.trouble.browser') }}</p>
    </div>

    {{-- Logs --}}
    <div class="mt-6 space-y-2 border-t border-slate-100 pt-5">
        <h3 class="font-semibold text-slate-900">{{ __($t.'.logs.title') }}</h3>
        <p class="flex gap-2 text-amber-800"><x-admin.icon name="alert" size="size-4" class="mt-0.5 shrink-0" />{{ __($t.'.logs.warning') }}</p>
        <div x-show="os === 'mac'" class="space-y-2">
            <p>{{ __($t.'.logs.path_mac') }}</p>
            <x-admin.copy-block :value="ConnectionGuide::LOG_CHECK_COMMAND" snippet="log-check" />
            <p class="text-slate-600">{{ __($t.'.logs.if_listed') }}</p>
        </div>
        <p x-cloak x-show="os === 'win'">{{ __($t.'.logs.path_win') }}</p>
    </div>

    <p class="mt-5 rounded-lg bg-slate-50 p-3 text-xs text-slate-600" data-connector-warning>{{ __($t.'.connector') }}</p>
    @if ($token === null)
        <p class="mt-2 text-xs text-slate-500">{{ __($t.'.lost_token') }}</p>
    @endif
</div>
