<?php

use App\Models\Company;
use App\Models\User;
use App\Models\UserWatchlist;
use Illuminate\Database\QueryException;

beforeEach(function (): void {
    $this->user = User::factory()->create();
    $this->company = Company::factory()->create(['ticker' => 'GOOGL']);
});

test('user can add company to watchlist', function (): void {
    $watchlist = UserWatchlist::create([
        'user_id' => $this->user->id,
        'company_id' => $this->company->id,
        'target_price' => 200.00,
        'notes' => 'Watching for AI momentum',
    ]);

    expect($watchlist->user_id)->toBe($this->user->id);
    expect($watchlist->company_id)->toBe($this->company->id);
});

test('user has many watchlist entries', function (): void {
    UserWatchlist::factory()->count(3)->create(['user_id' => $this->user->id]);

    expect($this->user->watchlists)->toHaveCount(3);
});

test('watchlist unique constraint prevents duplicates', function (): void {
    UserWatchlist::create([
        'user_id' => $this->user->id,
        'company_id' => $this->company->id,
    ]);

    $this->expectException(QueryException::class);

    UserWatchlist::create([
        'user_id' => $this->user->id,
        'company_id' => $this->company->id,
    ]);
});
