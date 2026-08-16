<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreWatchlistRequest;
use App\Models\UserWatchlist;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class WatchlistController extends Controller
{
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

    public function destroy(Request $request, UserWatchlist $watchlist): JsonResponse
    {
        if ($watchlist->user_id !== $request->user()->id) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        $watchlist->delete();

        return response()->json(['message' => 'Removed from watchlist.'], 200);
    }
}
