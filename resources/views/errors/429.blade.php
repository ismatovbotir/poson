@php
    $supported = (array) config('app.supported_locales');
    $cookie = request()->cookie('locale');
    $locale = is_string($cookie) && in_array($cookie, $supported, true) ? $cookie : app()->getLocale();
    $seconds = max(1, (int) ($exception->getHeaders()['Retry-After'] ?? 60));
@endphp
<!DOCTYPE html>
<html lang="{{ $locale }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>{{ __('site.throttle.title', [], $locale) }}</title>
    <style>body{margin:0;display:grid;min-height:100vh;place-items:center;font-family:system-ui,sans-serif;color:#1f2937;text-align:center;padding:1rem}h1{font-size:1.5rem;margin:0 0 .5rem}p{margin:0;color:#4b5563}</style>
</head>
<body>
    <main>
        <h1>{{ __('site.throttle.title', [], $locale) }}</h1>
        <p>{{ __('site.throttle.text', ['seconds' => $seconds], $locale) }}</p>
    </main>
</body>
</html>
