<?php

namespace App\Http\Requests;

use App\Support\JsonArrayInput;
use Illuminate\Foundation\Http\FormRequest;

class ConfirmReceiptRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $images = $this->input('images');
        $decoded = JsonArrayInput::decode($images);
        if (is_string($decoded)) {
            $decoded = array_values(array_filter(
                array_map('trim', explode(',', $decoded)),
                fn (string $item) => $item !== '',
            ));
        }
        if ($decoded !== $images) {
            $this->merge(['images' => $decoded]);
        }
    }

    public function rules(): array
    {
        return [
            'images' => ['required', 'array', 'min:1', 'max:6'],
            'images.*' => ['required', 'string', 'max:255'],
        ];
    }

    public function messages(): array
    {
        return [
            'images.required' => '请上传接收照片',
            'images.min' => '请至少上传 1 张接收照片',
        ];
    }
}
