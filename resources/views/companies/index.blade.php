@extends('layouts.app')

@section('title', 'Dashboard — StockPrediction')

@section('content')
{{-- ======================================================================
     BLOOMBERG TERMINAL — 3-COLUMN DASHBOARD LAYOUT
     Left (2): Global Markets  |  Center (7): Main App  |  Right (3): News
     ====================================================================== --}}
<div class="grid grid-cols-12 gap-3 h-[calc(100vh-5rem)]">

    {{-- ╔══════════════════════════════════════════════════════════════════╗ --}}
    {{-- ║  LEFT COLUMN (2/12) — GLOBAL MARKETS & MACRO INDICATORS         ║ --}}
    {{-- ╚══════════════════════════════════════════════════════════════════╝ --}}
    <div class="col-span-2 flex flex-col gap-3 overflow-y-auto pr-1" style="scrollbar-width: thin;">

        {{-- Commodities & Indices Widget --}}
        <div class="bg-noir-800 border border-noir-400 p-3">
            <div class="flex items-center justify-between mb-3">
                <h3 class="text-[10px] font-bold text-mute-dim uppercase tracking-[0.15em]">Global Markets</h3>
                <span class="w-1.5 h-1.5 rounded-full bg-accent animate-pulse"></span>
            </div>

            @php
            $marketData = [
                ['symbol' => 'XAUUSD', 'name' => 'Gold', 'price' => '2,917.85', 'chg' => '+0.32', 'up' => true],
                ['symbol' => 'CL=F',  'name' => 'Crude Oil', 'price' => '76.44', 'chg' => '-1.28', 'up' => false],
                ['symbol' => 'SPX',   'name' => 'S&P 500', 'price' => '5,834.22', 'chg' => '+0.71', 'up' => true],
                ['symbol' => 'DXY',   'name' => 'US Dollar', 'price' => '104.87', 'chg' => '-0.15', 'up' => false],
                ['symbol' => 'BTCUSD', 'name' => 'Bitcoin', 'price' => '87,421', 'chg' => '+2.41', 'up' => true],
                ['symbol' => 'TNX',   'name' => 'US 10Y', 'price' => '4.482', 'chg' => '+0.03', 'up' => true],
                ['symbol' => 'VIX',   'name' => 'VIX', 'price' => '15.92', 'chg' => '-3.22', 'up' => false],
                ['symbol' => 'NDX',   'name' => 'NASDAQ', 'price' => '20,741', 'chg' => '+0.94', 'up' => true],
            ];
            @endphp

            <div class="space-y-1.5">
                @foreach ($marketData as $item)
                <div class="flex items-center justify-between py-1 border-b border-noir-500/50 last:border-0">
                    <div class="flex items-center gap-1.5 min-w-0">
                        <span class="text-[10px] font-mono font-bold text-white w-12 truncate">{{ $item['symbol'] }}</span>
                        <span class="text-[10px] text-mute-dim hidden xl:inline truncate">{{ $item['name'] }}</span>
                    </div>
                    <div class="flex items-center gap-2 shrink-0">
                        <span class="text-[11px] font-mono text-white tabular-nums">{{ $item['price'] }}</span>
                        <span class="text-[10px] font-mono font-medium tabular-nums {{ $item['up'] ? 'text-buy' : 'text-sell' }}">
                            {{ $item['chg'] }}%
                        </span>
                    </div>
                </div>
                @endforeach
            </div>
        </div>

        {{-- Macro Indicators --}}
        <div class="bg-noir-800 border border-noir-400 p-3">
            <h3 class="text-[10px] font-bold text-mute-dim uppercase tracking-[0.15em] mb-3">Macro Indicators</h3>

            @php
            $macroData = [
                ['label' => 'Fed Funds Rate', 'value' => '5.25–5.50%', 'trend' => 'steady'],
                ['label' => 'US CPI YoY', 'value' => '3.1%', 'trend' => 'down'],
                ['label' => 'US Core PCE', 'value' => '2.8%', 'trend' => 'down'],
                ['label' => 'US GDP Q2', 'value' => '+2.8%', 'trend' => 'up'],
                ['label' => 'Unemployment', 'value' => '4.1%', 'trend' => 'up'],
                ['label' => 'ISM Mfg PMI', 'value' => '48.7', 'trend' => 'down'],
                ['label' => 'JPM Vol Index', 'value' => '9.22', 'trend' => 'steady'],
            ];
            @endphp

            <div class="space-y-1.5">
                @foreach ($macroData as $item)
                <div class="flex items-center justify-between py-1 border-b border-noir-500/50 last:border-0">
                    <span class="text-[10px] text-mute-dim">{{ $item['label'] }}</span>
                    <div class="flex items-center gap-1.5">
                        <span class="text-[11px] font-mono text-white">{{ $item['value'] }}</span>
                        <span class="text-[9px] font-mono px-1 rounded {{ $item['trend'] === 'up' ? 'text-buy bg-buy-muted' : ($item['trend'] === 'down' ? 'text-sell bg-sell-muted' : 'text-mute-dim bg-noir-600') }}">
                            {{ $item['trend'] === 'up' ? '▲' : ($item['trend'] === 'down' ? '▼' : '◆') }}
                        </span>
                    </div>
                </div>
                @endforeach
            </div>
        </div>    </div>

    {{-- ╔══════════════════════════════════════════════════════════════════╗ --}}
    {{-- ║  CENTER COLUMN (7/12) — MAIN APPLICATION AREA                   ║ --}}
    {{-- ╚══════════════════════════════════════════════════════════════════╝ --}}
    <div class="col-span-7 flex flex-col gap-3 overflow-y-auto" style="scrollbar-width: thin;">

        {{-- Compact Header Bar --}}
        <div class="flex items-center justify-between">
            <div class="flex items-center gap-3">
                <h1 class="text-sm font-bold text-white uppercase tracking-[0.15em]">Company Watchlist</h1>
                <span class="text-[10px] font-mono text-mute-dim">{{ $companies->total() }} positions</span>
            </div>
            <div class="flex items-center gap-2">
                {{-- Search compact --}}
                <form method="GET" class="relative">
                    <svg class="absolute left-2 top-1/2 -translate-y-1/2 w-3 h-3 text-mute-dim" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/>
                    </svg>
                    <input type="text" name="search" value="{{ request('search') }}" placeholder="Search ticker..."
                           class="w-40 pl-7 pr-2 py-1.5 bg-noir-800 border border-noir-400 text-xs focus:outline-none focus:border-accent/50 text-white placeholder-mute-dim font-mono">
                </form>
                <select name="sector" onchange="this.form.submit()" form="sector-form"
                        class="px-2 py-1.5 bg-noir-800 border border-noir-400 text-xs focus:outline-none focus:border-accent/50 text-white font-mono">
                    <option value="">All Sectors</option>
                    @foreach ($sectors as $s)
                        <option value="{{ $s }}" {{ request('sector') === $s ? 'selected' : '' }}>{{ $s }}</option>
                    @endforeach
                </select>
                <form id="sector-form" method="GET" class="hidden"></form>
                <button onclick="document.getElementById('add-company-modal').showModal()"
                        class="px-3 py-1.5 bg-accent hover:bg-accent-hover text-white text-xs font-bold uppercase tracking-wider transition-colors">
                    + Add
                </button>
            </div>
        </div>

        {{-- Top Ranking Cards (compact row) --}}
        @if ($topBuys->isNotEmpty() || $topSells->isNotEmpty())
        <div class="grid grid-cols-2 gap-3">
            {{-- Strong Buy --}}
            <div class="bg-noir-800 border border-noir-400 p-3">
                <div class="flex items-center gap-2 mb-2">
                    <span class="w-1.5 h-1.5 rounded-full bg-buy"></span>
                    <h2 class="text-[10px] font-bold text-buy uppercase tracking-wider">Strong Buy</h2>
                    <span class="text-[9px] font-mono text-mute-dim ml-auto">{{ $topBuys->count() }} names</span>
                </div>
                @if ($topBuys->isEmpty())
                    <p class="text-[10px] text-mute-dim text-center py-4">No buy signals yet.</p>
                @else
                    <div class="space-y-1">
                        @foreach ($topBuys->take(5) as $rank => $company)
                            <a href="{{ route('companies.show', $company) }}"
                               class="flex items-center gap-2 py-1 px-2 hover:bg-noir-700 transition-colors group border border-transparent hover:border-noir-400">
                                <span class="text-[10px] font-mono font-bold text-mute-dim w-4">{{ $rank + 1 }}</span>
                                <span class="text-[10px] font-mono font-bold text-white w-14">{{ $company->ticker }}</span>
                                <span class="text-[10px] text-mute-dim truncate flex-1 hidden sm:inline">{{ $company->name }}</span>
                                <span class="text-[10px] font-mono font-bold text-buy tabular-nums">{{ round((float) $company->latest_confidence_score * 100) }}%</span>
                            </a>
                        @endforeach
                    </div>
                    <a href="{{ route('companies.rankings') }}" class="block mt-2 text-center text-[10px] font-mono text-buy hover:text-[#00e676]/80 border-t border-noir-500 pt-1.5">
                        VIEW ALL →
                    </a>
                @endif
            </div>

            {{-- Strong Sell --}}
            <div class="bg-noir-800 border border-noir-400 p-3">
                <div class="flex items-center gap-2 mb-2">
                    <span class="w-1.5 h-1.5 rounded-full bg-sell"></span>
                    <h2 class="text-[10px] font-bold text-sell uppercase tracking-wider">Strong Sell</h2>
                    <span class="text-[9px] font-mono text-mute-dim ml-auto">{{ $topSells->count() }} names</span>
                </div>
                @if ($topSells->isEmpty())
                    <p class="text-[10px] text-mute-dim text-center py-4">No sell signals yet.</p>
                @else
                    <div class="space-y-1">
                        @foreach ($topSells->take(5) as $rank => $company)
                            <a href="{{ route('companies.show', $company) }}"
                               class="flex items-center gap-2 py-1 px-2 hover:bg-noir-700 transition-colors group border border-transparent hover:border-noir-400">
                                <span class="text-[10px] font-mono font-bold text-mute-dim w-4">{{ $rank + 1 }}</span>
                                <span class="text-[10px] font-mono font-bold text-white w-14">{{ $company->ticker }}</span>
                                <span class="text-[10px] text-mute-dim truncate flex-1 hidden sm:inline">{{ $company->name }}</span>
                                <span class="text-[10px] font-mono font-bold text-sell tabular-nums">{{ round((float) $company->latest_confidence_score * 100) }}%</span>
                            </a>
                        @endforeach
                    </div>
                    <a href="{{ route('companies.rankings') }}" class="block mt-2 text-center text-[10px] font-mono text-sell hover:text-[#ff1744]/80 border-t border-noir-500 pt-1.5">
                        VIEW ALL →
                    </a>
                @endif
            </div>
        </div>
        @endif

        {{-- Company Cards Grid (compact 3-col) --}}
        @if ($companies->isEmpty())
            <div class="text-center py-16 text-mute-dim">
                <p class="text-xs font-mono">NO POSITIONS FOUND</p>
                <p class="text-[10px] mt-1">Add a company to begin analysis.</p>
            </div>
        @else
            <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-2">
                @foreach ($companies as $company)
                    <a href="{{ route('companies.show', $company) }}"
                       class="block p-3 bg-noir-800 border border-noir-400 hover:border-noir-300 hover:bg-noir-700 transition-all group">
                        <div class="flex items-start justify-between mb-2">
                            <div class="min-w-0">
                                <div class="flex items-center gap-1.5">
                                    <span class="px-1.5 py-0.5 bg-noir-600 text-[10px] font-mono font-bold text-white">
                                        {{ $company->ticker }}
                                    </span>
                                    <span class="text-[9px] text-mute-dim truncate">{{ $company->sector }}</span>
                                </div>
                                <h3 class="mt-1 text-xs font-medium text-white group-hover:text-white transition-colors line-clamp-1">
                                    {{ $company->name }}
                                </h3>
                            </div>
                            @if ($company->latest_price)
                                <div class="text-right shrink-0">
                                    <div class="text-sm font-mono font-semibold text-white tabular-nums">${{ number_format($company->latest_price, 2) }}</div>
                                </div>
                            @endif
                        </div>
                        <div class="flex items-center gap-3 text-[9px] font-mono text-mute-dim">
                            @if($company->market_cap)
                                <span>${{ number_format($company->market_cap / 1e9, 1) }}B</span>
                            @endif
                            <span>{{ $company->financialStatements_count ?? 0 }} filings</span>
                            @if ($company->latest_signal_type)
                                <span class="ml-auto font-bold px-1.5 py-0.5 {{ $company->latest_signal_type === 'buy' ? 'text-buy bg-buy-muted' : ($company->latest_signal_type === 'sell' ? 'text-sell bg-sell-muted' : 'text-amber-400 bg-amber-500/10') }}">
                                    {{ strtoupper($company->latest_signal_type) }}
                                </span>
                            @endif
                        </div>
                    </a>
                @endforeach
            </div>
            <div class="text-xs">
                {{ $companies->links() }}
            </div>
        @endif
    </div>

    {{-- ╔══════════════════════════════════════════════════════════════════╗ --}}
    {{-- ║  RIGHT COLUMN (3/12) — LIVE GEOPOLITICAL & MARKET NEWS          ║ --}}
    {{-- ╚══════════════════════════════════════════════════════════════════╝ --}}
    <div class="col-span-3 flex flex-col gap-3 overflow-hidden">
        <div class="bg-noir-800 border border-noir-400 flex flex-col h-full">
            {{-- Header --}}
            <div class="flex items-center justify-between px-3 py-2 border-b border-noir-400">
                <div class="flex items-center gap-2">
                    <span class="w-1.5 h-1.5 rounded-full bg-[#ff3b00] animate-pulse"></span>
                    <h3 class="text-[10px] font-bold text-white uppercase tracking-[0.15em]">Live News Feed</h3>
                </div>
                <span class="text-[9px] font-mono text-mute-dim">{{ date('H:i') }} WIB</span>
            </div>

            {{-- News Items — scrolling --}}
            <div class="flex-1 overflow-y-auto px-3 py-2 space-y-3" style="scrollbar-width: thin; max-height: calc(100vh - 8rem);">

                @php
                $newsItems = [
                    ['time' => '14:22', 'cat' => 'GEOPOLITICS', 'catColor' => 'text-amber-400 bg-amber-500/10', 'headline' => 'China unveils $284B stimulus package targeting tech sector and property market stabilization', 'source' => 'Reuters', 'impact' => 'HIGH'],
                    ['time' => '14:18', 'cat' => 'FED', 'catColor' => 'text-blue-400 bg-blue-500/10', 'headline' => 'Fed minutes signal readiness to cut rates in September if inflation continues cooling', 'source' => 'Bloomberg', 'impact' => 'HIGH'],
                    ['time' => '14:15', 'cat' => 'ENERGY', 'catColor' => 'text-orange-400 bg-orange-500/10', 'headline' => 'Brent crude dips below $77 as OPEC+ signals potential output increase in Q4', 'source' => 'FT', 'impact' => 'MED'],
                    ['time' => '14:09', 'cat' => 'TECH', 'catColor' => 'text-purple-400 bg-purple-500/10', 'headline' => 'NVIDIA reports record $35B revenue; AI chip demand outpaces supply through 2027', 'source' => 'CNBC', 'impact' => 'HIGH'],
                    ['time' => '14:04', 'cat' => 'GEOPOLITICS', 'catColor' => 'text-amber-400 bg-amber-500/10', 'headline' => 'Middle East ceasefire talks progress; shipping lanes could reopen within weeks', 'source' => 'AP', 'impact' => 'MED'],
                    ['time' => '13:57', 'cat' => 'TREASURY', 'catColor' => 'text-cyan-400 bg-cyan-500/10', 'headline' => 'US 10-year yield rises to 4.48% as bond market reprices rate cut expectations', 'source' => 'WSJ', 'impact' => 'MED'],
                    ['time' => '13:51', 'cat' => 'CRYPTO', 'catColor' => 'text-yellow-400 bg-yellow-500/10', 'headline' => 'Bitcoin breaks above $87K; ETF inflows surpass $1.2B in single session', 'source' => 'CoinDesk', 'impact' => 'HIGH'],
                    ['time' => '13:45', 'cat' => 'EARNINGS', 'catColor' => 'text-emerald-400 bg-emerald-500/10', 'headline' => 'Apple beats Q3 estimates with $94.9B revenue; services segment grows 14% YoY', 'source' => 'Reuters', 'impact' => 'HIGH'],
                    ['time' => '13:38', 'cat' => 'EUROPE', 'catColor' => 'text-indigo-400 bg-indigo-500/10', 'headline' => 'ECB holds rates steady at 3.75%; Lagarde warns of persistent services inflation', 'source' => 'Bloomberg', 'impact' => 'MED'],
                    ['time' => '13:32', 'cat' => 'FED', 'catColor' => 'text-blue-400 bg-blue-500/10', 'headline' => 'Fed\'s Bostic: Labor market cooling faster than expected, prefers single cut in 2024', 'source' => 'CNBC', 'impact' => 'MED'],
                    ['time' => '13:25', 'cat' => 'ASIA', 'catColor' => 'text-pink-400 bg-pink-500/10', 'headline' => 'BOJ maintains ultra-loose policy; yen weakens past ¥157 against dollar', 'source' => 'Nikkei', 'impact' => 'MED'],
                    ['time' => '13:19', 'cat' => 'COMMODITY', 'catColor' => 'text-orange-400 bg-orange-500/10', 'headline' => 'Gold futures hit $2,918 all-time high as central bank buying accelerates', 'source' => 'Kitco', 'impact' => 'HIGH'],
                    ['time' => '13:12', 'cat' => 'REGULATION', 'catColor' => 'text-red-400 bg-red-500/10', 'headline' => 'SEC proposes new AI conflict-of-interest rules for broker-dealers and advisors', 'source' => 'WSJ', 'impact' => 'MED'],
                    ['time' => '13:05', 'cat' => 'TECH', 'catColor' => 'text-purple-400 bg-purple-500/10', 'headline' => 'Microsoft commits $4.3B to European AI infrastructure expansion through 2026', 'source' => 'FT', 'impact' => 'MED'],
                    ['time' => '12:58', 'cat' => 'GEOPOLITICS', 'catColor' => 'text-amber-400 bg-amber-500/10', 'headline' => 'US-China trade talks resume in Geneva; semiconductor export controls on agenda', 'source' => 'Reuters', 'impact' => 'HIGH'],
                ];
                @endphp

                @foreach ($newsItems as $news)
                <div class="border-b border-noir-500/50 pb-2.5 last:border-0 last:pb-0">
                    <div class="flex items-center gap-1.5 mb-1">
                        <span class="text-[9px] font-mono text-mute-dim tabular-nums">{{ $news['time'] }}</span>
                        <span class="text-[8px] font-bold px-1.5 py-0.5 {{ $news['catColor'] }} uppercase tracking-wider">{{ $news['cat'] }}</span>
                        @if ($news['impact'] === 'HIGH')
                            <span class="text-[8px] font-bold text-sell bg-sell-muted px-1 py-0.5">HIGH</span>
                        @else
                            <span class="text-[8px] font-bold text-amber-400 bg-amber-500/10 px-1 py-0.5">MED</span>
                        @endif
                    </div>
                    <p class="text-[11px] text-white leading-relaxed">{{ $news['headline'] }}</p>
                    <span class="text-[9px] text-mute-dim mt-0.5 inline-block">— {{ $news['source'] }}</span>
                </div>
                @endforeach
            </div>
        </div>
    </div>
