<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'StockPrediction') — Stock Market Prediction</title>
    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=inter:400,500,600,700" rel="stylesheet" />
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    fontFamily: {
                        sans: ['Inter', 'ui-sans-serif', 'system-ui', 'sans-serif'],
                    },
                },
            },
        }
    </script>
    @stack('head')
</head>
<body class="h-full bg-zinc-950 text-zinc-100 font-sans antialiased">

    <!-- Navigation -->
    <nav class="border-b border-zinc-800 bg-zinc-900/50 backdrop-blur-sm sticky top-0 z-50">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="flex items-center justify-between h-16">
                <div class="flex items-center gap-8">
                    <a href="{{ route('companies.index') }}" class="flex items-center gap-2 font-semibold text-lg">
                        <svg class="w-7 h-7 text-emerald-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                d="M13 7h8m0 0v8m0-8l-8 8-4-4-6 6" />
                        </svg>
                        StockPrediction
                    </a>
                    <div class="hidden sm:flex gap-6">
                        <a href="{{ route('companies.index') }}"
                           class="text-sm {{ request()->routeIs('companies.index') ? 'text-emerald-400' : 'text-zinc-400 hover:text-zinc-200' }} transition-colors">
                            Companies
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </nav>

    <!-- Flash Messages -->
    @if (session('success') || session('error'))
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 mt-4">
            @if (session('success'))
                <div class="bg-emerald-900/30 border border-emerald-700/50 text-emerald-300 px-4 py-3 rounded-lg text-sm">
                    {{ session('success') }}
                </div>
            @endif
            @if (session('error'))
                <div class="bg-red-900/30 border border-red-700/50 text-red-300 px-4 py-3 rounded-lg text-sm">
                    {{ session('error') }}
                </div>
            @endif
        </div>
    @endif

    <!-- Main Content -->
    <main class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
        @yield('content')
    </main>

    @stack('scripts')
    <script src="https://cdn.jsdelivr.net/npm/alpinejs@3.14.1/dist/cdn.min.js"></script>
</body>
</html>
