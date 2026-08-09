@extends('layouts.app')

@section('title', 'Companies — StockPrediction')

@section('content')
<div class="flex flex-col gap-8">
    {{-- Header --}}
    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
        <div>
            <h1 class="text-2xl font-bold tracking-tight">Companies</h1>
            <p class="text-zinc-400 text-sm mt-1">Financial statement analysis and stock predictions</p>
        </div>
        <button onclick="document.getElementById('add-company-modal').showModal()"
                class="inline-flex items-center gap-2 px-4 py-2 bg-emerald-600 hover:bg-emerald-500 text-white text-sm font-medium rounded-lg transition-colors">
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
        <div class="p-5 bg-emerald-950/30 border border-emerald-800/40 rounded-xl">
            <div class="flex items-center gap-2 mb-4">
                <span class="text-lg">🟢</span>
                <h2 class="text-sm font-semibold text-emerald-400 uppercase tracking-wider">Strong Buy Candidates</h2>
                <span class="text-xs text-zinc-500 ml-auto">Live ranking</span>
            </div>
            @if ($topBuys->isEmpty())
                <p class="text-sm text-zinc-500 text-center py-6">No strong buy signals yet. Run predictions to populate rankings.</p>
            @else
                <div class="space-y-2">
                    @foreach ($topBuys as $rank => $company)
                        <a href="{{ route('companies.show', $company) }}"
                           class="flex items-center gap-3 p-3 bg-zinc-900/60 hover:bg-zinc-800/60 rounded-lg border border-zinc-800/50 transition-colors group">
                            <span class="w-6 h-6 rounded-full bg-emerald-500/20 text-emerald-400 text-xs font-bold flex items-center justify-center shrink-0">
                                {{ $rank + 1 }}
                            </span>
                            <div class="flex-1 min-w-0">
                                <div class="flex items-center gap-2">
                                    <span class="px-1.5 py-0.5 bg-zinc-800 text-xs font-mono font-bold text-zinc-300 rounded">
                                        {{ $company->ticker }}
                                    </span>
                                    <span class="text-xs text-zinc-400 truncate">{{ $company->name }}</span>
                                </div>
                            </div>
                            <div class="text-right shrink-0">
                                <div class="flex items-center gap-1.5">
                                    <span class="text-xs font-bold text-emerald-400">
                                        {{ round((float) $company->latest_confidence_score * 100) }}%
                                    </span>
                                    <span class="text-[10px] text-zinc-600">conf</span>
                                </div>
                                @if ($company->latest_predicted_return !== null)
                                    <div class="text-[11px] font-mono text-emerald-300">
                                        {{ $company->latest_predicted_return > 0 ? '+' : '' }}{{ round((float) $company->latest_predicted_return * 100, 1) }}%
                                    </div>
                                @endif
                            </div>
                            <svg class="w-4 h-4 text-zinc-600 group-hover:text-zinc-400 shrink-0 transition-colors" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7" />
                            </svg>
                        </a>
                    @endforeach
                </div>
                <a href="{{ route('companies.rankings') }}"
                   class="block mt-3 text-center text-xs text-emerald-500 hover:text-emerald-400 transition-colors py-1.5 bg-emerald-500/5 rounded-lg border border-emerald-500/10">
                    View Full Rankings →
                </a>
            @endif
        </div>

        {{-- Top Sells --}}
        <div class="p-5 bg-red-950/30 border border-red-800/40 rounded-xl">
            <div class="flex items-center gap-2 mb-4">
                <span class="text-lg">🔴</span>
                <h2 class="text-sm font-semibold text-red-400 uppercase tracking-wider">Strong Sell Signals</h2>
                <span class="text-xs text-zinc-500 ml-auto">Live ranking</span>
            </div>
            @if ($topSells->isEmpty())
                <p class="text-sm text-zinc-500 text-center py-6">No strong sell signals yet. Run predictions to populate rankings.</p>
            @else
                <div class="space-y-2">
                    @foreach ($topSells as $rank => $company)
                        <a href="{{ route('companies.show', $company) }}"
                           class="flex items-center gap-3 p-3 bg-zinc-900/60 hover:bg-zinc-800/60 rounded-lg border border-zinc-800/50 transition-colors group">
                            <span class="w-6 h-6 rounded-full bg-red-500/20 text-red-400 text-xs font-bold flex items-center justify-center shrink-0">
                                {{ $rank + 1 }}
                            </span>
                            <div class="flex-1 min-w-0">
                                <div class="flex items-center gap-2">
                                    <span class="px-1.5 py-0.5 bg-zinc-800 text-xs font-mono font-bold text-zinc-300 rounded">
                                        {{ $company->ticker }}
                                    </span>
                                    <span class="text-xs text-zinc-400 truncate">{{ $company->name }}</span>
                                </div>
                            </div>
                            <div class="text-right shrink-0">
                                <div class="flex items-center gap-1.5">
                                    <span class="text-xs font-bold text-red-400">
                                        {{ round((float) $company->latest_confidence_score * 100) }}%
                                    </span>
                                    <span class="text-[10px] text-zinc-600">conf</span>
                                </div>
                                @if ($company->latest_predicted_return !== null)
                                    <div class="text-[11px] font-mono text-red-300">
                                        {{ $company->latest_predicted_return > 0 ? '+' : '' }}{{ round((float) $company->latest_predicted_return * 100, 1) }}%
                                    </div>
                                @endif
                            </div>
                            <svg class="w-4 h-4 text-zinc-600 group-hover:text-zinc-400 shrink-0 transition-colors" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7" />
                            </svg>
                        </a>
                    @endforeach
                </div>
                <a href="{{ route('companies.rankings') }}"
                   class="block mt-3 text-center text-xs text-red-500 hover:text-red-400 transition-colors py-1.5 bg-red-500/5 rounded-lg border border-red-500/10">
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
                <svg class="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-zinc-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/>
                </svg>
                <input type="text" name="search" value="{{ request('search') }}" placeholder="Search by ticker or name..."
                       class="w-full pl-10 pr-4 py-2 bg-zinc-900 border border-zinc-700 rounded-lg text-sm focus:outline-none focus:border-emerald-500/50 text-zinc-200 placeholder-zinc-500">
            </div>
        </form>
        <select name="sector" onchange="this.form.submit()" form="sector-form"
                class="px-3 py-2 bg-zinc-900 border border-zinc-700 rounded-lg text-sm focus:outline-none focus:border-emerald-500/50 text-zinc-200">
            <option value="">All Sectors</option>
            @foreach ($sectors as $s)
                <option value="{{ $s }}" {{ request('sector') === $s ? 'selected' : '' }}>{{ $s }}</option>
            @endforeach
        </select>
        <form id="sector-form" method="GET" class="hidden"></form>
    </div>

    {{-- Companies Grid --}}
    @if ($companies->isEmpty())
        <div class="text-center py-20 text-zinc-500">
            <p class="text-lg">No companies found.</p>
            <p class="text-sm mt-1">Add a company to get started with predictions.</p>
        </div>
    @else
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
            @foreach ($companies as $company)
                <a href="{{ route('companies.show', $company) }}"
                   class="block p-5 bg-zinc-900 border border-zinc-800 rounded-xl hover:border-zinc-700 hover:bg-zinc-800/50 transition-all group">
                    <div class="flex items-start justify-between mb-3">
                        <div>
                            <div class="flex items-center gap-2">
                                <span class="px-2 py-0.5 bg-zinc-800 text-xs font-mono font-semibold text-zinc-300 rounded">
                                    {{ $company->ticker }}
                                </span>
                                <span class="text-xs text-zinc-500">{{ $company->sector }}</span>
                            </div>
                            <h3 class="mt-1.5 font-medium text-zinc-100 group-hover:text-white transition-colors line-clamp-1">
                                {{ $company->name }}
                            </h3>
                        </div>
                        @if ($company->latest_price)
                            <div class="text-right">
                                <div class="text-lg font-semibold">${{ number_format($company->latest_price, 2) }}</div>
                            </div>
                        @endif
                    </div>

                    <div class="flex items-center gap-4 text-xs text-zinc-500">
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
<dialog id="add-company-modal" class="bg-zinc-900 border border-zinc-700 rounded-xl p-6 w-full max-w-md text-zinc-100 backdrop:bg-black/50">
    <div class="flex items-center justify-between mb-4">
        <h2 class="text-lg font-semibold">Add Company</h2>
        <button onclick="document.getElementById('add-company-modal').close()"
                class="text-zinc-500 hover:text-zinc-300 transition-colors">&times;</button>
    </div>
    <form method="POST" action="{{ route('companies.store') }}" class="flex flex-col gap-4">
        @csrf
        <div>
            <label class="block text-sm font-medium text-zinc-400 mb-1">Ticker Symbol</label>
            <input type="text" name="ticker" required maxlength="10" placeholder="AAPL"
                   class="w-full px-3 py-2 bg-zinc-800 border border-zinc-700 rounded-lg text-sm focus:outline-none focus:border-emerald-500/50">
        </div>
        <div>
            <label class="block text-sm font-medium text-zinc-400 mb-1">Company Name</label>
            <input type="text" name="name" required maxlength="255" placeholder="Apple Inc."
                   class="w-full px-3 py-2 bg-zinc-800 border border-zinc-700 rounded-lg text-sm focus:outline-none focus:border-emerald-500/50">
        </div>
        <div class="grid grid-cols-2 gap-3">
            <div>
                <label class="block text-sm font-medium text-zinc-400 mb-1">Sector</label>
                <input type="text" name="sector" maxlength="100" placeholder="Technology"
                       class="w-full px-3 py-2 bg-zinc-800 border border-zinc-700 rounded-lg text-sm focus:outline-none focus:border-emerald-500/50">
            </div>
            <div>
                <label class="block text-sm font-medium text-zinc-400 mb-1">SEC CIK</label>
                <input type="text" name="cik" maxlength="20" placeholder="0000320193"
                       class="w-full px-3 py-2 bg-zinc-800 border border-zinc-700 rounded-lg text-sm focus:outline-none focus:border-emerald-500/50">
            </div>
        </div>
        <div class="flex gap-3 justify-end mt-2">
            <button type="button" onclick="document.getElementById('add-company-modal').close()"
                    class="px-4 py-2 text-sm text-zinc-400 hover:text-zinc-200 transition-colors">Cancel</button>
            <button type="submit"
                    class="px-4 py-2 bg-emerald-600 hover:bg-emerald-500 text-white text-sm font-medium rounded-lg transition-colors">
                Add Company
            </button>
        </div>
    </form>
</dialog>
@endsection
