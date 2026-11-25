{{-- Careers list (App\Livewire\Site\Careers\Index): vacancy cards, then the open application block. --}}
<div class="site-container">
    @push('head')
        @foreach ($jsonLd as $block)
            <script type="application/ld+json">{!! $block !!}</script>
        @endforeach
    @endpush

    <x-site.breadcrumbs :items="$breadcrumbs" />

    <header class="mb-7">
        <h1 class="text-[26px] leading-[1.15] font-bold tracking-[-0.025em] sm:text-[34px]">{{ __('careers.site.title') }}</h1>
        <p class="mt-2 text-ink-2">{{ __('careers.site.subtitle') }}</p>
    </header>

    @if ($cards === [])
        <p class="rounded-box-lg border border-dashed border-line bg-bg-soft p-8 text-center text-ink-2" data-careers-empty>{{ __('careers.site.empty') }}</p>
    @else
        <ul class="grid gap-5 sm:grid-cols-2 lg:grid-cols-3">
            @foreach ($cards as $card)
                <li wire:key="vacancy-{{ $card['id'] }}" class="group relative flex flex-col gap-2 rounded-box-lg border border-line bg-white p-5 transition hover:-translate-y-[3px] hover:border-brand hover:shadow-card motion-reduce:hover:translate-y-0" data-vacancy-card="{{ $card['id'] }}">
                    <p class="text-[13px] text-ink-muted">{{ $card['department'] }} · {{ $card['location'] }} · {{ $card['type'] }}</p>
                    <h2 class="text-[17px] leading-snug font-bold text-ink group-hover:text-brand-600">
                        <a href="{{ $card['url'] }}" class="after:absolute after:inset-0 focus-visible:outline-none">{{ $card['title'] }}</a>
                    </h2>
                    <p class="line-clamp-3 text-sm text-ink-2">{{ $card['summary'] }}</p>
                </li>
            @endforeach
        </ul>
    @endif

    @if ($departments !== [])
        <section class="mt-12" aria-labelledby="careers-open-title" data-careers-open>
            <x-site.section-head id="careers-open-title" :title="__('careers.open.title')" :subtitle="__('careers.open.intro')" />
            <ul class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                @foreach ($departments as $department)
                    <li class="flex items-center justify-between gap-3 rounded-box-lg border border-line bg-white p-4" data-open-department="{{ $department->value }}">
                        <span class="font-semibold text-ink">{{ $department->label() }}</span>
                        <a href="{{ route('site.careers.open', ['locale' => app()->getLocale(), 'department' => $department->value]) }}" class="text-sm font-semibold text-brand-600 hover:underline">{{ __('careers.open.apply') }} <span aria-hidden="true">→</span></a>
                    </li>
                @endforeach
            </ul>
        </section>
    @endif
</div>
