<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable implements FilamentUser
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, HasRoles, Notifiable;

    protected $fillable = [
        'full_name',
        'email',
        'phone',
        'password',
        'role',
        'avatar_url',
        'bio',
        'email_verified',
        'email_verified_at',
        'fcm_tokens',
        'delivery_address',
        'pickup_address',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified' => 'boolean',
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'fcm_tokens' => 'array',
            'delivery_address' => 'array',
            'pickup_address' => 'array',
        ];
    }

    public function canAccessPanel(Panel $panel): bool
    {
        // Spatie roles for Filament; keep legacy column as fallback for existing admins
        return $this->hasAnyRole(['super_admin', 'admin'])
            || $this->role === 'admin';
    }

    public function getNameAttribute(): string
    {
        return $this->full_name;
    }

    public function products(): HasMany
    {
        return $this->hasMany(Product::class, 'seller_id');
    }

    /**
     * Marketplace / FCM inbox (custom table). Laravel/Filament DB notifications
     * use Notifiable::notifications() against the morph `notifications` table.
     */
    public function appNotifications(): HasMany
    {
        return $this->hasMany(Notification::class);
    }

    public function emptyAddress(): array
    {
        return [
            'fullName' => '',
            'phone' => '',
            'line1' => '',
            'line2' => '',
            'city' => '',
            'district' => '',
            'note' => '',
        ];
    }
}
