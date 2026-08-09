@extends('layouts.app')

@section('title', "{$company->ticker} — StockPrediction")

@push('head')
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<style>
    [x-cloak] { display: none !important; }
    @keyframes toast-in {
        from { opacity: 0; transform: translateY(-8px); }
        to { opacity: 1; transform: translateY(0); }
    }
    .toast-enter { animation: toast-in 0.25s ease-out; }
</style>
@endpush

@section('content')
<div
    x-data="{
        companyId: {{ $company->id }},
        ticker: '{{ $company->ticker }}',
        importUrl: '{{ route('api.v1.companies.import', $company) }}',
        predictUrl: '{{ route('api.v1.companies.predictions.store', $company) }}',
        importing: false,
        predicting: false,
        timeframe: '3m',
        prediction: {!! \Illuminate\Support\Js::from($predictionData) !!},
        statements: {!! \Illuminate\Support\Js::from($statementsData) !!},
        toast: { show: false, message: '', type: 'success' },

        init() {
            this.$watch('prediction', () => {
                this.$nextTick(() => this.renderFeatureChart());
            });
        },

        showToast(message, type) {
            type = type || 'success';
            this.toast = { show: true, message: message, type: type };
            var self = this;
            setTimeout(function () { self.toast.show = false; }, 4000);
        },

        async importSecData() {
            this.importing = true;
            var self = this;
            try {
                var resp = await fetch(this.importUrl, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': (document.querySelector('meta[name=csrf-token]') || {}).content || '',
                        'Accept': 'application/json',
                    },
                });
                var data = await resp.json();
                if (!resp.ok) throw new Error(data.message || 'Import failed.');
                if (data.statements && data.statements.length) {
                    self.statements = data.statements;
                }
                self.showToast(data.message || 'Financial data imported successfully.', 'success');
            } catch (err) {
                self.showToast(err.message || 'Import failed.', 'error');
            } finally {
                self.importing = false;
            }
        },

        async runPrediction() {
            this.predicting = true;
            var self = this;
            try {
                var resp = await fetch(this.predictUrl, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': (document.querySelector('meta[name=csrf-token]') || {}).content || '',
                        'Accept': 'application/json',
                    },
                    body: JSON.stringify({ timeframe: this.timeframe }),
                });
                var result = await resp.json();
                if (!resp.ok) throw new Error(result.message || 'Prediction failed.');
                self.prediction = result.data || result;
                self.showToast(result.message || 'Prediction completed successfully.', 'success');
            } catch (err) {
                self.showToast(err.message || 'Prediction failed. Is the AI service running?', 'error');
            } finally {
                self.predicting = false;
            }
        },

        renderFeatureChart() {
            if (!this.prediction || !this.prediction.feature_importance || !this.prediction.feature_importance.length) return;
            var canvas = document.getElementById('featureImportanceChart');
            if (!canvas) return;
            var existing = Chart.getChart(canvas);
            if (existing) existing.destroy();
            var fi = this.prediction.feature_importance;
            var labels = fi.map(function (f) { return (f.feature || '').replace(/_/g, ' ').toUpperCase(); });
            var values = fi.map(function (f) { return f.importance || 0; });
            new Chart(canvas, {
                type: 'bar',
                data: {
                    labels: labels,
                    datasets: [{
                        label: 'Importance',
                        data: values,
                        backgroundColor: values.map(function (v, i) {
                            return i === 0 ? 'rgba(52, 211, 153, 0.7)' :
                                   i < 3  ? 'rgba(52, 211, 153, 0.4)' :
                                            'rgba(52, 211, 153, 0.2)';
                        }),
                        borderColor: 'rgba(52, 211, 153, 0.8)',
                        borderWidth: 1,
                        borderRadius: 4,
                    }],
                },
                options: {
                    indexAxis: 'y',
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: { legend: { display: false } },
                    scales: {
                        x: {
                            beginAtZero: true,
                            max: 0.3,
                            grid: { color: 'rgba(63, 63, 70, 0.3)' },
                            ticks: {
                                color: '#a1a1aa',
                                callback: function (v) { return (v * 100).toFixed(0) + '%'; },
                            },
                        },
                        y: {
                            grid: { display: false },
                            ticks: { color: '#a1a1aa', font: { size: 11 } },
                        },
                    },
                },
            });
        },
    }"
    x-cloak
    class="flex flex-col gap-8"
