<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * @property-read string $timeframe
 */
final class TriggerPredictionRequest extends FormRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'timeframe' => ['required', 'string', 'in:1m,3m,6m,1y,1 Month,3 Months,6 Months,1 Year'],
        ];
    }
}
