<?php

namespace App\Http\Requests;

use App\Services\SupportService;
use Illuminate\Foundation\Http\FormRequest;

class StoreSupportOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'client_token' => trim((string) $this->input('client_token', '')),
        ]);
    }

    public function rules(): array
    {
        return [
            'paw_count' => ['required', 'integer', 'min:1', 'max:'.SupportService::MAX_PAW_COUNT],
            'anonymous' => ['required', 'boolean'],
            'client_token' => ['required', 'string', 'regex:/^[A-Za-z0-9_-]{8,64}$/'],
        ];
    }

    public function messages(): array
    {
        return [
            'paw_count.required' => '请填写爪印数',
            'paw_count.integer' => '爪印数须为整数',
            'paw_count.min' => '最少 1 爪印',
            'paw_count.max' => '单笔过大，请换成小额分笔或联系客服',
            'anonymous.required' => '请选择是否匿名',
            'anonymous.boolean' => '请选择是否匿名',
            'client_token.required' => '请重试',
            'client_token.regex' => '请重试',
        ];
    }
}
