<?php

namespace App\Http\Controllers;

use App\Actions\ImportFinancialData;
use App\Actions\TriggerPrediction;
use App\Http\Requests\StoreCompanyRequest;
use App\Http\Resources\CompanyResource;
use App\Models\Company;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

final class CompanyController extends Controller
{
    /**
     * Display a listing of companies with top buy/sell rankings.
     */
    public function index(Request $request): View
    {
        $companies = Company::query()
            ->when($request->query('sector'), fn ($q, $sector) => $q->where('sector', $sector))
            ->when($request->query('search'), fn ($q, $search) => $q->where(function ($q) use ($search): void {
                $q->where('ticker', 'like', "%{$search}%")
                    ->orWhere('name', 'like', "%{$search}%");
            }))
            ->orderBy('ticker')
            ->paginate(25)
            ->withQueryString();

        $sectors = Company::query()
            ->distinct()
            ->whereNotNull('sector')
            ->pluck('sector');

        // ── Rankings: Top 5 overall (strongest signals first) ──
        $topRanked = Company::query()
            ->withLatestPrediction()
            ->get()
            ->filter(fn (Company $c) => $c->latest_signal_type !== null && (float) ($c->latest_confidence_score ?? 0) >= 0.5)
            ->sortByDesc(function (Company $company): float {
                $directionScore = match ($company->latest_signal_type) {
                    'buy' => 1.0,
                    'sell' => -1.0,
                    default => 0.0,
                };

                return $directionScore * (float) ($company->latest_confidence_score ?? 0);
            })
            ->take(5)
            ->values();

        $topBuys = $topRanked->filter(fn (Company $c) => $c->latest_signal_type === 'buy')->values();
        $topSells = $topRanked->filter(fn (Company $c) => $c->latest_signal_type === 'sell')->values();

        return view('companies.index', compact(
            'companies',
            'sectors',
            'topBuys',
            'topSells',
        ));
    }

    /**
     * Full rankings page — all companies ranked by prediction strength.
     *
     * BUY signals first (by confidence), then HOLD, then SELL,
     * then companies with no predictions.
     */
    public function rankings(): View
    {
        // Fetch ALL companies with their latest completed prediction data
        $ranked = Company::query()
            ->withLatestPrediction()
            ->orderBy('ticker')
            ->get()
            ->sortByDesc(function (Company $company): float {
                $signal = $company->latest_signal_type;
                $confidence = (float) ($company->latest_confidence_score ?? 0);

                // Composite score: BUY=+1, HOLD=0, SELL=-1 multiplier × confidence
                $directionScore = match ($signal) {
                    'buy' => 1.0,
                    'sell' => -1.0,
                    default => 0.0,
                };

                // Prioritize companies with predictions over those without
                $hasPrediction = $signal !== null ? 100.0 : 0.0;

                // Final score: direction × confidence + has-prediction bonus
                return $hasPrediction + ($directionScore * $confidence);
            })
            ->values();

        return view('companies.rankings', compact('ranked'));
    }

    /**
     * Show a single company with financial data and predictions.
     */
    public function show(Company $company): View
    {
        $company->load([
            'financialStatements' => fn ($q) => $q->latest('reported_date')->limit(20),
            'predictions' => fn ($q) => $q->where('status', 'completed')->latest()->limit(5),
        ]);

        $statements = $company->financialStatements()
            ->orderBy('fiscal_year')
            ->orderBy('fiscal_quarter')
            ->get();

        $chartData = $company->financialStatements()
            ->orderBy('fiscal_year')
            ->orderBy('fiscal_quarter')
            ->get();

        // Build prediction data for Alpine.js initialization
        $latestPrediction = $company->latestPrediction();
        $predictionData = null;

        if ($latestPrediction && $latestPrediction->isActionable()) {
            $predictionData = [
                'id' => $latestPrediction->id,
                'predicted_price' => $latestPrediction->predicted_price,
                'confidence_score' => $latestPrediction->confidence_score,
                'prediction_direction' => $latestPrediction->prediction_direction,
                'signal_type' => $latestPrediction->signal_type,
                'predicted_return' => $latestPrediction->predicted_return,
                'target_period' => $latestPrediction->target_period,
                'feature_importance' => $latestPrediction->feature_importance,
                'model_metadata' => $latestPrediction->model_metadata,
                'status' => $latestPrediction->status,
            ];
        }

        // Build statements data for Alpine.js table initialization
        $statementsData = $statements->map(fn ($s) => [
            'id' => $s->id,
            'fiscal_year' => $s->fiscal_year,
            'fiscal_quarter' => $s->fiscal_quarter,
            'filing_type' => $s->filing_type,
            'revenue' => $s->revenue,
            'net_income' => $s->net_income,
            'eps' => $s->eps,
            'pe_ratio' => $s->pe_ratio,
            'debt_to_equity' => $s->debt_to_equity,
            'free_cash_flow' => $s->free_cash_flow,
            'roe' => $s->roe,
            'reported_date' => $s->reported_date?->toDateString(),
        ])->values();

        return view('companies.show', compact(
            'company',
            'statements',
            'chartData',
            'predictionData',
            'statementsData',
        ));
    }

