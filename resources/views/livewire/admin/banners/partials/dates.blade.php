{{-- Date window in Tashkent time; "no limit" for an empty side. --}}
@php($format = fn ($date) => $date ? \App\Support\DateFormat::iso($date, 'D MMM YYYY, HH:mm') : __('admin.banners.no_limit'))
<span class="block whitespace-nowrap">{{ __('admin.banners.from') }} {{ $format($banner->localStartsAt()) }}</span>
<span class="block whitespace-nowrap">{{ __('admin.banners.until') }} {{ $format($banner->localEndsAt()) }}</span>
