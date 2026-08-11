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
                    colors: {
                        noir: {
                            950: '#000000',
                            900: '#050505',
                            800: '#0d0d0d',
                            700: '#121212',
                            600: '#1a1a1a',
                            500: '#1f1f1f',
                            400: '#262626',
                            300: '#333333',
                        },
                        accent: {
                            DEFAULT: '#ff3b00',
                            hover: '#e03400',
                            muted: 'rgba(255,59,0,0.10)',
                        },
                        buy: {
                            DEFAULT: '#00e676',
                            muted: 'rgba(0,230,118,0.10)',
                            soft: 'rgba(0,230,118,0.20)',
                        },
                        sell: {
                            DEFAULT: '#ff1744',
                            muted: 'rgba(255,23,68,0.10)',
                            soft: 'rgba(255,23,68,0.20)',
                        },
                        mute: {
                            DEFAULT: '#a1a1aa',
                            dim: '#8e8e93',
                        },
                    },
                },
            },
        }
    </script>
    @stack('head')
</head>
<body class="h-full bg-noir-950 text-white font-sans antialiased">

    <!-- Navigation -->
    <nav class="border-b border-noir-500 bg-noir-950/80 backdrop-blur-sm sticky top-0 z-50">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="flex items-center justify-between h-16">
                <div class="flex items-center gap-8">
                    <a href="{{ route('companies.index') }}" class="flex items-center gap-2 font-semibold text-lg text-white">
                        <svg class="w-7 h-7 text-accent" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                d="M13 7h8m0 0v8m0-8l-8 8-4-4-6 6" />
                        </svg>
                        StockPrediction
                    </a>
                    <div class="hidden sm:flex gap-6">
                        <a href="{{ route('companies.index') }}"
                           class="text-sm {{ request()->routeIs('companies.index') ? 'text-accent' : 'text-mute hover:text-white' }} transition-colors">
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
                <div class="bg-buy-muted border border-buy-soft text-[#00e676] px-4 py-3 rounded-lg text-sm">
                    {{ session('success') }}
                </div>
            @endif
            @if (session('error'))
                <div class="bg-sell-muted border border-sell-soft text-[#ff1744] px-4 py-3 rounded-lg text-sm">
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
