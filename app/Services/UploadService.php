<?php

namespace App\Services;

use App\Exceptions\BusinessException;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

class UploadService
{
    public function store(User $user, UploadedFile $file): array
    {
        $path = $file->store('uploads/'.$user->id, 'public');

        return [
            'path' => $path,
            'url' => Storage::disk('public')->url($path),
        ];
    }

    public function assertOwned(User $user, string $path): void
    {
        $prefix = 'uploads/'.$user->id.'/';
        if (! str_starts_with($path, $prefix) || str_contains($path, '..')) {
            throw new BusinessException('图片无效');
        }

        if (! Storage::disk('public')->exists($path)) {
            throw new BusinessException('图片无效');
        }
    }
}
