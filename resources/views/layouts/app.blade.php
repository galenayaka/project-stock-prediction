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
    <nav class="border-b border-noir-400 bg-noir-950/90 backdrop-blur-sm sticky top-0 z-50">
        <div class="w-full px-4">
            <div class="flex items-center justify-between h-12">
                <div class="flex items-center gap-6">
                    <a href="{{ route('companies.index') }}" class="flex items-center gap-2 font-semibold text-sm text-white tracking-tight">
                        <svg class="w-5 h-5 text-accent" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                d="M13 7h8m0 0v8m0-8l-8 8-4-4-6 6" />
                        </svg>
                        <span class="hidden sm:inline">STOCKPREDICTION</span>
                    </a>
                    <div class="hidden sm:flex gap-4 text-xs">
                        <a href="{{ route('companies.index') }}"
                           class="uppercase tracking-wider {{ request()->routeIs('companies.index') ? 'text-accent' : 'text-mute-dim hover:text-white' }} transition-colors">
                            Dashboard
                        </a>
                        <a href="{{ route('companies.rankings') }}"
                           class="uppercase tracking-wider {{ request()->routeIs('companies.rankings') ? 'text-accent' : 'text-mute-dim hover:text-white' }} transition-colors">
                            Rankings
                        </a>
                    </div>
                </div>
                <div class="flex items-center gap-4 text-[10px] font-mono text-mute-dim">
                    <span class="hidden sm:inline">{{ date('D, d M Y H:i:s') }} WIB</span>
                    <span class="flex items-center gap-1"><span class="w-1.5 h-1.5 rounded-full bg-buy animate-pulse"></span> LIVE</span>
                </div>
            </div>
        </div>
    </nav>

    <!-- Flash Messages -->
    @if (session('success') || session('error'))
        <div class="w-full px-4 mt-2">
            @if (session('success'))
                <div class="bg-buy-muted border border-buy-soft text-[#00e676] px-3 py-2 text-xs font-mono">
                    {{ session('success') }}
                </div>
            @endif
            @if (session('error'))
                <div class="bg-sell-muted border border-sell-soft text-[#ff1744] px-3 py-2 text-xs font-mono">
                    {{ session('error') }}
                </div>
            @endif
        </div>
    @endif

    <!-- Main Content -->
    <main class="w-full px-4 py-3">
        @yield('content')
    </main>

    <!-- Footer -->
    <footer class="w-full px-4 py-2 border-t border-noir-400 flex items-center justify-between text-[9px] font-mono text-mute-dim">
        <span>STOCKPREDICTION v1.1.0</span>
        <span>&copy; 2026 Galen Nayaka Nayottama. All rights reserved.</span>
    </footer>

    @stack('scripts')
    <script src="https://cdn.jsdelivr.net/npm/alpinejs@3.14.1/dist/cdn.min.js"></script>
</body>
</html>
