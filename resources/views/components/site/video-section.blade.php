@props(['videos', 'title' => null, 'id' => 'videos-title'])

{{-- Videos block (plan 19): each video as a click-to-load facade with its title. Nothing when there are no videos. --}}
@if ($videos !== [])
    <section {{ $attributes->class(['scroll-mt-28']) }} aria-labelledby="{{ $id }}" data-videos>
        <x-site.section-head :id="$id" :title="$title ?? __('site.videos.title')" />

        <div class="grid gap-6 md:grid-cols-2">
            @foreach ($videos as $video)
                <figure class="min-w-0" wire:key="video-{{ $id }}-{{ $video['id'] }}" data-video>
                    <x-site.video-facade :video="$video" />
                    <figcaption class="mt-3 font-semibold text-ink">{{ $video['title'] }}</figcaption>
                </figure>
            @endforeach
        </div>
    </section>
@endif
