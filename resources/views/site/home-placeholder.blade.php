{{-- TEMPORARY: replaced by the Livewire home page in T5. --}}
<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ config('app.name') }}</title>
</head>
<body>
    <p data-locale="{{ app()->getLocale() }}">{{ config('app.name') }} · {{ app()->getLocale() }}</p>
    <ul>
        @foreach (\App\Support\LocaleUrl::alternates() as $code => $url)
            <li><a href="{{ $url }}" hreflang="{{ $code }}">{{ strtoupper($code) }}</a></li>
        @endforeach
    </ul>
</body>
</html>
