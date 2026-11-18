<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>{{ __('repairs.receipt.title') }}</title>
    <style>body{margin:0;display:grid;min-height:100vh;place-items:center;font-family:system-ui,sans-serif;color:#1f2937;text-align:center;padding:1rem}p{max-width:32rem;color:#4b5563}</style>
</head>
<body>
    <main><p>{{ $message }}</p></main>
</body>
</html>
