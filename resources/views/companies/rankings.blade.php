@extends('layouts.app')

@section('title', 'Company Rankings — StockPrediction')

@section('content')
<div class="flex flex-col gap-8">
    {{-- Header --}}
    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
        <div>
            <div class="flex items-center gap-3 mb-1">
                <a href="{{ route('companies.index') }}" class="text-zinc-500 hover:text-zinc-300 transition-colors">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7" />
                    </svg>
                </a>
                <h1 class="text-2xl font-bold tracking-tight">Company Rankings</h1>
            </div>
            <p class="text-zinc-400 text-sm mt-1">
                All {{ $ranked->count() }} companies ranked by AI prediction strength.
                BUY signals first, then HOLD, then SELL, then unpredicted.
                Refreshes with every new prediction.
            </p>
        </div>
        <a href="{{ route('companies.index') }}"
           class="inline-flex items-center gap-2 px-4 py-2 bg-zinc-800 hover:bg-zinc-700 border border-zinc-700 text-sm font-medium rounded-lg transition-colors">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 10h16M4 14h16M4 18h16" />
            </svg>
            All Companies
        </a>
    </div>

    {{-- Legend --}}
    <div class="flex flex-wrap gap-3 text-xs">
        <span class="inline-flex items-center gap-1 px-2 py-1 bg-emerald-500/10 text-emerald-400 rounded border border-emerald-500/20">
            <span class="w-2 h-2 rounded-full bg-emerald-500"></span> BUY
        </span>
        <span class="inline-flex items-center gap-1 px-2 py-1 bg-amber-500/10 text-amber-400 rounded border border-amber-500/20">
            <span class="w-2 h-2 rounded-full bg-amber-500"></span> HOLD
        </span>
        <span class="inline-flex items-center gap-1 px-2 py-1 bg-red-500/10 text-red-400 rounded border border-red-500/20">
            <span class="w-2 h-2 rounded-full bg-red-500"></span> SELL
        </span>
        <span class="inline-flex items-center gap-1 px-2 py-1 bg-zinc-800 text-zinc-500 rounded border border-zinc-700">
            <span class="w-2 h-2 rounded-full bg-zinc-600"></span> No Prediction
        </span>
    </div>

    {{-- Unified Ranking Table --}}
    <div class="overflow-hidden bg-zinc-900 border border-zinc-800 rounded-xl">
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="text-left text-xs text-zinc-500 uppercase tracking-wider border-b border-zinc-800">
                        <th class="py-3 pl-5 w-12">#</th>
                        <th class="py-3">Company</th>
                        <th class="py-3 hidden sm:table-cell">Sector</th>
                        <th class="py-3 text-right hidden sm:table-cell">Price</th>
                        <th class="py-3 text-center w-20">Signal</th>
                        <th class="py-3 text-right hidden md:table-cell">Exp. Return</th>
                        <th class="py-3 text-right pr-5">Confidence</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-zinc-800">
                    @forelse ($ranked as $rank => $company)
                        @php
                            $signal = $company->latest_signal_type;
                            $confidence = (float) ($company->latest_confidence_score ?? 0);
                            $predictedReturn = $company->latest_predicted_return !== null
                                ? (float) $company->latest_predicted_return : null;
                            $hasPrediction = $signal !== null && $confidence >= 0.5;

                            $rowBg = match ($signal) {
                                'buy' => 'hover:bg-emerald-950/20',
                                'sell' => 'hover:bg-red-950/20',
                                'hold' => 'hover:bg-amber-950/10',
                                default => 'hover:bg-zinc-800/30',
                            };
                        @endphp
                        <tr class="{{ $rowBg }} transition-colors group cursor-pointer"
                            onclick="window.location='{{ route('companies.show', $company) }}'">
                            {{-- Rank --}}
                            <td class="py-3 pl-5">
                                <span class="inline-flex items-center justify-center w-7 h-7 rounded-full text-xs font-bold
                                    {{ $rank < 3 ? 'bg-zinc-700 text-zinc-200' : ($rank < 10 ? 'bg-zinc-800 text-zinc-400' : 'text-zinc-600') }}">
                                    {{ $rank + 1 }}
                                </span>
                            </td>
                            {{-- Company --}}
                            <td class="py-3">
                                <div class="flex items-center gap-2">
                                    <span class="px-1.5 py-0.5 bg-zinc-800 text-xs font-mono font-bold text-zinc-200 rounded">
                                        {{ $company->ticker }}
                                    </span>
                                    <span class="text-zinc-300 group-hover:text-white transition-colors truncate max-w-[200px] hidden sm:inline">
                                        {{ $company->name }}
                                    </span>
                                </div>
                            </td>
                            {{-- Sector --}}
                            <td class="py-3 text-zinc-500 hidden sm:table-cell">
                                {{ $company->sector ?? '—' }}
                            </td>
                            {{-- Price --}}
                            <td class="py-3 text-right font-mono text-zinc-300 hidden sm:table-cell">
                                {{ $company->latest_price ? '$'.number_format($company->latest_price, 2) : '—' }}
                            </td>
                            {{-- Signal Badge --}}
                            <td class="py-3">
                                <div class="flex justify-center">
                                    @if ($hasPrediction)
                                        <span class="inline-block px-2.5 py-1 text-xs font-bold uppercase rounded
                                            {{ $signal === 'buy' ? 'bg-emerald-500/20 text-emerald-400' : '' }}
                                            {{ $signal === 'sell' ? 'bg-red-500/20 text-red-400' : '' }}
                                            {{ $signal === 'hold' ? 'bg-amber-500/20 text-amber-400' : '' }}">
                                            {{ $signal }}
                                        </span>
                                    @else
                                        <span class="text-xs text-zinc-600">—</span>
                                    @endif
                                </div>
                            </td>
                            {{-- Expected Return --}}
                            <td class="py-3 text-right font-mono hidden md:table-cell">
                                @if ($predictedReturn !== null)
                                    <span class="{{ $predictedReturn > 0 ? 'text-emerald-400' : ($predictedReturn < 0 ? 'text-red-400' : 'text-zinc-400') }}">
                                        {{ $predictedReturn > 0 ? '+' : '' }}{{ round($predictedReturn * 100, 2) }}%
                                    </span>
                                @else
                                    <span class="text-zinc-600">—</span>
                                @endif
                            </td>
                            {{-- Confidence --}}
                            <td class="py-3 text-right pr-5">
                                @if ($hasPrediction)
                                    <div class="flex items-center justify-end gap-2">
                                        <div class="w-14 bg-zinc-800 rounded-full h-1.5">
                                            <div class="rounded-full h-1.5
                                                {{ $signal === 'buy' ? 'bg-emerald-500' : '' }}
                                                {{ $signal === 'sell' ? 'bg-red-500' : '' }}
                                                {{ $signal === 'hold' ? 'bg-amber-500' : '' }}"
                                                 style="width: {{ round($confidence * 100) }}%">
                                            </div>
                                        </div>
                                        <span class="text-xs font-bold w-9 text-right
                                            {{ $signal === 'buy' ? 'text-emerald-400' : '' }}
                                            {{ $signal === 'sell' ? 'text-red-400' : '' }}
                                            {{ $signal === 'hold' ? 'text-amber-400' : '' }}">
                                            {{ round($confidence * 100) }}%
                                        </span>
                                    </div>
                                @else
                                    <span class="text-xs text-zinc-600">No data</span>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7" class="text-center py-16 text-zinc-500">
                                <p class="text-lg">No companies added yet.</p>
                                <p class="text-sm mt-1">Add companies and run predictions to populate rankings.</p>
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    {{-- Summary stats --}}
    <div class="grid grid-cols-2 sm:grid-cols-4 gap-3 text-center text-xs">
        @php
            $buyCount = $ranked->filter(fn($c) => $c->latest_signal_type === 'buy')->count();
            $holdCount = $ranked->filter(fn($c) => $c->latest_signal_type === 'hold')->count();
            $sellCount = $ranked->filter(fn($c) => $c->latest_signal_type === 'sell')->count();
            $noneCount = $ranked->filter(fn($c) => $c->latest_signal_type === null)->count();
        @endphp
        <div class="p-3 bg-emerald-500/5 rounded-lg border border-emerald-500/10">
            <p class="text-emerald-400 font-bold text-lg">{{ $buyCount }}</p>
            <p class="text-zinc-500">🟢 BUY</p>
        </div>
        <div class="p-3 bg-amber-500/5 rounded-lg border border-amber-500/10">
            <p class="text-amber-400 font-bold text-lg">{{ $holdCount }}</p>
            <p class="text-zinc-500">🟡 HOLD</p>
        </div>
        <div class="p-3 bg-red-500/5 rounded-lg border border-red-500/10">
            <p class="text-red-400 font-bold text-lg">{{ $sellCount }}</p>
            <p class="text-zinc-500">🔴 SELL</p>
        </div>
        <div class="p-3 bg-zinc-800/50 rounded-lg border border-zinc-700/50">
            <p class="text-zinc-400 font-bold text-lg">{{ $noneCount }}</p>
            <p class="text-zinc-500">⚪ No Data</p>
        </div>
    </div>
</div>
@endsection
