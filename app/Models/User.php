<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class User extends Authenticatable
{
    use HasFactory, Notifiable;

    protected $fillable = [
        'name',
        'employee_id',
        'email',
        'password',
        'telegram_chat_id',
        'telegram_username',
        'phone',
        'role', // 'employee', 'hod', 'barista', 'admin'
        'branch_id',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function drinkOrders(): HasMany
    {
        return $this->hasMany(DrinkOrder::class);
    }

    // Role helpers
    public function isAdmin(): bool
    {
        return $this->role === 'admin';
    }

    public function isHod(): bool
    {
        return in_array($this->role, ['hod', 'admin']);
    }

    public function isBarista(): bool
    {
        return in_array($this->role, ['barista', 'admin']);
    }
}
