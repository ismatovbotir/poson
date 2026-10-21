@props(['links' => [], 'variant' => 'dark'])

{{--
    Social network icons from Settings → Company → Social. Links are already
    validated (https on the network's own hosts) by CompanySettings::social().
--}}
@if ($links !== [])
    <ul {{ $attributes->class(['flex flex-wrap gap-2']) }} aria-label="{{ __('site.contacts.social') }}" data-social-links>
        @foreach ($links as $link)
            @php($network = \App\Enums\SocialNetwork::from($link['network']))
            <li>
                <a
                    href="{{ $link['url'] }}"
                    target="_blank"
                    rel="noopener noreferrer"
                    @class([
                        'grid size-10 place-items-center rounded-btn transition-colors',
                        'bg-ink-line text-white hover:bg-brand-600 focus-visible:outline-white' => $variant === 'dark',
                        'bg-bg-soft text-ink hover:bg-brand-50 hover:text-brand-600' => $variant !== 'dark',
                    ])
                >
                    <x-site.icon :name="$network->value" />
                    <span class="sr-only">{{ $network->label() }}</span>
                </a>
            </li>
        @endforeach
    </ul>
@endif
