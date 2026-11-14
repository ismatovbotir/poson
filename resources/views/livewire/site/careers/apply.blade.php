{{-- Open application to a department (App\Livewire\Site\Careers\Apply). --}}
<div class="site-container">
    <x-site.breadcrumbs :items="$breadcrumbs" />

    <div class="max-w-[900px]" data-open-application="{{ $departmentCase->value }}">
        <header class="mb-6">
            <h1 class="text-[26px] leading-[1.15] font-bold tracking-[-0.025em] sm:text-[34px]">{{ __('careers.open.title') }}: {{ $departmentCase->label() }}</h1>
            <p class="mt-4 text-lg text-ink-2">{{ __('careers.open.intro') }}</p>
        </header>

        @include('livewire.site.careers.partials.apply-form', ['heading' => __('careers.site.how_to_apply_title')])
    </div>

    <p class="mt-10">
        <a href="{{ route('site.careers.index', ['locale' => app()->getLocale()]) }}" class="font-semibold text-brand-600 hover:underline"><span aria-hidden="true">←</span> {{ __('careers.site.back') }}</a>
    </p>
</div>
