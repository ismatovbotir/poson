{{-- Mini App error page (App\Support\Telegram\MiniApp\TmaErrorPage): CSP-safe, no personal data. --}}
@php
    $key = in_array($status, [403, 404, 419, 429], true) ? (string) $status : 'default';
@endphp
@extends('layouts.tma', ['bare' => true, 'title' => __('tma.error.'.$key.'.title')])

@section('content')
    <section class="tma-card grid gap-3 text-center" data-error="{{ $status }}">
        <h1 class="text-lg font-semibold">{{ __('tma.error.'.$key.'.title') }}</h1>
        <p class="text-tg-hint">{{ __('tma.error.'.$key.'.text') }}</p>
        <a href="{{ route('tma.bootstrap') }}" class="tma-btn">{{ __('tma.error.home') }}</a>
    </section>
@endsection
