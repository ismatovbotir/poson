@php
    $t = 'chatbot_admin.rules';
    $locales = config('app.supported_locales');
    $action = $form->action;
    $answerUsed = in_array($action, ['answer', 'handoff', 'menu', 'product_search', 'order_status', 'repair_status', 'lead_dialog'], true);
@endphp

<form wire:submit="save" novalidate data-chatbot-rule-form>
    <x-admin.page-header :title="$title" :back-href="route('admin.chatbot.rules.index')" :back-label="__($t.'.title')">
        <x-slot:breadcrumbs>{{ __('menu.groups.chatbot') }} <span class="text-slate-300">/</span> {{ __($t.'.title') }}</x-slot:breadcrumbs>
        @if ($canSave)
            <x-slot:actions>
                <div class="hidden gap-2 lg:flex">
                    <x-admin.button variant="secondary" :href="route('admin.chatbot.rules.index')" wire:navigate>{{ __('admin.common.cancel') }}</x-admin.button>
                    <x-admin.button type="submit" icon="check" loading="save">{{ __('admin.common.save') }}</x-admin.button>
                </div>
            </x-slot:actions>
        @endif
    </x-admin.page-header>

    @error('form.rule')
        <p class="mt-4 rounded-lg bg-red-50 px-3 py-2.5 text-sm text-danger" role="alert">{{ $message }}</p>
    @enderror

    <div class="mt-5 grid grid-cols-1 gap-4 lg:grid-cols-3 lg:gap-6">
        <div class="min-w-0 space-y-4 lg:col-span-2 lg:space-y-6">

            {{-- Main --}}
            <x-admin.card :title="__($t.'.main')" :description="__($t.'.main_help')">
                <div class="grid gap-4 sm:grid-cols-2">
                    <x-admin.input wire:model="form.name" :label="__($t.'.fields.name')" required maxlength="120" />
                    @if ($isSystem)
                        <div>
                            <span class="text-sm font-semibold">{{ __($t.'.fields.key') }}</span>
                            <p class="mt-1.5 flex h-11 items-center gap-2 rounded-lg bg-slate-50 px-3 text-sm text-slate-600"><code>{{ $form->key }}</code><x-admin.badge color="outline">{{ __($t.'.system') }}</x-admin.badge></p>
                            <span class="mt-1 block text-xs text-slate-500">{{ __($t.'.system_hint') }}</span>
                        </div>
                    @else
                        <x-admin.input wire:model="form.key" :label="__($t.'.fields.key')" required maxlength="60" autocomplete="off" spellcheck="false" :hint="__($t.'.key_hint')" />
                    @endif
                </div>

                <div class="mt-4 grid gap-4 sm:grid-cols-2">
                    @if ($isSystem)
                        <div>
                            <span class="text-sm font-semibold">{{ __($t.'.fields.action') }}</span>
                            <p class="mt-1.5 flex h-11 items-center rounded-lg bg-slate-50 px-3 text-sm text-slate-600">{{ $actions[$form->action] ?? $form->action }}</p>
                        </div>
                    @else
                        <x-admin.select wire:model.live="form.action" :label="__($t.'.fields.action')" :options="$actions" required />
                    @endif

                    {{-- Params per action --}}
                    @if ($action === 'faq')
                        <x-admin.select wire:model="form.params.faq_id" :label="__($t.'.fields.faq')" :options="$this->faqOptions" :placeholder="__($t.'.faq_list')" :hint="__($t.'.faq_hint')" />
                    @elseif ($action === 'company_info')
                        <x-admin.select wire:model="form.params.topic" :label="__($t.'.fields.topic')" :options="$topics" :placeholder="__($t.'.choose')" required />
                    @elseif ($action === 'lead_dialog')
                        <x-admin.select wire:model="form.params.flow" :label="__($t.'.fields.flow')" :options="$flows" :placeholder="__($t.'.choose')" required />
                    @endif
                </div>

                @if ($action === 'link')
                    <div class="mt-4 grid gap-4 sm:grid-cols-2" data-link-params>
                        <x-admin.select wire:model.live="form.params.target" :label="__($t.'.fields.target')" :options="$targets" :placeholder="__($t.'.choose')" />
                        @if (($form->params['target'] ?? '') === 'page')
                            <x-admin.select wire:model="form.params.slug" :label="__($t.'.fields.page')" :options="$this->pageOptions" :placeholder="__($t.'.choose')" />
                        @endif
                        @if ($canUseUrl)
                            <x-admin.input wire:model="form.params.url" :label="__($t.'.fields.url')" maxlength="500" autocomplete="off" spellcheck="false" :hint="__($t.'.url_hint')" class="sm:col-span-2" />
                        @elseif (($form->params['url'] ?? '') !== '')
                            <div class="sm:col-span-2" data-url-readonly>
                                <span class="text-sm font-semibold">{{ __($t.'.fields.url') }}</span>
                                <p class="mt-1.5 break-all rounded-lg bg-slate-50 px-3 py-2.5 text-sm text-slate-600">{{ $form->params['url'] }}</p>
                                <span class="mt-1 block text-xs text-slate-500">{{ __($t.'.url_admin_only') }}</span>
                            </div>
                        @endif
                    </div>
                    <div class="mt-4">
                        <p class="text-sm font-semibold">{{ __($t.'.fields.link_label') }}</p>
                        <div class="mt-1.5 grid gap-3 sm:grid-cols-3">
                            @foreach ($locales as $locale)
                                <x-admin.input wire:model="form.params.link_label.{{ $locale }}" :label="strtoupper($locale)" :required="$locale === 'uz'" maxlength="{{ config('chatbot.label_max') }}" />
                            @endforeach
                        </div>
                    </div>
                @endif
            </x-admin.card>

            {{-- Answer --}}
            @if ($answerUsed)
                <x-admin.card :title="__($t.'.answer')" :description="__($t.'.answer_help', ['max' => config('chatbot.answer_max')])">
                    <x-admin.locale-tabs :error-keys="['form.answer']" :label="__('admin.form.content_language')">
                        @foreach ($locales as $locale)
                            <x-admin.locale-panel :locale="$locale">
                                <x-admin.textarea wire:model="form.answer.{{ $locale }}" :label="__($t.'.fields.answer')" rows="5" :required="$locale === 'uz' && $action === 'answer'" maxlength="{{ config('chatbot.answer_max') }}" />
                            </x-admin.locale-panel>
                        @endforeach
                    </x-admin.locale-tabs>
                </x-admin.card>
            @endif

            {{-- Buttons (answer rules only) --}}
            @if ($action === 'answer')
                <x-admin.card :title="__($t.'.buttons')" :description="__($t.'.buttons_help', ['max' => config('chatbot.max_buttons')])">
                    <div class="space-y-4" data-buttons>
                        @foreach ($form->buttons as $i => $button)
                            <div wire:key="button-{{ $i }}" class="rounded-xl border border-slate-200 p-3.5" data-button-row="{{ $i }}">
                                <div class="grid gap-3 sm:grid-cols-3">
                                    @foreach ($locales as $locale)
                                        <x-admin.input wire:model="form.buttons.{{ $i }}.label.{{ $locale }}" :label="__($t.'.fields.button_label').' '.strtoupper($locale)" :required="$locale === 'uz'" maxlength="{{ config('chatbot.label_max') }}" />
                                    @endforeach
                                </div>
                                <div class="mt-3 grid gap-3 sm:grid-cols-3">
                                    <x-admin.select wire:model.live="form.buttons.{{ $i }}.kind" :label="__($t.'.fields.button_kind')" :options="['rule' => __($t.'.kind_rule'), 'site' => __($t.'.kind_site'), 'url' => __($t.'.kind_url')]" />
                                    @if (($button['kind'] ?? 'site') === 'rule')
                                        <x-admin.select wire:model="form.buttons.{{ $i }}.rule_id" :label="__($t.'.fields.rule')" :options="$this->ruleOptions" :placeholder="__($t.'.choose')" />
                                    @elseif (($button['kind'] ?? 'site') === 'url')
                                        @if ($canUseUrl)
                                            <x-admin.input wire:model="form.buttons.{{ $i }}.url" :label="__($t.'.fields.url')" maxlength="500" autocomplete="off" spellcheck="false" />
                                        @else
                                            <div data-url-readonly>
                                                <span class="text-sm font-semibold">{{ __($t.'.fields.url') }}</span>
                                                @if (($button['url'] ?? '') !== '')
                                                    <p class="mt-1.5 break-all rounded-lg bg-slate-50 px-3 py-2.5 text-sm text-slate-600">{{ $button['url'] }}</p>
                                                @endif
                                                <span class="mt-1 block text-xs text-slate-500">{{ __($t.'.url_admin_only') }}</span>
                                            </div>
                                        @endif
                                    @else
                                        <x-admin.select wire:model.live="form.buttons.{{ $i }}.target" :label="__($t.'.fields.target')" :options="$targets" :placeholder="__($t.'.choose')" />
                                        @if (($button['target'] ?? '') === 'page')
                                            <x-admin.select wire:model="form.buttons.{{ $i }}.slug" :label="__($t.'.fields.page')" :options="$this->pageOptions" :placeholder="__($t.'.choose')" />
                                        @endif
                                    @endif
                                </div>
                                <button type="button" wire:click="removeButton({{ $i }})" class="mt-3 text-sm font-semibold text-danger hover:underline">{{ __($t.'.remove') }}</button>
                            </div>
                        @endforeach
                    </div>
                    @if (count($form->buttons) < config('chatbot.max_buttons'))
                        <x-admin.button type="button" variant="secondary" icon="plus" wire:click="addButton" class="mt-4">{{ __($t.'.add_button') }}</x-admin.button>
                    @endif
                </x-admin.card>
            @endif

            {{-- Keywords --}}
            <x-admin.card :title="__($t.'.keywords')" :description="__($t.'.keywords_help')">
                <div class="space-y-3" data-keywords>
                    @forelse ($form->keywords as $i => $keyword)
                        <div wire:key="keyword-{{ $i }}" class="rounded-xl border border-slate-200 p-3" data-keyword-row="{{ $i }}">
                            <div class="grid gap-3 sm:grid-cols-12">
                                <x-admin.input wire:model.live.debounce.400ms="form.keywords.{{ $i }}.phrase" :label="__($t.'.fields.phrase')" maxlength="{{ config('chatbot.keyword_max') }}" class="sm:col-span-5" />
                                <x-admin.select wire:model="form.keywords.{{ $i }}.locale" :label="__($t.'.fields.locale')" :options="['' => __($t.'.any_locale'), 'uz' => 'UZ', 'ru' => 'RU', 'en' => 'EN']" class="sm:col-span-2" />
                                <x-admin.select wire:model="form.keywords.{{ $i }}.match_type" :label="__($t.'.fields.match_type')" :options="$matchTypes" class="sm:col-span-3" />
                                <x-admin.input wire:model="form.keywords.{{ $i }}.weight" type="number" min="1" max="100" :label="__($t.'.fields.weight')" class="sm:col-span-2" />
                            </div>
                            <p class="mt-2 flex flex-wrap items-center justify-between gap-2 text-xs text-slate-500">
                                <span>{{ __($t.'.normalized_as') }}: <code class="rounded bg-slate-100 px-1.5 py-0.5 text-slate-700" data-normalized>{{ \App\Support\Chat\ChatText::normalize((string) ($keyword['phrase'] ?? ''), (int) config('chatbot.keyword_max')) ?: '—' }}</code></span>
                                <button type="button" wire:click="removeKeyword({{ $i }})" class="text-sm font-semibold text-danger hover:underline">{{ __($t.'.remove') }}</button>
                            </p>
                            @error('form.keywords.'.$i)<p class="mt-1 text-xs text-danger">{{ $message }}</p>@enderror
                        </div>
                    @empty
                        <p class="text-sm text-slate-500">{{ __($t.'.no_keywords') }}</p>
                    @endforelse
                </div>
                @error('form.keywords')<p class="mt-2 text-xs text-danger">{{ $message }}</p>@enderror
                @if (count($form->keywords) < config('chatbot.max_keywords_per_rule'))
                    <x-admin.button type="button" variant="secondary" icon="plus" wire:click="addKeyword" class="mt-4">{{ __($t.'.add_keyword') }}</x-admin.button>
                @endif
            </x-admin.card>
        </div>

        {{-- Side column --}}
        <aside class="min-w-0 space-y-4 lg:sticky lg:top-24 lg:self-start lg:space-y-6">
            <x-admin.card :title="__($t.'.publishing')">
                <div class="space-y-4">
                    <x-admin.toggle wire:model="form.is_active" :label="__($t.'.fields.is_active')" :description="__($t.'.is_active_hint')" />
                    <x-admin.toggle wire:model="form.on_telegram" :label="__($t.'.fields.on_telegram')" />
                    <x-admin.toggle wire:model="form.on_web" :label="__($t.'.fields.on_web')" />
                    <x-admin.input wire:model="form.priority" type="number" min="-1000" max="1000" :label="__($t.'.fields.priority')" :hint="__($t.'.priority_hint')" />
                </div>
            </x-admin.card>

            <x-admin.card :title="__($t.'.menu')">
                <div class="space-y-4">
                    <x-admin.toggle wire:model.live="form.in_menu" :label="__($t.'.fields.in_menu')" :description="__($t.'.in_menu_hint')" />
                    @if ($form->in_menu)
                        <x-admin.input wire:model="form.menu_sort" type="number" min="0" max="32000" :label="__($t.'.fields.menu_sort')" />
                        @foreach ($locales as $locale)
                            <x-admin.input wire:model="form.params.label.{{ $locale }}" :label="__($t.'.fields.menu_label').' '.strtoupper($locale)" :required="$locale === 'uz'" maxlength="{{ config('chatbot.label_max') }}" />
                        @endforeach
                    @endif
                </div>
            </x-admin.card>

            @if ($canDelete)
                <section class="rounded-2xl border border-red-200 bg-white p-4 sm:p-5">
                    <h2 class="text-sm font-semibold text-danger">{{ __('admin.catalog.danger_zone') }}</h2>
                    <p class="mt-1 text-xs text-slate-500">{{ __($t.'.delete_help') }}</p>
                    <button
                        type="button"
                        x-on:click="$dispatch('confirm', {
                            title: @js(__($t.'.delete_title')),
                            body: @js(__($t.'.delete_body', ['name' => $title])),
                            label: @js(__('admin.common.delete')),
                            component: $wire.$id, method: 'delete',
                        })"
                        class="mt-3 h-10 w-full rounded-lg border border-red-200 text-sm font-semibold text-danger hover:bg-red-50"
                        data-delete-rule
                    >{{ __('admin.common.delete') }}</button>
                </section>
            @elseif ($isSystem)
                <p class="rounded-2xl border border-slate-200 bg-white p-4 text-xs text-slate-500 sm:p-5">{{ __($t.'.system_no_delete') }}</p>
            @endif
        </aside>
    </div>

    @if ($canSave)
        <x-admin.sticky-save-bar :cancel-href="route('admin.chatbot.rules.index')" />
    @endif
</form>
