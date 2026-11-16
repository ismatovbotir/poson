{{-- AI wizard step "confirm" (plan 32 §5); variables from AiWizard::render(). --}}
@php
    $metaTitle = ($texts['meta_title']['uz'] ?? '') !== '' ? $texts['meta_title']['uz'] : ($texts['name']['uz'] ?? '');
    $metaDescription = $texts['meta_description']['uz'] ?? '';
@endphp
<div class="space-y-4" data-step="confirm">
    <x-admin.card :title="__($t.'.confirm.seo')">
        <div class="space-y-3 text-sm">
            <p><span class="text-slate-500">{{ __($t.'.confirm.slug') }}:</span> <code class="font-mono">{{ $slugPreview ?: '—' }}</code>@unless ($improve) <span class="text-xs text-slate-500">· {{ __($t.'.confirm.slug_final') }}</span>@endunless</p>
            <div class="rounded-xl border border-slate-200 p-4" data-serp>
                <p class="truncate text-base text-sky-800">{{ $metaTitle !== '' ? $metaTitle : '—' }}</p>
                <p class="mt-1 text-xs text-brand-700">{{ parse_url((string) config('app.url'), PHP_URL_HOST) }}/uz/product/{{ $slugPreview }}</p>
                <p class="mt-1 line-clamp-2 text-slate-600">{{ $metaDescription !== '' ? $metaDescription : '—' }}</p>
            </div>
            <p class="text-xs text-slate-500">{{ __($t.'.confirm.counts', ['title' => mb_strlen($metaTitle), 'description' => mb_strlen($metaDescription)]) }}</p>
        </div>
    </x-admin.card>

    <x-admin.card :title="__($t.'.confirm.changes')">
        <ul class="space-y-1.5 text-sm" data-changes>
            @if ($changes['info'] !== [])
                <li>{{ __($t.'.confirm.info', ['fields' => collect($changes['info'])->map(fn ($key) => __($t.'.info_fields.'.$key))->implode(', ')]) }}</li>
            @endif
            <li>{{ __($t.'.confirm.texts', ['fields' => $changes['texts'] !== [] ? collect($changes['texts'])->map(fn ($field) => $fieldLabels[$field])->implode(', ') : '—']) }}</li>
            <li>{{ trans_choice($t.'.confirm.specs', $changes['specs'], ['count' => $changes['specs']]) }}</li>
            @if ($changes['alts'])<li>{{ __($t.'.confirm.alts') }}</li>@endif
            @foreach ($changes['images'] as $change)
                <li wire:key="change-{{ $change['index'] }}">{{ __($t.'.confirm.image_'.$change['kind'], ['number' => $change['index'] + 1]) }}</li>
            @endforeach
        </ul>

        @if ($imageConfirmations !== [] && $status !== \App\Enums\AiDraftStatus::Applying)
            <div class="mt-4 space-y-2 rounded-xl border border-amber-200 bg-amber-50 p-3 text-sm text-amber-900" data-image-confirm>
                @foreach ($imageConfirmations as $item)
                    <label wire:key="confirm-{{ $item['index'] }}" class="flex items-start gap-2">
                        <input type="checkbox" wire:model="confirmed.{{ $item['index'] }}" class="mt-0.5 rounded border-amber-400 text-brand-600">
                        <span>{{ __($t.'.confirm.confirm_'.$item['kind'], ['number' => $item['target'] + 1]) }}</span>
                    </label>
                @endforeach
                <p class="text-xs">{{ __($t.'.confirm.kept_original') }}</p>
            </div>
        @endif

        @if ($improve && $product?->is_active)
            <p class="mt-4 flex items-start gap-2 rounded-lg bg-amber-50 px-3 py-2.5 text-sm text-amber-800" role="note" data-live><x-admin.icon name="alert" size="size-4" class="mt-0.5 shrink-0" />{{ __($t.'.confirm.live') }}</p>
        @elseif (! $improve)
            <p class="mt-4 flex items-start gap-2 rounded-lg bg-slate-50 px-3 py-2.5 text-sm text-slate-600" role="note" data-draft-note><x-admin.icon name="info" size="size-4" class="mt-0.5 shrink-0" />{{ __($t.'.confirm.draft') }}</p>
        @endif

        @if ($errors->any())
            <ul class="mt-4 space-y-1 rounded-lg bg-red-50 px-3 py-2.5 text-sm text-danger" role="alert" data-apply-errors>
                @foreach (array_slice($errors->all(), 0, 10) as $message)
                    <li>{{ $message }}</li>
                @endforeach
            </ul>
        @endif
    </x-admin.card>

    @if ($status === \App\Enums\AiDraftStatus::Applying)
        {{-- An interrupted apply (F3): the actions resume from the draft's progress, nothing is added twice. --}}
        <div class="flex flex-wrap items-center justify-between gap-3 rounded-2xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900" role="status" data-applying>
            <p class="flex min-w-0 flex-1 items-start gap-2"><x-admin.icon name="alert" size="size-4" class="mt-0.5 shrink-0" />{{ __($t.'.confirm.applying') }}</p>
            <x-admin.button icon="check" wire:click="apply" loading="apply" data-apply data-finish>{{ __($t.'.confirm.finish') }}</x-admin.button>
        </div>
    @else
        <div class="flex flex-wrap justify-between gap-2">
            <x-admin.button variant="secondary" icon="chevron-left" wire:click="goTo('review')">{{ __($t.'.back') }}</x-admin.button>
            <x-admin.button icon="check" wire:click="apply" loading="apply" data-apply>{{ $improve ? __($t.'.confirm.apply') : __($t.'.confirm.create') }}</x-admin.button>
        </div>
    @endif
</div>
