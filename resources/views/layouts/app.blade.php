<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="theme-color" content="#12344d" media="(prefers-color-scheme: light)">
    <meta name="theme-color" content="#091d2c" media="(prefers-color-scheme: dark)">
    <meta name="color-scheme" content="light">
    <meta name="application-name" content="BULA-IA">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="default">
    <meta name="apple-mobile-web-app-title" content="BULA-IA">
    <meta name="format-detection" content="telephone=no">
    <link rel="manifest" href="/manifest.webmanifest">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <link rel="icon" href="/brand/bulai-icone-app.svg" type="image/svg+xml">
    <link rel="icon" href="/brand/bulai-icone-192.png" type="image/png" sizes="192x192">
    <link rel="apple-touch-icon" href="/brand/bulai-icone-180.png" sizes="180x180">

    <title>{{ $title ?? config('app.name') }}</title>

    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @livewireStyles
</head>
<body class="min-h-screen bg-slate-50 text-slate-950 antialiased">
    {{ $slot }}

    @livewireScripts
</body>
</html>
