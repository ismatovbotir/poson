{{-- The Mini App can't be used now (UnavailableController::REASONS). No personal data. --}}
@extends('layouts.tma', ['bare' => true, 'title' => __('tma.unavailable.'.$reason.'.title')])

@section('content')
    <section class="tma-card grid gap-4 text-center" data-unavailable="{{ $reason }}">
        <span class="mx-auto inline-flex rounded-lg bg-white px-3 py-2">
            <img src="{{ asset('assets/site/logo.svg') }}" alt="POSON" width="120" height="32" class="h-8 w-auto">
        </span>
        <h1 class="text-lg font-semibold">{{ __('tma.unavailable.'.$reason.'.title') }}</h1>
        <p class="text-tg-hint">{{ __('tma.unavailable.'.$reason.'.text') }}</p>

        @if ($botLink !== null)
            <a href="{{ $botLink }}" data-tg-link="{{ $botLink }}" @if ($reason === 'register') data-close @endif class="tma-btn">{{ __('tma.unavailable.'.$reason.'.action') }}</a>
        @endif

        @if (in_array($reason, ['expired', 'disabled', 'banned'], true))
            <button type="button" data-tma-close class="tma-btn-outline">{{ __('tma.unavailable.close') }}</button>
        @endif
    </section>
@endsection