>

    {{-- ================================================================
         Toast Notification (slides in top-right, auto-hides after 4s)
         ================================================================ --}}
    <div
        x-show="toast.show"
        x-transition:enter="toast-enter"
        x-transition:leave="transition-opacity duration-200"
        x-transition:leave-end="opacity-0"
        @click="toast.show = false"
        class="fixed top-20 right-4 z-50 max-w-sm cursor-pointer"
    >
        <div
            :class="toast.type === 'success'
                ? 'bg-emerald-900/90 border-emerald-700/60 text-emerald-200'
                : 'bg-red-900/90 border-red-700/60 text-red-200'"
            class="border px-5 py-3 rounded-xl shadow-2xl backdrop-blur-sm text-sm font-medium"
        >
            <span x-text="toast.message"></span>
        </div>
    </div>

    {{-- ================================================================
         Header: ticker, name, sector, market cap + action buttons
         ================================================================ --}}
    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
        <div>
            <div class="flex items-center gap-3 mb-1">
                <span class="px-2.5 py-1 bg-zinc-800 text-sm font-mono font-bold text-zinc-200 rounded-md">
                    {{ $company->ticker }}
                </span>
                <h1 class="text-2xl font-bold">{{ $company->name }}</h1>
            </div>
            <p class="text-zinc-400 text-sm">
                {{ $company->sector }} @if($company->industry)&middot; {{ $company->industry }} @endif
                @if($company->market_cap)&middot; Mkt Cap: ${{ number_format($company->market_cap / 1e9, 2) }}B @endif
            </p>
        </div>

        {{-- Action Buttons --}}
        <div class="flex gap-3">
            {{-- Import SEC Data --}}
            <button
                @click="importSecData()"
                :disabled="importing"
                class="px-4 py-2 bg-zinc-800 hover:bg-zinc-700 border border-zinc-700 text-sm font-medium rounded-lg transition-colors disabled:opacity-50 disabled:cursor-not-allowed inline-flex items-center gap-2"
            >
                {{-- Spinner (shown while importing) --}}
                <svg
                    x-show="importing"
                    class="animate-spin h-4 w-4 text-zinc-400"
                    xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24"
                >
                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path>
                </svg>
                <span x-text="importing ? 'Importing...' : 'Import SEC Data'"></span>
            </button>

            {{-- Run Prediction (timeframe select + button) --}}
            <div class="flex gap-2">
                <select
                    x-model="timeframe"
                    :disabled="predicting"
                    class="px-3 py-2 bg-zinc-800 border border-zinc-700 rounded-lg text-sm focus:outline-none focus:border-emerald-500/50 disabled:opacity-50"
                >
                    <option value="1m">1 Month</option>
                    <option value="3m">3 Months</option>
                    <option value="6m">6 Months</option>
                    <option value="1y">1 Year</option>
                </select>
                <button
                    @click="runPrediction()"
                    :disabled="predicting"
                    class="px-4 py-2 bg-emerald-600 hover:bg-emerald-500 text-white text-sm font-medium rounded-lg transition-colors disabled:opacity-50 disabled:cursor-not-allowed inline-flex items-center gap-2"
                >
                    <svg
                        x-show="predicting"
                        class="animate-spin h-4 w-4 text-white"
                        xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24"
                    >
                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path>
                    </svg>
                    <span x-text="predicting ? 'Running AI Model...' : 'Run Prediction'"></span>
                </button>
            </div>
        </div>
    </div>

    {{-- ================================================================
         Prediction Card — dynamic: swaps between empty state and results
         ================================================================ --}}

    {{-- State A: No prediction yet (shown when prediction is null) --}}
    <div
        x-show="!prediction"
        class="p-6 bg-zinc-900 border border-zinc-800 rounded-xl text-center text-zinc-500"
    >
        <p>No actionable prediction yet. Import financial data and run a prediction to get started.</p>
    </div>

    {{-- State B: Prediction results (shown when prediction exists) --}}
    <template x-if="prediction">
        <div>
            {{-- Metric cards --}}
            <div class="grid grid-cols-1 md:grid-cols-4 gap-4 mb-4">
                {{-- Signal Badge --}}
                <div class="p-5 bg-zinc-900 border border-zinc-800 rounded-xl">
                    <p class="text-xs text-zinc-500 uppercase tracking-wider">Signal</p>
                    <p class="text-2xl font-bold mt-1"
                        :class="{
                            'text-emerald-400': prediction.signal_type === 'buy',
                            'text-red-400': prediction.signal_type === 'sell',
                            'text-amber-400': prediction.signal_type === 'hold',
                        }"
                        x-text="prediction.signal_type ? prediction.signal_type.toUpperCase() : '—'"
                    ></p>
                </div>

                {{-- Expected Return % --}}
                <div class="p-5 bg-zinc-900 border border-zinc-800 rounded-xl">
                    <p class="text-xs text-zinc-500 uppercase tracking-wider">Expected Return</p>
                    <p class="text-2xl font-bold mt-1"
                        :class="{
                            'text-emerald-400': (prediction.predicted_return ?? 0) > 0,
                            'text-red-400': (prediction.predicted_return ?? 0) < 0,
                        }"
                    >
                        <span x-text="prediction.predicted_return != null
                            ? ((prediction.predicted_return > 0 ? '+' : '') + (prediction.predicted_return * 100).toFixed(2) + '%')
                            : '—'"
                        ></span>
                    </p>
                </div>

                {{-- Confidence Score % --}}
                <div class="p-5 bg-zinc-900 border border-zinc-800 rounded-xl">
                    <p class="text-xs text-zinc-500 uppercase tracking-wider">Confidence</p>
                    <p class="text-2xl font-bold mt-1"
                        :class="{
                            'text-emerald-400': (prediction.confidence_score ?? 0) >= 0.7,
                            'text-amber-400': (prediction.confidence_score ?? 0) >= 0.5 && (prediction.confidence_score ?? 0) < 0.7,
                            'text-red-400': (prediction.confidence_score ?? 0) < 0.5,
                        }"
                        x-text="prediction.confidence_score != null
                            ? (prediction.confidence_score * 100).toFixed(1) + '%'
                            : '—'"
                    ></p>
                </div>
                <div class="p-5 bg-zinc-900 border border-zinc-800 rounded-xl">
                    <p class="text-xs text-zinc-500 uppercase tracking-wider">
                        Target Price (<span x-text="prediction.target_period ?? '—'"></span>)
                    </p>
                    <p class="text-2xl font-bold mt-1"
                        x-text="prediction.predicted_price != null
                            ? '$' + Number(prediction.predicted_price).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 })
                            : '—'"
                    ></p>
                </div>
            </div>

            {{-- Key Drivers (feature importance → bullet list) --}}
            <template x-if="prediction.feature_importance && prediction.feature_importance.length">
                <div class="p-6 bg-zinc-900 border border-zinc-800 rounded-xl">
                    <h3 class="text-sm font-semibold mb-4">Key Drivers</h3>
                    <ul class="space-y-2">
                        <template x-for="driver in prediction.feature_importance" :key="driver.feature">
                            <li class="flex items-start gap-3 text-sm">
                                {{-- Impact icon --}}
                                <span class="mt-0.5 shrink-0"
                                    :class="{
                                        'text-emerald-400': driver.impact === 'positive',
                                        'text-red-400': driver.impact === 'negative',
                                        'text-zinc-500': driver.impact === 'neutral',
                                    }"
                                >
                                    <template x-if="driver.impact === 'positive'">
                                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 10l7-7m0 0l7 7m-7-7v18" />
                                        </svg>
                                    </template>
                                    <template x-if="driver.impact === 'negative'">
                                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 14l-7 7m0 0l-7-7m7 7V3" />
                                        </svg>
                                    </template>
                                    <template x-if="driver.impact === 'neutral'">
                                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 12h14" />
                                        </svg>
                                    </template>
                                </span>
                                <div>
                                    <span class="font-medium text-zinc-200" x-text="driver.feature"></span>
                                    <span class="text-zinc-500 ml-1"
                                        :class="{
                                            'text-emerald-500': driver.impact === 'positive',
                                            'text-red-500': driver.impact === 'negative',
                                        }"
                                        x-text="'(' + driver.impact + ')'"
                                    ></span>
                                </div>
                            </li>
                        </template>
                    </ul>
                </div>
            </template>

            {{-- Confidence Breakdown — explains how the confidence score is derived --}}
            <template x-if="prediction.model_metadata && prediction.model_metadata.confidence_breakdown">
                <div
                    x-data="{ breakdownOpen: false }"
                    class="p-6 bg-zinc-900 border border-zinc-800 rounded-xl mt-4"
                >
                    <button
                        @click="breakdownOpen = !breakdownOpen"
                        class="w-full flex items-center justify-between text-left"
                    >
                        <div>
                            <h3 class="text-sm font-semibold">Confidence Breakdown</h3>
                            <p class="text-xs text-zinc-500 mt-0.5">How the AI determines its certainty</p>
                        </div>
                        <svg
                            :class="breakdownOpen ? 'rotate-180' : ''"
                            class="w-4 h-4 text-zinc-500 transition-transform shrink-0"
                            fill="none" stroke="currentColor" viewBox="0 0 24 24"
                        >
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7" />
                        </svg>
                    </button>

                    <div x-show="breakdownOpen" x-collapse class="mt-4 space-y-5">

                        {{-- Prediction Mode explainer --}}
                        <div class="p-4 bg-emerald-400/5 rounded-lg border border-emerald-500/20">
                            <div class="flex items-start gap-3">
                                <span class="text-lg shrink-0">🧠</span>
                                <div>
                                    <p class="text-xs text-emerald-400 uppercase tracking-wider mb-1">Prediction Mode in Use</p>
                                    <p class="text-sm text-zinc-300 leading-relaxed font-medium">
                                        Enhanced Fundamental Analysis
                                    </p>
                                    <p class="text-xs text-zinc-500 mt-1.5 leading-relaxed">
                                        This prediction combines <span class="text-zinc-400 font-medium">fundamental analysis</span> with <span class="text-blue-400 font-medium">technical price momentum</span>.
                                        The system scans your company's historical financial statements (revenue, EPS, ROE, margins,
                                        debt ratios) from SEC EDGAR, enriches each report with <span class="text-zinc-400 font-medium">post-earnings price reactions</span>
                                        from Yahoo Finance, and also evaluates <span class="text-blue-400 font-medium">daily/weekly Open-to-Close OHLC price trends</span>
                                        from your local price history database. If technical price action confirms the fundamental
                                        direction, confidence increases — if it contradicts, a penalty is applied.
                                    </p>
                                    <div class="mt-2 pt-2 border-t border-emerald-500/10 flex flex-wrap gap-3 text-xs text-zinc-500">
                                        <span>📊 SEC EDGAR XBRL data</span>
                                        <span class="text-zinc-600">+</span>
                                        <span>📈 Yahoo Finance post-earnings</span>
                                        <span class="text-zinc-600">+</span>
                                        <span>🕯️ Daily/Weekly OHLC momentum</span>
                                        <span class="text-zinc-600">=</span>
                                        <span class="text-emerald-400">6 trend checks</span>
                                    </div>
                                </div>
                            </div>
                        </div>

                        {{-- Methodology explainer --}}
                        <div class="p-4 bg-zinc-800/30 rounded-lg border border-zinc-700/30">
                            <p class="text-xs text-zinc-500 uppercase tracking-wider mb-2">How Confidence is Calculated</p>
                            <div class="text-sm text-zinc-300 leading-relaxed space-y-2">
                                <p>
                                    Confidence uses a <span class="text-amber-400 font-medium">transparent heuristic</span> — not a black-box ML model.
                                    Each of the <span class="font-semibold">6 trend categories</span> acts as a <span class="font-medium">piece of evidence</span>:
                                </p>
                                <ul class="space-y-1 ml-4 text-xs text-zinc-400">
                                    <li>• <span class="text-zinc-300">5 Fundamental</span> — EPS trend, ROE trajectory, margin direction, leverage change, post-earnings history</li>
                                    <li>• <span class="text-blue-400">1 Technical</span> — OHLC price momentum alignment (Open vs Close trends from your local <code class="text-zinc-500 bg-zinc-800 px-1 rounded text-[10px]">daily_price_histories</code> table)</li>
                                </ul>
                                <p class="text-xs text-zinc-500 mt-2">
                                    <span class="text-emerald-400">Technical Alignment</span> acts as a <span class="font-medium text-zinc-300">trend confirmation check</span>:
                                    if daily/weekly price candles are moving in the same direction as the fundamental signal,
                                    confidence gets a <span class="text-emerald-400">+10% boost</span>. If price action contradicts
                                    fundamentals, confidence receives a <span class="text-red-400">−10% penalty</span>.
                                    Final confidence is clamped between <span class="font-semibold">30% (floor)</span> and <span class="font-semibold">95% (cap)</span>.
                                </p>
                            </div>
                            <div class="mt-3 flex items-start gap-2 text-xs text-zinc-500">
                                <span class="shrink-0 mt-0.5">💡</span>
                                <span>
                                    <span class="text-zinc-400 font-medium">Also available:</span> A separate XGBoost + RandomForest
                                    ensemble mode (<code class="text-zinc-500 bg-zinc-800 px-1 rounded">POST /api/v1/train</code>)
                                    can be trained on OHLCV price history for ML-based price forecasting. The dashboard uses
                                    fundamental mode because it provides interpretable, driver-based signals — you can see
                                    <span class="italic">why</span> the AI made its decision.
                                </span>
                            </div>
                        </div>

                        {{-- Formula with step-by-step --}}
                        <div class="p-4 bg-zinc-800/50 rounded-lg border border-zinc-700/50">
                            <p class="text-xs text-zinc-500 uppercase tracking-wider mb-3">Formula</p>
                            <div class="space-y-2">
                                <div class="flex items-center gap-3">
                                    <span class="px-2 py-0.5 bg-zinc-700 rounded text-xs font-mono text-zinc-300">Step 1</span>
                                    <span class="text-sm text-zinc-300">Start with <span class="font-semibold">40% base confidence</span> from fundamental analysis (Python AI)</span>
                                </div>
                                <div class="flex items-center gap-3">
                                    <span class="px-2 py-0.5 bg-zinc-700 rounded text-xs font-mono text-zinc-300">Step 2</span>
                                    <span class="text-sm text-zinc-300">Add <span class="font-semibold text-emerald-400">+10% for each detected trend</span> across 6 categories (5 fundamental + 1 technical)</span>
                                </div>
                                <div class="flex items-center gap-3">
                                    <span class="px-2 py-0.5 bg-zinc-700 rounded text-xs font-mono text-zinc-300">Step 3</span>
                                    <span class="text-sm text-zinc-300">Apply <span class="font-semibold text-blue-400">technical alignment adjustment</span>: ±10% if price momentum confirms/contradicts fundamentals</span>
                                </div>
                                <div class="flex items-center gap-3">
                                    <span class="px-2 py-0.5 bg-zinc-700 rounded text-xs font-mono text-zinc-300">Step 4</span>
                                    <span class="text-sm text-zinc-300">Clamp between <span class="font-semibold text-red-400">30% floor</span> and <span class="font-semibold text-amber-400">95% cap</span></span>
                                </div>
                            </div>
                            <div class="mt-3 pt-3 border-t border-zinc-700/50">
                                {{-- Dynamic formula: 40% base + (N fundamental × 10%) + (M technical × 10%) = X% --}}
                                <p class="text-sm font-mono text-zinc-200">
                                    <span>40% base</span>
                                    <template x-if="prediction.model_metadata.confidence_breakdown.total_drivers > 0">
                                        <span>
                                            <span class="text-zinc-500"> + (</span>
                                            <span class="text-emerald-400" x-text="prediction.model_metadata.confidence_breakdown.total_drivers"></span>
                                            <span class="text-zinc-500"> fundamental drivers × 10%)</span>
                                        </span>
                                    </template>
                                    <template x-if="prediction.model_metadata.confidence_breakdown.technical_alignment && prediction.model_metadata.confidence_breakdown.technical_alignment.driver_added">
                                        <span>
                                            <span class="text-zinc-500"> + (1 technical alignment driver × 10%)</span>
                                        </span>
                                    </template>
                                    <span class="text-zinc-500"> = </span>
                                    <span class="font-bold"
                                        :class="{
                                            'text-emerald-400': prediction.confidence_score >= 0.7,
                                            'text-amber-400': prediction.confidence_score >= 0.5 && prediction.confidence_score < 0.7,
                                            'text-red-400': prediction.confidence_score < 0.5,
                                        }"
                                        x-text="(prediction.confidence_score * 100).toFixed(1) + '%'"
                                    ></span>
                                </p>
                                {{-- Adjustment note --}}
                                <template x-if="prediction.model_metadata.confidence_breakdown.technical_alignment && prediction.model_metadata.confidence_breakdown.technical_alignment.adjustment && prediction.model_metadata.confidence_breakdown.technical_alignment.adjustment !== 0">
                                    <p class="text-xs text-zinc-500 mt-1">
                                        <span x-text="prediction.model_metadata.confidence_breakdown.technical_alignment.alignment_result === 'aligned' ? '↳ Includes' : '↳ After'"></span>
                                        <span class="font-mono"
                                            :class="prediction.model_metadata.confidence_breakdown.technical_alignment.adjustment > 0 ? 'text-emerald-400' : 'text-red-400'"
                                            x-text="(prediction.model_metadata.confidence_breakdown.technical_alignment.adjustment > 0 ? '+' : '') + (prediction.model_metadata.confidence_breakdown.technical_alignment.adjustment * 100).toFixed(0) + '% technical '
                                                + (prediction.model_metadata.confidence_breakdown.technical_alignment.alignment_result === 'aligned' ? 'bonus' : 'penalty')"
                                        ></span>
                                    </p>
                                </template>
                                {{-- Fallback: show Python formula if no dynamic data --}}
                                <template x-if="!prediction.model_metadata.confidence_breakdown.total_drivers">
                                    <p class="text-sm font-mono text-zinc-200"
                                        x-text="prediction.model_metadata.confidence_breakdown.formula"
                                    ></p>
                                </template>
                            </div>
                        </div>

                        {{-- Component bars --}}
                        <div>
                            <p class="text-xs text-zinc-500 uppercase tracking-wider mb-3">Score Components</p>
                            <div class="space-y-3">
                                {{-- Base confidence bar --}}
                                <div>
                                    <div class="flex justify-between text-xs text-zinc-400 mb-1">
                                        <span>Base Confidence (always 40%)</span>
                                        <span x-text="(prediction.model_metadata.confidence_breakdown.base_confidence * 100).toFixed(0) + '%'"></span>
                                    </div>
                                    <div class="w-full bg-zinc-800 rounded-full h-2.5">
                                        <div class="bg-zinc-500 rounded-full h-2.5"
                                            :style="'width: ' + (prediction.model_metadata.confidence_breakdown.base_confidence * 100) + '%'"
                                        ></div>
                                    </div>
                                </div>

                                {{-- Driver bonus bar --}}
                                <div>
                                    <div class="flex justify-between text-xs text-zinc-400 mb-1">
                                        <span>Trend Bonus (<span x-text="prediction.model_metadata.confidence_breakdown.total_drivers"></span> detected trends × 10% each)</span>
                                        <span class="text-emerald-400" x-text="'+' + (prediction.model_metadata.confidence_breakdown.driver_bonus * 100).toFixed(0) + '%'"></span>
                                    </div>
                                    <div class="w-full bg-zinc-800 rounded-full h-2.5">
                                        <div class="bg-emerald-500 rounded-full h-2.5"
                                            :style="'width: ' + (prediction.model_metadata.confidence_breakdown.driver_bonus * 100) + '%'"
                                        ></div>
                                    </div>
                                </div>

                                {{-- Total bar --}}
                                <div>
                                    <div class="flex justify-between text-xs font-semibold mb-1"
                                        :class="{
                                            'text-emerald-400': prediction.confidence_score >= 0.7,
                                            'text-amber-400': prediction.confidence_score >= 0.5 && prediction.confidence_score < 0.7,
                                            'text-red-400': prediction.confidence_score < 0.5,
                                        }"
                                    >
                                        <span>Final Confidence</span>
                                        <span x-text="(prediction.confidence_score * 100).toFixed(1) + '%'"></span>
                                    </div>
                                    <div class="w-full bg-zinc-800 rounded-full h-3">
                                        <div class="rounded-full h-3 transition-all duration-700"
                                            :class="{
                                                'bg-emerald-500': prediction.confidence_score >= 0.7,
                                                'bg-amber-500': prediction.confidence_score >= 0.5 && prediction.confidence_score < 0.7,
                                                'bg-red-500': prediction.confidence_score < 0.5,
                                            }"
                                            :style="'width: ' + (prediction.confidence_score * 100) + '%'"
                                        ></div>
                                    </div>
                                </div>

                                {{-- Cap indicator --}}
                                <template x-if="prediction.model_metadata.confidence_breakdown.cap_applied">
                                    <div class="flex items-center gap-2 text-xs text-amber-400 bg-amber-400/10 rounded-lg px-3 py-2">
                                        <svg class="w-3.5 h-3.5 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-2.5L13.732 4c-.77-.833-2.694-.833-3.464 0L3.34 16.5c-.77.833.192 2.5 1.732 2.5z" />
                                        </svg>
                                        <span>Capped at 95% — raw score was <span class="font-semibold" x-text="(prediction.model_metadata.confidence_breakdown.raw_confidence * 100).toFixed(0) + '%'"></span>. No prediction can be 100% certain.</span>
                                    </div>
                                </template>
                            </div>
                        </div>

                        {{-- Driver score summary --}}
                        <div>
                            <p class="text-xs text-zinc-500 uppercase tracking-wider mb-3">Driver Scorecard</p>
                            <div class="grid grid-cols-3 gap-3 mb-3">
                                <div class="p-3 bg-emerald-400/10 rounded-lg text-center border border-emerald-500/20">
                                    <p class="text-xl font-bold text-emerald-400"
                                        x-text="prediction.model_metadata.confidence_breakdown.positive_drivers"
                                    ></p>
                                    <p class="text-xs text-zinc-500 mt-0.5">🟢 Positive</p>
                                </div>
                                <div class="p-3 bg-red-400/10 rounded-lg text-center border border-red-500/20">
                                    <p class="text-xl font-bold text-red-400"
                                        x-text="prediction.model_metadata.confidence_breakdown.negative_drivers"
                                    ></p>
                                    <p class="text-xs text-zinc-500 mt-0.5">🔴 Negative</p>
                                </div>
                                <div class="p-3 bg-zinc-800/50 rounded-lg text-center border border-zinc-700/50">
                                    <p class="text-xl font-bold"
                                        :class="{
                                            'text-emerald-400': prediction.model_metadata.confidence_breakdown.net_score > 0,
                                            'text-red-400': prediction.model_metadata.confidence_breakdown.net_score < 0,
                                            'text-zinc-400': prediction.model_metadata.confidence_breakdown.net_score === 0,
                                        }"
                                        x-text="prediction.model_metadata.confidence_breakdown.net_score >= 0
                                            ? '+' + prediction.model_metadata.confidence_breakdown.net_score
                                            : prediction.model_metadata.confidence_breakdown.net_score"
                                    ></p>
                                    <p class="text-xs text-zinc-500 mt-0.5">Net Score</p>
                                </div>
                            </div>
                            <p class="text-xs text-zinc-500 leading-relaxed">
                                Net Score = Positive − Negative drivers. This determines the final trading signal.
                            </p>
                        </div>

                        {{-- Signal determination --}}
                        <div class="p-4 bg-zinc-800/50 rounded-lg border border-zinc-700/50">
                            <p class="text-xs text-zinc-500 uppercase tracking-wider mb-3">Signal Rules</p>
                            <div class="space-y-2 text-sm">
                                <div class="flex items-center gap-2">
                                    <span class="w-16 shrink-0 font-mono text-xs text-zinc-500">Net ≥ +2</span>
                                    <span class="text-zinc-400">→</span>
                                    <span class="px-2 py-0.5 bg-emerald-500/20 text-emerald-400 rounded text-xs font-bold">BUY</span>
                                    <span class="text-zinc-500 text-xs">Strong bullish evidence</span>
                                </div>
                                <div class="flex items-center gap-2">
                                    <span class="w-16 shrink-0 font-mono text-xs text-zinc-500">Net ≤ −2</span>
                                    <span class="text-zinc-400">→</span>
                                    <span class="px-2 py-0.5 bg-red-500/20 text-red-400 rounded text-xs font-bold">SELL</span>
                                    <span class="text-zinc-500 text-xs">Strong bearish evidence</span>
                                </div>
                                <div class="flex items-center gap-2">
                                    <span class="w-16 shrink-0 font-mono text-xs text-zinc-500">−1 to +1</span>
                                    <span class="text-zinc-400">→</span>
                                    <span class="px-2 py-0.5 bg-amber-500/20 text-amber-400 rounded text-xs font-bold">HOLD</span>
                                    <span class="text-zinc-500 text-xs">Mixed or insufficient evidence</span>
                                </div>
                            </div>
                        </div>

                        {{-- What drivers are checked --}}
                        <div class="p-4 bg-zinc-800/30 rounded-lg border border-zinc-700/30">
                            <p class="text-xs text-zinc-500 uppercase tracking-wider mb-3">The 6 Trend Checks (This Prediction Mode)</p>
                            <p class="text-xs text-zinc-500 mb-3 leading-relaxed">
                                The system scans your company's imported financial statements for these 6 trend categories.
                                Each one found adds <span class="text-emerald-400 font-medium">+10% confidence</span> and counts toward the BUY/SELL/HOLD signal.
                                These come from two data sources: SEC EDGAR financials and Yahoo Finance price history.
                            </p>
                            <div class="space-y-2.5 text-xs">
                                <div class="flex items-start gap-2">
                                    <span class="text-emerald-400 mt-0.5 shrink-0 font-bold">1.</span>
                                    <div>
                                        <span class="text-zinc-300 font-medium">EPS Trend</span>
                                        <span class="text-zinc-500"> — Is earnings per share growing or declining across all filed reports? Compares first and last EPS values in the history.</span>
                                        <span class="text-zinc-600 block text-[10px] mt-0.5">Source: SEC EDGAR XBRL</span>
                                    </div>
                                </div>
                                <div class="flex items-start gap-2">
                                    <span class="text-emerald-400 mt-0.5 shrink-0 font-bold">2.</span>
                                    <div>
                                        <span class="text-zinc-300 font-medium">ROE Trajectory</span>
                                        <span class="text-zinc-500"> — Is return on equity improving or deteriorating? Triggers if ROE changed more than ±2% across reports.</span>
                                        <span class="text-zinc-600 block text-[10px] mt-0.5">Source: SEC EDGAR XBRL</span>
                                    </div>
                                </div>
                                <div class="flex items-start gap-2">
                                    <span class="text-emerald-400 mt-0.5 shrink-0 font-bold">3.</span>
                                    <div>
                                        <span class="text-zinc-300 font-medium">Margin Direction</span>
                                        <span class="text-zinc-500"> — Are gross margins expanding or contracting? Triggers if margin changed more than ±2% across reports.</span>
                                        <span class="text-zinc-600 block text-[10px] mt-0.5">Source: SEC EDGAR XBRL</span>
                                    </div>
                                </div>
                                <div class="flex items-start gap-2">
                                    <span class="text-emerald-400 mt-0.5 shrink-0 font-bold">4.</span>
                                    <div>
                                        <span class="text-zinc-300 font-medium">Leverage Change</span>
                                        <span class="text-zinc-500"> — Is debt-to-equity rising (negative) or falling/deleveraging (positive)? Triggers if D/E changed more than ±0.3.</span>
                                        <span class="text-zinc-600 block text-[10px] mt-0.5">Source: SEC EDGAR XBRL</span>
                                    </div>
                                </div>
                                <div class="flex items-start gap-2">
                                    <span class="text-emerald-400 mt-0.5 shrink-0 font-bold">5.</span>
                                    <div>
                                        <span class="text-zinc-300 font-medium">Post-Earnings Price History</span>
                                        <span class="text-zinc-500"> — What was the average stock price reaction after past earnings reports? Triggers if average post-report return exceeds ±3%.</span>
                                        <span class="text-zinc-600 block text-[10px] mt-0.5">Source: Yahoo Finance (via yfinance)</span>
                                    </div>
                                </div>
                                <div class="flex items-start gap-2">
                                    <span class="text-blue-400 mt-0.5 shrink-0 font-bold">6.</span>
                                    <div>
                                        <span class="text-zinc-300 font-medium">Technical Price Alignment</span>
                                        <span class="inline-block px-1.5 py-0.5 bg-blue-500/10 text-blue-400 rounded text-[10px] font-bold ml-1 align-middle">NEW</span>
                                        <span class="text-zinc-500"> — Does recent Open-to-Close price momentum confirm or contradict the fundamental direction? Queries your local <code class="text-zinc-500 bg-zinc-800 px-1 rounded text-[10px]">daily_price_histories</code> table for net price change %, green/red candle ratio, and trend direction.</span>
                                        <span class="text-zinc-600 block text-[10px] mt-0.5">Source: Local DB (<code class="text-zinc-500 bg-zinc-800 px-1 rounded text-[10px]">daily_price_histories</code>) — Alignment = +10% confidence | Contradiction = −10% penalty</span>
                                    </div>
                                </div>
                            </div>

                            <div class="mt-4 pt-3 border-t border-zinc-700/30">
                                <p class="text-xs text-zinc-500 leading-relaxed">
                                    <span class="text-zinc-400 font-medium">Data sources:</span>
                                    Checks 1–4 use <span class="text-zinc-400">SEC EDGAR XBRL</span> financials imported via "Import SEC Data".
                                    Check 5 uses <span class="text-zinc-400">Yahoo Finance</span> post-earnings price reactions.
                                    Check 6 uses your <span class="text-blue-400">local price history database</span> (<code class="text-zinc-500 bg-zinc-800 px-1 rounded text-[10px]">daily_price_histories</code> table)
                                    populated by the OHLC data fetcher — no external API call needed.
                                </p>
                            </div>
                        </div>

                        {{-- Technical Alignment — shows local DB momentum data and alignment result --}}
                        <template x-if="prediction.model_metadata && prediction.model_metadata.confidence_breakdown && prediction.model_metadata.confidence_breakdown.technical_alignment">
                            <div class="p-4 bg-blue-400/5 rounded-lg border border-blue-500/20">
                                <p class="text-xs text-blue-400 uppercase tracking-wider mb-3">🔬 Technical Price Alignment</p>

                                {{-- Case: Technical driver was added (aligned or contradiction) --}}
                                <template x-if="prediction.model_metadata.confidence_breakdown.technical_alignment.driver_added">
                                    <div class="space-y-3">
                                        {{-- Direction labels --}}
                                        <div class="grid grid-cols-2 gap-2 text-xs">
                                            <div class="p-2 bg-zinc-800/50 rounded text-center">
                                                <p class="text-zinc-500 mb-0.5">Fundamental Direction</p>
                                                <p class="font-bold uppercase"
                                                    :class="{
                                                        'text-emerald-400': prediction.model_metadata.confidence_breakdown.technical_alignment.fundamental_direction === 'bullish',
                                                        'text-red-400': prediction.model_metadata.confidence_breakdown.technical_alignment.fundamental_direction === 'bearish',
                                                        'text-amber-400': prediction.model_metadata.confidence_breakdown.technical_alignment.fundamental_direction === 'neutral',
                                                    }"
                                                    x-text="prediction.model_metadata.confidence_breakdown.technical_alignment.fundamental_direction || 'neutral'"
                                                ></p>
                                            </div>
                                            <div class="p-2 bg-zinc-800/50 rounded text-center">
                                                <p class="text-zinc-500 mb-0.5">Technical Signal</p>
                                                <p class="font-bold uppercase"
                                                    :class="{
                                                        'text-emerald-400': prediction.model_metadata.confidence_breakdown.technical_alignment.technical_signal === 'bullish',
                                                        'text-red-400': prediction.model_metadata.confidence_breakdown.technical_alignment.technical_signal === 'bearish',
                                                        'text-amber-400': prediction.model_metadata.confidence_breakdown.technical_alignment.technical_signal === 'neutral',
                                                    }"
                                                    x-text="prediction.model_metadata.confidence_breakdown.technical_alignment.technical_signal || 'neutral'"
                                                ></p>
                                            </div>
                                        </div>

                                        {{-- Momentum metrics --}}
                                        <div class="grid grid-cols-2 gap-2 text-xs">
                                            <div class="p-2 bg-zinc-800/50 rounded">
                                                <p class="text-zinc-500 mb-0.5">Net Price Change</p>
                                                <p class="font-mono font-bold"
                                                    :class="{
                                                        'text-emerald-400': (prediction.model_metadata.confidence_breakdown.technical_alignment.net_change_pct ?? 0) > 0,
                                                        'text-red-400': (prediction.model_metadata.confidence_breakdown.technical_alignment.net_change_pct ?? 0) < 0,
                                                    }"
                                                    x-text="prediction.model_metadata.confidence_breakdown.technical_alignment.net_change_pct != null
                                                        ? ((prediction.model_metadata.confidence_breakdown.technical_alignment.net_change_pct > 0 ? '+' : '') + prediction.model_metadata.confidence_breakdown.technical_alignment.net_change_pct.toFixed(2) + '%')
                                                        : 'N/A'"
                                                ></p>
                                            </div>
                                            <div class="p-2 bg-zinc-800/50 rounded">
                                                <p class="text-zinc-500 mb-0.5">Data Points</p>
                                                <p class="font-mono font-bold text-zinc-300"
                                                    x-text="prediction.model_metadata.confidence_breakdown.technical_alignment.data_points || '0'"
                                                ></p>
                                            </div>
                                        </div>

                                        {{-- Green candle ratio --}}
                                        <div class="p-2 bg-zinc-800/50 rounded text-xs">
                                            <div class="flex justify-between items-center">
                                                <span class="text-zinc-500">Green Candle Ratio</span>
                                                <span class="font-mono font-bold"
                                                    :class="{
                                                        'text-emerald-400': (prediction.model_metadata.confidence_breakdown.technical_alignment.green_candle_ratio ?? 0) >= 0.5,
                                                        'text-red-400': (prediction.model_metadata.confidence_breakdown.technical_alignment.green_candle_ratio ?? 0) < 0.5,
                                                    }"
                                                    x-text="prediction.model_metadata.confidence_breakdown.technical_alignment.green_candle_ratio != null
                                                        ? (prediction.model_metadata.confidence_breakdown.technical_alignment.green_candle_ratio * 100).toFixed(0) + '%'
                                                        : 'N/A'"
                                                ></span>
                                            </div>
                                            <div class="w-full bg-zinc-700 rounded-full h-1.5 mt-1.5">
                                                <div class="rounded-full h-1.5 transition-all"
                                                    :class="(prediction.model_metadata.confidence_breakdown.technical_alignment.green_candle_ratio ?? 0) >= 0.5 ? 'bg-emerald-500' : 'bg-red-500'"
                                                    :style="'width: ' + ((prediction.model_metadata.confidence_breakdown.technical_alignment.green_candle_ratio ?? 0) * 100) + '%'"
                                                ></div>
                                            </div>
                                        </div>

                                        {{-- Detail text --}}
                                        <div class="p-2 bg-zinc-800/50 rounded text-xs">
                                            <p class="text-zinc-400" x-text="prediction.model_metadata.confidence_breakdown.technical_alignment.detail"></p>
                                        </div>

                                        {{-- Alignment result badge --}}
                                        <div class="p-3 rounded-lg"
                                            :class="{
                                                'bg-emerald-400/10 border border-emerald-500/20': prediction.model_metadata.confidence_breakdown.technical_alignment.alignment_result === 'aligned',
                                                'bg-red-400/10 border border-red-500/20': prediction.model_metadata.confidence_breakdown.technical_alignment.alignment_result === 'contradiction',
                                                'bg-amber-400/10 border border-amber-500/20': prediction.model_metadata.confidence_breakdown.technical_alignment.alignment_result === 'insufficient_data',
                                            }"
                                        >
                                            <div class="flex items-center gap-2 mb-1">
                                                <span class="text-xs font-bold uppercase"
                                                    :class="{
                                                        'text-emerald-400': prediction.model_metadata.confidence_breakdown.technical_alignment.driver_factor && prediction.model_metadata.confidence_breakdown.technical_alignment.driver_factor.includes('Alignment'),
                                                        'text-red-400': prediction.model_metadata.confidence_breakdown.technical_alignment.driver_factor && prediction.model_metadata.confidence_breakdown.technical_alignment.driver_factor.includes('Divergence'),
                                                    }"
                                                    x-text="prediction.model_metadata.confidence_breakdown.technical_alignment.driver_factor || 'Technical Alignment'"
                                                ></span>
                                                <span class="text-[10px] px-1.5 py-0.5 rounded font-bold"
                                                    :class="{
                                                        'bg-emerald-500/20 text-emerald-400': prediction.model_metadata.confidence_breakdown.technical_alignment.adjustment > 0,
                                                        'bg-red-500/20 text-red-400': prediction.model_metadata.confidence_breakdown.technical_alignment.adjustment < 0,
                                                        'bg-zinc-500/20 text-zinc-400': prediction.model_metadata.confidence_breakdown.technical_alignment.adjustment === 0,
                                                    }"
                                                    x-text="prediction.model_metadata.confidence_breakdown.technical_alignment.adjustment > 0
                                                        ? '+' + (prediction.model_metadata.confidence_breakdown.technical_alignment.adjustment * 100).toFixed(0) + '%'
                                                        : prediction.model_metadata.confidence_breakdown.technical_alignment.adjustment < 0
                                                            ? (prediction.model_metadata.confidence_breakdown.technical_alignment.adjustment * 100).toFixed(0) + '%'
                                                            : '0%'"
                                                ></span>
                                            </div>
                                            <p class="text-xs text-zinc-400"
                                                x-text="prediction.model_metadata.confidence_breakdown.technical_alignment.driver_detail"
                                            ></p>
                                        </div>
                                    </div>
                                </template>

                                {{-- Case: No technical driver (insufficient data or mixed signals) --}}
                                <template x-if="!prediction.model_metadata.confidence_breakdown.technical_alignment.driver_added">
                                    <div class="flex items-start gap-2 text-xs text-zinc-500">
                                        <span class="shrink-0 mt-0.5">⚠️</span>
                                        <span x-text="prediction.model_metadata.confidence_breakdown.technical_alignment.reason || 'Technical alignment could not be computed.'"></span>
                                    </div>
                                </template>
                            </div>
                        </template>
                        <div class="p-4 bg-zinc-800/30 rounded-lg border border-zinc-700/30">
                            <p class="text-xs text-zinc-500 uppercase tracking-wider mb-3">Interpretation Guide</p>
                            <div class="grid grid-cols-3 gap-2 text-xs text-center">
                                <div class="p-2 bg-red-400/10 rounded border border-red-500/20">
                                    <p class="text-red-400 font-bold text-sm">30–49%</p>
                                    <p class="text-zinc-500 mt-1">Low confidence<br/>Few or no trends detected.<br/>Proceed with caution.</p>
                                </div>
                                <div class="p-2 bg-amber-400/10 rounded border border-amber-500/20">
                                    <p class="text-amber-400 font-bold text-sm">50–69%</p>
                                    <p class="text-zinc-500 mt-1">Moderate confidence<br/>Some trends visible.<br/>Use as one data point.</p>
                                </div>
                                <div class="p-2 bg-emerald-400/10 rounded border border-emerald-500/20">
                                    <p class="text-emerald-400 font-bold text-sm">70–95%</p>
                                    <p class="text-zinc-500 mt-1">High confidence<br/>Multiple trends aligned.<br/>Stronger signal.</p>
                                </div>
                            </div>
                        </div>

                    </div>
                </div>
            </template>

            {{-- Feature Importance Bar Chart (rendered by Alpine after DOM update) --}}
            <template x-if="prediction.feature_importance && prediction.feature_importance.length">
                <div class="p-6 bg-zinc-900 border border-zinc-800 rounded-xl mt-4">
                    <h3 class="text-sm font-semibold mb-4">Feature Importance</h3>
                    <div class="relative" style="height: 280px;">
                        <canvas id="featureImportanceChart"></canvas>
                    </div>
                </div>
            </template>
        </div>
    </template>

    {{-- ================================================================
         Financial Statements Table (refreshable after import)
         ================================================================ --}}
    <div class="overflow-hidden bg-zinc-900 border border-zinc-800 rounded-xl">
        <div class="px-6 py-4 border-b border-zinc-800 flex items-center justify-between">
            <h3 class="text-sm font-semibold">Financial Statements</h3>
            <span class="text-xs text-zinc-500" x-text="statements.length + ' records'"></span>
        </div>

        {{-- Empty state --}}
        <template x-if="!statements.length">
            <div class="p-6 text-center text-zinc-500 text-sm">
                No financial data imported yet. Click "Import SEC Data" to pull from the SEC EDGAR API.
            </div>
        </template>

        {{-- Table --}}
        <template x-if="statements.length">
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="text-left text-xs text-zinc-500 uppercase tracking-wider border-b border-zinc-800">
                            <th class="px-6 py-3">Period</th>
                            <th class="px-6 py-3">Filing</th>
                            <th class="px-6 py-3 text-right">Revenue</th>
                            <th class="px-6 py-3 text-right">Net Income</th>
                            <th class="px-6 py-3 text-right">EPS</th>
                            <th class="px-6 py-3 text-right">P/E</th>
                            <th class="px-6 py-3 text-right">D/E</th>
                            <th class="px-6 py-3 text-right">FCF</th>
                            <th class="px-6 py-3 text-right">ROE</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-zinc-800">
                        <template x-for="stmt in statements.slice(0, 12)" :key="stmt.id">
                            <tr class="hover:bg-zinc-800/30 transition-colors">
                                <td class="px-6 py-3 font-mono text-zinc-300">
                                    <span x-text="stmt.fiscal_year"></span>
                                    <span x-show="stmt.fiscal_quarter > 0" x-text="' Q' + stmt.fiscal_quarter"></span>
                                </td>
                                <td class="px-6 py-3">
                                    <span class="px-2 py-0.5 bg-zinc-800 text-xs rounded" x-text="stmt.filing_type"></span>
                                </td>
                                <td class="px-6 py-3 text-right font-mono"
                                    x-text="stmt.revenue ? '$' + (stmt.revenue / 1e9).toFixed(2) + 'B' : '—'"
                                ></td>
                                <td class="px-6 py-3 text-right font-mono"
                                    :class="(stmt.net_income ?? 0) >= 0 ? 'text-emerald-400' : 'text-red-400'"
                                    x-text="stmt.net_income ? '$' + (stmt.net_income / 1e9).toFixed(2) + 'B' : '—'"
                                ></td>
                                <td class="px-6 py-3 text-right font-mono"
                                    x-text="stmt.eps ? Number(stmt.eps).toFixed(2) : '—'"
                                ></td>
                                <td class="px-6 py-3 text-right font-mono"
                                    x-text="stmt.pe_ratio ? Number(stmt.pe_ratio).toFixed(2) : '—'"
                                ></td>
                                <td class="px-6 py-3 text-right font-mono"
                                    x-text="stmt.debt_to_equity ? Number(stmt.debt_to_equity).toFixed(2) : '—'"
                                ></td>
                                <td class="px-6 py-3 text-right font-mono"
                                    x-text="stmt.free_cash_flow ? '$' + (stmt.free_cash_flow / 1e9).toFixed(2) + 'B' : '—'"
                                ></td>
                                <td class="px-6 py-3 text-right font-mono"
                                    x-text="stmt.roe ? (stmt.roe * 100).toFixed(1) + '%' : '—'"
                                ></td>
                            </tr>
                        </template>
                    </tbody>
                </table>
            </div>
        </template>
    </div>

    {{-- ================================================================
         Revenue & Net Income Trend Chart (Chart.js)
         ================================================================ --}}
    @if ($chartData->isNotEmpty())
    <div class="p-6 bg-zinc-900 border border-zinc-800 rounded-xl">
        <h3 class="text-sm font-semibold mb-4">Revenue &amp; Net Income Trends</h3>
        <div class="relative" style="height: 320px;">
            <canvas id="trendChart"></canvas>
        </div>
    </div>
    @endif
