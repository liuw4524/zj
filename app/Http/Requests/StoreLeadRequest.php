<?php

namespace App\Http\Requests;

use App\Support\Phone;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreLeadRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $phone = Phone::normalize($this->input('phone'));
        $contactPhone = Phone::normalize($this->input('contact_phone')) ?? $phone;

        $merged = [
            'phone' => $phone,
            'contact_phone' => $contactPhone,
        ];
        if ($this->input('kind') === 'seen' && ! is_array($this->input('images'))) {
            $merged['images'] = [];
        }

        $this->merge($merged);
    }

    public function rules(): array
    {
        $images = $this->input('kind') === 'seen'
            ? ['array', 'max:6']
            : ['required', 'array', 'min:1', 'max:6'];

        return [
            'kind' => ['required', 'string', Rule::in(['held', 'seen'])],
            'content' => ['required', 'string', 'max:1000'],
            'contact_name' => ['required', 'string', 'max:40'],
            'contact_phone' => ['required', 'regex:/^1\d{10}$/'],
            'phone' => ['required', 'regex:/^1\d{10}$/'],
            'images' => $images,
            'images.*' => ['required', 'string', 'max:255'],
        ];
    }

    public function messages(): array
    {
        return [
            'kind.required' => '请选择线索类型',
            'kind.in' => '请选择线索类型',
            'content.required' => $this->input('kind') === 'seen' ? '请填写看到的地点和时间' : '请填写找回说明',
            'contact_phone.regex' => '请填写11位手机号',
            'contact_phone.required' => '请填写手机号',
            'phone.regex' => '请填写11位手机号',
            'phone.required' => '请填写手机号',
            'images.required' => '请至少上传 1 张照片',
            'images.min' => '请至少上传 1 张照片',
        ];
    }
}
