<?php

namespace App\Http\Requests;

use App\Support\Phone;
use Illuminate\Foundation\Http\FormRequest;

class UpdateProfileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $merged = [
            'name' => trim((string) $this->input('name')),
            'phone' => Phone::normalize($this->input('phone')),
        ];
        foreach (['province', 'city', 'district', 'address'] as $key) {
            if ($this->exists($key)) {
                $merged[$key] = $this->blankToNull($key);
            }
        }
        $this->merge($merged);
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:20'],
            'phone' => ['required', 'regex:/^1\d{10}$/'],
            'province' => ['nullable', 'string', 'max:40'],
            'city' => ['nullable', 'string', 'max:40'],
            'district' => ['nullable', 'string', 'max:40'],
            'address' => ['nullable', 'string', 'max:80'],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            $province = $this->input('province');
            $city = $this->input('city');
            $district = $this->input('district');
            if (($city || $district) && ! $province) {
                $validator->errors()->add('province', '请选择完整地址');
            }
            if ($district && ! $city) {
                $validator->errors()->add('city', '请选择完整地址');
            }
        });
    }

    public function messages(): array
    {
        return [
            'name.required' => '请填写昵称',
            'name.max' => '昵称最多 20 个字',
            'phone.required' => '请填写联系方式',
            'phone.regex' => '请填写11位手机号',
            'address.max' => '地址最多 80 个字',
        ];
    }

    private function blankToNull(string $key): ?string
    {
        $value = trim((string) $this->input($key, ''));

        return $value === '' ? null : $value;
    }
}
