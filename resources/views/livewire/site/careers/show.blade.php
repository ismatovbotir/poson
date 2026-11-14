{{-- Vacancy page (App\Livewire\Site\Careers\Show). All texts are plain and escaped. --}}
<div class="site-container">
    @push('head')
        @foreach ($jsonLd as $block)
            <script type="application/ld+json">{!! $block !!}</script>
        @endforeach
    @endpush

    <x-site.breadcrumbs :items="$breadcrumbs" />

    <article class="max-w-[900px]" data-vacancy>
        <header class="mb-6">
            <p class="mb-2 text-sm text-ink-3">{{ $vacancy->department->label() }} · {{ $vacancy->location->label() }} · {{ $vacancy->employment_type->label() }}</p>
            <h1 class="text-[26px] leading-[1.15] font-bold tracking-[-0.025em] sm:text-[34px]">{{ $title }}</h1>
            <p class="mt-4 text-lg text-ink-2">{{ $summary }}</p>
            @if ($vacancy->valid_through)
                <p class="mt-3 text-sm text-ink-3">{{ __('careers.site.valid_through', ['date' => \App\Support\DateFormat::iso($vacancy->valid_through, 'D MMMM YYYY')]) }}</p>
            @endif
        </header>

        <div class="space-y-4 text-ink-2" data-vacancy-description>
            @foreach ($paragraphs as $paragraph)
                <p>{!! nl2br(e($paragraph)) !!}</p>
            @endforeach
        </div>

        @if ($requirements !== [])
            <section class="mt-8" aria-labelledby="vacancy-requirements" data-vacancy-requirements>
                <h2 id="vacancy-requirements" class="mb-3 text-xl font-bold text-ink">{{ __('careers.site.requirements') }}</h2>
                <ul class="list-disc space-y-2 pl-5 text-ink-2 marker:text-brand">
                    @foreach ($requirements as $item)
                        <li>{{ $item }}</li>
                    @endforeach
                </ul>
            </section>
        @endif

        @if ($offer !== [])
            <section class="mt-8" aria-labelledby="vacancy-offer" data-vacancy-offer>
                <h2 id="vacancy-offer" class="mb-3 text-xl font-bold text-ink">{{ __('careers.site.offer') }}</h2>
                <ul class="list-disc space-y-2 pl-5 text-ink-2 marker:text-brand">
                    @foreach ($offer as $item)
                        <li>{{ $item }}</li>
                    @endforeach
                </ul>
            </section>
        @endif

        @include('livewire.site.careers.partials.apply-form', ['heading' => __('careers.site.how_to_apply_title')])
    </article>

    <p class="mt-10">
        <a href="{{ route('site.careers.index', ['locale' => app()->getLocale()]) }}" class="font-semibold text-brand-600 hover:underline"><span aria-hidden="true">←</span> {{ __('careers.site.back') }}</a>
    </p>
</div>
