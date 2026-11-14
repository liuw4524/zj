<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class WechatLoginRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'code' => ['required', 'string', 'max:128'],
        ];
    }

    public function messages(): array
    {
        return [
            'code.required' => '请先完成微信登录',
        ];
    }
}