</div>
@endsection

@push('scripts')
<script>
// ── Revenue & Net Income Trend Chart (static, rendered once) ──
document.addEventListener('DOMContentLoaded', function () {
    @if ($chartData->isNotEmpty())
    (function() {
        var trendCtx = document.getElementById('trendChart');
        if (!trendCtx) return;

        var rawData = @json($chartData);
        var labels = rawData.map(function(r) {
            return r.fiscal_year + (r.fiscal_quarter > 0 ? ' Q' + r.fiscal_quarter : '');
        });
        var revenue = rawData.map(function(r) { return r.revenue ? r.revenue / 1e9 : null; });
        var netIncome = rawData.map(function(r) { return r.net_income ? r.net_income / 1e9 : null; });

        new Chart(trendCtx, {
            type: 'line',
            data: {
                labels: labels,
                datasets: [
                    {
                        label: 'Revenue ($B)',
                        data: revenue,
                        borderColor: 'rgba(52, 211, 153, 1)',
                        backgroundColor: 'rgba(52, 211, 153, 0.1)',
                        fill: true,
                        tension: 0.3,
                        pointRadius: 3,
                        pointBackgroundColor: 'rgba(52, 211, 153, 1)',
                    },
                    {
                        label: 'Net Income ($B)',
                        data: netIncome,
                        borderColor: 'rgba(96, 165, 250, 1)',
                        backgroundColor: 'rgba(96, 165, 250, 0.1)',
                        fill: true,
                        tension: 0.3,
                        pointRadius: 3,
                        pointBackgroundColor: 'rgba(96, 165, 250, 1)',
                    },
                ],
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                interaction: { intersect: false, mode: 'index' },
                plugins: {
                    legend: {
                        labels: { color: '#a1a1aa', usePointStyle: true, padding: 20 },
                    },
                },
                scales: {
                    x: {
                        grid: { color: 'rgba(63, 63, 70, 0.3)' },
                        ticks: { color: '#a1a1aa', maxTicksLimit: 12 },
                    },
                    y: {
                        grid: { color: 'rgba(63, 63, 70, 0.3)' },
                        ticks: { color: '#a1a1aa', callback: function(v) { return '$' + v.toFixed(1) + 'B'; } },
                    },
                },
            },
        });
    })();
    @endif
});
</script>
@endpush
