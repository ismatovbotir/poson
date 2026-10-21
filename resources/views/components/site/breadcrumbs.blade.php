@props(['items'])

{{--
    Breadcrumbs (template .crumbs). $items: list of ['label' => ..., 'url' => ?string];
    the last item (no url) is the current page. The matching BreadcrumbList JSON-LD
    comes from StructuredData::breadcrumbs().
--}}
<nav {{ $attributes->class('py-5 text-[13px] text-ink-3') }} aria-label="{{ __('site.breadcrumbs.aria_label') }}">
    <ol class="flex flex-wrap items-center gap-2">
        @foreach ($items as $item)
            <li class="flex items-center gap-2">
                @if (! $loop->first)
                    <span aria-hidden="true">/</span>
                @endif
                @if ($item['url'] !== null)
                    <a href="{{ $item['url'] }}" class="transition-colors hover:text-brand-600">{{ $item['label'] }}</a>
                @else
                    <span class="text-ink-2" aria-current="page">{{ $item['label'] }}</span>
                @endif
            </li>
        @endforeach
    </ol>
</nav>
