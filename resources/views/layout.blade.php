<!doctype html>
<html lang="et">
<head>
    <meta charset="UTF-8">
    <meta name="viewport"
          content="width=device-width, user-scalable=no, initial-scale=1.0, maximum-scale=1.0, minimum-scale=1.0">
    <meta http-equiv="X-UA-Compatible" content="ie=edge">
    <link href="{{ asset('vendor/bladewind/css/animate.min.css') }}" rel="stylesheet"/>
    <link href="{{ asset('vendor/bladewind/css/bladewind-ui.min.css') }}" rel="stylesheet"/>
    <script src="{{ asset('vendor/bladewind/js/helpers.js') }}"></script>
    @vite('resources/css/app.css')
    <title>@yield('title')</title>
    <meta name="description" content="@yield('description')">
    @yield('head')
</head>
<body>
<nav class="border-b-gray-200 border-b mb-4">
    <div class="container mx-auto px-4">
        <ul class="flex gap-6 grid-cols ">
            <li class="py-4"><a class="{{request()->is('/') ? 'font-bold': ''}}" href="/">Dokumendiregistrite otsing</a></li>
            <li class="py-4"><a class="{{request()->is('arhiiv*') ? 'font-bold': ''}}" href="/arhiiv">Arhiiv</a></li>
            <li class="py-4"><a class="{{request()->is('projektist') ? 'font-bold': ''}}"
                                href="/projektist">Projektist</a></li>
            <li class="py-4"><a class="{{request()->is('api/*') || request()->is('api') ? 'font-bold': ''}}"
                                href="/api/docs">API</a></li>
        </ul>
    </div>
</nav>
@yield('content')


<script src="//unpkg.com/alpinejs" defer></script>
@yield('script')
</body>
</html>
