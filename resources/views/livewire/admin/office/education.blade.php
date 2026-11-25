@php($t = 'office.education')

{{-- Virtual office → Education (plan 41 §8.4). Admin only; every save is a new immutable version. --}}
<div data-office-education>
    <x-admin.page-header :title="__($t.'.title')" :subtitle="__($t.'.subtitle')">
        <x-slot:breadcrumbs>{{ __('menu.groups.office') }} <span class="text-slate-300">/</span> {{ __($t.'.title') }}</x-slot:breadcrumbs>
    </x-admin.page-header>

    <nav class="mt-4 flex flex-wrap rounded-lg border border-slate-200 bg-white p-1" aria-label="{{ __($t.'.roles') }}">
        @foreach ($roles as $case)
            <a href="{{ route('admin.office.education', $case->value) }}" wire:navigate @if ($case->value === $role) aria-current="page" @endif @class(['h-8 rounded-md px-3 text-sm font-semibold leading-8', 'bg-slate-900 text-white' => $case->value === $role, 'text-slate-600 hover:text-slate-900' => $case->value !== $role])>{{ $case->label() }}</a>
        @endforeach
    </nav>

    <p class="mt-4 flex max-w-4xl items-start gap-2 rounded-lg bg-amber-50 px-3 py-2.5 text-sm text-amber-800" role="note" data-education-warning>
        <x-admin.icon name="alert" size="size-4" class="mt-0.5 shrink-0" />{{ __($t.'.no_real_conversations') }}
    </p>

    @error('version')<p class="mt-4 max-w-4xl rounded-lg bg-red-50 px-3 py-2.5 text-sm text-danger" role="alert" data-version-error>{{ $message }}</p>@enderror

    <div class="mt-5 grid gap-4 lg:grid-cols-3 lg:gap-6">
        <form wire:submit="save" class="space-y-4 lg:col-span-2" novalidate>
            <x-admin.card :title="__($t.'.mandatory')" :description="__($t.'.mandatory_hint')">
                <pre class="max-h-64 overflow-auto whitespace-pre-wrap rounded-lg bg-slate-50 p-3 text-xs text-slate-700" data-core-rules>{{ $coreRules }}</pre>
            </x-admin.card>

            <x-admin.card :title="__($t.'.teaching')" :description="$current ? __($t.'.current', ['version' => $current->version]) : __($t.'.no_version')">
                <div class="space-y-4">
                    <x-admin.textarea wire:model="instructions" :label="__($t.'.instructions')" rows="8" maxlength="{{ $limits['instructions_max'] }}" :hint="__($t.'.max_chars', ['max' => $limits['instructions_max']])" required />
                    <x-admin.textarea wire:model="rules" :label="__($t.'.rules')" rows="4" maxlength="{{ $limits['rules_max'] }}" :hint="__($t.'.max_chars', ['max' => $limits['rules_max']])" />
                    <x-admin.textarea wire:model="knowledge" :label="__($t.'.knowledge')" rows="6" maxlength="{{ $limits['knowledge_max'] }}" :hint="__($t.'.max_chars', ['max' => $limits['knowledge_max']])" />
                    @if ($role !== 'router')
                        <x-admin.toggle wire:model="useSiteKnowledge" :label="__($t.'.use_site_knowledge')" :description="__($t.'.use_site_knowledge_hint')" />
                    @endif
                    <div class="grid gap-4 sm:grid-cols-2">
                        <x-admin.select wire:model="model" :label="__($t.'.model')" :options="array_combine($models, $models)" />
                        <x-admin.input wire:model="maxTokens" type="number" min="{{ $tokenBounds['max_tokens_min'] ?? 100 }}" max="{{ $tokenBounds['max_tokens_max'] ?? 500 }}" :label="__($t.'.max_tokens')" :hint="__($t.'.max_tokens_hint', ['min' => $tokenBounds['max_tokens_min'] ?? 100, 'max' => $tokenBounds['max_tokens_max'] ?? 500])" />
                    </div>
                </div>
            </x-admin.card>

            <x-admin.card :title="__($t.'.examples')" :description="__($t.'.examples_hint', ['max' => $limits['examples_max']])">
                <div class="space-y-4">
                    @foreach ($examples as $index => $example)
                        <div wire:key="example-{{ $index }}" class="space-y-2 rounded-lg border border-slate-200 p-3">
                            <x-admin.textarea wire:model="examples.{{ $index }}.client" :label="__($t.'.example_client')" rows="2" maxlength="{{ $limits['example_chars'] }}" />
                            <x-admin.textarea wire:model="examples.{{ $index }}.reply" :label="__($t.'.example_reply')" rows="3" maxlength="{{ $limits['example_chars'] }}" />
                            <x-admin.input wire:model="examples.{{ $index }}.note" :label="__($t.'.example_note')" maxlength="{{ $limits['example_chars'] }}" />
                            <div class="text-right"><x-admin.button variant="ghost" size="sm" icon="trash" wire:click="removeExample({{ $index }})">{{ __($t.'.remove_example') }}</x-admin.button></div>
                        </div>
                    @endforeach
                    @if (count($examples) < $limits['examples_max'])
                        <x-admin.button variant="secondary" size="sm" icon="plus" wire:click="addExample">{{ __($t.'.add_example') }}</x-admin.button>
                    @endif
                </div>
            </x-admin.card>

            <x-admin.card>
                <div class="space-y-3">
                    <x-admin.input wire:model="changeNote" :label="__($t.'.change_note')" maxlength="{{ $limits['change_note_max'] }}" />
                    <div class="flex flex-wrap gap-2">
                        <x-admin.button type="submit" icon="check" loading="save" data-action="save">{{ __($t.'.save') }}</x-admin.button>
                        <x-admin.button variant="secondary" wire:click="saveAndPublish" loading="saveAndPublish" data-action="save-publish">{{ __($t.'.save_publish') }}</x-admin.button>
                    </div>
                </div>
            </x-admin.card>
        </form>

        <div class="space-y-4">
            <x-admin.card :title="__($t.'.versions')">
                @forelse ($versions as $version)
                    <div wire:key="version-{{ $version->id }}" class="border-b border-slate-100 py-2.5 text-sm last:border-0" data-version="{{ $version->version }}">
                        <div class="flex items-center justify-between gap-2">
                            <span class="font-semibold">v{{ $version->version }} @if ($current?->id === $version->id)<x-admin.badge color="brand" pill>{{ __($t.'.published_badge') }}</x-admin.badge>@endif</span>
                            <span class="text-xs text-slate-500">{{ $version->created_at?->copy()->setTimezone(config('app.timezone'))->format('d.m.Y H:i') }}</span>
                        </div>
                        <p class="mt-0.5 text-xs text-slate-500">{{ $version->createdBy?->name ?? __($t.'.system') }} · {{ $version->model }} · {{ $version->max_tokens }}@if ($version->change_note) · {{ $version->change_note }}@endif</p>
                        <div class="mt-1.5 flex flex-wrap gap-3 text-xs font-semibold">
                            @if ($current?->id !== $version->id)
                                <button type="button" wire:click="publish({{ $version->id }})" wire:confirm="{{ __($t.'.confirm_publish', ['version' => $version->version]) }}" class="text-brand-700 hover:underline" data-action="publish">{{ __($t.'.publish') }}</button>
                                <button type="button" wire:click="compare({{ $version->id }})" class="text-slate-600 hover:underline">{{ __($t.'.compare') }}</button>
                            @endif
                            <button type="button" wire:click="edit({{ $version->id }})" class="text-slate-600 hover:underline">{{ __($t.'.load') }}</button>
                        </div>
                    </div>
                @empty
                    <p class="text-sm text-slate-500">{{ __($t.'.no_version') }}</p>
                @endforelse
            </x-admin.card>

            @if ($compared)
                <x-admin.card :title="__($t.'.diff_title', ['version' => $compared->version])">
                    <p class="mb-2 flex flex-wrap gap-3 text-xs text-slate-500"><span class="rounded bg-red-50 px-1.5 text-red-800">− {{ __($t.'.diff_published') }}</span><span class="rounded bg-brand-50 px-1.5 text-brand-800">+ {{ __($t.'.diff_other', ['version' => $compared->version]) }}</span></p>
                    @if (collect($diff)->every(fn ($row) => $row['type'] === 'same'))
                        <p class="text-sm text-slate-500">{{ __($t.'.no_diff') }}</p>
                    @else
                        <div class="max-h-96 overflow-auto" data-diff>
                            @foreach ($diff as $row)
                                <p @class(['whitespace-pre-wrap rounded px-2 py-0.5 text-xs', 'text-slate-500' => $row['type'] === 'same', 'bg-red-50 text-red-800' => $row['type'] === 'published', 'bg-brand-50 text-brand-800' => $row['type'] === 'other'])>{{ match ($row['type']) { 'published' => '−', 'other' => '+', default => ' ' } }} {{ $row['line'] }}</p>
                            @endforeach
                        </div>
                    @endif
                    <div class="mt-2"><x-admin.button variant="ghost" size="sm" wire:click="compare(null)">{{ __('admin.a11y.close') }}</x-admin.button></div>
                </x-admin.card>
            @endif
        </div>
    </div>
</div>
