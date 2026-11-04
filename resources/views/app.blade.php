<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="h-full" data-device="{{ \App\Platform\Support\DeviceHint::fromRequest(request()) }}">
<head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover" />
    <meta name="theme-color" content="#0b1a33" />
    <meta name="csrf-token" content="{{ csrf_token() }}" />

    <link rel="preconnect" href="https://fonts.googleapis.com" />
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet" />

    <link rel="icon" href="/favicon.ico" sizes="any" />
    <link rel="icon" type="image/png" sizes="32x32" href="/favicon-32.png" />
    <link rel="apple-touch-icon" href="/apple-touch-icon.png" />
    <link rel="manifest" href="/site.webmanifest" />
    <meta property="og:site_name" content="OPES Hôtels Soft" />
    <meta property="og:type" content="website" />
    <meta property="og:image" content="{{ url('/brand/og-image.png') }}" />
    <meta property="og:image:width" content="1200" />
    <meta property="og:image:height" content="630" />
    <meta name="twitter:card" content="summary_large_image" />
    <meta name="twitter:image" content="{{ url('/brand/og-image.png') }}" />

    {{-- Device class before first paint (docs/DEVICE-CERTIFICATION.md). One rule, read by CSS variants and useDevice():
         <768 px phone; mouse/trackpad (hover + fine pointer) from 900 px desktop (compact density under 1100);
         touch from 1280 px desktop; everything else tablet. The server hint above is only the fallback. --}}
    <script nonce="{{ \Illuminate\Support\Facades\Vite::cspNonce() }}">
    (function(){var d=document.documentElement,m=window.matchMedia;function c(){var w=d.clientWidth||window.innerWidth,f=!!(m&&m('(hover: hover) and (pointer: fine)').matches),v=w<768?'phone':((f&&w>=900)||w>=1280?'desktop':'tablet'),n=v==='desktop'&&w<1100?'compact':'regular';if(d.getAttribute('data-device')!==v){d.setAttribute('data-device',v);window.dispatchEvent(new Event('opes:device'))}if(d.getAttribute('data-density')!==n)d.setAttribute('data-density',n)}c();window.addEventListener('resize',c);if(m){try{m('(hover: hover) and (pointer: fine)').addEventListener('change',c)}catch(e){}}window.__opesDevice=c})();
    </script>

    @inertiaHead

    @viteReactRefresh
    @vite(['resources/css/app.css', 'resources/js/app.tsx'])
</head>
<body class="h-full font-sans antialiased">
    @inertia
</body>
</html>
