<?php

namespace App\Http\Requests;

use App\Support\JsonArrayInput;
use App\Support\Phone;
use Illuminate\Foundation\Http\FormRequest;

class StoreCluePostRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $merged = [];
        $phone = Phone::normalize($this->input('phone'));
        $contactPhone = Phone::normalize($this->input('contact_phone')) ?? $phone;
        if ($phone !== null) {
            $merged['phone'] = $phone;
        }
        if ($contactPhone !== null) {
            $merged['contact_phone'] = $contactPhone;
        }
        foreach (['province', 'city'] as $key) {
            $value = $this->input($key);
            if (is_string($value)) {
                $value = trim($value);
                $merged[$key] = $value === '' ? null : $value;
            }
        }
        $images = JsonArrayInput::decode($this->input('images'));
        if ($images !== $this->input('images')) {
            $merged['images'] = $images;
        }
        $this->merge($merged);
    }

    public function rules(): array
    {
        return [
            'description' => ['required', 'string', 'max:1000'],
            'province' => ['required', 'string', 'max:40'],
            'city' => ['required', 'string', 'max:40'],
            'found_at' => ['required', 'date', 'before_or_equal:now'],
            'contact_name' => ['required', 'string', 'max:40'],
            'contact_phone' => ['required', 'regex:/^1\d{10}$/'],
            'phone' => ['required', 'regex:/^1\d{10}$/'],
            'contact_public' => ['required', 'boolean'],
            'images' => ['required', 'array', 'min:1', 'max:4'],
            'images.*' => ['required', 'string', 'max:255'],
        ];
    }

    public function messages(): array
    {
        return [
            'description.required' => '请填写说明',
            'province.required' => '请选择城市',
            'city.required' => '请选择城市',
            'found_at.required' => '请填写发现时间',
            'contact_name.required' => '请填写联系人',
            'contact_phone.regex' => '联系电话格式不正确',
            'phone.regex' => '手机号格式不正确',
            'contact_public.required' => '请选择联系方式是否公开',
            'images.required' => '请上传照片',
            'images.min' => '请至少上传 1 张照片',
            'images.max' => '最多上传 4 张照片',
        ];
    }
}
