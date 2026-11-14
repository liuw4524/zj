<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateClueContactRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'contact_public' => ['required', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'contact_public.required' => '请选择联系方式是否公开',
        ];
    }
}
