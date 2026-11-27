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
        @if (auth()->user()?->hasRole('admin'))
            <a href="{{ route('admin.office.education.test', $role) }}" wire:navigate class="h-8 rounded-md px-3 text-sm font-semibold leading-8 text-slate-600 hover:text-slate-900" data-test-console-link>{{ __('office.dry.title') }}</a>
            <a href="{{ route('admin.office.education.review') }}" wire:navigate class="h-8 rounded-md px-3 text-sm font-semibold leading-8 text-slate-600 hover:text-slate-900" data-review-link>{{ __('office.review.title') }}</a>
        @endif
    </nav>

    <p class="mt-4 flex items-start gap-2 rounded-lg bg-amber-50 px-3 py-2.5 text-sm text-amber-800" role="note" data-education-warning>
        <x-admin.icon name="alert" size="size-4" class="mt-0.5 shrink-0" />{{ __($t.'.no_real_conversations') }}
    </p>

    @error('version')<p class="mt-4 rounded-lg bg-red-50 px-3 py-2.5 text-sm text-danger" role="alert" data-version-error>{{ $message }}</p>@enderror

    <div class="mt-5 grid gap-4 lg:grid-cols-3 lg:gap-6">
        <form wire:submit="save" class="space-y-4 lg:col-span-2" novalidate>
            <x-admin.card :title="__($t.'.mandatory')" :description="__($t.'.mandatory_hint')">
                <pre class="max-h-64 overflow-auto whitespace-pre-wrap rounded-lg bg-slate-50 p-3 text-xs text-slate-700" data-core-rules>{{ $coreRules }}</pre>
            </x-admin.card>


            <div class="flex flex-wrap rounded-lg border border-slate-200 bg-white p-1" role="tablist" aria-label="{{ __($t.'.tabs') }}" data-education-tabs>
                @foreach (['fields', 'free', 'examples'] as $name)
                    <button type="button" role="tab" wire:click="setTab('{{ $name }}')" aria-selected="{{ $tab === $name ? 'true' : 'false' }}" data-tab="{{ $name }}" @class(['h-8 rounded-md px-3 text-sm font-semibold', 'bg-slate-900 text-white' => $tab === $name, 'text-slate-600 hover:text-slate-900' => $tab !== $name])>{{ __($t.'.tab_'.$name) }}@if (in_array($name, $errorTabs, true))<span class="ml-1 inline-block size-2 rounded-full bg-danger" data-tab-error aria-label="{{ __($t.'.tab_error') }}"></span>@endif</button>
                @endforeach
            </div>

            <div @class(['space-y-4', 'hidden' => $tab !== 'fields']) data-tab-panel="fields">
                <x-admin.card :title="__($t.'.fields')" :description="__($t.'.fields_hint')">
                    <div class="space-y-5">
                        @unless ($isRouter)
                            <div class="grid gap-4 sm:grid-cols-2">
                                <x-admin.select wire:model="guidance.tone.preset" :label="__($t.'.tone')" :options="collect($tones)->mapWithKeys(fn ($case) => [$case->value => $case->label()])->all()" />
                                <x-admin.input wire:model="guidance.tone.note" :label="__($t.'.tone_note')" maxlength="{{ $guidanceLimits['tone_note'] }}" />
                            </div>
                            <x-admin.textarea wire:model="guidance.must_ask" :label="__($t.'.must_ask')" rows="5" :hint="__($t.'.lines_hint', ['items' => $guidanceLimits['must_ask_items'], 'chars' => $guidanceLimits['must_ask_chars']]).' '.__($t.'.must_ask_hint')" />

                            <div data-escalations>
                                <p class="text-sm font-semibold">{{ __($t.'.escalations') }}</p>
                                <p class="mt-0.5 text-xs text-slate-500">{{ __($t.'.escalations_hint') }}</p>
                                <div class="mt-2 overflow-x-auto">
                                    <table class="w-full text-sm">
                                        <thead><tr class="text-left text-xs text-slate-500"><th class="py-1 pr-3 font-semibold">{{ __($t.'.escalation_topic') }}</th>@foreach ($actions as $action)<th class="px-2 py-1 font-semibold">{{ $action->label() }}</th>@endforeach</tr></thead>
                                        <tbody>
                                            @foreach ($triggers as $trigger)
                                                <tr wire:key="trigger-{{ $trigger->value }}" class="border-t border-slate-100">
                                                    <td class="py-1.5 pr-3">{{ $trigger->label() }} @if ($trigger->isLocked())<span class="text-xs text-slate-500" data-locked>({{ __($t.'.escalation_locked') }})</span>@endif</td>
                                                    @foreach ($actions as $action)
                                                        <td class="px-2 py-1.5"><input type="checkbox" wire:model="guidance.escalations.triggers.{{ $trigger->value }}" value="{{ $action->value }}" @disabled($trigger->isLocked()) class="size-4 rounded accent-brand-600" aria-label="{{ $trigger->label() }}: {{ $action->label() }}"></td>
                                                    @endforeach
                                                </tr>
                                            @endforeach
                                        </tbody>
                                    </table>
                                </div>
                                <div class="mt-3 space-y-2">
                                    @foreach ((array) ($guidance['escalations']['custom'] ?? []) as $index => $custom)
                                        <div wire:key="custom-{{ $index }}" class="flex flex-wrap items-end gap-2">
                                            <x-admin.input class="min-w-56 flex-1" wire:model="guidance.escalations.custom.{{ $index }}.text" :label="__($t.'.escalation_custom')" maxlength="{{ $guidanceLimits['custom_escalation_chars'] }}" />
                                            <x-admin.select wire:model="guidance.escalations.custom.{{ $index }}.action" :label="__($t.'.escalation_action')" :options="collect($actions)->mapWithKeys(fn ($case) => [$case->value => $case->label()])->all()" />
                                            <x-admin.button variant="ghost" size="sm" icon="trash" wire:click="removeCustomEscalation({{ $index }})">{{ __($t.'.remove_example') }}</x-admin.button>
                                        </div>
                                    @endforeach
                                    @if (count((array) ($guidance['escalations']['custom'] ?? [])) < $guidanceLimits['custom_escalations'])
                                        <x-admin.button variant="secondary" size="sm" icon="plus" wire:click="addCustomEscalation">{{ __($t.'.add_custom_escalation') }}</x-admin.button>
                                    @endif
                                </div>
                            </div>

                            <x-admin.textarea wire:model="guidance.service_facts" :label="__($t.'.service_facts')" rows="5" :hint="__($t.'.lines_hint', ['items' => $guidanceLimits['service_facts_items'], 'chars' => $guidanceLimits['service_fact_chars']]).' '.__($t.'.service_facts_hint')" />

                            <fieldset class="space-y-2">
                                <legend class="text-sm font-semibold">{{ __($t.'.knowledge_sources') }}</legend>
                                <div class="flex flex-wrap gap-x-4 gap-y-1">
                                    @foreach ($sources as $source)
                                        <label class="flex items-center gap-1.5 text-sm"><input type="checkbox" wire:model="guidance.knowledge.sources" value="{{ $source->value }}" class="size-4 rounded accent-brand-600">{{ $source->label() }}</label>
                                    @endforeach
                                </div>
                                <x-admin.input wire:model="guidance.knowledge.top_n" type="number" min="{{ $guidanceLimits['top_n_min'] }}" max="{{ $guidanceLimits['top_n_max'] }}" :label="__($t.'.top_n')" :hint="__($t.'.top_n_hint', ['min' => $guidanceLimits['top_n_min'], 'max' => $guidanceLimits['top_n_max']])" />
                            </fieldset>

                            <x-admin.input wire:model="guidance.confidence_min" type="number" min="{{ $guidanceLimits['confidence_floor'] }}" max="100" :label="__($t.'.confidence_min')" :hint="__($t.'.confidence_hint', ['floor' => $guidanceLimits['confidence_floor']])" />
                        @endunless

                        <x-admin.textarea wire:model="guidance.forbidden_topics" :label="__($t.'.forbidden_topics')" rows="3" :hint="__($t.'.lines_hint', ['items' => $guidanceLimits['forbidden_items'], 'chars' => $guidanceLimits['forbidden_chars']]).' '.__($t.'.forbidden_hint')" />

                        @if ($isRouter)
                            <div class="space-y-3" data-routing-hints>
                                <p class="text-sm font-semibold">{{ __($t.'.routing_hints') }}</p>
                                @foreach ($routable as $case)
                                    <x-admin.textarea wire:model="guidance.routing_hints.{{ $case->value }}" :label="$case->label()" rows="2" maxlength="{{ $guidanceLimits['routing_hint_chars'] }}" />
                                @endforeach
                            </div>
                        @endif
                    </div>
                </x-admin.card>
            </div>

            <div @class(['space-y-4', 'hidden' => $tab !== 'free']) data-tab-panel="free">
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
            </div>

            <div @class(['space-y-4', 'hidden' => $tab !== 'examples']) data-tab-panel="examples">
            <x-admin.card :title="__($t.'.examples')" :description="__($t.'.examples_hint', ['max' => $limits['examples_max']])">
                <p class="mb-3 flex items-start gap-2 rounded-lg bg-amber-50 px-3 py-2.5 text-sm text-amber-800" role="note"><x-admin.icon name="alert" size="size-4" class="mt-0.5 shrink-0" />{{ __($t.'.examples_warning') }}</p>
                <div class="space-y-4">
                    @foreach ($examples as $index => $example)
                        <div wire:key="example-{{ $index }}" class="space-y-2 rounded-lg border border-slate-200 p-3" data-example-kind="{{ $example['kind'] }}">
                            <div class="flex flex-wrap items-end gap-2">
                                <span class="rounded bg-slate-100 px-2 py-0.5 text-xs font-semibold text-slate-700">{{ __($t.'.kind_'.$example['kind']) }}</span>
                                <x-admin.select wire:model="examples.{{ $index }}.language" :label="__($t.'.example_language')" :options="['uz' => 'uz', 'ru' => 'ru', 'en' => 'en']" />
                                @if ($example['kind'] === 'route')
                                    <x-admin.select wire:model="examples.{{ $index }}.role" :label="__($t.'.example_role')" :options="collect($routable)->mapWithKeys(fn ($case) => [$case->value => $case->label()])->all()" />
                                @endif
                            </div>
                            <x-admin.textarea wire:model="examples.{{ $index }}.client" :label="__($t.'.example_client')" rows="2" maxlength="{{ $limits['example_chars'] }}" />
                            @if ($example['kind'] !== 'route')
                                <x-admin.textarea wire:model="examples.{{ $index }}.reply" :label="__($example['kind'] === 'bad' ? $t.'.example_bad_reply' : $t.'.example_reply')" rows="3" maxlength="{{ $limits['example_chars'] }}" />
                            @endif
                            <x-admin.input wire:model="examples.{{ $index }}.note" :label="__($t.'.example_note')" maxlength="{{ $guidanceLimits['example_note_chars'] }}" />
                            <div class="text-right"><x-admin.button variant="ghost" size="sm" icon="trash" wire:click="removeExample({{ $index }})">{{ __($t.'.remove_example') }}</x-admin.button></div>
                        </div>
                    @endforeach
                    @if (count($examples) < $limits['examples_max'])
                        <div class="flex flex-wrap gap-2">
                            @if ($isRouter)
                                <x-admin.button variant="secondary" size="sm" icon="plus" wire:click="addExample('route')">{{ __($t.'.add_route') }}</x-admin.button>
                            @else
                                <x-admin.button variant="secondary" size="sm" icon="plus" wire:click="addExample('good')">{{ __($t.'.add_good') }}</x-admin.button>
                                <x-admin.button variant="secondary" size="sm" icon="plus" wire:click="addExample('bad')">{{ __($t.'.add_bad') }}</x-admin.button>
                            @endif
                        </div>
                    @endif
                </div>
            </x-admin.card>
            </div>

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
                @if ($pendingNewer !== [])
                    <p class="mb-3 rounded-lg bg-amber-50 px-3 py-2 text-xs text-amber-800" role="note" data-pending-hint>{{ __($t.'.pending_hint', ['versions' => 'v'.implode(', v', $pendingNewer)]) }}</p>
                @endif
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
