@extends('layouts.app')

@section('title', 'Companies — StockPrediction')

@section('content')
<div class="flex flex-col gap-8">
    {{-- Header --}}
    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
        <div>
            <h1 class="text-2xl font-bold tracking-tight text-white">Companies</h1>
            <p class="text-mute text-sm mt-1">Financial statement analysis and stock predictions</p>
        </div>
        <button onclick="document.getElementById('add-company-modal').showModal()"
                class="inline-flex items-center gap-2 px-4 py-2 bg-accent hover:bg-accent-hover text-white text-sm font-medium rounded-lg transition-colors">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/>
            </svg>
            Add Company
        </button>
    </div>

    {{-- ================================================================
         Live Rankings — Top Buys & Top Sells
         ================================================================ --}}
    @if ($topBuys->isNotEmpty() || $topSells->isNotEmpty())
    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
        {{-- Top Buys --}}
        <div class="p-5 bg-noir-800 border border-noir-500 rounded-xl">
            <div class="flex items-center gap-2 mb-4">
                <span class="w-2 h-2 rounded-full bg-buy"></span>
                <h2 class="text-sm font-semibold text-buy uppercase tracking-wider">Strong Buy Candidates</h2>
                <span class="text-xs text-mute-dim ml-auto">Live ranking</span>
            </div>
            @if ($topBuys->isEmpty())
                <p class="text-sm text-mute-dim text-center py-6">No strong buy signals yet. Run predictions to populate rankings.</p>
            @else
                <div class="space-y-2">
                    @foreach ($topBuys as $rank => $company)
                        <a href="{{ route('companies.show', $company) }}"
                           class="flex items-center gap-3 p-3 bg-noir-700 hover:bg-noir-600 rounded-lg border border-noir-500 transition-colors group">
                            <span class="w-6 h-6 rounded-full bg-buy-soft text-buy text-xs font-bold flex items-center justify-center shrink-0">
                                {{ $rank + 1 }}
                            </span>
                            <div class="flex-1 min-w-0">
                                <div class="flex items-center gap-2">
                                    <span class="px-1.5 py-0.5 bg-noir-600 text-xs font-mono font-bold text-white rounded">
                                        {{ $company->ticker }}
                                    </span>
                                    <span class="text-xs text-mute truncate">{{ $company->name }}</span>
                                </div>
                            </div>
                            <div class="text-right shrink-0">
                                <div class="flex items-center gap-1.5">
                                    <span class="text-xs font-bold text-buy">
                                        {{ round((float) $company->latest_confidence_score * 100) }}%
                                    </span>
                                    <span class="text-[10px] text-mute-dim">conf</span>
                                </div>
                                @if ($company->latest_predicted_return !== null)
                                    <div class="text-[11px] font-mono text-buy">
                                        {{ $company->latest_predicted_return > 0 ? '+' : '' }}{{ round((float) $company->latest_predicted_return * 100, 1) }}%
                                    </div>
                                @endif
                            </div>
                            <svg class="w-4 h-4 text-mute-dim group-hover:text-mute shrink-0 transition-colors" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7" />
                            </svg>
                        </a>
                    @endforeach
                </div>
                <a href="{{ route('companies.rankings') }}"
                   class="block mt-3 text-center text-xs text-buy hover:text-[#00e676]/80 transition-colors py-1.5 bg-buy-muted rounded-lg border border-buy-soft">
                    View Full Rankings →
                </a>
            @endif
        </div>

        {{-- Top Sells --}}
        <div class="p-5 bg-noir-800 border border-noir-500 rounded-xl">
            <div class="flex items-center gap-2 mb-4">
                <span class="w-2 h-2 rounded-full bg-sell"></span>
                <h2 class="text-sm font-semibold text-sell uppercase tracking-wider">Strong Sell Signals</h2>
                <span class="text-xs text-mute-dim ml-auto">Live ranking</span>
            </div>
            @if ($topSells->isEmpty())
                <p class="text-sm text-mute-dim text-center py-6">No strong sell signals yet. Run predictions to populate rankings.</p>
            @else
                <div class="space-y-2">
                    @foreach ($topSells as $rank => $company)
                        <a href="{{ route('companies.show', $company) }}"
                           class="flex items-center gap-3 p-3 bg-noir-700 hover:bg-noir-600 rounded-lg border border-noir-500 transition-colors group">
                            <span class="w-6 h-6 rounded-full bg-sell-soft text-sell text-xs font-bold flex items-center justify-center shrink-0">
                                {{ $rank + 1 }}
                            </span>
                            <div class="flex-1 min-w-0">
                                <div class="flex items-center gap-2">
                                    <span class="px-1.5 py-0.5 bg-noir-600 text-xs font-mono font-bold text-white rounded">
                                        {{ $company->ticker }}
                                    </span>
                                    <span class="text-xs text-mute truncate">{{ $company->name }}</span>
                                </div>
                            </div>
                            <div class="text-right shrink-0">
                                <div class="flex items-center gap-1.5">
                                    <span class="text-xs font-bold text-sell">
                                        {{ round((float) $company->latest_confidence_score * 100) }}%
                                    </span>
                                    <span class="text-[10px] text-mute-dim">conf</span>
                                </div>
                                @if ($company->latest_predicted_return !== null)
                                    <div class="text-[11px] font-mono text-sell">
                                        {{ $company->latest_predicted_return > 0 ? '+' : '' }}{{ round((float) $company->latest_predicted_return * 100, 1) }}%
                                    </div>
                                @endif
                            </div>
                            <svg class="w-4 h-4 text-mute-dim group-hover:text-mute shrink-0 transition-colors" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7" />
                            </svg>
                        </a>
                    @endforeach
                </div>
                <a href="{{ route('companies.rankings') }}"
                   class="block mt-3 text-center text-xs text-sell hover:text-[#ff1744]/80 transition-colors py-1.5 bg-sell-muted rounded-lg border border-sell-soft">
                    View Full Rankings →
                </a>
            @endif
        </div>
    </div>
    @endif

    {{-- Filters --}}
    <div class="flex flex-col sm:flex-row gap-3">
        <form method="GET" class="flex-1">
            <div class="relative">
                <svg class="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-mute-dim" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/>
                </svg>
                <input type="text" name="search" value="{{ request('search') }}" placeholder="Search by ticker or name..."
                       class="w-full pl-10 pr-4 py-2 bg-noir-800 border border-noir-400 rounded-lg text-sm focus:outline-none focus:border-accent/50 text-white placeholder-mute-dim">
            </div>
        </form>
        <select name="sector" onchange="this.form.submit()" form="sector-form"
                class="px-3 py-2 bg-noir-800 border border-noir-400 rounded-lg text-sm focus:outline-none focus:border-accent/50 text-white">
            <option value="">All Sectors</option>
            @foreach ($sectors as $s)
                <option value="{{ $s }}" {{ request('sector') === $s ? 'selected' : '' }}>{{ $s }}</option>
            @endforeach
        </select>
        <form id="sector-form" method="GET" class="hidden"></form>
    </div>

    {{-- Companies Grid --}}
    @if ($companies->isEmpty())
        <div class="text-center py-20 text-mute-dim">
            <p class="text-lg">No companies found.</p>
            <p class="text-sm mt-1">Add a company to get started with predictions.</p>
        </div>
    @else
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
            @foreach ($companies as $company)
                <a href="{{ route('companies.show', $company) }}"
                   class="block p-5 bg-noir-800 border border-noir-500 rounded-xl hover:border-noir-400 hover:bg-noir-700 transition-all group">
                    <div class="flex items-start justify-between mb-3">
                        <div>
                            <div class="flex items-center gap-2">
                                <span class="px-2 py-0.5 bg-noir-600 text-xs font-mono font-semibold text-white rounded">
                                    {{ $company->ticker }}
                                </span>
                                <span class="text-xs text-mute-dim">{{ $company->sector }}</span>
                            </div>
                            <h3 class="mt-1.5 font-medium text-white group-hover:text-white transition-colors line-clamp-1">
                                {{ $company->name }}
                            </h3>
                        </div>
                        @if ($company->latest_price)
                            <div class="text-right">
                                <div class="text-lg font-semibold text-white">${{ number_format($company->latest_price, 2) }}</div>
                            </div>
                        @endif
                    </div>

                    <div class="flex items-center gap-4 text-xs text-mute-dim">
                        @if($company->market_cap)
                            <span>Mkt Cap: ${{ number_format($company->market_cap / 1e9, 1) }}B</span>
                        @endif
                        <span>{{ $company->financialStatements_count ?? 0 }} statements</span>
                    </div>
                </a>
            @endforeach
        </div>

        <div class="mt-6">
            {{ $companies->links() }}
        </div>
    @endif
