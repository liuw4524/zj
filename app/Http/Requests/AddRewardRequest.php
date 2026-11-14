<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class AddRewardRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'add_amount' => ['required', 'integer', 'min:1'],
        ];
    }

    public function messages(): array
    {
        return [
            'add_amount.required' => '请填写追加金额',
            'add_amount.integer' => '请填写追加金额',
            'add_amount.min' => '追加金额必须大于 0',
        ];
    }
}
