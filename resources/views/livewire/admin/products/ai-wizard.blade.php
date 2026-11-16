@php
    $t = 'admin.ai_wizard';
    $locales = config('app.supported_locales');
    $steps = \App\Livewire\Admin\Products\AiWizard::STEPS;
    $currentIndex = array_search($step, $steps, true);
    $fieldLabels = [
        'name' => __('admin.catalog.name'),
        'short_description' => __('admin.products.fields.short_description'),
        'description' => __('admin.products.fields.description'),
        'meta_title' => __('admin.catalog.meta_title'),
        'meta_description' => __('admin.catalog.meta_description'),
    ];
    $backHref = $improve && $product ? route('admin.products.edit', $product) : route('admin.products.index');
    $backLabel = $improve && $product ? $product->name : __('admin.products.title');
@endphp

<div data-ai-wizard="{{ $improve ? 'improve' : 'create' }}">
    <x-admin.page-header :title="$title" :subtitle="__($t.'.subtitle')" :back-href="$backHref" :back-label="$backLabel">
        <x-slot:breadcrumbs>{{ __('admin.nav.groups.catalog') }} <span class="text-slate-300">/</span> {{ __('admin.products.title') }} <span class="text-slate-300">/</span> {{ __($t.'.breadcrumb') }}</x-slot:breadcrumbs>
        @if ($draft)
            <x-slot:actions>
                <x-admin.button
                    variant="secondary"
                    icon="x"
                    x-on:click="$dispatch('confirm', {
                        title: @js(__($t.'.discard_title')),
                        body: @js(__($t.'.discard_body')),
                        label: @js(__($t.'.discard')),
                        component: $wire.$id, method: 'cancel', params: [],
                    })"
                    data-discard
                >{{ __($t.'.discard') }}</x-admin.button>
            </x-slot:actions>
        @endif
    </x-admin.page-header>

    {{-- Stepper (order.html) --}}
    <ol class="scrollbar-none mt-5 flex items-center gap-2 overflow-x-auto rounded-2xl border border-slate-200 bg-white p-3 text-sm sm:gap-3 sm:p-4" aria-label="{{ __($t.'.steps_label') }}">
        @foreach ($steps as $index => $name)
            @if ($index > 0)
                <li @class(['h-px w-6 shrink-0 sm:flex-1', 'bg-brand-600' => $index <= $currentIndex, 'bg-slate-200' => $index > $currentIndex]) aria-hidden="true"></li>
            @endif
            @if ($index < $currentIndex)
                <li class="flex shrink-0 items-center gap-2">
                    <button type="button" wire:click="goTo('{{ $name }}')" @disabled($pending) class="flex items-center gap-2 disabled:cursor-not-allowed">
                        <span class="grid size-7 place-items-center rounded-full bg-brand-600 text-white"><x-admin.icon name="check" size="size-4" /></span>
                        <span class="font-semibold">{{ __($t.'.steps.'.$name) }}</span>
                    </button>
                </li>
            @elseif ($index === $currentIndex)
                <li class="flex shrink-0 items-center gap-2" aria-current="step"><span class="grid size-7 place-items-center rounded-full border-2 border-brand-600 text-xs font-bold text-brand-700">{{ $index + 1 }}</span><span class="font-semibold text-brand-700">{{ __($t.'.steps.'.$name) }}</span></li>
            @else
                <li class="flex shrink-0 items-center gap-2 text-slate-400"><span class="grid size-7 place-items-center rounded-full border-2 border-slate-200 text-xs font-bold">{{ $index + 1 }}</span>{{ __($t.'.steps.'.$name) }}</li>
            @endif
        @endforeach
    </ol>

    {{-- A stage ended without a result: fixed message, retry of that stage only --}}
    @if ($failed && in_array($step, ['facts', 'write', 'photos'], true))
        <div class="mt-4 flex flex-wrap items-start gap-3 rounded-2xl border border-red-200 bg-red-50 p-4 text-sm text-danger" role="alert" data-stage-error="{{ $errorKey }}">
            <x-admin.icon name="alert" size="size-5" class="shrink-0" />
            <p class="min-w-0 flex-1">{{ __($t.'.stage_errors.'.(in_array($errorKey, ['not_allowed', 'capped', 'unavailable', 'api_error', 'refused', 'truncated', 'invalid_answer', 'no_images'], true) ? $errorKey : 'error')) }}</p>
            @if ($step === 'write')
                <x-admin.button size="sm" variant="secondary" wire:click="write" loading="write">{{ __($t.'.retry') }}</x-admin.button>
            @else
                <x-admin.button size="sm" variant="secondary" wire:click="look" loading="look">{{ __($t.'.retry') }}</x-admin.button>
            @endif
        </div>
    @endif

    {{-- An apply that was interrupted (N5): nothing runs again; open the product or discard the draft --}}
    @if ($interrupted)
        <div class="mt-4 flex flex-wrap items-start gap-3 rounded-2xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-800" role="alert" data-apply-interrupted>
            <x-admin.icon name="alert" size="size-5" class="shrink-0" />
            <div class="min-w-0 flex-1">
                <p class="font-semibold">{{ __($t.'.interrupted.title') }}</p>
                <p class="mt-1">{{ __($t.'.interrupted.body') }}</p>
            </div>
            <div class="flex flex-wrap gap-2">
                @if ($interruptedProductId)
                    <x-admin.button size="sm" variant="secondary" :href="route('admin.products.edit', $interruptedProductId)" wire:navigate data-open-product>{{ __($t.'.interrupted.open_product') }}</x-admin.button>
                @endif
                <x-admin.button
                    size="sm"
                    variant="danger"
                    x-on:click="$dispatch('confirm', {
                        title: @js(__($t.'.discard_title')),
                        body: @js(__($t.'.discard_body')),
                        label: @js(__($t.'.discard')),
                        component: $wire.$id, method: 'cancel', params: [],
                    })"
                >{{ __($t.'.discard') }}</x-admin.button>
            </div>
        </div>
    @else
    <div class="mt-4">
        {{-- 1. Main info --}}
        @if ($step === 'info')
            @include('livewire.admin.products.ai-wizard.info')
        @endif

        {{-- 2. Photos --}}
        @if ($step === 'photos')
            @include('livewire.admin.products.ai-wizard.photos')
        @endif

        {{-- 3a. Facts --}}
        @if ($step === 'facts')
            @include('livewire.admin.products.ai-wizard.facts')
        @endif

        {{-- 3b. Writing --}}
        @if ($step === 'write')
            @include('livewire.admin.products.ai-wizard.write')
        @endif

        {{-- 4. Review (improve: diff current → proposed) --}}
        @if ($step === 'review')
            @include('livewire.admin.products.ai-wizard.review')
        @endif

        {{-- 5. SEO and confirm --}}
        @if ($step === 'confirm')
            @include('livewire.admin.products.ai-wizard.confirm')
        @endif
    </div>
    @endif
</div>
