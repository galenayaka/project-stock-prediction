<?php

namespace App\Jobs;

use App\Models\Prediction;
use App\Services\PredictionService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Job to run an ML prediction via the Python FastAPI microservice.
 * Processes a single Prediction record asynchronously.
 */
final class RunPrediction implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * The number of times the job may be attempted.
     */
    public int $tries = 3;

    /**
     * The maximum number of unhandled exceptions to allow before failing.
     */
    public int $maxExceptions = 2;

    /**
     * Delete the job if its model no longer exists.
     */
    public bool $deleteWhenMissingModels = true;

    /**
     * Create a new job instance.
     */
    public function __construct(
        private readonly Prediction $prediction,
    ) {}

    public function handle(PredictionService $predictionService): void
    {
        $predictionService->predictAndStore($this->prediction);
    }

    /**
     * Handle a job failure.
     */
    public function failed(\Throwable $exception): void
    {
        $this->prediction->markFailed($exception->getMessage());
    }

    /**
     * Get the tags for the job.
     *
     * @return array<int, string>
     */
    public function tags(): array
    {
        return ['run-prediction', "prediction:{$this->prediction->id}"];
    }
}