</div>

{{-- Add Company Modal --}}
<dialog id="add-company-modal" class="bg-noir-800 border border-noir-400 rounded-xl p-6 w-full max-w-md text-white backdrop:bg-black/50">
    <div class="flex items-center justify-between mb-4">
        <h2 class="text-lg font-semibold">Add Company</h2>
        <button onclick="document.getElementById('add-company-modal').close()"
                class="text-mute-dim hover:text-white transition-colors text-xl">&times;</button>
    </div>
    <form method="POST" action="{{ route('companies.store') }}" class="flex flex-col gap-4">
        @csrf
        <div>
            <label class="block text-sm font-medium text-mute mb-1">Ticker Symbol</label>
            <input type="text" name="ticker" required maxlength="10" placeholder="AAPL"
                   class="w-full px-3 py-2 bg-noir-700 border border-noir-400 rounded-lg text-sm focus:outline-none focus:border-accent/50 text-white placeholder-mute-dim">
        </div>
        <div>
            <label class="block text-sm font-medium text-mute mb-1">Company Name</label>
            <input type="text" name="name" required maxlength="255" placeholder="Apple Inc."
                   class="w-full px-3 py-2 bg-noir-700 border border-noir-400 rounded-lg text-sm focus:outline-none focus:border-accent/50 text-white placeholder-mute-dim">
        </div>
        <div class="grid grid-cols-2 gap-3">
            <div>
                <label class="block text-sm font-medium text-mute mb-1">Sector</label>
                <input type="text" name="sector" maxlength="100" placeholder="Technology"
                       class="w-full px-3 py-2 bg-noir-700 border border-noir-400 rounded-lg text-sm focus:outline-none focus:border-accent/50 text-white placeholder-mute-dim">
            </div>
            <div>
                <label class="block text-sm font-medium text-mute mb-1">SEC CIK</label>
                <input type="text" name="cik" maxlength="20" placeholder="0000320193"
                       class="w-full px-3 py-2 bg-noir-700 border border-noir-400 rounded-lg text-sm focus:outline-none focus:border-accent/50 text-white placeholder-mute-dim">
            </div>
        </div>
        <div class="flex gap-3 justify-end mt-2">
            <button type="button" onclick="document.getElementById('add-company-modal').close()"
                    class="px-4 py-2 text-sm text-mute hover:text-white transition-colors">Cancel</button>
            <button type="submit"
                    class="px-4 py-2 bg-accent hover:bg-accent-hover text-white text-sm font-medium rounded-lg transition-colors">
                Add Company
            </button>
        </div>
    </form>
</dialog>
@endsection