    /**
     * Store a newly created company.
     */
    public function store(StoreCompanyRequest $request): RedirectResponse
    {
        $company = Company::create($request->validated());

        return redirect()
            ->route('companies.show', $company)
            ->with('success', "Company {$company->ticker} added successfully.");
    }

    /**
     * Trigger financial data import from SEC for a company.
     */
    public function importFinancials(Company $company, ImportFinancialData $importer): RedirectResponse
    {
        try {
            $importer->handle($company);

            return redirect()
                ->route('companies.show', $company)
                ->with('success', "Financial data import queued for {$company->ticker}.");
        } catch (\Throwable $e) {
            return redirect()
                ->route('companies.show', $company)
                ->with('error', "Import failed: {$e->getMessage()}");
        }
    }

    /**
     * Trigger a prediction for a company.
     */
    public function triggerPrediction(Company $company, TriggerPrediction $trigger): RedirectResponse
    {
        try {
            $prediction = $trigger->handle($company, request()->input('target_period', '3m'));

            return redirect()
                ->route('companies.show', $company)
                ->with('success', "Prediction queued for {$company->ticker}. Check back shortly.");
        } catch (\Throwable $e) {
            return redirect()
                ->route('companies.show', $company)
                ->with('error', "Prediction failed: {$e->getMessage()}");
        }
    }

    // ─── API Methods ───────────────────────────────────────────

    /**
     * API: List companies.
     */
    public function apiIndex(Request $request): JsonResponse
    {
        $companies = Company::query()
            ->when($request->query('sector'), fn ($q, $sector) => $q->where('sector', $sector))
            ->when($request->query('search'), fn ($q, $search) => $q->where(function ($q) use ($search): void {
                $q->where('ticker', 'like', "%{$search}%")
                    ->orWhere('name', 'like', "%{$search}%");
            }))
            ->orderBy('ticker')
            ->paginate(25);

        return response()->json([
            'data' => CompanyResource::collection($companies),
            'meta' => [
                'current_page' => $companies->currentPage(),
                'last_page' => $companies->lastPage(),
                'total' => $companies->total(),
            ],
        ]);
    }

    /**
     * API: Show a single company.
     */
    public function apiShow(Company $company): JsonResponse
    {
        $company->load([
            'financialStatements' => fn ($q) => $q->latest('reported_date')->limit(20),
            'predictions' => fn ($q) => $q->where('status', 'completed')->latest()->limit(5),
        ]);

        return response()->json([
            'data' => new CompanyResource($company),
        ]);
    }

    /**
     * API: Store a new company.
     */
    public function apiStore(StoreCompanyRequest $request): JsonResponse
    {
        $company = Company::create($request->validated());

        return response()->json([
            'data' => new CompanyResource($company),
        ], 201);
    }

    /**
     * API: Import SEC financial data for a company.
     *
     * Called by the Alpine.js "Import SEC Data" button via AJAX.
     * Returns the latest financial statements as JSON so the
     * frontend can refresh the table inline.
     */
    public function apiImportFinancials(Company $company, ImportFinancialData $importer): JsonResponse
    {
        try {
            $statements = $importer->handle($company);

            $statementData = $statements->map(fn ($s) => [
                'id' => $s->id,
                'fiscal_year' => $s->fiscal_year,
                'fiscal_quarter' => $s->fiscal_quarter,
                'filing_type' => $s->filing_type,
                'revenue' => $s->revenue,
                'net_income' => $s->net_income,
                'eps' => $s->eps,
                'pe_ratio' => $s->pe_ratio,
                'debt_to_equity' => $s->debt_to_equity,
                'free_cash_flow' => $s->free_cash_flow,
                'roe' => $s->roe,
                'reported_date' => $s->reported_date?->toDateString(),
            ]);

            return response()->json([
                'message' => "Financial data imported for {$company->ticker}. {$statements->count()} statements loaded.",
                'statements' => $statementData,
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'message' => "Import failed: {$e->getMessage()}",
            ], 500);
        }
    }
}