</div>

{{-- Add Company Modal — Terminal Style --}}
<dialog id="add-company-modal" class="bg-noir-800 border border-noir-400 p-5 w-full max-w-sm text-white backdrop:bg-black/70">
    <div class="flex items-center justify-between mb-4">
        <h2 class="text-xs font-bold text-mute uppercase tracking-[0.15em]">Add Position</h2>
        <button onclick="document.getElementById('add-company-modal').close()"
                class="text-mute-dim hover:text-white transition-colors text-lg leading-none">&times;</button>
    </div>
    <form method="POST" action="{{ route('companies.store') }}" class="flex flex-col gap-3">
        @csrf
        <div>
            <label class="block text-[10px] font-medium text-mute-dim uppercase tracking-wider mb-1">Ticker</label>
            <input type="text" name="ticker" required maxlength="10" placeholder="AAPL"
                   class="w-full px-2.5 py-1.5 bg-noir-700 border border-noir-400 text-xs font-mono focus:outline-none focus:border-accent/50 text-white placeholder-mute-dim">
        </div>
        <div>
            <label class="block text-[10px] font-medium text-mute-dim uppercase tracking-wider mb-1">Company Name</label>
            <input type="text" name="name" required maxlength="255" placeholder="Apple Inc."
                   class="w-full px-2.5 py-1.5 bg-noir-700 border border-noir-400 text-xs focus:outline-none focus:border-accent/50 text-white placeholder-mute-dim">
        </div>
        <div class="grid grid-cols-2 gap-2">
            <div>
                <label class="block text-[10px] font-medium text-mute-dim uppercase tracking-wider mb-1">Sector</label>
                <input type="text" name="sector" maxlength="100" placeholder="Technology"
                       class="w-full px-2.5 py-1.5 bg-noir-700 border border-noir-400 text-xs focus:outline-none focus:border-accent/50 text-white placeholder-mute-dim">
            </div>
            <div>
                <label class="block text-[10px] font-medium text-mute-dim uppercase tracking-wider mb-1">CIK</label>
                <input type="text" name="cik" maxlength="20" placeholder="0000320193"
                       class="w-full px-2.5 py-1.5 bg-noir-700 border border-noir-400 text-xs font-mono focus:outline-none focus:border-accent/50 text-white placeholder-mute-dim">
            </div>
        </div>
        <div class="flex gap-2 justify-end mt-1">
            <button type="button" onclick="document.getElementById('add-company-modal').close()"
                    class="px-3 py-1.5 text-[10px] text-mute-dim hover:text-white transition-colors uppercase tracking-wider">Cancel</button>
            <button type="submit"
                    class="px-3 py-1.5 bg-accent hover:bg-accent-hover text-white text-[10px] font-bold uppercase tracking-wider transition-colors">
                Add Position
            </button>
        </div>
    </form>
</dialog>
@endsection
