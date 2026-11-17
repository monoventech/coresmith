<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}"  @class(['dark' => ($appearance ?? request()->cookie('appearance') ?? 'dark') == 'dark'])>
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        @if (($sentry = \App\Support\Sentry\BrowserSentry::settings()) !== null)
            <meta name="coresmith-sentry" content="{{ json_encode($sentry) }}">
        @endif

        <link rel="icon" href="/favicon.ico" sizes="32x32">
        <link rel="icon" href="/favicon.svg" type="image/svg+xml">
        <link rel="apple-touch-icon" href="/apple-touch-icon.png">
        <link rel="manifest" href="/site.webmanifest">
        <meta name="theme-color" content="#13110d" media="(prefers-color-scheme: dark)">
        <meta name="theme-color" content="#fefcf9" media="(prefers-color-scheme: light)">
        <meta property="og:site_name" content="Coresmith">
        <meta property="og:image" content="{{ asset('og-image.png') }}">
        <meta property="og:image:width" content="1200">
        <meta property="og:image:height" content="630">
        <meta property="og:image:alt" content="Coresmith, self-hosted server management and deployment">
        <meta name="twitter:card" content="summary_large_image">

        @fonts

        @routes(nonce: \Illuminate\Support\Facades\Vite::cspNonce())
        @vite(['resources/css/app.css', 'resources/js/app.ts'])
        <x-inertia::head>
            <title>{{ config('app.name', 'Laravel') }}</title>
        </x-inertia::head>
    </head>
    <body class="font-sans antialiased">
        <x-inertia::app />
    </body>
</html>
