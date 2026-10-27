{{-- Banner state badge (BannerState: active / scheduled / expired / inactive). --}}
@php($state = $banner->state())
<x-admin.badge :color="$state->color()" pill dot data-banner-state-badge="{{ $state->value }}">{{ $state->label() }}</x-admin.badge>
