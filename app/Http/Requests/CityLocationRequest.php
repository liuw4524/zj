<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CityLocationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'latitude' => ['required', 'numeric', 'between:-90,90'],
            'longitude' => ['required', 'numeric', 'between:-180,180'],
        ];
    }

    public function messages(): array
    {
        return [
            'latitude.required' => '请提供定位',
            'longitude.required' => '请提供定位',
            'latitude.between' => '定位无效',
            'longitude.between' => '定位无效',
        ];
    }
}
