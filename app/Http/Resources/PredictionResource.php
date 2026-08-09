<?php

namespace App\Http\Resources;

use App\Models\Prediction;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Prediction
 */
final class PredictionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'company_id' => $this->company_id,
            'ticker' => $this->company?->ticker,
            'predicted_price' => $this->predicted_price,
            'confidence_score' => $this->confidence_score,
            'prediction_direction' => $this->prediction_direction,
            'signal_type' => $this->signal_type,
            'predicted_return' => $this->predicted_return,
            'target_period' => $this->target_period,
            'feature_importance' => $this->feature_importance,
            'model_metadata' => $this->model_metadata,
            'status' => $this->status,
            'is_actionable' => $this->isActionable(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
