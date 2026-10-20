@props(['title' => null, 'description' => null, 'padding' => true])

{{-- Card: border border-slate-200 bg-white rounded-2xl. Optional header with title and an "actions" slot. --}}
<section {{ $attributes->class(['rounded-2xl border border-slate-200 bg-white']) }}>
    @if ($title || isset($actions))
        <div class="flex flex-wrap items-center justify-between gap-3 border-b border-slate-100 px-4 py-3.5 sm:px-6">
            <div class="min-w-0">
                @if ($title)<h2 class="text-base font-semibold">{{ $title }}</h2>@endif
                @if ($description)<p class="mt-0.5 text-sm text-slate-500">{{ $description }}</p>@endif
            </div>
            @isset($actions)
                <div class="flex items-center gap-2">{{ $actions }}</div>
            @endisset
        </div>
    @endif

    <div @class(['p-4 sm:p-6' => $padding])>
        {{ $slot }}
    </div>
</section>
