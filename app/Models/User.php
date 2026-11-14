<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Exceptions\BusinessException;
use App\Support\DebugUsers;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable implements FilamentUser
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'phone',
        'password',
        'is_blocked',
        'province',
        'city',
        'district',
        'address',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_blocked' => 'boolean',
            'growth_points' => 'integer',
            'medal_level' => 'integer',
            'support_paws' => 'integer',
        ];
    }

    public function growthLogs(): HasMany
    {
        return $this->hasMany(GrowthLog::class);
    }

    public function supportLogs(): HasMany
    {
        return $this->hasMany(SupportLog::class);
    }

    public function supportOrders(): HasMany
    {
        return $this->hasMany(SupportOrder::class);
    }

    public function notifications(): MorphMany
    {
        return $this->morphMany(OpsDatabaseNotification::class, 'notifiable')->latest();
    }

    public function assertNotBlocked(): void
    {
        if ($this->is_blocked) {
            throw new BusinessException('账号已限制', 403);
        }
    }

    public function canAccessPanel(Panel $panel): bool
    {
        return $this->email === DebugUsers::OPS;
    }
}
