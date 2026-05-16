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
            <li class="py-4 ml-auto">
                <a href="https://github.com/karlerss/dokumendiregistrid" target="_blank" rel="noopener noreferrer"
                   class="flex items-center gap-2" title="GitHub">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor"
                         class="w-5 h-5" aria-hidden="true">
                        <path fill-rule="evenodd" clip-rule="evenodd"
                              d="M12 .5C5.73.5.5 5.73.5 12c0 5.08 3.29 9.39 7.86 10.91.58.11.79-.25.79-.56 0-.27-.01-1-.02-1.96-3.2.7-3.88-1.54-3.88-1.54-.52-1.33-1.28-1.68-1.28-1.68-1.05-.72.08-.71.08-.71 1.16.08 1.77 1.19 1.77 1.19 1.03 1.77 2.71 1.26 3.37.96.1-.75.4-1.26.73-1.55-2.55-.29-5.24-1.28-5.24-5.7 0-1.26.45-2.29 1.19-3.09-.12-.29-.52-1.47.11-3.07 0 0 .97-.31 3.18 1.18a11 11 0 015.79 0c2.2-1.49 3.17-1.18 3.17-1.18.63 1.6.23 2.78.12 3.07.74.8 1.19 1.83 1.19 3.09 0 4.43-2.7 5.4-5.27 5.69.41.36.78 1.06.78 2.14 0 1.54-.01 2.79-.01 3.17 0 .31.21.68.8.56C20.21 21.39 23.5 17.08 23.5 12 23.5 5.73 18.27.5 12 .5z"/>
                    </svg>
                </a>
            </li>
        </ul>
    </div>
</nav>
@yield('content')


<script src="//unpkg.com/alpinejs" defer></script>
@yield('script')
</body>
</html>
