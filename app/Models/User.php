<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable;

    protected $fillable = [
        'name',
        'email',
        'password',
        'role',
        'account_status',
        'guide_qualification_path',
        'phone',
        'is_active',
        'guide_visible',
        'spam_violation_count',
        'spam_last_submission_at',
        'spam_validation_status',
        'spam_restriction_expires_at',
        'spam_last_request_hash',
        'spam_flagged_at',
    ];

    protected $hidden = ['password', 'remember_token', 'guide_qualification_path'];

    protected function email(): Attribute
    {
        return Attribute::make(
            set: static fn (mixed $value): string => strtolower(trim((string) $value)),
        );
    }

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_active' => 'boolean',
            'guide_visible' => 'boolean',
            'spam_last_submission_at' => 'datetime',
            'spam_restriction_expires_at' => 'datetime',
            'spam_flagged_at' => 'datetime',
            'spam_violation_count' => 'integer',
        ];
    }

    public function trails()
    {
        return $this->hasMany(Trail::class);
    }

    public function bookings()
    {
        return $this->hasMany(Booking::class);
    }
}
