<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * @property-read int $company_id
 * @property-read float|null $target_price
 * @property-read string|null $notes
 */
final class StoreWatchlistRequest extends FormRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'company_id' => ['required', 'integer', 'exists:companies,id'],
            'target_price' => ['nullable', 'numeric', 'min:0', 'max:9999999'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
