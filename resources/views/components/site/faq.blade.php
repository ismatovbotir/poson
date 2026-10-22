@props(['items', 'title', 'id' => 'faq-title', 'subtitle' => null])

{{--
    FAQ block (plan 04 T12): native <details>/<summary> (keyboard and screen-reader
    accessible without JS). Answers are plain text: escaped, line breaks kept.
    Pages print the matching FAQPage JSON-LD only when this block is shown.
--}}
<section {{ $attributes->class(['scroll-mt-28']) }} aria-labelledby="{{ $id }}" data-faq>
    <x-site.section-head :id="$id" :title="$title" :subtitle="$subtitle" />

    <div class="grid max-w-[900px] gap-3">
        @foreach ($items as $item)
            <details class="group rounded-box border border-line bg-white open:border-brand open:shadow-card" wire:key="faq-{{ $id }}-{{ $loop->index }}" data-faq-item>
                <summary class="flex cursor-pointer list-none items-center justify-between gap-4 rounded-box px-5 py-4 text-base font-semibold text-ink hover:text-brand-600 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-600 [&::-webkit-details-marker]:hidden">
                    <span>{{ $item['question'] }}</span>
                    <span class="grid size-8 shrink-0 place-items-center rounded-full bg-bg-tint text-xl leading-none text-brand-600 transition-transform group-open:rotate-45" aria-hidden="true">+</span>
                </summary>
                <div class="px-5 pb-5 text-ink-2" data-faq-answer>{!! nl2br(e($item['answer']), false) !!}</div>
            </details>
        @endforeach
    </div>
</section>
