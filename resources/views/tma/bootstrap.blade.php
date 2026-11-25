{{-- GET /tma: tma.js signs in with Telegram's initData and moves on (BootstrapController). --}}
@extends('layouts.tma', ['boot' => true, 'title' => null])

@section('content')
    <div class="grid min-h-[60vh] place-items-center text-center" data-boot>
        <div class="grid justify-items-center gap-4">
            <span class="inline-flex rounded-lg bg-white px-3 py-2">
                <img src="{{ asset('assets/site/logo.svg') }}" alt="POSON" width="149" height="40" class="h-10 w-auto">
            </span>
            <p class="text-tg-hint">{{ __('tma.boot.loading') }}</p>
            <p class="text-sm text-tg-danger" data-boot-error hidden>{{ __('tma.boot.failed') }}</p>
        </div>
    </div>
@endsection
