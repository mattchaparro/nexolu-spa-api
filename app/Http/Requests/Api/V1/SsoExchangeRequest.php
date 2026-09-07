<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;

class SsoExchangeRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Publica: la asercion firmada ES la credencial. Quien la valida es
        // NexoluAuthAssertion, no un guard.
        return true;
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'assertion' => ['required', 'string'],
            'device_name' => ['required', 'string', 'max:255'],
        ];
    }
}
