{{-- AI wizard step "review" (plan 32 §5); variables from AiWizard::render(). --}}
<div class="space-y-4" data-step="review">
    @if ($status === \App\Enums\AiDraftStatus::Truncated)
        <p class="flex items-start gap-2 rounded-lg bg-amber-50 px-3 py-2.5 text-sm text-amber-800" role="note" data-truncated><x-admin.icon name="alert" size="size-4" class="mt-0.5 shrink-0" />{{ __($t.'.review.truncated') }}</p>
    @endif
    @if ($messages !== [])
        <ul class="space-y-1 rounded-lg bg-slate-50 px-3 py-2.5 text-sm text-slate-700" data-messages>
            @foreach (array_slice($messages, 0, 30) as $message)
                <li wire:key="msg-{{ $loop->index }}" class="flex items-start gap-2"><x-admin.icon name="info" size="size-4" class="mt-0.5 shrink-0 text-slate-400" />
                    @if (($message['message'] ?? '') === $t.'.guard.promise')<x-admin.badge color="amber" data-warning="promise">{{ __($t.'.review.warning') }}</x-admin.badge>@endif
                    <span>@if (! empty($message['key']) && isset($fieldLabels[$message['key']])){{ $fieldLabels[$message['key']] }}@if (! empty($message['locale'])) ({{ strtoupper($message['locale']) }})@endif: @endif{{ __((string) ($message['message'] ?? $t.'.review.warning'), (array) ($message['params'] ?? [])) }}</span>
                </li>
            @endforeach
        </ul>
    @endif

    <x-admin.card :title="__($t.'.review.texts')" :description="$improve ? __($t.'.review.texts_help_improve') : __($t.'.review.texts_help_create')">
        <div class="space-y-5">
            @if ($improve)
                <x-admin.toggle wire:model.live="onlyEmpty" :label="__($t.'.review.only_empty')" :description="__($t.'.review.only_empty_hint')" data-only-empty />
                <div class="grid gap-2 sm:grid-cols-2" data-accept-fields>
                    @foreach ($fieldLabels as $field => $label)
                        <label class="flex items-center gap-2 rounded-lg bg-slate-50 px-3 py-2 text-sm">
                            <input type="checkbox" wire:model.live="accept.{{ $field }}" class="rounded border-slate-300 text-brand-600" @disabled(array_filter($texts[$field] ?? []) === [])>
                            <span>{{ __($t.'.review.apply_field', ['field' => $label]) }}</span>
                        </label>
                    @endforeach
                </div>
            @endif
            <x-admin.locale-tabs :error-keys="array_map(fn ($field) => 'texts.'.$field, array_keys($fieldLabels))" :label="__('admin.form.content_language')">
                @foreach ($locales as $locale)
                    <x-admin.locale-panel :locale="$locale">
                        @foreach ($fieldLabels as $field => $label)
                            <div wire:key="text-{{ $field }}-{{ $locale }}" @class(['space-y-2', 'opacity-60' => $improve && ! ($accept[$field] ?? false)])>
                                @if ($improve && $current)
                                    <div class="text-sm" data-current="{{ $field }}">
                                        <p class="text-xs font-semibold uppercase text-slate-500">{{ $label }} · {{ __($t.'.review.current') }}</p>
                                        <p class="mt-1 line-clamp-4 whitespace-pre-line rounded-lg bg-slate-50 px-3 py-2 text-slate-600">{{ $current['texts'][$field][$locale] !== '' ? \Illuminate\Support\Str::limit($current['texts'][$field][$locale], 600) : '—' }}</p>
                                    </div>
                                @endif
                                <x-admin.textarea
                                    wire:model="texts.{{ $field }}.{{ $locale }}"
                                    name="texts.{{ $field }}.{{ $locale }}"
                                    :label="$improve ? $label.' · '.__($t.'.review.proposed') : $label"
                                    :rows="$field === 'description' ? 10 : ($field === 'name' || $field === 'meta_title' ? 2 : 3)"
                                    maxlength="{{ \App\Actions\Catalog\Ai\AiDraftProposal::max($field) }}"
                                    :hint="$field === 'description' ? __($t.'.review.html_hint') : null"
                                />
                            </div>
                        @endforeach
                    </x-admin.locale-panel>
                @endforeach
            </x-admin.locale-tabs>
        </div>
    </x-admin.card>

    @if ($specs !== [])
        <x-admin.card :title="__($t.'.review.specs')" :description="__($t.'.review.specs_help')" :padding="false">
            <div class="overflow-x-auto">
                <table class="w-full text-sm" data-specs>
                    <thead class="bg-slate-50 text-left text-xs font-semibold uppercase text-slate-500">
                        <tr>
                            <th class="px-4 py-2.5">{{ __($t.'.review.apply') }}</th>
                            <th class="px-4 py-2.5">{{ __($t.'.review.attribute') }}</th>
                            @if ($improve)<th class="px-4 py-2.5">{{ __($t.'.review.current') }}</th>@endif
                            <th class="px-4 py-2.5">{{ __($t.'.review.proposed') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        @foreach ($specs as $id => $row)
                            @continue(! isset($specMeta[$id]))
                            @php($meta = $specMeta[$id])
                            <tr wire:key="spec-{{ $id }}" data-spec="{{ $id }}">
                                <td class="px-4 py-2.5"><input type="checkbox" wire:model.live="acceptSpecs.{{ $id }}" class="rounded border-slate-300 text-brand-600" aria-label="{{ __($t.'.review.apply') }}: {{ $meta['name'] }}"></td>
                                <td class="px-4 py-2.5">
                                    <span class="font-semibold">{{ $meta['name'] }}</span>@if ($meta['unit'] !== '') <span class="text-slate-500">({{ $meta['unit'] }})</span>@endif
                                    @if ($row['unsure'] ?? true)
                                        <x-admin.badge color="amber" class="ml-1" data-unsure>{{ __($t.'.review.unsure') }}</x-admin.badge>
                                    @endif
                                </td>
                                @if ($improve)<td class="px-4 py-2.5 text-slate-600">{{ $current['specs'][$id] ?? '—' }}</td>@endif
                                <td class="px-4 py-2.5">
                                    @if ($meta['type'] === \App\Enums\AttributeType::Number)
                                        <input type="text" inputmode="decimal" wire:model="specs.{{ $id }}.number" maxlength="32" class="h-9 w-32 rounded-lg border border-slate-200 px-2 text-sm" aria-label="{{ $meta['name'] }}">
                                    @elseif ($meta['type'] === \App\Enums\AttributeType::Text)
                                        <div class="grid gap-1 sm:grid-cols-3">
                                            @foreach ($locales as $locale)
                                                <input type="text" wire:model="specs.{{ $id }}.text.{{ $locale }}" maxlength="255" placeholder="{{ strtoupper($locale) }}" class="h-9 rounded-lg border border-slate-200 px-2 text-sm" aria-label="{{ $meta['name'] }} ({{ strtoupper($locale) }})">
                                            @endforeach
                                        </div>
                                    @else
                                        {{ $meta['value'] !== '' ? $meta['value'] : '—' }}
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </x-admin.card>
    @endif

    @if ($alts !== [])
        <x-admin.card :title="__($t.'.review.alts')" :description="__($t.'.review.alts_help')">
            <div class="space-y-4">
                @if ($improve)
                    <label class="flex items-center gap-2 text-sm"><input type="checkbox" wire:model.live="accept.alts" class="rounded border-slate-300 text-brand-600"><span>{{ __($t.'.review.apply_alts') }}</span></label>
                @endif
                @foreach ($alts as $index => $altLocales)
                    @continue(($images[$index]['action'] ?? 'remove') === 'remove')
                    <div wire:key="alt-{{ $index }}" class="grid gap-2 sm:grid-cols-4" data-alt="{{ $index }}">
                        <span class="text-sm font-semibold sm:pt-2">{{ __($t.'.photos.photo', ['number' => $index + 1]) }}</span>
                        @foreach ($locales as $locale)
                            <x-admin.input wire:model="alts.{{ $index }}.{{ $locale }}" name="alts.{{ $index }}.{{ $locale }}" :label="strtoupper($locale)" maxlength="255" autocomplete="off" />
                        @endforeach
                    </div>
                @endforeach
            </div>
        </x-admin.card>
    @endif

    <div class="flex flex-wrap justify-between gap-2">
        <x-admin.button variant="secondary" icon="chevron-left" wire:click="goTo('facts')">{{ __($t.'.review.rewrite') }}</x-admin.button>
        <x-admin.button icon="chevron-right" wire:click="toConfirm" loading="toConfirm" data-to-confirm>{{ __($t.'.next') }}</x-admin.button>
    </div>
</div>
