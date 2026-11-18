<?php

namespace App\Http\Requests;

use App\Support\JsonArrayInput;
use App\Support\Phone;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StorePostRequest extends FormRequest
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
        foreach (['province', 'city', 'district', 'town'] as $key) {
            $value = $this->input($key);
            if (is_string($value)) {
                $value = trim($value);
                $merged[$key] = $value === '' ? null : $value;
            }
        }
        if ((int) $this->input('reward_amount') <= 0) {
            $merged['escrow_to_platform'] = false;
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
            'pet_name' => ['required', 'string', 'max:40'],
            'breed' => ['nullable', 'string', 'max:40'],
            'sex' => ['required', 'string', Rule::in(['公', '母', '未知'])],
            'description' => ['required', 'string', 'max:1000'],
            'pet_traits' => ['nullable', 'string', 'max:1000'],
            'province' => ['required', 'string', 'max:40'],
            'city' => ['required', 'string', 'max:40'],
            'district' => ['nullable', 'string', 'max:40', 'required_with:town'],
            'town' => ['nullable', 'string', 'max:40'],
            'lost_place' => ['required', 'string', 'max:100'],
            'lost_story' => ['nullable', 'string', 'max:1000'],
            'lost_at' => ['required', 'date', 'before_or_equal:now'],
            'reward_amount' => ['required', 'integer', 'min:0'],
            'escrow_to_platform' => ['required', 'boolean'],
            'contact_name' => ['required', 'string', 'max:40'],
            'contact_phone' => ['required', 'regex:/^1\d{10}$/'],
            'phone' => ['required', 'regex:/^1\d{10}$/'],
            'images' => ['required', 'array', 'min:1', 'max:5'],
            'images.*' => ['required', 'string', 'max:255'],
        ];
    }

    public function messages(): array
    {
        return [
            'pet_name.required' => '请填写昵称',
            'sex.required' => '请选择性别',
            'sex.in' => '请选择性别',
            'description.required' => '请填写描述',
            'province.required' => '请选择地点',
            'city.required' => '请选择城市',
            'district.required_with' => '填写镇或乡时要先选到区县',
            'lost_place.required' => '请填写具体丢失地点',
            'lost_at.required' => '请填写丢失时间',
            'reward_amount.required' => '请填写酬谢金额',
            'reward_amount.min' => '酬谢不能小于 0',
            'escrow_to_platform.required' => '请选择是否交给平台',
            'escrow_to_platform.boolean' => '请选择是否交给平台',
            'contact_phone.regex' => '联系电话格式不正确',
            'phone.regex' => '手机号格式不正确',
            'images.required' => '请上传照片',
            'images.min' => '请至少上传 1 张照片',
            'images.max' => '最多上传 5 张照片',
        ];
    }
}
