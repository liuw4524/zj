<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreUploadRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'file' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
        ];
    }

    public function messages(): array
    {
        return [
            'file.required' => '请上传图片',
            'file.image' => '只能上传图片',
            'file.mimes' => '图片格式仅支持 jpg、png、webp',
            'file.max' => '图片不能超过 5MB',
        ];
    }
}
