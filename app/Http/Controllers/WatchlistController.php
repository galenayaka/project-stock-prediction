<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreWatchlistRequest;
use App\Models\UserWatchlist;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class WatchlistController extends Controller
{
    /**
     * List user's watchlist.
     */
    public function index(Request $request): JsonResponse
    {
        $watchlist = UserWatchlist::query()
            ->where('user_id', $request->user()->id)
            ->with(['company', 'company.latestPrediction'])
            ->latest()
            ->get();

        return response()->json([
            'data' => $watchlist,
        ]);
    }

    /**
     * Add a company to the user's watchlist.
     */
    public function store(StoreWatchlistRequest $request): JsonResponse
    {
        $watchlist = UserWatchlist::create([
            'user_id' => $request->user()->id,
            ...$request->validated(),
        ]);

        $watchlist->load('company');

        return response()->json([
            'data' => $watchlist,
            'message' => 'Company added to watchlist.',
        ], 201);
    }

    /**
     * Remove a company from the user's watchlist.
     */
    public function destroy(Request $request, UserWatchlist $watchlist): JsonResponse
    {
        if ($watchlist->user_id !== $request->user()->id) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        $watchlist->delete();

        return response()->json(['message' => 'Removed from watchlist.'], 200);
    }
}
