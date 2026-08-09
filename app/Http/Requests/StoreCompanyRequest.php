<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * @property-read string $ticker
 * @property-read string $name
 * @property-read string|null $sector
 * @property-read string|null $industry
 * @property-read string|null $cik
 */
final class StoreCompanyRequest extends FormRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'ticker' => ['required', 'string', 'max:10', 'unique:companies,ticker'],
            'name' => ['required', 'string', 'max:255'],
            'sector' => ['nullable', 'string', 'max:100'],
            'industry' => ['nullable', 'string', 'max:100'],
            'description' => ['nullable', 'string', 'max:5000'],
            'cik' => ['nullable', 'string', 'max:20'],
        ];
    }
}
